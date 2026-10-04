<?php
/**
 * Reference price rules.
 *
 * @package Pnscripts\Omnibus\Tests
 */

declare(strict_types=1);

namespace Pnscripts\Omnibus\Tests\Unit;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Pnscripts\Omnibus\Domain\Money;
use Pnscripts\Omnibus\Domain\PriceRecord;
use Pnscripts\Omnibus\Domain\PriceTimeline;
use Pnscripts\Omnibus\Domain\ReferencePolicy;
use Pnscripts\Omnibus\Domain\ReferencePriceCalculator;
use Pnscripts\Omnibus\Domain\ReferenceResult;

/**
 * Covers the anchor, period, progressive, scheduled, new-product, coverage and time zone rules.
 */
final class ReferencePriceCalculatorTest extends TestCase {

	private const DAY = 86400;

	/**
	 * 2026-06-15 12:00:00 UTC (15:00 in Sofia, summer time).
	 *
	 * @var int
	 */
	private int $now;

	private ReferencePriceCalculator $calculator;

	protected function setUp(): void {
		parent::setUp();
		$this->now        = ( new DateTimeImmutable( '2026-06-15 12:00:00', new DateTimeZone( 'UTC' ) ) )->getTimestamp();
		$this->calculator = new ReferencePriceCalculator( new DateTimeZone( 'Europe/Sofia' ) );
	}

	/**
	 * Configuration record N days before now, with the stored price WooCommerce would write at that moment
	 * (the sale price only while the schedule is active).
	 */
	private function rec( float $days_ago, ?string $regular, ?string $sale = null, ?int $from = null, ?int $to = null, ?int $unknown_since = null ): PriceRecord {
		$at        = $this->ago( $days_ago );
		$active    = null !== $sale && null !== $regular && (float) $sale < (float) $regular
			&& ( null === $from || $from <= $at ) && ( null === $to || $at <= $to );
		$effective = $active ? $sale : $regular;
		return new PriceRecord( $at, $regular, $sale, $from, $to, $effective, null, $unknown_since );
	}

	private function assertPrice( string $expected, ?string $actual, string $message = '' ): void {
		$this->assertNotNull( $actual, $message );
		$this->assertSame( 0, Money::compare( $expected, (string) $actual ), $message . " (got {$actual})" );
	}

	private function ago( float $days ): int {
		return (int) ( $this->now - $days * self::DAY );
	}

	/**
	 * @param PriceRecord[] $records Records.
	 */
	private function calc( array $records, ?ReferencePolicy $policy = null, ?int $launched = null, ?int $at = null ): ReferenceResult {
		return $this->calculator->calculate(
			PriceTimeline::from_records( $records ),
			$at ?? $this->now,
			$policy ?? new ReferencePolicy(),
			$launched
		);
	}

	public function test_simple_reduction_uses_price_before_discount(): void {
		$result = $this->calc( array( $this->rec( 60, '100' ), $this->rec( 10, '100', '80' ) ) );

		$this->assertSame( ReferenceResult::KNOWN, $result->status );
		$this->assertPrice( '100', $result->price );
		$this->assertSame( $this->ago( 10 ), $result->anchor );
		$this->assertSame( 30, $result->period_days );
		$this->assertFalse( $result->shortened );
	}

	public function test_earlier_promotion_inside_period_is_the_lowest_price(): void {
		$result = $this->calc(
			array(
				$this->rec( 60, '100' ),
				$this->rec( 25, '100', '90' ),
				$this->rec( 20, '100' ),
				$this->rec( 5, '100', '80' ),
			)
		);

		$this->assertPrice( '90', $result->price );
		$this->assertSame( $this->ago( 5 ), $result->anchor );
	}

	public function test_earlier_promotion_before_period_is_ignored(): void {
		$result = $this->calc(
			array(
				$this->rec( 80, '100' ),
				$this->rec( 50, '100', '70' ),
				$this->rec( 40, '100' ),
				$this->rec( 5, '100', '80' ),
			)
		);

		$this->assertPrice( '100', $result->price );
	}

	public function test_lower_regular_price_inside_period_counts(): void {
		$result = $this->calc(
			array(
				$this->rec( 60, '95' ),
				$this->rec( 15, '120' ),
				$this->rec( 3, '120', '99' ),
			)
		);

		$this->assertPrice( '95', $result->price, 'Raising the regular price shortly before a sale must not hide the earlier lower price.' );
	}

