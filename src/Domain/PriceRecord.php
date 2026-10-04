<?php
/**
 * One stored price state of a product or variation.
 *
 * @package Pnscripts\Omnibus
 */

declare(strict_types=1);

namespace Pnscripts\Omnibus\Domain;

defined( 'ABSPATH' ) || exit;

/**
 * A price state captured at a point in time.
 *
 * A record with a regular price describes the full WooCommerce price configuration (regular, sale and
 * sale schedule), so the effective price at any later moment can be derived until the next record.
 * Imported records from other plugins usually only know the effective price ("price"), optionally with
 * an on-sale flag. Without the flag the price counts as a prior price, but whether it was a reduction is
 * unknown: a reduction that starts right after such a price has an unknown start (it may have begun earlier).
 *
 * All timestamps are Unix timestamps (UTC).
 */
final class PriceRecord {

	/**
	 * Constructor.
	 *
	 * @param int         $changed_at    When this state started (UTC timestamp).
	 * @param string|null $regular       Regular price (normalised) or null when unknown/empty.
	 * @param string|null $sale          Sale price (normalised) or null.
	 * @param int|null    $sale_from     Scheduled sale start (UTC timestamp) or null.
	 * @param int|null    $sale_to       Scheduled sale end, inclusive (UTC timestamp) or null.
	 * @param string|null $price         Effective price stored by WooCommerce at capture time.
	 * @param bool|null   $on_sale       For records without a configuration: whether the price was a reduction.
	 * @param int|null    $unknown_since Prices between this moment and $changed_at are unknown (tracking gap).
	 * @param string      $source        Where the change came from (admin, rest, import:omnibus, ...).
	 * @param int         $id            Storage id, 0 when not stored.
	 * @param string      $currency      Shop currency the prices are in (ISO code), empty when unknown.
	 */
	public function __construct(
		public readonly int $changed_at,
		public readonly ?string $regular,
		public readonly ?string $sale,
		public readonly ?int $sale_from,
		public readonly ?int $sale_to,
		public readonly ?string $price,
		public readonly ?bool $on_sale = null,
		public readonly ?int $unknown_since = null,
		public readonly string $source = '',
		public readonly int $id = 0,
		public readonly string $currency = ''
	) {
	}

	/**
	 * Whether this record carries a full WooCommerce price configuration.
	 */
	public function has_configuration(): bool {
		return null !== $this->regular;
	}

	/**
	 * Whether two records describe the same price state (ignoring time, source and gaps).
	 *
	 * @param PriceRecord $other Other record.
	 */
	public function same_state( PriceRecord $other ): bool {
		return self::same_price( $this->regular, $other->regular )
			&& self::same_price( $this->sale, $other->sale )
			&& $this->sale_from === $other->sale_from
			&& $this->sale_to === $other->sale_to
			&& self::same_price( $this->price, $other->price );
	}

	/**
	 * Moments inside (from, to) where the effective price of this record changes because of the sale schedule.
	 *
	 * @param int      $from Interval start (exclusive).
	 * @param int|null $to   Interval end (exclusive), null for open.
	 * @return list<int>
	 */
	public function breakpoints( int $from, ?int $to ): array {
		if ( ! $this->has_configuration() || null === $this->sale ) {
			return array();
		}
		$points = array();
		if ( null !== $this->sale_from ) {
			$points[] = $this->sale_from;
		}
		if ( null !== $this->sale_to ) {
			$points[] = $this->sale_to + 1;
		}
		$points = array_values(
			array_filter(
				array_unique( $points ),
				static fn ( int $t ): bool => $t > $from && ( null === $to || $t < $to )
			)
		);
		sort( $points );
		return $points;
	}

	/**
	 * Effective price state at a moment covered by this record.
	 *
	 * @param int $at UTC timestamp.
	 * @return array{kind: string, price: string|null, reduced: bool, reduced_known: bool}
	 */
	public function state_at( int $at ): array {
		if ( null !== $this->regular ) {
			$sale_active = null !== $this->sale
				&& Money::compare( $this->sale, $this->regular ) < 0
				&& ( null === $this->sale_from || $this->sale_from <= $at )
				&& ( null === $this->sale_to || $at <= $this->sale_to );
			$configured  = $sale_active && null !== $this->sale ? $this->sale : $this->regular;
			// A lower stored price (_price written directly by other software, or a sale price WooCommerce kept
			// after the schedule ended because its cron has not run yet) is the price customers were charged.
			$applied = null !== $this->price && Money::compare( $this->price, $configured ) < 0 ? $this->price : $configured;
			return array(
				'kind'          => PriceSegment::PRICED,
				'price'         => $applied,
				'reduced'       => $sale_active,
				'reduced_known' => true,
			);
		}
		if ( null !== $this->price ) {
			return array(
				'kind'          => PriceSegment::PRICED,
				'price'         => $this->price,
				'reduced'       => true === $this->on_sale,
				'reduced_known' => null !== $this->on_sale,
			);
		}
		return array(
			'kind'          => PriceSegment::NO_PRICE,
			'price'         => null,
			'reduced'       => false,
			'reduced_known' => true,
		);
	}

	/**
	 * Null-safe price equality.
	 *
	 * @param string|null $a First.
	 * @param string|null $b Second.
	 */
	private static function same_price( ?string $a, ?string $b ): bool {
		if ( null === $a || null === $b ) {
			return $a === $b;
		}
		return 0 === Money::compare( $a, $b );
	}
}
