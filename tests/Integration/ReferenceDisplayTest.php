<?php
/**
 * End-to-end: recorded history → reference price → shop output.
 *
 * @package Pnscripts\Omnibus\Tests
 */

declare(strict_types=1);

namespace Pnscripts\Omnibus\Tests\Integration;

use Pnscripts\Omnibus\Display\Shortcode;
use Pnscripts\Omnibus\Domain\ReferenceResult;
use Pnscripts\Omnibus\Storage\HistoryRepository;
use WC_Product;
use WC_Tax;

/**
 * Notices in price HTML, variations, shortcode; tax display; settings.
 */
final class ReferenceDisplayTest extends IntegrationTestCase {

	/**
	 * Product with regular 100 for 60 days, then on sale at 80 for the last 10 days (all via real saves).
	 */
	private function product_on_sale(): WC_Product {
		$product = $this->simple( '100' );
		$this->age( array( $product->get_id() ), 50 );
		$product = $this->fresh( $product->get_id() );
		$product->set_sale_price( '80' );
		$product->save();
		$this->age( array( $product->get_id() ), 10 );
		return $this->fresh( $product->get_id() );
	}

	public function test_reference_price_after_real_saves(): void {
		$product = $this->product_on_sale();
		$result  = $this->plugin->reference->compute( $product, time() );

		$this->assertSame( ReferenceResult::KNOWN, $result->status );
		$this->assertSame( '100.000000', $result->price );
		$this->assertEqualsWithDelta( time() - 10 * self::DAY, $result->anchor, 5 );
	}

	public function test_progressive_reduction_after_real_saves(): void {
		$product = $this->product_on_sale();
		$product->set_sale_price( '70' );
		$product->save();
		$this->age( array( $product->get_id() ), 2 );

		$result = $this->plugin->reference->compute( $this->fresh( $product->get_id() ), time() );

		$this->assertSame( '100.000000', $result->price );
		$this->assertEqualsWithDelta( time() - 12 * self::DAY, $result->anchor, 5 );
	}

	public function test_inactive_period_not_yet_checked_hides_the_reference(): void {
		// Recorded 100 days ago: regular 100, sale 80 scheduled from 10 days ago. The plugin was inactive from
		// day 30 to day 1; meanwhile the regular price was 60 for five days. The resume job has not run yet.
		$product = $this->simple( '100' );
		$product->set_sale_price( '80' );
		$product->set_date_on_sale_from( (string) ( time() - 10 * self::DAY ) );
		$product->save();
		$this->age( array( $product->get_id() ), 100 );
		update_option(
			\Pnscripts\Omnibus\Capture\PriceRecorder::RESUME_OPTION,
			array(
				'from' => time() - 30 * self::DAY,
				'to'   => time() - self::DAY,
			)
		);
		HistoryRepository::bump_cache();

		$result = $this->plugin->reference->compute( $this->fresh( $product->get_id() ), time() );

		$this->assertSame( ReferenceResult::UNKNOWN, $result->status );
		$this->assertSame( ReferenceResult::REASON_GAP, $result->reason );
	}

	public function test_price_html_contains_notice(): void {
		$html = $this->product_on_sale()->get_price_html();

		$this->assertStringContainsString( 'pnscripts-omnibus-notice', $html );
		$this->assertStringContainsString( 'Lowest price in the 30 days before the discount:', $html );
		$this->assertMatchesRegularExpression( '/100[.,]00/', wp_strip_all_tags( $html ) );
	}

	public function test_custom_text_and_placeholders(): void {
		$this->set_settings( array( 'notice_text' => 'Najniższa cena z {days} dni przed obniżką: {price}' ) );
		$html = $this->product_on_sale()->get_price_html();

		$this->assertStringContainsString( 'Najniższa cena z 30 dni przed obniżką:', $html );
	}

	public function test_loop_and_single_can_be_disabled(): void {
		$this->set_settings( array( 'show_loop' => false ) );
		$this->assertStringNotContainsString( 'pnscripts-omnibus-notice', $this->product_on_sale()->get_price_html() );
	}

	public function test_unknown_is_hidden_by_default_and_optional_text_is_shown(): void {
		$product = $this->simple( '100' );
		$product->set_sale_price( '90' );
		$product->save();

		$this->assertStringNotContainsString( 'pnscripts-omnibus-notice', $this->fresh( $product->get_id() )->get_price_html() );

		$this->set_settings( array( 'hide_when_unknown' => false ) );
		$html = $this->fresh( $product->get_id() )->get_price_html();
		$this->assertStringContainsString( 'data-status="unknown"', $html );
		$this->assertStringContainsString( 'is not available', $html );
	}