	public function test_progressive_reduction_keeps_anchor_of_first_reduction(): void {
		$result = $this->calc(
			array(
				$this->rec( 60, '100' ),
				$this->rec( 20, '100', '90' ),
				$this->rec( 10, '100', '80' ),
				$this->rec( 2, '100', '70' ),
			)
		);

		$this->assertSame( ReferenceResult::KNOWN, $result->status );
		$this->assertSame( $this->ago( 20 ), $result->anchor );
		$this->assertPrice( '100', $result->price, 'The 90 and 80 steps belong to the same reduction and are not prior prices.' );
	}

	public function test_progressive_reduction_with_intermediate_increase_is_still_one_run(): void {
		$result = $this->calc(
			array(
				$this->rec( 60, '100' ),
				$this->rec( 20, '100', '80' ),
				$this->rec( 10, '100', '90' ),
			)
		);

		$this->assertSame( $this->ago( 20 ), $result->anchor );
		$this->assertPrice( '100', $result->price );
	}

	public function test_progressive_run_longer_than_period_still_looks_before_first_reduction(): void {
		$result = $this->calc(
			array(
				$this->rec( 120, '100' ),
				$this->rec( 50, '100', '90' ),
				$this->rec( 20, '100', '75' ),
			)
		);

		$this->assertSame( $this->ago( 50 ), $result->anchor );
		$this->assertPrice( '100', $result->price );
	}

	public function test_sale_ended_and_restarted_starts_a_new_reduction(): void {
		$result = $this->calc(
			array(
				$this->rec( 60, '100' ),
				$this->rec( 20, '100', '85' ),
				$this->rec( 12, '100' ),
				$this->rec( 4, '100', '90' ),
			)
		);

		$this->assertSame( $this->ago( 4 ), $result->anchor );
		$this->assertPrice( '85', $result->price );
	}

	public function test_regular_price_change_during_sale_keeps_the_run(): void {
		$result = $this->calc(
			array(
				$this->rec( 60, '100' ),
				$this->rec( 10, '100', '80' ),
				$this->rec( 5, '110', '80' ),
			)
		);

		$this->assertSame( $this->ago( 10 ), $result->anchor );
		$this->assertPrice( '100', $result->price );
	}

	public function test_scheduled_sale_in_one_record_starts_at_scheduled_date(): void {
		$from   = $this->ago( 5 );
		$to     = $this->ago( -5 );
		$result = $this->calc( array( $this->rec( 60, '100', '70', $from, $to ) ) );

		$this->assertSame( ReferenceResult::KNOWN, $result->status );
		$this->assertSame( $from, $result->anchor );
		$this->assertPrice( '100', $result->price );
	}

	public function test_future_scheduled_sale_is_not_on_sale_yet(): void {
		$result = $this->calc( array( $this->rec( 60, '100', '70', $this->ago( -2 ), $this->ago( -9 ) ) ) );

		$this->assertSame( ReferenceResult::NOT_ON_SALE, $result->status );
	}

	public function test_scheduled_sale_end_is_inclusive_to_the_second(): void {
		$from    = $this->ago( 5 );
		$to      = $this->ago( 1 );
		$records = array( $this->rec( 60, '100', '70', $from, $to ) );

		$this->assertSame( ReferenceResult::KNOWN, $this->calc( $records, null, null, $to )->status );
		$this->assertSame( ReferenceResult::NOT_ON_SALE, $this->calc( $records, null, null, $to + 1 )->status );
	}

	public function test_past_scheduled_sale_inside_period_counts_after_cron_cleared_it(): void {
		// Scheduled 80 sale between -25 and -20 days; WooCommerce later stored the regular price again.
		$result = $this->calc(
			array(
				$this->rec( 60, '100', '80', $this->ago( 25 ), $this->ago( 20 ) ),
				$this->rec( 19.5, '100' ),
				$this->rec( 3, '100', '90' ),
			)
		);

		$this->assertPrice( '80', $result->price );
		$this->assertSame( $this->ago( 3 ), $result->anchor );
	}

	public function test_scheduled_reductions_back_to_back_are_progressive(): void {
		$result = $this->calc(
			array(
				$this->rec( 60, '100', '90', $this->ago( 10 ), $this->ago( 6 ) - 1 ),
				$this->rec( 6, '100', '80' ),
			)
		);

		$this->assertSame( $this->ago( 10 ), $result->anchor );
		$this->assertPrice( '100', $result->price );
	}

