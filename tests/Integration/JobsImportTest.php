<?php
/**
 * Background jobs, imports and uninstall.
 *
 * @package Pnscripts\Omnibus\Tests
 */

declare(strict_types=1);

namespace Pnscripts\Omnibus\Tests\Integration;

use Pnscripts\Omnibus\Domain\ReferenceResult;
use Pnscripts\Omnibus\Import\Importers;
use Pnscripts\Omnibus\Jobs\Backfill;
use Pnscripts\Omnibus\Settings;
use Pnscripts\Omnibus\Storage\HistoryRepository;
use Pnscripts\Omnibus\Storage\Schema;

/**
 * Backfill, repair, retention, the three importers and uninstall.
 */
final class JobsImportTest extends IntegrationTestCase {

	public function test_baseline_backfill_records_products_without_history(): void {
		$product = $this->simple( '30' );
		$this->plugin->repository->delete_product( $product->get_id() );
		$this->plugin->recorder->reset();

		$this->assertTrue( $this->plugin->backfill->process_product( $this->fresh( $product->get_id() ), Backfill::MODE_BASELINE ) );
		$records = $this->records( $product->get_id() );
		$this->assertCount( 1, $records );
		$this->assertSame( 'baseline', $records[0]->source );
		$this->assertEqualsWithDelta( time(), $records[0]->changed_at, 5 );
		$this->assertFalse( $this->plugin->backfill->process_product( $this->fresh( $product->get_id() ), Backfill::MODE_BASELINE ) );
	}

	public function test_baseline_can_trust_last_modified_date(): void {
		$this->set_settings( array( 'trust_last_modified' => true ) );
		global $wpdb;
		$product = $this->simple( '30' );
		$wpdb->update(
			$wpdb->posts,
			array(
				'post_modified'     => gmdate( 'Y-m-d H:i:s', time() - 40 * self::DAY ),
				'post_modified_gmt' => gmdate( 'Y-m-d H:i:s', time() - 40 * self::DAY ),
			),
			array( 'ID' => $product->get_id() )
		);
		clean_post_cache( $product->get_id() );
		$this->plugin->repository->delete_product( $product->get_id() );
		$this->plugin->recorder->reset();

		$this->plugin->backfill->process_product( $this->fresh( $product->get_id() ), Backfill::MODE_BASELINE );

		$records = $this->records( $product->get_id() );
		$this->assertCount( 1, $records );
		$this->assertLessThan( time() - 30 * self::DAY, $records[0]->changed_at );
	}

	public function test_batch_processing_walks_the_catalogue(): void {
		$a = $this->simple( '1' );
		$b = $this->simple( '2' );
		$this->plugin->repository->delete_product( $a->get_id() );
		$this->plugin->repository->delete_product( $b->get_id() );
		$this->plugin->recorder->reset();

		$after = $a->get_id() - 1;
		$first = $this->plugin->backfill->process( $after, Backfill::MODE_BASELINE, 1 );
		$this->assertSame( $a->get_id(), $first['next'] );
		$this->plugin->backfill->process( (int) $first['next'], Backfill::MODE_BASELINE, 1 );

		$this->assertCount( 1, $this->records( $a->get_id() ) );
		$this->assertCount( 1, $this->records( $b->get_id() ) );
	}

	public function test_repair_marks_price_changed_outside_hooks(): void {
		global $wpdb;
		$product = $this->simple( '100' );
		$this->age( array( $product->get_id() ), 60 );

		// Write that bypasses WordPress and WooCommerce entirely.
		$wpdb->update(
			$wpdb->postmeta,
			array( 'meta_value' => '70' ),
			array(
				'post_id'  => $product->get_id(),
				'meta_key' => '_sale_price',
			)
		);
		if ( 0 === $wpdb->rows_affected ) {
			$wpdb->insert(
				$wpdb->postmeta,
				array(
					'post_id'    => $product->get_id(),
					'meta_key'   => '_sale_price',
					'meta_value' => '70',
				)
			);
		}
		wp_cache_delete( $product->get_id(), 'post_meta' );
		clean_post_cache( $product->get_id() );

		$this->assertTrue( $this->plugin->backfill->process_product( $this->fresh( $product->get_id() ), Backfill::MODE_REPAIR ) );
		$latest = $this->plugin->repository->latest( $product->get_id() );
		$this->assertNotNull( $latest );
		$this->assertSame( 'repair', $latest->source );
		$this->assertNotNull( $latest->unknown_since );

		$result = $this->plugin->reference->compute( $this->fresh( $product->get_id() ), time() );
		$this->assertSame( ReferenceResult::UNKNOWN, $result->status, 'When the change moment is unknown, nothing is shown.' );
	}