	public function test_not_on_sale_shows_nothing(): void {
		$product = $this->simple( '100' );
		$this->age( array( $product->get_id() ), 60 );

		$this->assertStringNotContainsString( 'pnscripts-omnibus-notice', $this->fresh( $product->get_id() )->get_price_html() );
	}

	public function test_new_product_rule(): void {
		$product = $this->simple( '100', '', 12 );
		$this->age( array( $product->get_id() ), 12 );
		$product = $this->fresh( $product->get_id() );
		$product->set_sale_price( '75' );
		$product->save();
		$this->age( array( $product->get_id() ), 2 );

		$strict = $this->plugin->reference->compute( $this->fresh( $product->get_id() ), time() );
		$this->assertSame( ReferenceResult::REASON_NEW_PRODUCT, $strict->reason );

		$this->set_settings(
			array(
				'preset'           => 'custom',
				'new_product_mode' => 'since_launch',
			)
		);
		$short = $this->plugin->reference->compute( $this->fresh( $product->get_id() ), time() );
		$this->assertSame( ReferenceResult::KNOWN, $short->status );
		$this->assertTrue( $short->shortened );
		$this->assertSame( 10, $short->period_days );
		$this->assertStringContainsString( 'since this product was launched (10 days)', $this->fresh( $product->get_id() )->get_price_html() );
	}

	public function test_perishable_exemption(): void {
		$product = $this->product_on_sale();
		update_post_meta( $product->get_id(), '_pnscripts_omnibus_perishable', 'yes' );

		$this->assertSame( ReferenceResult::KNOWN, $this->plugin->reference->compute( $product, time() )->status, 'Exemption is off by default.' );

		$this->set_settings(
			array(
				'preset'               => 'custom',
				'perishable_exemption' => true,
			)
		);
		$this->assertSame( ReferenceResult::EXEMPT, $this->plugin->reference->compute( $product, time() )->status );
	}

	public function test_perishable_category(): void {
		$term = wp_insert_term( 'Fresh ' . wp_generate_password( 4, false ), 'product_cat' );
		$this->assertIsArray( $term );
		update_term_meta( (int) $term['term_id'], 'pnscripts_omnibus_perishable', 'yes' );
		$product = $this->product_on_sale();
		$product->set_category_ids( array( (int) $term['term_id'] ) );
		$product->save();
		$this->set_settings( array( 'preset' => 'de' ) );

		$this->assertSame( ReferenceResult::EXEMPT, $this->plugin->reference->compute( $this->fresh( $product->get_id() ), time() )->status );
		wp_delete_term( (int) $term['term_id'], 'product_cat' );
	}

	public function test_tax_inclusive_display_matches_shop_setting(): void {
		update_option( 'woocommerce_calc_taxes', 'yes' );
		update_option( 'woocommerce_prices_include_tax', 'no' );
		update_option( 'woocommerce_tax_display_shop', 'incl' );
		update_option( 'woocommerce_default_country', 'BG' );
		update_option( 'woocommerce_tax_based_on', 'base' );
		$rate_id = WC_Tax::_insert_tax_rate(
			array(
				'tax_rate_country'  => '',
				'tax_rate'          => '20.0000',
				'tax_rate_name'     => 'VAT',
				'tax_rate_priority' => '1',
				'tax_rate_shipping' => '1',
				'tax_rate_order'    => '1',
				'tax_rate_class'    => '',
			)
		);

		try {
			$product = $this->product_on_sale();
			$result  = $this->plugin->reference->compute( $product, time() );
			$this->assertEqualsWithDelta( 120.0, $this->plugin->reference->display_price( $product, $result ), 0.001 );

			update_option( 'woocommerce_tax_display_shop', 'excl' );
			$this->assertEqualsWithDelta( 100.0, $this->plugin->reference->display_price( $product, $result ), 0.001 );
		} finally {
			WC_Tax::_delete_tax_rate( $rate_id );
			update_option( 'woocommerce_calc_taxes', 'no' );
			update_option( 'woocommerce_tax_display_shop', 'excl' );
		}
	}