	public function test_window_starts_at_local_midnight_thirty_days_before_anchor_day(): void {
		$anchor = ( new DateTimeImmutable( '2026-06-10 15:30:00', new DateTimeZone( 'Europe/Sofia' ) ) )->getTimestamp();
		$expect = ( new DateTimeImmutable( '2026-05-11 00:00:00', new DateTimeZone( 'Europe/Sofia' ) ) )->getTimestamp();

		$this->assertSame( $expect, $this->calculator->window_start( $anchor, 30 ) );
	}

	public function test_window_uses_shop_calendar_day_not_utc_day(): void {
		// 2026-06-10 00:30 in Sofia is still 2026-06-09 in UTC.
		$anchor = ( new DateTimeImmutable( '2026-06-10 00:30:00', new DateTimeZone( 'Europe/Sofia' ) ) )->getTimestamp();
		$expect = ( new DateTimeImmutable( '2026-05-11 00:00:00', new DateTimeZone( 'Europe/Sofia' ) ) )->getTimestamp();

		$this->assertSame( $expect, $this->calculator->window_start( $anchor, 30 ) );
	}

	public function test_window_across_daylight_saving_change_is_not_shortened(): void {
		// Summer time starts 2026-03-29 in Sofia; the period still spans 30 full calendar days.
		$anchor = ( new DateTimeImmutable( '2026-04-10 09:00:00', new DateTimeZone( 'Europe/Sofia' ) ) )->getTimestamp();
		$start  = $this->calculator->window_start( $anchor, 30 );

		$this->assertSame( '2026-03-11 00:00:00 EET', ( new DateTimeImmutable( '@' . $start ) )->setTimezone( new DateTimeZone( 'Europe/Sofia' ) )->format( 'Y-m-d H:i:s T' ) );
		$this->assertGreaterThanOrEqual( 30 * self::DAY, $anchor - $start );
	}

	public function test_price_ending_exactly_at_window_start_is_excluded(): void {
		$anchor = $this->ago( 5 );
		$start  = $this->calculator->window_start( $anchor, 30 );
		$result = $this->calc(
			array(
				new PriceRecord( $start - 10 * self::DAY, '50', null, null, null, '50' ),
				new PriceRecord( $start, '100', null, null, null, '100' ),
				new PriceRecord( $anchor, '100', '90', null, null, '90' ),
			)
		);

		$this->assertPrice( '100', $result->price );
	}

	public function test_price_ending_one_second_after_window_start_is_included(): void {
		$anchor = $this->ago( 5 );
		$start  = $this->calculator->window_start( $anchor, 30 );
		$result = $this->calc(
			array(
				new PriceRecord( $start - 10 * self::DAY, '50', null, null, null, '50' ),
				new PriceRecord( $start + 1, '100', null, null, null, '100' ),
				new PriceRecord( $anchor, '100', '90', null, null, '90' ),
			)
		);

		$this->assertPrice( '50', $result->price );
	}

	public function test_history_starting_exactly_at_window_start_is_complete(): void {
		$anchor = $this->ago( 5 );
		$start  = $this->calculator->window_start( $anchor, 30 );
		$result = $this->calc(
			array(
				new PriceRecord( $start, '100', null, null, null, '100' ),
				new PriceRecord( $anchor, '100', '90', null, null, '90' ),
			)
		);

		$this->assertSame( ReferenceResult::KNOWN, $result->status );
	}

	public function test_history_starting_one_second_after_window_start_is_incomplete(): void {
		$anchor = $this->ago( 5 );
		$start  = $this->calculator->window_start( $anchor, 30 );
		$result = $this->calc(
			array(
				new PriceRecord( $start + 1, '100', null, null, null, '100' ),
				new PriceRecord( $anchor, '100', '90', null, null, '90' ),
			)
		);

		$this->assertSame( ReferenceResult::UNKNOWN, $result->status );
		$this->assertSame( ReferenceResult::REASON_INCOMPLETE, $result->reason );
		$this->assertNull( $result->price );
	}

	public function test_short_history_is_unknown(): void {
		$result = $this->calc( array( $this->rec( 20, '100' ), $this->rec( 5, '100', '80' ) ), null, $this->ago( 400 ) );

		$this->assertSame( ReferenceResult::REASON_INCOMPLETE, $result->reason );
	}

	public function test_already_on_sale_when_recording_started_is_unknown(): void {
		$result = $this->calc( array( $this->rec( 60, '100', '80' ) ), null, $this->ago( 400 ) );

		$this->assertSame( ReferenceResult::UNKNOWN, $result->status );
		$this->assertSame( ReferenceResult::REASON_START_UNKNOWN, $result->reason );
	}