	public function test_retention_prunes_old_rows_but_keeps_the_price_in_force(): void {
		$product = $this->simple( '100' );
		$id      = $product->get_id();
		$this->age( array( $id ), 200 );
		foreach ( array( '90', '95', '100' ) as $price ) {
			$product = $this->fresh( $id );
			$product->set_regular_price( $price );
			$product->save();
			$this->age( array( $id ), 50 );
		}
		$this->assertCount( 4, $this->records( $id ) );

		$this->plugin->retention->prune( $id - 1, 10 );

		// Rows at -350, -150, -100 and -50 days: the cutoff is -90 days, the -100 row was in force then.
		$records = $this->records( $id );
		$this->assertCount( 2, $records, 'Rows older than 90 days are deleted except the last one before the cutoff.' );
		$this->assertSame( '95.000000', $records[0]->regular );
		$this->assertSame( '100.000000', $records[1]->regular );
	}

	public function test_retention_keeps_rows_needed_by_a_running_sale(): void {
		$product = $this->simple( '100' );
		$id      = $product->get_id();
		$this->age( array( $id ), 30 );
		$product = $this->fresh( $id );
		$product->set_regular_price( '95' );
		$product->save();
		$this->age( array( $id ), 50 );
		$product = $this->fresh( $id );
		$product->set_sale_price( '80' );
		$product->save();
		$this->age( array( $id ), 80 );
		// Sale running for 80 days; it needs the prices 30 days before it started (110+ days ago).

		$this->plugin->retention->prune( $id - 1, 10 );

		$result = $this->plugin->reference->compute( $this->fresh( $id ), time() );
		$this->assertSame( ReferenceResult::KNOWN, $result->status );
		$this->assertSame( '95.000000', $result->price );
	}

	public function test_import_from_omnibus_by_iworks_v3_and_v2(): void {
		$product = $this->simple( '100' );
		$id      = $product->get_id();
		$this->age( array( $id ), 5 );

		$log = wp_insert_post(
			array(
				'post_type'     => 'iw_omnibus_price_log',
				'post_status'   => 'publish',
				'post_parent'   => $id,
				'post_title'    => 'log',
				'post_date_gmt' => gmdate( 'Y-m-d H:i:s', time() - 40 * self::DAY ),
			)
		);
		$this->assertIsInt( $log );
		add_post_meta( $log, 'price_regular', '100' );
		add_post_meta( $log, 'price_sale', '' );
		add_post_meta( $log, 'price', '100' );
		add_post_meta( $log, 'timestamp', time() - 40 * self::DAY );
		add_post_meta(
			$id,
			'_iwo_price_lowest',
			array(
				'price'     => '88',
				'timestamp' => time() - 35 * self::DAY,
			)
		);
		add_post_meta(
			$id,
			'_iwo_price_log',
			array(
				'price'     => '99',
				'timestamp' => time() - 1 * self::DAY,
			)
		);

		$this->run_import( 'omnibus' );

		$imported = array_values( array_filter( $this->records( $id ), static fn ( $r ): bool => 'import:omnibus' === $r->source ) );
		$this->assertCount( 2, $imported, 'Entries after our own first record are skipped.' );
		$this->run_import( 'omnibus' );
		$this->assertCount( 3, $this->records( $id ), 'Re-running does not duplicate.' );

		wp_delete_post( $log, true );
	}

