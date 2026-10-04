<?php
/**
 * Price capture on real WooCommerce write paths.
 *
 * @package Pnscripts\Omnibus\Tests
 */

declare(strict_types=1);

namespace Pnscripts\Omnibus\Tests\Integration;

use Automattic\WooCommerce\Utilities\FeaturesUtil;
use Pnscripts\Omnibus\Storage\Schema;
use WP_REST_Request;

/**
 * Every write path that ends in WC_Product::save() or a price meta write is recorded.
 */
final class CaptureTest extends IntegrationTestCase {

	public function test_table_is_installed(): void {
		global $wpdb;
		$this->assertSame( PNSCRIPTS_OMNIBUS_DB_VERSION, get_option( Schema::VERSION_OPTION ) );
		$this->assertSame( Schema::table(), $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', Schema::table() ) ) );
	}

	public function test_new_product_is_recorded_once_and_unchanged_saves_are_ignored(): void {
		$product = $this->simple( '100' );
		$product->set_name( 'Renamed' );
		$product->save();
		$product->save();

		$records = $this->records( $product->get_id() );
		$this->assertCount( 1, $records );
		$this->assertSame( '100.000000', $records[0]->regular );
		$this->assertNull( $records[0]->sale );
		$this->assertSame( '100.000000', $records[0]->price );
	}

	public function test_sale_price_change_is_recorded(): void {
		$product = $this->simple( '100' );
		$product->set_sale_price( '80' );
		$product->save();
		$product->set_sale_price( '70' );
		$product->save();
		$product->set_sale_price( '' );
		$product->save();

		$prices = array_map( static fn ( $r ): ?string => $r->sale, $this->records( $product->get_id() ) );
		$this->assertSame( array( null, '80.000000', '70.000000', null ), $prices );
	}

	public function test_scheduled_sale_dates_are_stored(): void {
		$from    = strtotime( '+2 days midnight' );
		$to      = strtotime( '+9 days 23:59:59' );
		$product = $this->simple( '100' );
		$product->set_sale_price( '75' );
		$product->set_date_on_sale_from( (string) $from );
		$product->set_date_on_sale_to( (string) $to );
		$product->save();

		$last = $this->plugin->repository->latest( $product->get_id() );
		$this->assertNotNull( $last );
		$this->assertSame( $from, $last->sale_from );
		$this->assertSame( $to, $last->sale_to );
		$this->assertSame( '100.000000', $last->price, 'The sale has not started yet, WooCommerce stores the regular price.' );
	}

	public function test_variations_are_recorded_individually_and_parent_is_not(): void {
		list( $parent, $variations ) = $this->variable( array( '50', '60' ) );

		$this->assertCount( 0, $this->records( $parent->get_id() ) );
		$this->assertCount( 1, $this->records( $variations[0]->get_id() ) );
		$this->assertCount( 1, $this->records( $variations[1]->get_id() ) );

		$variations[1]->set_sale_price( '45' );
		$variations[1]->save();

		$this->assertCount( 1, $this->records( $variations[0]->get_id() ) );
		$latest = $this->plugin->repository->latest( $variations[1]->get_id() );
		$this->assertNotNull( $latest );
		$this->assertSame( '45.000000', $latest->sale );
	}

	public function test_direct_meta_write_is_recorded_at_shutdown(): void {
		$product = $this->simple( '100' );
		$id      = $product->get_id();

		update_post_meta( $id, '_sale_price', '66' );
		update_post_meta( $id, '_price', '66' );
		wp_cache_delete( $id, 'post_meta' );
		$this->plugin->recorder->flush_queue();

		$latest = $this->plugin->repository->latest( $id );
		$this->assertNotNull( $latest );
		$this->assertSame( '66.000000', $latest->sale );
		$this->assertStringEndsWith( ':meta', $latest->source );
	}

	public function test_rest_api_update_is_recorded(): void {
		$product = $this->simple( '100' );
		$admin   = get_users(
			array(
				'role'   => 'administrator',
				'number' => 1,
			)
		);
		$this->assertNotEmpty( $admin );
		wp_set_current_user( $admin[0]->ID );

		$request = new WP_REST_Request( 'PUT', '/wc/v3/products/' . $product->get_id() );
		$request->set_body_params( array( 'sale_price' => '85' ) );
		$response = rest_do_request( $request );

		$this->assertSame( 200, $response->get_status() );
		$latest = $this->plugin->repository->latest( $product->get_id() );
		$this->assertNotNull( $latest );
		$this->assertSame( '85.000000', $latest->sale );
	}

	public function test_csv_import_is_recorded(): void {
		$sku  = 'PNO-' . wp_generate_password( 8, false );
		$file = get_temp_dir() . 'pnscripts-omnibus-' . wp_generate_password( 6, false ) . '.csv';
		file_put_contents( $file, "Type,SKU,Name,Published,Regular price,Sale price\nsimple,{$sku},CSV product,1,40,30\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents

		include_once WC_ABSPATH . 'includes/import/class-wc-product-csv-importer.php';
		$importer = new \WC_Product_CSV_Importer(
			$file,
			array(
				'parse'           => true,
				'mapping'         => array(
					'Type'          => 'type',
					'SKU'           => 'sku',
					'Name'          => 'name',
					'Published'     => 'published',
					'Regular price' => 'regular_price',
					'Sale price'    => 'sale_price',
				),
				'update_existing' => false,
			)
		);
		$result   = $importer->import();
		unlink( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink

		$this->assertCount( 1, $result['imported'] );
		$id = (int) $result['imported'][0];
		$this->track( $id );
		$latest = $this->plugin->repository->latest( $id );
		$this->assertNotNull( $latest );
		$this->assertSame( '40.000000', $latest->regular );
		$this->assertSame( '30.000000', $latest->sale );
	}

	public function test_scheduled_sale_start_by_woocommerce_is_recorded(): void {
		$product = $this->simple( '100' );
		$product->set_sale_price( '60' );
		$product->set_date_on_sale_from( (string) ( time() - 3600 ) );
		$product->save();
		// Simulate the stored price WooCommerce had before its scheduled-sales job ran.
		update_post_meta( $product->get_id(), '_price', '100' );
		$this->plugin->recorder->flush_queue();
		$count = count( $this->records( $product->get_id() ) );

		do_action( 'woocommerce_scheduled_sales' );

		$latest = $this->plugin->repository->latest( $product->get_id() );
		$this->assertNotNull( $latest );
		$this->assertSame( '60.000000', $latest->price );
		$this->assertGreaterThan( $count, count( $this->records( $product->get_id() ) ) );
	}

	public function test_deleting_a_product_deletes_its_history(): void {
		$product = $this->simple( '10' );
		$id      = $product->get_id();
		$this->assertCount( 1, $this->records( $id ) );

		$product->delete( true );

		$this->assertCount( 0, $this->records( $id ) );
	}

	public function test_resume_after_inactivity_marks_gap_when_product_changed(): void {
		$product = $this->simple( '100' );
		$this->age( array( $product->get_id() ), 20 );
		update_option(
			\Pnscripts\Omnibus\Capture\PriceRecorder::RESUME_OPTION,
			array(
				'from' => time() - 10 * self::DAY,
				'to'   => time() - 60,
			)
		);
		// Changed while the plugin was inactive (no hooks): date_modified moved into the gap.
		$product = $this->fresh( $product->get_id() );
		$product->set_date_modified( time() - 5 * self::DAY );
		$product->save();

		$latest = $this->plugin->repository->latest( $product->get_id() );
		$this->assertNotNull( $latest );
		$this->assertNotNull( $latest->unknown_since );
		$this->assertEqualsWithDelta( time() - 10 * self::DAY, $latest->unknown_since, 5 );
	}

	public function test_woocommerce_feature_compatibility_is_declared(): void {
		$file = plugin_basename( PNSCRIPTS_OMNIBUS_FILE );
		$hpos = FeaturesUtil::get_compatible_plugins_for_feature( 'custom_order_tables' );
		$this->assertContains( $file, $hpos['compatible'] );
		$blocks = FeaturesUtil::get_compatible_plugins_for_feature( 'cart_checkout_blocks' );
		$this->assertContains( $file, $blocks['compatible'] );
	}
}
