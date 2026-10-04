<?php
/**
 * Storage edge cases.
 *
 * @package Pnscripts\Omnibus\Tests
 */

declare(strict_types=1);

namespace Pnscripts\Omnibus\Tests\Integration;

use Pnscripts\Omnibus\Domain\PriceRecord;
use Pnscripts\Omnibus\Domain\ReferenceResult;
use Pnscripts\Omnibus\Storage\HistoryRepository;
use Pnscripts\Omnibus\Storage\Schema;

/**
 * Row cap and ordering of the history read.
 */
final class HistoryRepositoryTest extends IntegrationTestCase {

	public function test_row_cap_keeps_the_newest_records(): void {
		global $wpdb;
		$product = $this->simple( '100', '80' );
		$id      = $product->get_id();
		$this->plugin->repository->delete_product( $id );

		// 5000 older price changes (a repricing tool), all long before the period that matters.
		$base   = time() - 190 * self::DAY;
		$values = array();
		for ( $i = 0; $i < 5000; $i++ ) {
			$price    = 0 === $i % 2 ? '100' : '101';
			$values[] = $wpdb->prepare( '(%d, 0, %s, %s, %s, %s, %s, %s)', $id, HistoryRepository::to_datetime( $base + $i * 60 ), $price, $price, 'EUR', 'admin', HistoryRepository::to_datetime( $base ) );
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared -- Test fixture; values prepared above.
		$wpdb->query( 'INSERT INTO ' . Schema::table() . ' (product_id, parent_id, changed_at, regular_price, price, currency, source, created_at) VALUES ' . implode( ',', $values ) );

		foreach ( array(
			array( 20, '60', null ),
			array( 15, '100', null ),
			array( 5, '100', '80' ),
		) as $change ) {
			$price = null === $change[2] ? $change[1] : $change[2];
			$this->plugin->repository->insert( $id, 0, new PriceRecord( time() - $change[0] * self::DAY, $change[1], $change[2], null, null, $price, null, null, 'admin' ), 'EUR' );
		}
		$this->plugin->recorder->reset();

		$records = $this->records( $id );
		$this->assertCount( 5000, $records );
		$this->assertSame( '80.000000', end( $records )->sale, 'The newest record is loaded.' );

		$result = $this->plugin->reference->compute( $this->fresh( $id ), time() );
		$this->assertSame( ReferenceResult::KNOWN, $result->status );
		$this->assertSame( '60.000000', $result->price );
	}
}
