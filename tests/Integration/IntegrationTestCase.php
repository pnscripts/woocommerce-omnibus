<?php
/**
 * Base class for tests against a real WordPress + WooCommerce site.
 *
 * @package Pnscripts\Omnibus\Tests
 */

declare(strict_types=1);

namespace Pnscripts\Omnibus\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Pnscripts\Omnibus\Domain\PriceRecord;
use Pnscripts\Omnibus\Plugin;
use Pnscripts\Omnibus\Settings;
use Pnscripts\Omnibus\Storage\HistoryRepository;
use Pnscripts\Omnibus\Storage\Schema;
use WC_Product;
use WC_Product_Simple;
use WC_Product_Variable;
use WC_Product_Variation;

/**
 * Helpers: product factories, "time travel" by ageing stored rows, clean-up.
 */
abstract class IntegrationTestCase extends TestCase {

	protected const DAY = 86400;

	/**
	 * Products created by the test.
	 *
	 * @var list<int>
	 */
	private array $created = array();

	protected Plugin $plugin;

	protected function setUp(): void {
		parent::setUp();
		$plugin = Plugin::instance();
		$this->assertNotNull( $plugin );
		$this->plugin = $plugin;
		$this->set_settings( array() );
		$this->plugin->recorder->reset();
		wp_set_current_user( 0 );
	}

	protected function tearDown(): void {
		foreach ( array_reverse( $this->created ) as $id ) {
			$product = wc_get_product( $id );
			if ( $product instanceof WC_Product ) {
				$product->delete( true );
			}
		}
		$this->created = array();
		$this->set_settings( array() );
		delete_option( \Pnscripts\Omnibus\Capture\PriceRecorder::RESUME_OPTION );
		$this->plugin->recorder->reset();
		parent::tearDown();
	}

	/**
	 * Replace settings.
	 *
	 * @param array<string, mixed> $values Values merged into the defaults.
	 */
	protected function set_settings( array $values ): void {
		update_option( Settings::OPTION, array_merge( Settings::defaults(), $values ) );
		$this->plugin->settings->flush();
		HistoryRepository::bump_cache();
	}

	/**
	 * Published simple product created $days_old days ago.
	 */
	protected function simple( string $regular, string $sale = '', int $days_old = 400 ): WC_Product_Simple {
		$product = new WC_Product_Simple();
		$product->set_name( 'Test product ' . wp_generate_password( 6, false ) );
		$product->set_status( 'publish' );
		$product->set_regular_price( $regular );
		$product->set_sale_price( $sale );
		$product->set_date_created( time() - $days_old * self::DAY );
		$product->save();
		$this->created[] = $product->get_id();
		return $product;
	}

	/**
	 * Variable product with one variation per regular price.
	 *
	 * @param string[] $prices Regular prices.
	 * @return array{0: WC_Product_Variable, 1: list<WC_Product_Variation>}
	 */
	protected function variable( array $prices, int $days_old = 400 ): array {
		$attribute = new \WC_Product_Attribute();
		$attribute->set_name( 'Size' );
		$attribute->set_options( array_map( static fn ( int $i ): string => 'S' . $i, array_keys( $prices ) ) );
		$attribute->set_visible( true );
		$attribute->set_variation( true );

		$parent = new WC_Product_Variable();
		$parent->set_name( 'Variable ' . wp_generate_password( 6, false ) );
		$parent->set_status( 'publish' );
		$parent->set_attributes( array( $attribute ) );
		$parent->set_date_created( time() - $days_old * self::DAY );
		$parent->save();
		$this->created[] = $parent->get_id();

		$variations = array();
		foreach ( $prices as $i => $price ) {
			$variation = new WC_Product_Variation();
			$variation->set_parent_id( $parent->get_id() );
			$variation->set_attributes( array( 'size' => 'S' . $i ) );
			$variation->set_regular_price( $price );
			$variation->set_status( 'publish' );
			$variation->set_date_created( time() - $days_old * self::DAY );
			$variation->save();
			$this->created[] = $variation->get_id();
			$variations[]    = $variation;
		}
		\WC_Product_Variable::sync( $parent->get_id() );
		$fresh = wc_get_product( $parent->get_id() );
		$this->assertInstanceOf( WC_Product_Variable::class, $fresh );
		return array( $fresh, $variations );
	}

	/**
	 * Move every stored row of the products back in time ("these changes happened N days earlier").
	 *
	 * @param int[] $ids  Product ids.
	 * @param float $days Days.
	 */
	protected function age( array $ids, float $days ): void {
		global $wpdb;
		$shift = (int) ( $days * self::DAY );
		foreach ( $ids as $id ) {
			foreach ( $this->plugin->repository->for_product( $id ) as $record ) {
				$wpdb->update(
					Schema::table(),
					array(
						'changed_at'    => HistoryRepository::to_datetime( $record->changed_at - $shift ),
						'unknown_since' => HistoryRepository::to_datetime( null === $record->unknown_since ? null : $record->unknown_since - $shift ),
					),
					array( 'id' => $record->id )
				);
			}
		}
		HistoryRepository::bump_cache();
		$this->plugin->recorder->reset();
	}

	/**
	 * Stored records, oldest first.
	 *
	 * @return list<PriceRecord>
	 */
	protected function records( int $id ): array {
		return $this->plugin->repository->for_product( $id );
	}

	/**
	 * Fresh product object.
	 */
	protected function fresh( int $id ): WC_Product {
		$product = wc_get_product( $id );
		$this->assertInstanceOf( WC_Product::class, $product );
		return $product;
	}

	/**
	 * Track a product created elsewhere for clean-up.
	 */
	protected function track( int $id ): void {
		$this->created[] = $id;
	}
}
