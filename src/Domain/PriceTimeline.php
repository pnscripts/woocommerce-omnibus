<?php
/**
 * Effective price over time, derived from stored records.
 *
 * @package Pnscripts\Omnibus
 */

declare(strict_types=1);

namespace Pnscripts\Omnibus\Domain;

/**
 * Ordered, contiguous list of price segments from the first record until now (open end).
 */
final class PriceTimeline {

	/**
	 * Constructor.
	 *
	 * @param PriceSegment[] $segments Ordered, contiguous segments.
	 * @phpstan-param list<PriceSegment> $segments
	 */
	private function __construct( private readonly array $segments ) {
	}

	/**
	 * Build the timeline.
	 *
	 * Each record is in force from its changed_at until the next record. Scheduled sale dates split a record's
	 * interval. A record with unknown_since cuts the previous record short and inserts an unknown segment.
	 *
	 * @param PriceRecord[] $records Records of one product, any order.
	 * @phpstan-param list<PriceRecord> $records
	 */
	public static function from_records( array $records ): self {
		usort(
			$records,
			static fn ( PriceRecord $a, PriceRecord $b ): int => array( $a->changed_at, $a->id ) <=> array( $b->changed_at, $b->id )
		);

		$segments = array();
		$count    = count( $records );
		for ( $i = 0; $i < $count; $i++ ) {
			$record = $records[ $i ];
			$next   = $records[ $i + 1 ] ?? null;
			$start  = $record->changed_at;
			$end    = null === $next ? null : $next->changed_at;

			if ( null !== $end && $end <= $start ) {
				continue; // Superseded within the same second.
			}

			// Every record starting at $end can declare a gap; the earliest one wins (records in the same second
			// supersede each other, but their gaps must not be lost).
			$unknown_since = null;
			for ( $j = $i + 1; $j < $count && null !== $end && $records[ $j ]->changed_at === $end; $j++ ) {
				$since = $records[ $j ]->unknown_since;
				if ( null !== $since && ( null === $unknown_since || $since < $unknown_since ) ) {
					$unknown_since = $since;
				}
			}

			$known_end = $end;
			if ( null !== $unknown_since && null !== $end && $unknown_since < $end ) {
				$known_end = max( $start, $unknown_since );
			}

			if ( null === $known_end || $known_end > $start ) {
				$cursor = $start;
				foreach ( array_merge( $record->breakpoints( $start, $known_end ), array( $known_end ) ) as $point ) {
					$state      = $record->state_at( $cursor );
					$segments[] = new PriceSegment( $cursor, $point, $state['kind'], $state['price'], $state['reduced'], $state['reduced_known'] );
					if ( null === $point ) {
						break;
					}
					$cursor = $point;
				}
			}

			if ( null !== $end && null !== $known_end && $known_end < $end ) {
				$segments[] = new PriceSegment( $known_end, $end, PriceSegment::UNKNOWN, null, false );
			}
		}

		return new self( self::merge( $segments ) );
	}

	/**
	 * Merge neighbours with the same state.
	 *
	 * @param PriceSegment[] $segments Segments.
	 * @phpstan-param list<PriceSegment> $segments
	 * @return list<PriceSegment>
	 */
	private static function merge( array $segments ): array {
		$merged = array();
		foreach ( $segments as $segment ) {
			$last = array_pop( $merged );
			if ( null !== $last && $last->same_state( $segment ) && $last->end === $segment->start ) {
				$merged[] = $last->with_end( $segment->end );
				continue;
			}
			if ( null !== $last ) {
				$merged[] = $last;
			}
			$merged[] = $segment;
		}
		return $merged;
	}

	/**
	 * Segments in time order.
	 *
	 * @return list<PriceSegment>
	 */
	public function segments(): array {
		return $this->segments;
	}

	/**
	 * Start of the known history, null when empty.
	 */
	public function coverage_start(): ?int {
		return isset( $this->segments[0] ) ? $this->segments[0]->start : null;
	}

	/**
	 * Index of the segment containing a moment, null when outside the history.
	 *
	 * @param int $at UTC timestamp.
	 */
	public function index_at( int $at ): ?int {
		foreach ( $this->segments as $index => $segment ) {
			if ( $segment->contains( $at ) ) {
				return $index;
			}
		}
		return null;
	}
}
