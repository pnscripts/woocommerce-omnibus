<?php
/**
 * Mapping of other plugins' data.
 *
 * @package Pnscripts\Omnibus\Tests
 */

declare(strict_types=1);

namespace Pnscripts\Omnibus\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Pnscripts\Omnibus\Import\ImportMapper;

/**
 * ImportMapper.
 */
final class ImportMapperTest extends TestCase {

	public function test_iworks_v2_meta_with_regular_and_sale(): void {
		$record = ImportMapper::iworks_meta(
			array(
				'price'         => '80',
				'price_regular' => '100',
				'price_sale'    => '80',
				'timestamp'     => 1700000000,
				'user_id'       => 1,
			),
			'import:omnibus'
		);

		$this->assertNotNull( $record );
		$this->assertSame( 1700000000, $record->changed_at );
		$this->assertSame( '100.000000', $record->regular );
		$this->assertSame( '80.000000', $record->sale );
		$this->assertSame( '80.000000', $record->price );
		$this->assertSame( 'import:omnibus', $record->source );
	}

	public function test_iworks_v2_meta_with_effective_price_only(): void {
		$record = ImportMapper::iworks_meta(
			array(
				'price'     => '49.90',
				'timestamp' => '1700000000',
			),
			'import:omnibus'
		);

		$this->assertNotNull( $record );
		$this->assertNull( $record->regular );
		$this->assertSame( '49.900000', $record->price );
	}

	public function test_iworks_v2_meta_invalid(): void {
		$this->assertNull( ImportMapper::iworks_meta( 'nope', 's' ) );
		$this->assertNull( ImportMapper::iworks_meta( array( 'price' => '1' ), 's' ) );
		$this->assertNull( ImportMapper::iworks_meta( array( 'timestamp' => 1700000000 ), 's' ) );
	}

	public function test_iworks_v3_log_uses_timestamp_meta_then_post_date(): void {
		$with_timestamp = ImportMapper::iworks_log(
			array(
				'price_regular' => '10',
				'price_sale'    => '',
				'timestamp'     => '1700000000',
			),
			'2020-01-01 00:00:00',
			's'
		);
		$with_date      = ImportMapper::iworks_log( array( 'price' => '10' ), '2024-03-01 10:00:00', 's' );

		$this->assertNotNull( $with_timestamp );
		$this->assertSame( 1700000000, $with_timestamp->changed_at );
		$this->assertNull( $with_timestamp->sale );
		$this->assertSame( '10.000000', $with_timestamp->price );
		$this->assertNotNull( $with_date );
		$this->assertSame( 1709287200, $with_date->changed_at );
		$this->assertNull( ImportMapper::iworks_log( array( 'price' => '10' ), '0000-00-00 00:00:00', 's' ) );
	}

	public function test_wc_price_history_row(): void {
		$record = ImportMapper::wc_price_history_row(
			array(
				'price'              => '100.0000',
				'sale_price'         => '75.0000',
				'date_gmt'           => '2024-03-01 10:00:00',
				'include_in_history' => '1',
			),
			's'
		);

		$this->assertNotNull( $record );
		$this->assertSame( 1709287200, $record->changed_at );
		$this->assertSame( '100.000000', $record->regular );
		$this->assertSame( '75.000000', $record->sale );
		$this->assertSame( '75.000000', $record->price );
	}

	public function test_wc_price_history_row_without_sale_and_excluded_rows(): void {
		$plain = ImportMapper::wc_price_history_row(
			array(
				'price'      => '100',
				'sale_price' => null,
				'date_gmt'   => '2024-03-01 10:00:00',
			),
			's'
		);

		$this->assertNotNull( $plain );
		$this->assertNull( $plain->sale );
		$this->assertSame( '100.000000', $plain->price );
		$this->assertNull(
			ImportMapper::wc_price_history_row(
				array(
					'price'              => '100',
					'date_gmt'           => '2024-03-01 10:00:00',
					'include_in_history' => '0',
				),
				's'
			)
		);
		$this->assertNull( ImportMapper::wc_price_history_row( array( 'price' => '' ), 's' ) );
	}

	public function test_wc_price_history_legacy_meta_subtracts_offset_and_skips_zero(): void {
		$records = ImportMapper::wc_price_history_meta(
			array(
				1700000000 + 7200 => '100',
				1700086400 + 7200 => '0',
				1700172800 + 7200 => '90',
				'bad'             => '5',
			),
			2.0,
			's'
		);

		$this->assertCount( 2, $records );
		$this->assertSame( 1700000000, $records[0]->changed_at );
		$this->assertSame( '90.000000', $records[1]->price );
		$this->assertSame( array(), ImportMapper::wc_price_history_meta( 'x', 0.0, 's' ) );
	}

	public function test_ilabs_meta(): void {
		$records = ImportMapper::ilabs_meta(
			array(
				array( 100.0, '2024-03-01 10:00:00', false, 1 ),
				array( 80.0, '2024-03-05 10:00:00', true, 1 ),
				array( 'x' ),
				'garbage',
				array( 70.0, 'not a date', true ),
			),
			's'
		);

		$this->assertCount( 2, $records );
		$this->assertSame( 1709287200, $records[0]->changed_at );
		$this->assertFalse( $records[0]->on_sale );
		$this->assertTrue( $records[1]->on_sale );
		$this->assertSame( '80.000000', $records[1]->price );
		$this->assertNull( $records[1]->regular );
	}
}
