<?php
/**
 * Captures price changes of products and variations.
 *
 * @package Pnscripts\Omnibus
 */

declare(strict_types=1);

namespace Pnscripts\Omnibus\Capture;

use Pnscripts\Omnibus\Domain\Clock;
use Pnscripts\Omnibus\Domain\Money;
use Pnscripts\Omnibus\Domain\PriceRecord;
use Pnscripts\Omnibus\Storage\HistoryRepository;
use WC_Product;

/**
 * Records a new row whenever the price state of a product changes.
 *
 * Write paths covered:
 * - every WC_Product::save() (admin, REST API, CSV import, bulk and quick edit, scheduled sale
 *   transitions, third-party code) via woocommerce_after_product_object_save;
 * - direct price meta writes (update_post_meta on _price, _regular_price, _sale_price and the sale dates),
 *   queued and checked once at shutdown.
 */
final class PriceRecorder {

	/**
	 * Meta keys that influence the price.
	 */
	private const PRICE_META_KEYS = array( '_price', '_regular_price', '_sale_price', '_sale_price_dates_from', '_sale_price_dates_to' );

	/**
	 * Most queued products checked at shutdown; the rest is left to the repair job.
	 */
	private const MAX_QUEUE = 500;

	/**
	 * Latest known record per product in this request.
	 *
	 * @var array<int, PriceRecord|null>
	 */
	private array $latest = array();

	/**
	 * Product ids touched by direct meta writes.
	 *
	 * @var array<int, true>
	 */
	private array $queue = array();

	/**
	 * Last-modified time of products as loaded before the current save (UTC).
	 *
	 * @var array<int, int>
	 */
	private array $modified_before_save = array();

	/**
	 * Products whose stored price differed from their latest record before a save: id => start of the unknown
	 * period (the price was written by something that bypassed every hook, at an unknown moment).
	 *
	 * @var array<int, int>
	 */
	private array $changed_out_of_band = array();

	/**
	 * Option holding the period the plugin was inactive, until the resume job has checked every product.
	 */
	public const RESUME_OPTION = 'pnscripts_omnibus_resume';

	/**
	 * Constructor.
	 *
	 * @param HistoryRepository $repository Storage.
	 * @param Clock             $clock      Time.
	 * @param SourceDetector    $sources    Source labels.
	 */
	public function __construct(
		private readonly HistoryRepository $repository,
		private readonly Clock $clock,
		private readonly SourceDetector $sources
	) {
	}

	/**
	 * Hook into WordPress.
	 */
	public function register(): void {
		add_action( 'woocommerce_before_product_object_save', array( $this, 'on_before_product_save' ), 10, 1 );
		add_action( 'woocommerce_after_product_object_save', array( $this, 'on_product_saved' ), 20, 1 );
		add_action( 'added_post_meta', array( $this, 'on_meta_changed' ), 10, 3 );
		add_action( 'updated_post_meta', array( $this, 'on_meta_changed' ), 10, 3 );
		add_action( 'deleted_post_meta', array( $this, 'on_meta_deleted' ), 10, 3 );
		add_action( 'shutdown', array( $this, 'flush_queue' ), 5 );
		add_action( 'delete_post', array( $this, 'on_post_deleted' ), 10, 1 );
	}

	/**
	 * Remember the stored last-modified time before WooCommerce overwrites it.
	 *
	 * @param mixed $product Product about to be saved.
	 */
	public function on_before_product_save( mixed $product ): void {
		if ( ! $product instanceof WC_Product || $product->get_id() <= 0 ) {
			return;
		}
		$id       = $product->get_id();
		$modified = $product->get_date_modified( 'edit' );
		if ( $modified ) {
			$this->modified_before_save[ $id ] = $modified->getTimestamp();
		}

		if ( ! self::supports( $product ) || isset( $this->queue[ $id ] ) ) {
			return; // Meta written in this request: the change happened now and is recorded as such.
		}
		if ( ! array_key_exists( $id, $this->latest ) ) {
			$this->latest[ $id ] = $this->repository->latest( $id );
		}
		$latest = $this->latest[ $id ];
		if ( null !== $latest && $latest->has_configuration() && ! $latest->same_state( self::stored_snapshot( $product ) ) ) {
			$this->changed_out_of_band[ $id ] = $latest->changed_at;
		}
	}

	/**
	 * Price state as loaded from the database, ignoring changes pending in this save.
	 *
	 * @param WC_Product $product Product about to be saved.
	 */
	private static function stored_snapshot( WC_Product $product ): PriceRecord {
		$data = $product->get_data();
		$from = $data['date_on_sale_from'] ?? null;
		$to   = $data['date_on_sale_to'] ?? null;
		return new PriceRecord(
			0,
			Money::normalize( $data['regular_price'] ?? null ),
			Money::normalize( $data['sale_price'] ?? null ),
			$from instanceof \DateTimeInterface ? $from->getTimestamp() : null,
			$to instanceof \DateTimeInterface ? $to->getTimestamp() : null,
			Money::normalize( $data['price'] ?? null )
		);
	}