	public function test_variation_notice_in_price_html_and_available_variation_data(): void {
		list( $parent, $variations ) = $this->variable( array( '50', '60' ) );
		$ids                         = array_map( static fn ( WC_Product $v ): int => $v->get_id(), $variations );
		$this->age( $ids, 50 );
		$variation = $this->fresh( $ids[1] );
		$variation->set_sale_price( '40' );
		$variation->save();
		$this->age( $ids, 5 );

		$variation = $this->fresh( $ids[1] );
		$this->assertStringContainsString( 'pnscripts-omnibus-notice--variation', $variation->get_price_html() );

		$parent = $this->fresh( $parent->get_id() );
		$this->assertInstanceOf( \WC_Product_Variable::class, $parent );
		$data = $parent->get_available_variation( $variation );
		$this->assertIsArray( $data );
		$this->assertStringContainsString( 'pnscripts-omnibus-notice', (string) $data['price_html'] );
		$this->assertMatchesRegularExpression( '/60[.,]00/', wp_strip_all_tags( (string) $data['price_html'] ) );

		$other = $parent->get_available_variation( $this->fresh( $ids[0] ) );
		$this->assertIsArray( $other );
		$this->assertStringNotContainsString( 'pnscripts-omnibus-notice', (string) $other['price_html'] );

		$this->assertStringNotContainsString( 'pnscripts-omnibus-notice', $parent->get_price_html(), 'Price ranges carry no notice.' );
	}

	public function test_variation_notice_when_all_variations_cost_the_same(): void {
		list( $parent, $variations ) = $this->variable( array( '50', '50' ) );
		$ids                         = array_map( static fn ( WC_Product $v ): int => $v->get_id(), $variations );
		$this->age( $ids, 50 );
		foreach ( $ids as $id ) {
			$variation = $this->fresh( $id );
			$variation->set_sale_price( '45' );
			$variation->save();
		}
		$this->age( $ids, 5 );
		\WC_Product_Variable::sync( $parent->get_id() );

		$parent = $this->fresh( $parent->get_id() );
		$this->assertInstanceOf( \WC_Product_Variable::class, $parent );
		$data = $parent->get_available_variation( $this->fresh( $ids[0] ) );
		$this->assertIsArray( $data );
		$this->assertStringContainsString( 'pnscripts-omnibus-notice', (string) $data['price_html'] );
	}

	public function test_store_api_price_html_used_by_blocks_contains_notice(): void {
		$product  = $this->product_on_sale();
		$response = rest_do_request( new \WP_REST_Request( 'GET', '/wc/store/v1/products/' . $product->get_id() ) );

		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertIsArray( $data );
		$this->assertStringContainsString( 'pnscripts-omnibus-notice', (string) $data['price_html'] );
	}

	public function test_shortcode(): void {
		$product = $this->product_on_sale();

		$this->assertStringContainsString( 'pnscripts-omnibus-notice--shortcode', do_shortcode( '[' . Shortcode::TAG . ' id="' . $product->get_id() . '"]' ) );
		$this->assertSame( '', do_shortcode( '[' . Shortcode::TAG . ' id="999999999"]' ) );

		$product->set_status( 'draft' );
		$product->save();
		$this->assertSame( '', do_shortcode( '[' . Shortcode::TAG . ' id="' . $product->get_id() . '"]' ), 'Drafts are not shown to visitors.' );
	}

	public function test_shortcode_hides_variations_of_non_public_parents_and_protected_products(): void {
		list( $parent, $variations ) = $this->variable( array( '100' ) );
		$variation                   = $variations[0];
		$this->age( array( $variation->get_id() ), 50 );
		$variation = $this->fresh( $variation->get_id() );
		$variation->set_sale_price( '80' );
		$variation->save();
		$this->age( array( $variation->get_id() ), 10 );
		$tag = '[' . Shortcode::TAG . ' id="' . $variation->get_id() . '"]';

		$this->assertStringContainsString( 'pnscripts-omnibus-notice', do_shortcode( $tag ), 'Published parent: shown.' );

		foreach ( array( 'draft', 'private', 'pending' ) as $status ) {
			wp_update_post(
				array(
					'ID'          => $parent->get_id(),
					'post_status' => $status,
				)
			);
			$this->assertSame( '', do_shortcode( $tag ), 'Parent ' . $status . ': hidden from visitors.' );
		}

		wp_update_post(
			array(
				'ID'            => $parent->get_id(),
				'post_status'   => 'publish',
				'post_password' => 'secret',
			)
		);
		$this->assertSame( '', do_shortcode( $tag ), 'Password-protected parent: hidden.' );

		$simple = $this->product_on_sale();
		wp_update_post(
			array(
				'ID'            => $simple->get_id(),
				'post_password' => 'secret',
			)
		);
		$this->assertSame( '', do_shortcode( '[' . Shortcode::TAG . ' id="' . $simple->get_id() . '"]' ), 'Password-protected product: hidden.' );
	}

	public function test_already_on_sale_at_install_is_unknown_until_history_is_complete(): void {
		$product = $this->simple( '100', '80' );
		$this->age( array( $product->get_id() ), 45 );

		$result = $this->plugin->reference->compute( $this->fresh( $product->get_id() ), time() );

		$this->assertSame( ReferenceResult::REASON_START_UNKNOWN, $result->reason );
	}
}
