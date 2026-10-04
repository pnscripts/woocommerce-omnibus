<?php
/**
 * Money helpers.
 *
 * @package Pnscripts\Omnibus\Tests
 */

declare(strict_types=1);

namespace Pnscripts\Omnibus\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Pnscripts\Omnibus\Domain\Money;

/**
 * Normalising and comparing decimal strings.
 */
final class MoneyTest extends TestCase {

	/**
	 * @return array<string, array{0: mixed, 1: string|null}>
	 */
	public static function values(): array {
		return array(
			'integer string' => array( '10', '10.000000' ),
			'decimal string' => array( '19.99', '19.990000' ),
			'float'          => array( 5.5, '5.500000' ),
			'int'            => array( 7, '7.000000' ),
			'zero'           => array( '0', '0.000000' ),
			'spaces'         => array( ' 3.10 ', '3.100000' ),
			'many decimals'  => array( '1.23456789', '1.234568' ),
			'empty'          => array( '', null ),
			'null'           => array( null, null ),
			'negative'       => array( '-1', null ),
			'text'           => array( 'abc', null ),
			'comma decimal'  => array( '1,50', null ),
			'array'          => array( array( 1 ), null ),
			'bool'           => array( true, null ),
		);
	}

	#[DataProvider( 'values' )]
	public function test_normalize( mixed $input, ?string $expected ): void {
		$this->assertSame( $expected, Money::normalize( $input ) );
	}

	public function test_compare(): void {
		$this->assertSame( 0, Money::compare( '10.000000', '10' ) );
		$this->assertSame( -1, Money::compare( '9.99', '10' ) );
		$this->assertSame( 1, Money::compare( '10.01', '10' ) );
		$this->assertSame( 0, Money::compare( '0.1', '0.10000000001' ) );
	}

	public function test_min(): void {
		$this->assertSame( '5', Money::min( null, '5' ) );
		$this->assertSame( '4', Money::min( '4', '5' ) );
		$this->assertSame( '3', Money::min( '4', '3' ) );
	}
}