	public function test_gap_inside_period_is_unknown(): void {
		$result = $this->calc(
			array(
				$this->rec( 60, '100' ),
				$this->rec( 15, '100', null, null, null, $this->ago( 18 ) ),
				$this->rec( 5, '100', '80' ),
			)
		);

		$this->assertSame( ReferenceResult::REASON_GAP, $result->reason );
	}

	public function test_gap_before_period_does_not_matter(): void {
		$result = $this->calc(
			array(
				$this->rec( 90, '100' ),
				$this->rec( 60, '100', null, null, null, $this->ago( 70 ) ),
				$this->rec( 5, '100', '80' ),
			)
		);

		$this->assertSame( ReferenceResult::KNOWN, $result->status );
		$this->assertPrice( '100', $result->price );
	}

	public function test_gap_right_before_reduction_makes_its_start_unknown(): void {
		$result = $this->calc(
			array(
				$this->rec( 60, '100' ),
				$this->rec( 5, '100', '80', null, null, $this->ago( 8 ) ),
			)
		);

		$this->assertSame( ReferenceResult::REASON_START_UNKNOWN, $result->reason );
	}

	public function test_new_product_is_hidden_by_default(): void {
		$result = $this->calc( array( $this->rec( 10, '100' ), $this->rec( 3, '100', '80' ) ), null, $this->ago( 10 ) );

		$this->assertSame( ReferenceResult::UNKNOWN, $result->status );
		$this->assertSame( ReferenceResult::REASON_NEW_PRODUCT, $result->reason );
	}

	public function test_new_product_since_launch_uses_shorter_period(): void {
		$policy = new ReferencePolicy( 30, ReferencePolicy::NEW_PRODUCT_SINCE_LAUNCH );
		$result = $this->calc(
			array( $this->rec( 10, '100' ), $this->rec( 8, '95' ), $this->rec( 6, '100' ), $this->rec( 3, '100', '80' ) ),
			$policy,
			$this->ago( 10 )
		);

		$this->assertSame( ReferenceResult::KNOWN, $result->status );
		$this->assertTrue( $result->shortened );
		$this->assertPrice( '95', $result->price );
		$this->assertSame( 7, $result->period_days );
		$this->assertSame( $this->ago( 10 ), $result->window_start );
	}

	public function test_new_product_since_launch_requires_history_from_launch(): void {
		$policy = new ReferencePolicy( 30, ReferencePolicy::NEW_PRODUCT_SINCE_LAUNCH );
		$result = $this->calc( array( $this->rec( 8, '100' ), $this->rec( 3, '100', '80' ) ), $policy, $this->ago( 10 ) );

		$this->assertSame( ReferenceResult::REASON_INCOMPLETE, $result->reason );
	}

	public function test_new_product_first_record_within_launch_tolerance_is_complete(): void {
		$policy = new ReferencePolicy( 30, ReferencePolicy::NEW_PRODUCT_SINCE_LAUNCH );
		$launch = $this->ago( 10 );
		$result = $this->calc(
			array( new PriceRecord( $launch + 30, '100', null, null, null, '100' ), $this->rec( 3, '100', '80' ) ),
			$policy,
			$launch
		);

		$this->assertSame( ReferenceResult::KNOWN, $result->status );
	}

	public function test_product_launched_on_sale_has_no_prior_price(): void {
		$policy = new ReferencePolicy( 30, ReferencePolicy::NEW_PRODUCT_SINCE_LAUNCH );
		$result = $this->calc( array( $this->rec( 10, '100', '80' ) ), $policy, $this->ago( 10 ) );

		$this->assertSame( ReferenceResult::REASON_NO_PRIOR_PRICE, $result->reason );
	}

	public function test_product_launched_long_ago_uses_full_period(): void {
		$policy = new ReferencePolicy( 30, ReferencePolicy::NEW_PRODUCT_SINCE_LAUNCH );
		$result = $this->calc( array( $this->rec( 60, '100' ), $this->rec( 3, '100', '80' ) ), $policy, $this->ago( 365 ) );

		$this->assertFalse( $result->shortened );
		$this->assertSame( 30, $result->period_days );
	}

	public function test_product_launched_exactly_at_window_start_is_not_new(): void {
		$anchor = $this->ago( 3 );
		$start  = $this->calculator->window_start( $anchor, 30 );
		$result = $this->calc(
			array( new PriceRecord( $start, '100', null, null, null, '100' ), new PriceRecord( $anchor, '100', '80', null, null, '80' ) ),
			null,
			$start
		);

		$this->assertSame( ReferenceResult::KNOWN, $result->status );
	}

