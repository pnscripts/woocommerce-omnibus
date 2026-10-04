<?php
/**
 * Prior price rules (Price Indication Directive 98/6/EC Art. 6a, as amended by Directive (EU) 2019/2161).
 *
 * @package Pnscripts\Omnibus
 */

declare(strict_types=1);

namespace Pnscripts\Omnibus\Domain;

use DateTimeImmutable;
use DateTimeZone;

defined( 'ABSPATH' ) || exit;

/**
 * Computes the lowest price applied during the period before the current reduction.
 *
 * Rules:
 * - The anchor is the start of the current, uninterrupted run of reduced prices. A progressive reduction
 *   (price lowered again while still reduced) keeps the anchor of the first reduction.
 * - The period ends at the anchor and starts at midnight (shop time zone) N calendar days before the anchor day,
 *   so it is never shorter than N days and daylight saving changes do not shorten it.
 * - Every price in force at any moment of the period counts, including earlier promotions.
 * - Anything the history cannot prove (gaps, history starting after the period start, unknown reduction start,
 *   a reduction right after an imported price that may itself have been a reduction) yields UNKNOWN, never a guess.
 */
final class ReferencePriceCalculator {

	/**
	 * Constructor.
	 *
	 * @param DateTimeZone $timezone Shop time zone (calendar days are counted in it).
	 */
	public function __construct( private readonly DateTimeZone $timezone ) {
	}

	/**
	 * Compute the reference price.
	 *
	 * @param PriceTimeline   $timeline Effective price timeline.
	 * @param int             $now      Current UTC timestamp.
	 * @param ReferencePolicy $policy   Rules.
	 * @param int|null        $launched When the product was first offered (UTC), null when unknown.
	 */
	public function calculate( PriceTimeline $timeline, int $now, ReferencePolicy $policy, ?int $launched = null ): ReferenceResult {
		$days     = $policy->period_days;
		$segments = $timeline->segments();
		$index    = $timeline->index_at( $now );
		$current  = null === $index ? null : ( $segments[ $index ] ?? null );

		if ( null === $index || null === $current ) {
			return ReferenceResult::unknown( ReferenceResult::REASON_NO_HISTORY, null, $days );
		}
		if ( PriceSegment::UNKNOWN === $current->kind ) {
			return ReferenceResult::unknown( ReferenceResult::REASON_GAP, null, $days );
		}
		if ( ! $this->is_reduced( $current ) ) {
			return ReferenceResult::not_on_sale();
		}

		list( $first, $before ) = $this->reduction_run( $segments, $index, $current );
		$anchor                 = $first->start;

		if ( null === $before ) {
			if ( null !== $launched && $anchor <= $launched + $policy->launch_tolerance ) {
				return ReferenceResult::unknown( ReferenceResult::REASON_NO_PRIOR_PRICE, $anchor, $days );
			}
			return ReferenceResult::unknown( ReferenceResult::REASON_START_UNKNOWN, null, $days );
		}
		if ( ! $this->start_is_known( $before ) ) {
			return ReferenceResult::unknown( ReferenceResult::REASON_START_UNKNOWN, null, $days );
		}

		$window_start = $this->window_start( $anchor, $days );
		$search_from  = $window_start;
		$shortened    = false;

		if ( null !== $launched && $launched > $window_start ) {
			if ( ReferencePolicy::NEW_PRODUCT_SINCE_LAUNCH !== $policy->new_product_mode ) {
				return ReferenceResult::unknown( ReferenceResult::REASON_NEW_PRODUCT, $anchor, $days );
			}
			$search_from = $launched;
			$shortened   = true;
		}

		$coverage = (int) $timeline->coverage_start();
		$required = $shortened ? $search_from + $policy->launch_tolerance : $search_from;
		if ( $coverage > $required ) {
			return ReferenceResult::unknown( ReferenceResult::REASON_INCOMPLETE, $anchor, $days );
		}

		$lowest = null;
		foreach ( $segments as $segment ) {
			if ( ! $segment->overlaps( $search_from, $anchor ) ) {
				continue;
			}
			if ( PriceSegment::UNKNOWN === $segment->kind ) {
				return ReferenceResult::unknown( ReferenceResult::REASON_GAP, $anchor, $days );
			}
			if ( PriceSegment::PRICED === $segment->kind && null !== $segment->price ) {
				$lowest = Money::min( $lowest, $segment->price );
			}
		}

		if ( null === $lowest ) {
			return ReferenceResult::unknown( ReferenceResult::REASON_NO_PRIOR_PRICE, $anchor, $days );
		}

		$period_days = $shortened ? max( 1, (int) round( ( $anchor - $search_from ) / 86400 ) ) : $days;

		return ReferenceResult::known( $lowest, $anchor, $search_from, $period_days, $shortened );
	}

	/**
	 * Start of the reduction currently in force, or null when the product is not reduced or the start is unknown.
	 *
	 * @param PriceTimeline $timeline Timeline.
	 * @param int           $now      Current UTC timestamp.
	 */
	public function current_anchor( PriceTimeline $timeline, int $now ): ?int {
		$segments = $timeline->segments();
		$index    = $timeline->index_at( $now );
		$current  = null === $index ? null : ( $segments[ $index ] ?? null );
		if ( null === $index || null === $current || ! $this->is_reduced( $current ) ) {
			return null;
		}
		list( $first, $before ) = $this->reduction_run( $segments, $index, $current );
		return null === $before || ! $this->start_is_known( $before ) ? null : $first->start;
	}

	/**
	 * First segment of the uninterrupted reduction containing $index, and the segment right before it.
	 *
	 * @param PriceSegment[] $segments Segments.
	 * @param int            $index    Index of the current (reduced) segment.
	 * @param PriceSegment   $current  Current segment.
	 * @return array{0: PriceSegment, 1: PriceSegment|null}
	 */
	private function reduction_run( array $segments, int $index, PriceSegment $current ): array {
		$first  = $current;
		$before = null;
		for ( $i = $index - 1; $i >= 0; $i-- ) {
			$segment = $segments[ $i ] ?? null;
			if ( null === $segment ) {
				break;
			}
			if ( ! $this->is_reduced( $segment ) ) {
				$before = $segment;
				break;
			}
			$first = $segment;
		}
		return array( $first, $before );
	}

	/**
	 * Whether the segment right before a reduction proves where the reduction started: its prices must be known
	 * and it must be known not to be a reduction itself (an unflagged imported price may have been the reduction).
	 *
	 * @param PriceSegment $before Segment before the reduction run.
	 */
	private function start_is_known( PriceSegment $before ): bool {
		return PriceSegment::UNKNOWN !== $before->kind && $before->reduced_known;
	}

	/**
	 * Midnight (shop time zone) N calendar days before the anchor day.
	 *
	 * @param int $anchor UTC timestamp.
	 * @param int $days   Days.
	 */
	public function window_start( int $anchor, int $days ): int {
		$local = ( new DateTimeImmutable( '@' . $anchor ) )->setTimezone( $this->timezone )->setTime( 0, 0 );
		return $local->modify( sprintf( '-%d days', $days ) )->getTimestamp();
	}

	/**
	 * Whether a segment is a priced reduction.
	 *
	 * @param PriceSegment $segment Segment.
	 */
	private function is_reduced( PriceSegment $segment ): bool {
		return PriceSegment::PRICED === $segment->kind && $segment->reduced;
	}
}