	/**
	 * Inactive period still being checked: array{from: int, to: int} or null.
	 *
	 * @return array{from: int, to: int}|null
	 */
	public static function resume_window(): ?array {
		$window = get_option( self::RESUME_OPTION, null );
		if ( is_array( $window ) && isset( $window['from'], $window['to'] ) && is_numeric( $window['from'] ) && is_numeric( $window['to'] ) ) {
			return array(
				'from' => (int) $window['from'],
				'to'   => (int) $window['to'],
			);
		}
		return null;
	}

	/**
	 * After any product or variation save.
	 *
	 * @param mixed $product Saved object.
	 */
	public function on_product_saved( mixed $product ): void {
		if ( $product instanceof WC_Product ) {
			$id    = $product->get_id();
			$since = $this->changed_out_of_band[ $id ] ?? null;
			unset( $this->changed_out_of_band[ $id ] );
			$this->record( $product, $this->sources->detect(), $since, null, null !== $since );
			unset( $this->queue[ $id ] );
		}
	}

	/**
	 * Queue products whose price meta was written directly.
	 *
	 * @param int|int[] $meta_id   Meta id(s).
	 * @param int       $object_id Post id.
	 * @param string    $meta_key  Meta key.
	 */
	public function on_meta_changed( $meta_id, $object_id, $meta_key ): void {
		unset( $meta_id );
		if ( ! in_array( $meta_key, self::PRICE_META_KEYS, true ) ) {
			return;
		}
		$object_id = (int) $object_id;
		$post_type = get_post_type( $object_id );
		if ( 'product' === $post_type || 'product_variation' === $post_type ) {
			$this->queue[ $object_id ] = true;
		}
	}

	/**
	 * Same as on_meta_changed for deletions (deleted_post_meta passes an array of ids first).
	 *
	 * @param int[]  $meta_ids  Meta ids.
	 * @param int    $object_id Post id.
	 * @param string $meta_key  Meta key.
	 */
	public function on_meta_deleted( $meta_ids, $object_id, $meta_key ): void {
		$this->on_meta_changed( $meta_ids, $object_id, $meta_key );
	}

	/**
	 * Check queued products once per request.
	 */
	public function flush_queue(): void {
		if ( array() === $this->queue ) {
			return;
		}
		$ids         = array_slice( array_keys( $this->queue ), 0, self::MAX_QUEUE );
		$this->queue = array();
		$source      = $this->sources->detect() . ':meta';
		foreach ( $ids as $id ) {
			$product = wc_get_product( $id );
			if ( $product instanceof WC_Product ) {
				$this->record( $product, $source );
			}
		}
	}

	/**
	 * Forget the history of permanently deleted products.
	 *
	 * @param int $post_id Post id.
	 */
	public function on_post_deleted( $post_id ): void {
		$post_type = get_post_type( (int) $post_id );
		if ( 'product' === $post_type || 'product_variation' === $post_type ) {
			$this->repository->delete_product( (int) $post_id );
			unset( $this->latest[ (int) $post_id ] );
		}
	}

	/**
	 * Whether a product's own price is tracked (variable and grouped parents have no own price).
	 *
	 * @param WC_Product $product Product.
	 */
	public static function supports( WC_Product $product ): bool {
		if ( $product->is_type( array( 'variable', 'grouped' ) ) ) {
			return false;
		}
		$status = $product->get_status( 'edit' );
		return ! in_array( $status, array( 'trash', 'auto-draft' ), true ) && $product->get_id() > 0;
	}

	/**
	 * Current price state of a product as a record.
	 *
	 * @param WC_Product $product Product.
	 * @param int        $at      UTC timestamp for the record.
	 * @param string     $source  Source label.
	 */
	public static function snapshot( WC_Product $product, int $at, string $source ): PriceRecord {
		$from = $product->get_date_on_sale_from( 'edit' );
		$to   = $product->get_date_on_sale_to( 'edit' );
		return new PriceRecord(
			$at,
			Money::normalize( $product->get_regular_price( 'edit' ) ),
			Money::normalize( $product->get_sale_price( 'edit' ) ),
			$from ? $from->getTimestamp() : null,
			$to ? $to->getTimestamp() : null,
			Money::normalize( $product->get_price( 'edit' ) ),
			null,
			null,
			$source
		);
	}