	public function test_no_history_is_unknown(): void {
		$result = $this->calc( array() );

		$this->assertSame( ReferenceResult::REASON_NO_HISTORY, $result->reason );
	}

	public function test_now_before_first_record_is_unknown(): void {
		$result = $this->calc( array( $this->rec( 1, '100' ) ), null, null, $this->ago( 2 ) );

		$this->assertSame( ReferenceResult::REASON_NO_HISTORY, $result->reason );
	}

	public function test_not_reduced_is_not_on_sale(): void {
		$this->assertSame( ReferenceResult::NOT_ON_SALE, $this->calc( array( $this->rec( 60, '100' ) ) )->status );
	}

	public function test_sale_price_not_below_regular_is_not_a_reduction(): void {
		$this->assertSame( ReferenceResult::NOT_ON_SALE, $this->calc( array( $this->rec( 60, '100', '100' ) ) )->status );
		$this->assertSame( ReferenceResult::NOT_ON_SALE, $this->calc( array( $this->rec( 60, '100', '120' ) ) )->status );
	}

	public function test_periods_without_price_are_ignored(): void {
		$result = $this->calc(
			array(
				new PriceRecord( $this->ago( 60 ), null, null, null, null, null ),
				$this->rec( 20, '100' ),
				$this->rec( 5, '100', '80' ),
			)
		);

		$this->assertPrice( '100', $result->price );
	}

	public function test_custom_longer_period(): void {
		$result = $this->calc(
			array( $this->rec( 90, '100' ), $this->rec( 40, '90' ), $this->rec( 35, '100' ), $this->rec( 5, '100', '80' ) ),
			new ReferencePolicy( 45 )
		);

		$this->assertPrice( '90', $result->price );
		$this->assertSame( 45, $result->period_days );
	}

	public function test_policy_never_goes_below_thirty_days(): void {
		$this->assertSame( 30, ( new ReferencePolicy( 7 ) )->period_days );
		$this->assertSame( 365, ( new ReferencePolicy( 9999 ) )->period_days );
	}

	public function test_imported_effective_prices_with_on_sale_flags(): void {
		$records = array(
			new PriceRecord( $this->ago( 60 ), null, null, null, null, '100', false ),
			new PriceRecord( $this->ago( 20 ), null, null, null, null, '85', true ),
			new PriceRecord( $this->ago( 15 ), null, null, null, null, '100', false ),
			$this->rec( 4, '100', '90' ),
		);
		$result  = $this->calc( $records );

		$this->assertSame( $this->ago( 4 ), $result->anchor );
		$this->assertPrice( '85', $result->price );
	}

	public function test_imported_effective_prices_without_flag_count_as_prior_prices(): void {
		$records = array(
			new PriceRecord( $this->ago( 60 ), null, null, null, null, '100' ),
			new PriceRecord( $this->ago( 20 ), null, null, null, null, '97' ),
			$this->rec( 10, '100' ),
			$this->rec( 3, '100', '80' ),
		);
		$result  = $this->calc( $records );

		$this->assertSame( $this->ago( 3 ), $result->anchor );
		$this->assertPrice( '97', $result->price, 'Unflagged imported prices count as prior prices inside the period.' );
	}


	public function test_reduction_starting_right_after_unflagged_import_is_unknown(): void {
		$records = array(
			new PriceRecord( $this->ago( 60 ), null, null, null, null, '100' ),
			new PriceRecord( $this->ago( 20 ), null, null, null, null, '97' ),
			$this->rec( 3, '100', '80' ),
		);

		$this->assertSame( ReferenceResult::REASON_START_UNKNOWN, $this->calc( $records )->reason, 'The unflagged 97 may already have been the reduction.' );
	}

	public function test_imported_lower_price_without_flag_is_not_on_sale(): void {
		$records = array(
			new PriceRecord( $this->ago( 60 ), null, null, null, null, '100' ),
			new PriceRecord( $this->ago( 10 ), null, null, null, null, '80' ),
		);

		$this->assertSame( ReferenceResult::NOT_ON_SALE, $this->calc( $records )->status );
	}

