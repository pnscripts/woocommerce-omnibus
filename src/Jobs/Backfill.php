<?php
/**
 * Background baseline, resume and repair of the price history.
 *
 * @package Pnscripts\Omnibus
 */

declare(strict_types=1);

namespace Pnscripts\Omnibus\Jobs;

use Pnscripts\Omnibus\Capture\PriceRecorder;
use Pnscripts\Omnibus\Domain\Clock;
use Pnscripts\Omnibus\Settings;
use Pnscripts\Omnibus\Storage\HistoryRepository;
use WC_Product;

defined( 'ABSPATH' ) || exit;

/**
 * Walks the catalogue in batches (by post id) in Action Scheduler; never on page load.
 *
 * Modes:
 * - baseline: products without history get their current price as the first record;
 * - resume:   after the plugin was inactive, products changed meanwhile get a record that marks the gap as unknown;
 * - repair:   products whose current price differs from the last record get a record that marks the time since the
 *             last record as unknown (a write bypassed all hooks).
 */
final class Backfill {

	public const HOOK          = 'pnscripts_omnibus_backfill';
	public const STATUS_OPTION = 'pnscripts_omnibus_backfill_status';
	public const BATCH         = 100;

	public const MODE_BASELINE = 'baseline';
	public const MODE_RESUME   = 'resume';
	public const MODE_REPAIR   = 'repair';

	/**
	 * Constructor.
	 *
	 * @param PriceRecorder     $recorder   Recorder.
	 * @param HistoryRepository $repository Storage.
	 * @param Settings          $settings   Settings.
	 * @param Clock             $clock      Time.
	 */
	public function __construct(
		private readonly PriceRecorder $recorder,
		private readonly HistoryRepository $repository,
		private readonly Settings $settings,
		private readonly Clock $clock
	) {
	}

	/**
	 * Hook into WordPress.
	 */
	public function register(): void {
		add_action( self::HOOK, array( $this, 'run_batch' ), 10, 2 );
	}

	/**
	 * Start a run in the background.
	 *
	 * @param string $mode Mode.
	 */
	public static function start( string $mode ): void {
		update_option(
			self::STATUS_OPTION,
			array(
				'mode'      => $mode,
				'started'   => time(),
				'finished'  => 0,
				'processed' => 0,
				'recorded'  => 0,
			),
			false
		);
		Queue::async( self::HOOK, array( 0, $mode ) );
	}

	/**
	 * Process one batch and queue the next.
	 *
	 * @param int|string $after_id Last processed post id.
	 * @param string     $mode     Mode.
	 */
	public function run_batch( $after_id = 0, $mode = self::MODE_BASELINE ): void {
		$result = $this->process( (int) $after_id, (string) $mode, self::BATCH );

		$status = get_option( self::STATUS_OPTION, array() );
		$status = is_array( $status ) ? $status : array();

		$status['processed'] = (int) ( $status['processed'] ?? 0 ) + $result['processed'];
		$status['recorded']  = (int) ( $status['recorded'] ?? 0 ) + $result['recorded'];
		if ( null === $result['next'] ) {
			$status['finished'] = time();
			if ( self::MODE_RESUME === $mode ) {
				delete_option( PriceRecorder::RESUME_OPTION );
			}
		}
		update_option( self::STATUS_OPTION, $status, false );

		if ( null !== $result['next'] ) {
			Queue::async( self::HOOK, array( $result['next'], (string) $mode ) );
		}
	}

	/**
	 * Process products with id greater than $after_id.
	 *
	 * @param int    $after_id Last processed id.
	 * @param string $mode     Mode.
	 * @param int    $limit    Batch size.
	 * @return array{processed: int, recorded: int, next: int|null}
	 */
	public function process( int $after_id, string $mode, int $limit ): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Keyset pagination over ids; WP_Query would load full posts.
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT ID FROM {$wpdb->posts} WHERE post_type IN ('product', 'product_variation') AND post_status NOT IN ('trash', 'auto-draft') AND ID > %d ORDER BY ID ASC LIMIT %d",
				$after_id,
				$limit
			)
		);
		$ids = array_map( 'intval', is_array( $ids ) ? $ids : array() );

		$recorded = 0;
		foreach ( $ids as $id ) {
			$product = wc_get_product( $id );
			if ( $product instanceof WC_Product && $this->process_product( $product, $mode ) ) {
				++$recorded;
			}
		}

		return array(
			'processed' => count( $ids ),
			'recorded'  => $recorded,
			'next'      => count( $ids ) === $limit ? (int) end( $ids ) : null,
		);
	}

	/**
	 * Apply the mode to one product.
	 *
	 * @param WC_Product $product Product.
	 * @param string     $mode    Mode.
	 * @return bool Whether a record was stored.
	 */
	public function process_product( WC_Product $product, string $mode ): bool {
		if ( ! PriceRecorder::supports( $product ) ) {
			return false;
		}
		$now    = $this->clock->now();
		$latest = $this->repository->latest( $product->get_id() );

		if ( null === $latest ) {
			$at = $now;
			if ( $this->settings->bool( 'trust_last_modified' ) && self::MODE_RESUME !== $mode ) {
				$modified = $product->get_date_modified( 'edit' ) ?? $product->get_date_created( 'edit' );
				if ( $modified ) {
					$at = min( $now, $modified->getTimestamp() );
				}
			}
			return $this->recorder->record( $product, 'baseline', null, $at ) > 0;
		}

		$current = PriceRecorder::snapshot( $product, $now, $mode );
		$changed = ! $latest->same_state( $current );

		if ( self::MODE_RESUME === $mode ) {
			$window = PriceRecorder::resume_window();
			if ( null === $window || $latest->changed_at >= $window['to'] ) {
				return false; // Already recorded since tracking resumed.
			}
			$modified = $product->get_date_modified( 'edit' );
			if ( ( $modified && $modified->getTimestamp() >= $window['from'] ) || $changed ) {
				return $this->recorder->record( $product, 'resume', $window['from'], $now, true ) > 0;
			}
			return false;
		}

		if ( $changed ) {
			return $this->recorder->record( $product, 'repair', $latest->changed_at, $now ) > 0;
		}
		return false;
	}
}
