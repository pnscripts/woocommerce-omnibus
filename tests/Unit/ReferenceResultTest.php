<?php
/**
 * Result value object.
 *
 * @package Pnscripts\Omnibus\Tests
 */

declare(strict_types=1);

namespace Pnscripts\Omnibus\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Pnscripts\Omnibus\Domain\ReferenceResult;

/**
 * Array round trip used by the object cache.
 */
final class ReferenceResultTest extends TestCase {

	public function test_round_trip(): void {
		foreach ( array(
			ReferenceResult::known( '12.500000', 1000, 500, 30 ),
			ReferenceResult::known( '9.000000', 1000, 800, 4, true ),
			ReferenceResult::unknown( ReferenceResult::REASON_GAP, 1000, 45 ),
			ReferenceResult::not_on_sale(),
			ReferenceResult::exempt( ReferenceResult::REASON_PERISHABLE ),
		) as $result ) {
			$this->assertEquals( $result, ReferenceResult::from_array( $result->to_array() ) );
		}
	}

	public function test_is_known(): void {
		$this->assertTrue( ReferenceResult::known( '1', 1, 0, 30 )->is_known() );
		$this->assertFalse( ReferenceResult::unknown( ReferenceResult::REASON_GAP )->is_known() );
	}

	public function test_from_garbage_is_unknown(): void {
		$this->assertSame( ReferenceResult::UNKNOWN, ReferenceResult::from_array( array( 'status' => 5 ) )->status );
	}
}