	public function test_imported_price_only_segment_before_reduction_makes_its_start_unknown(): void {
		// Imported effective prices carry no sale flag: 80 from day 20 may already have been the reduction
		// (then the period would be days 50..20 with 60 in it). Showing 80 would be too high.
		$records = array(
			new PriceRecord( $this->ago( 60 ), null, null, null, null, '60' ),
			new PriceRecord( $this->ago( 45 ), null, null, null, null, '100' ),
			new PriceRecord( $this->ago( 20 ), null, null, null, null, '80' ),
			$this->rec( 10, '100', '70' ),
		);
		$result  = $this->calc( $records );

		$this->assertSame( ReferenceResult::UNKNOWN, $result->status );
		$this->assertSame( ReferenceResult::REASON_START_UNKNOWN, $result->reason );
		$this->assertNull( $this->calculator->current_anchor( PriceTimeline::from_records( $records ), $this->now ) );
	}


	public function test_imported_price_only_segments_inside_period_still_count(): void {
		$records = array(
			new PriceRecord( $this->ago( 60 ), null, null, null, null, '100' ),
			new PriceRecord( $this->ago( 25 ), null, null, null, null, '90' ),
			$this->rec( 15, '100' ),
			$this->rec( 5, '100', '80' ),
		);
		$result  = $this->calc( $records );

		$this->assertSame( $this->ago( 5 ), $result->anchor );
		$this->assertPrice( '90', $result->price );
	}


	public function test_effective_price_below_configuration_is_the_applied_price(): void {
		// Another plugin wrote _price = 60 directly for ten days while the regular price stayed 100.
		$records = array(
			$this->rec( 60, '100' ),
			new PriceRecord( $this->ago( 25 ), '100', null, null, null, '60' ),
			$this->rec( 15, '100' ),
			$this->rec( 5, '100', '80' ),
		);
		$result  = $this->calc( $records );

		$this->assertSame( $this->ago( 5 ), $result->anchor );
		$this->assertPrice( '60', $result->price );
	}


	public function test_effective_price_below_active_sale_keeps_the_reduction_run(): void {
		$records = array(
			$this->rec( 80, '50' ),
			$this->rec( 50, '100' ),
			$this->rec( 20, '100', '80' ),
			new PriceRecord( $this->ago( 15 ), '100', '80', null, null, '70' ),
			$this->rec( 12, '100', '80' ),
		);
		$result  = $this->calc( $records );

		$this->assertSame( $this->ago( 20 ), $result->anchor, 'A lower effective price during a sale is still part of the same reduction.' );
		$this->assertPrice( '50', $result->price );
	}


	public function test_current_anchor_helper(): void {
		$timeline = PriceTimeline::from_records( array( $this->rec( 60, '100' ), $this->rec( 20, '100', '90' ), $this->rec( 10, '100', '80' ) ) );

		$this->assertSame( $this->ago( 20 ), $this->calculator->current_anchor( $timeline, $this->now ) );
		$this->assertNull( $this->calculator->current_anchor( PriceTimeline::from_records( array( $this->rec( 60, '100' ) ) ), $this->now ) );
		$this->assertNull( $this->calculator->current_anchor( PriceTimeline::from_records( array( $this->rec( 60, '100', '80' ) ) ), $this->now ) );
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public static function time_zones(): array {
		return array(
			'UTC'         => array( 'UTC' ),
			'Sofia'       => array( 'Europe/Sofia' ),
			'Warsaw'      => array( 'Europe/Warsaw' ),
			'Lisbon'      => array( 'Europe/Lisbon' ),
			'Kiritimati'  => array( 'Pacific/Kiritimati' ),
			'Los Angeles' => array( 'America/Los_Angeles' ),
		);
	}

	#[DataProvider( 'time_zones' )]
	public function test_period_is_at_least_thirty_full_days_in_any_time_zone( string $zone ): void {
		$calculator = new ReferencePriceCalculator( new DateTimeZone( $zone ) );
		foreach ( array( '2026-03-29 01:30:00', '2026-10-25 03:30:00', '2026-01-01 00:00:00', '2026-06-15 23:59:59' ) as $moment ) {
			$anchor = ( new DateTimeImmutable( $moment, new DateTimeZone( $zone ) ) )->getTimestamp();
			$start  = $calculator->window_start( $anchor, 30 );
			$local  = ( new DateTimeImmutable( '@' . $start ) )->setTimezone( new DateTimeZone( $zone ) );

			$this->assertSame( '00:00', $local->format( 'H:i' ), $zone . ' ' . $moment );
			$this->assertGreaterThanOrEqual( 30 * self::DAY - 3600, $anchor - $start, $zone . ' ' . $moment );
			$this->assertLessThan( 31 * self::DAY + 3600, $anchor - $start, $zone . ' ' . $moment );
		}
	}
}
