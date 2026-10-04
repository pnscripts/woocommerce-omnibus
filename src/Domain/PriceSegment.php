<?php
/**
 * A time interval with a constant effective price.
 *
 * @package Pnscripts\Omnibus
 */

declare(strict_types=1);

namespace Pnscripts\Omnibus\Domain;

/**
 * Half-open interval [start, end) of the effective price timeline.
 */
final class PriceSegment {

	public const PRICED   = 'priced';
	public const NO_PRICE = 'no_price';
	public const UNKNOWN  = 'unknown';

	/**
	 * Constructor.
	 *
	 * @param int         $start   Start (UTC timestamp, inclusive).
	 * @param int|null    $end     End (UTC timestamp, exclusive) or null when still current.
	 * @param string      $kind    One of the class constants.
	 * @param string|null $price   Effective price when priced.
	 * @param bool        $reduced Whether the price is an announced reduction (sale).
	 */
	public function __construct(
		public readonly int $start,
		public readonly ?int $end,
		public readonly string $kind,
		public readonly ?string $price,
		public readonly bool $reduced
	) {
	}

	/**
	 * Whether the segment contains a moment.
	 *
	 * @param int $at UTC timestamp.
	 */
	public function contains( int $at ): bool {
		return $at >= $this->start && ( null === $this->end || $at < $this->end );
	}

	/**
	 * Whether the segment overlaps [from, to).
	 *
	 * @param int $from Start (inclusive).
	 * @param int $to   End (exclusive).
	 */
	public function overlaps( int $from, int $to ): bool {
		return $this->start < $to && ( null === $this->end || $this->end > $from );
	}

	/**
	 * Same with a new end.
	 *
	 * @param int|null $end New end.
	 */
	public function with_end( ?int $end ): self {
		return new self( $this->start, $end, $this->kind, $this->price, $this->reduced );
	}

	/**
	 * Whether two segments carry the same price state.
	 *
	 * @param PriceSegment $other Other segment.
	 */
	public function same_state( PriceSegment $other ): bool {
		if ( $this->kind !== $other->kind || $this->reduced !== $other->reduced ) {
			return false;
		}
		if ( null === $this->price || null === $other->price ) {
			return $this->price === $other->price;
		}
		return 0 === Money::compare( $this->price, $other->price );
	}
}