	public function test_import_from_wc_price_history_table_and_meta(): void {
		global $wpdb;
		$table = $wpdb->prefix . 'wc_price_history';
		$wpdb->query( "CREATE TABLE IF NOT EXISTS {$table} (id bigint(20) unsigned NOT NULL AUTO_INCREMENT, product_id bigint(20) unsigned NOT NULL, price decimal(19,4) NOT NULL, sale_price decimal(19,4) DEFAULT NULL, previous_price decimal(19,4) DEFAULT NULL, previous_sale_price decimal(19,4) DEFAULT NULL, date datetime NOT NULL, date_gmt datetime NOT NULL, include_in_history tinyint(1) DEFAULT 1, PRIMARY KEY (id))" ); // phpcs:ignore WordPress.DB

		$product = $this->simple( '100' );
		$id      = $product->get_id();
		$this->age( array( $id ), 10 );
		$product = $this->fresh( $id );
		$product->set_sale_price( '80' );
		$product->save();
		$wpdb->insert(
			$table,
			array(
				'product_id' => $id,
				'price'      => '100',
				'sale_price' => null,
				'date'       => gmdate( 'Y-m-d H:i:s', time() - 50 * self::DAY ),
				'date_gmt'   => gmdate( 'Y-m-d H:i:s', time() - 50 * self::DAY ),
			)
		);
		add_post_meta( $id, '_wc_price_history', array( time() - 20 * self::DAY => 97.0 ) );

		$this->run_import( 'wc-price-history' );

		$result = $this->plugin->reference->compute( $this->fresh( $id ), time() );
		$this->assertSame( ReferenceResult::KNOWN, $result->status, 'Imported history completes the period before our first record.' );
		$this->assertSame( '97.000000', $result->price );

		// Already on sale when our recording started: the unflagged imported 97 may have been that sale.
		$on_sale = $this->simple( '100', '80' );
		$this->age( array( $on_sale->get_id() ), 3 );
		add_post_meta(
			$on_sale->get_id(),
			'_wc_price_history',
			array(
				time() - 50 * self::DAY => 100.0,
				time() - 20 * self::DAY => 97.0,
			)
		);
		$this->run_import( 'wc-price-history' );
		$this->assertSame( ReferenceResult::REASON_START_UNKNOWN, $this->plugin->reference->compute( $this->fresh( $on_sale->get_id() ), time() )->reason );

		$wpdb->query( "DROP TABLE IF EXISTS {$table}" ); // phpcs:ignore WordPress.DB
	}

	public function test_import_from_omnibus_by_ilabs(): void {
		$product = $this->simple( '100', '85' );
		$id      = $product->get_id();
		$this->age( array( $id ), 2 );
		add_post_meta(
			$id,
			'omnibus_by_ilabs_prices_history',
			array(
				array( 100.0, gmdate( 'Y-m-d H:i:s', time() - 60 * self::DAY ), false, 1 ),
				array( 90.0, gmdate( 'Y-m-d H:i:s', time() - 25 * self::DAY ), true, 1 ),
				array( 100.0, gmdate( 'Y-m-d H:i:s', time() - 20 * self::DAY ), false, 1 ),
			)
		);

		$this->assertGreaterThan( 0, $this->plugin->importers->count( 'omnibus-by-ilabs' ) );
		$this->run_import( 'omnibus-by-ilabs' );

		$result = $this->plugin->reference->compute( $this->fresh( $id ), time() );
		$this->assertSame( ReferenceResult::KNOWN, $result->status );
		$this->assertSame( '90.000000', $result->price );
	}

	public function test_uninstall_keeps_data_unless_opted_in(): void {
		global $wpdb;
		$product = $this->simple( '10' );

		define( 'WP_UNINSTALL_PLUGIN', 'pnscripts-omnibus/pnscripts-omnibus.php' );
		include dirname( __DIR__, 2 ) . '/uninstall.php';
		$this->assertSame( Schema::table(), $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', Schema::table() ) ) );
		$this->assertCount( 1, $this->records( $product->get_id() ) );

		$this->set_settings( array( 'delete_data_on_uninstall' => true ) );
		pnscripts_omnibus_uninstall_site();
		$this->assertNull( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', Schema::table() ) ) );
		$this->assertFalse( get_option( Settings::OPTION ) );

		Schema::install();
		HistoryRepository::bump_cache();
	}

	/**
	 * Run an import group synchronously.
	 */
	private function run_import( string $group ): void {
		$this->assertArrayHasKey( $group, Importers::GROUPS );
		$reader = 0;
		$after  = 0;
		do {
			$step = $this->plugin->import_runner->step( $group, $reader, $after, 200 );
			if ( null !== $step['next'] ) {
				list( $reader, $after ) = $step['next'];
			}
		} while ( null !== $step['next'] );
		HistoryRepository::bump_cache();
	}
}