	/**
	 * Record the current state when it differs from the latest stored one.
	 *
	 * @param WC_Product $product       Product or variation.
	 * @param string     $source        Source label.
	 * @param int|null   $unknown_since Mark prices since this moment as unknown (tracking gap).
	 * @param int|null   $at            Record time, defaults to now.
	 * @param bool       $force         Store even when the state did not change.
	 * @return int Inserted id, 0 when nothing was stored.
	 */
	public function record( WC_Product $product, string $source, ?int $unknown_since = null, ?int $at = null, bool $force = false ): int {
		if ( ! self::supports( $product ) ) {
			return 0;
		}
		$id = $product->get_id();

		$record = self::snapshot( $product, $at ?? $this->clock->now(), $source );
		if ( null !== $unknown_since ) {
			$record = self::with_unknown_since( $record, $unknown_since );
		}

		/**
		 * Filters the record before it is stored. Return null to skip it.
		 *
		 * Extension point for dynamic-pricing capture (Pro).
		 *
		 * @param PriceRecord|null $record  Record.
		 * @param WC_Product       $product Product.
		 * @param string           $source  Source label.
		 */
		$record = apply_filters( 'pnscripts_omnibus_capture_record', $record, $product, $source );
		if ( ! $record instanceof PriceRecord ) {
			return 0;
		}

		if ( ! array_key_exists( $id, $this->latest ) ) {
			$this->latest[ $id ] = $this->repository->latest( $id );
		}
		$latest = $this->latest[ $id ];

		$window = null === $record->unknown_since && null !== $latest ? self::resume_window() : null;
		if ( null !== $window && null !== $latest && $latest->changed_at < $window['to'] ) {
			// First record since the plugin was inactive: if the product may have changed meanwhile, the gap is unknown.
			$modified = $this->modified_before_save[ $id ] ?? null;
			if ( null === $modified ) {
				$date     = $product->get_date_modified( 'edit' );
				$modified = $date ? $date->getTimestamp() : 0;
			}
			if ( $modified >= $window['from'] || ! $latest->same_state( $record ) ) {
				$record = self::with_unknown_since( $record, $window['from'] );
				$force  = true;
			}
		}

		$currency = self::currency( $product );
		$record   = self::with_currency( $record, $currency );
		if ( null !== $latest && '' !== $latest->currency && '' !== $currency && 0 !== strcasecmp( $latest->currency, $currency ) ) {
			$force = true; // The shop currency changed: older amounts no longer apply.
		}

		if ( ! $force && null !== $latest && $latest->same_state( $record ) ) {
			return 0;
		}
		if ( null === $latest && null === $record->price && null === $record->regular ) {
			return 0; // Nothing to remember yet (product without any price).
		}

		$inserted = $this->repository->insert( $id, $product->get_parent_id(), $record, $currency );
		if ( $inserted > 0 ) {
			$this->latest[ $id ] = $record;
			/**
			 * Fires after a price change was stored.
			 *
			 * Extension point for evidence logs and exports (Pro).
			 *
			 * @param int         $inserted Row id.
			 * @param int         $id       Product or variation id.
			 * @param PriceRecord $record   Record.
			 */
			do_action( 'pnscripts_omnibus_price_recorded', $inserted, $id, $record );
		}
		return $inserted;
	}

	/**
	 * Currency the product's prices are in.
	 *
	 * @param WC_Product $product Product.
	 */
	public static function currency( WC_Product $product ): string {
		$currency = (string) get_option( 'woocommerce_currency', '' );
		/**
		 * Filters the currency stored with the record and used to read the history (single store currency in
		 * the free version).
		 *
		 * @param string     $currency ISO code.
		 * @param WC_Product $product  Product.
		 */
		return (string) apply_filters( 'pnscripts_omnibus_currency', $currency, $product );
	}

	/**
	 * Copy of a record with a currency.
	 *
	 * @param PriceRecord $record   Record.
	 * @param string      $currency ISO code.
	 */
	private static function with_currency( PriceRecord $record, string $currency ): PriceRecord {
		return new PriceRecord(
			$record->changed_at,
			$record->regular,
			$record->sale,
			$record->sale_from,
			$record->sale_to,
			$record->price,
			$record->on_sale,
			$record->unknown_since,
			$record->source,
			$record->id,
			substr( $currency, 0, 3 )
		);
	}

	/**
	 * Copy of a record with a tracking gap before it.
	 *
	 * @param PriceRecord $record        Record.
	 * @param int         $unknown_since Gap start.
	 */
	private static function with_unknown_since( PriceRecord $record, int $unknown_since ): PriceRecord {
		return new PriceRecord(
			$record->changed_at,
			$record->regular,
			$record->sale,
			$record->sale_from,
			$record->sale_to,
			$record->price,
			$record->on_sale,
			min( $unknown_since, $record->changed_at ),
			$record->source,
			$record->id,
			$record->currency
		);
	}

	/**
	 * Forget per-request state (tests, long CLI runs).
	 */
	public function reset(): void {
		$this->latest               = array();
		$this->queue                = array();
		$this->modified_before_save = array();
		$this->changed_out_of_band  = array();
	}
}
