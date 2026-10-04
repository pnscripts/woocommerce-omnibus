<?php
/**
 * Effective price timeline.
 *
 * @package Pnscripts\Omnibus\Tests
 */

declare(strict_types=1);

namespace Pnscripts\Omnibus\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Pnscripts\Omnibus\Domain\PriceRecord;
use Pnscripts\Omnibus\Domain\PriceSegment;
use Pnscripts\Omnibus\Domain\PriceTimeline;

/**
 * Segments built from records.
 */
final class PriceTimelineTest extends TestCase {

	public function test_records_become_contiguous_segments(): void {
		$timeline = PriceTimeline::from_records(
			array(
				new PriceRecord( 2000, '100', '80', null, null, '80' ),
				new PriceRecord( 1000, '100', null, null, null, '100' ),
			)
		);
		$segments = $timeline->segments();

		$this->assertCount( 2, $segments );
		$this->assertSame( array( 1000, 2000 ), array( $segments[0]->start, $segments[0]->end ) );
		$this->assertFalse( $segments[0]->reduced );
		$this->assertSame( array( 2000, null ), array( $segments[1]->start, $segments[1]->end ) );
		$this->assertTrue( $segments[1]->reduced );
		$this->assertSame( '80', $segments[1]->price );
		$this->assertSame( 1000, $timeline->coverage_start() );
	}

	public function test_schedule_splits_a_record(): void {
		$segments = PriceTimeline::from_records( array( new PriceRecord( 1000, '100', '70', 1500, 1799, '100' ) ) )->segments();

		$this->assertCount( 3, $segments );
		$this->assertSame( array( 1000, 1500, false ), array( $segments[0]->start, $segments[0]->end, $segments[0]->reduced ) );
		$this->assertSame( array( 1500, 1800, true ), array( $segments[1]->start, $segments[1]->end, $segments[1]->reduced ) );
		$this->assertSame( array( 1800, null, false ), array( $segments[2]->start, $segments[2]->end, $segments[2]->reduced ) );
	}

	public function test_schedule_outside_record_interval_is_ignored(): void {
		$segments = PriceTimeline::from_records(
			array(
				new PriceRecord( 1000, '100', '70', 5000, 6000, '100' ),
				new PriceRecord( 2000, '100', null, null, null, '100' ),
			)
		)->segments();

		$this->assertCount( 1, $segments, 'The scheduled sale was replaced before it started.' );
		$this->assertFalse( $segments[0]->reduced );
	}

	public function test_same_second_record_supersedes_previous(): void {
		$segments = PriceTimeline::from_records(
			array(
				new PriceRecord( 1000, '100', null, null, null, '100', null, null, '', 1 ),
				new PriceRecord( 1000, '100', '90', null, null, '90', null, null, '', 2 ),
			)
		)->segments();

		$this->assertCount( 1, $segments );
		$this->assertTrue( $segments[0]->reduced );
	}

	public function test_unknown_since_cuts_previous_record(): void {
		$segments = PriceTimeline::from_records(
			array(
				new PriceRecord( 1000, '100', null, null, null, '100' ),
				new PriceRecord( 3000, '100', null, null, null, '100', null, 2000 ),
			)
		)->segments();

		$this->assertCount( 3, $segments );
		$this->assertSame( PriceSegment::UNKNOWN, $segments[1]->kind );
		$this->assertSame( array( 2000, 3000 ), array( $segments[1]->start, $segments[1]->end ) );
	}

	public function test_identical_neighbours_are_merged(): void {
		$segments = PriceTimeline::from_records(
			array(
				new PriceRecord( 1000, '100', null, null, null, '100' ),
				new PriceRecord( 2000, '100.000000', null, null, null, '100' ),
				new PriceRecord( 3000, '100', null, null, null, '100' ),
			)
		)->segments();

		$this->assertCount( 1, $segments );
	}

	public function test_record_without_price_is_a_no_price_segment(): void {
		$segments = PriceTimeline::from_records( array( new PriceRecord( 1000, null, null, null, null, null ) ) )->segments();

		$this->assertSame( PriceSegment::NO_PRICE, $segments[0]->kind );
	}

	public function test_index_at(): void {
		$timeline = PriceTimeline::from_records(
			array(
				new PriceRecord( 1000, '100', null, null, null, '100' ),
				new PriceRecord( 2000, '100', '80', null, null, '80' ),
			)
		);

		$this->assertNull( $timeline->index_at( 999 ) );
		$this->assertSame( 0, $timeline->index_at( 1000 ) );
		$this->assertSame( 0, $timeline->index_at( 1999 ) );
		$this->assertSame( 1, $timeline->index_at( 2000 ) );
		$this->assertSame( 1, $timeline->index_at( PHP_INT_MAX ) );
	}

	public function test_same_state_compares_prices_numerically(): void {
		$a = new PriceRecord( 1, '10', null, null, null, '10' );
		$b = new PriceRecord( 2, '10.000000', null, null, null, '10.00', null, null, 'other' );
		$c = new PriceRecord( 3, '10', '9', null, null, '9' );
		$d = new PriceRecord( 3, '10', '9', 100, null, '9' );

		$this->assertTrue( $a->same_state( $b ) );
		$this->assertFalse( $a->same_state( $c ) );
		$this->assertFalse( $c->same_state( $d ), 'A new schedule is a new state.' );
	}

	public function test_price_only_record_has_unknown_reduction_state(): void {
		$segments = PriceTimeline::from_records(
			array(
				new PriceRecord( 1000, null, null, null, null, '100' ),
				new PriceRecord( 2000, null, null, null, null, '100', false ),
			)
		)->segments();

		$this->assertCount( 2, $segments, 'Same price, but only the second one is known not to be a reduction.' );
		$this->assertFalse( $segments[0]->reduced_known );
		$this->assertTrue( $segments[1]->reduced_known );
	}
}
