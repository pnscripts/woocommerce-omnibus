<?php
/**
 * Adds the notice to WooCommerce price output.
 *
 * @package Pnscripts\Omnibus
 */

declare(strict_types=1);

namespace Pnscripts\Omnibus\Display;

use Pnscripts\Omnibus\Settings;
use WC_Product;

/**
 * Appends the notice to the price HTML. WooCommerce uses the same price HTML in classic templates,
 * the Product Price block (single product and product collections), product variations
 * (woocommerce_available_variation) and the Store API, so one filter covers classic and block themes.
 */
final class PriceDisplay {

	/**
	 * Constructor.
	 *
	 * @param Settings       $settings Settings.
	 * @param NoticeRenderer $renderer Renderer.
	 */
	public function __construct(
		private readonly Settings $settings,
		private readonly NoticeRenderer $renderer
	) {
	}

	/**
	 * Hook into WordPress.
	 */
	public function register(): void {
		add_filter( 'woocommerce_get_price_html', array( $this, 'filter_price_html' ), 100, 2 );
		add_filter( 'woocommerce_available_variation', array( $this, 'filter_available_variation' ), 100, 3 );
		add_action( 'wp_enqueue_scripts', array( $this, 'register_style' ) );
	}

	/**
	 * Register the small stylesheet; it is enqueued only when a notice is printed.
	 */
	public function register_style(): void {
		wp_register_style( NoticeRenderer::STYLE_HANDLE, PNSCRIPTS_OMNIBUS_URL . 'assets/css/frontend.css', array(), PNSCRIPTS_OMNIBUS_VERSION );
	}

	/**
	 * Append the notice to the price HTML.
	 *
	 * @param mixed $html    Price HTML.
	 * @param mixed $product Product.
	 * @return mixed
	 */
	public function filter_price_html( $html, $product ) {
		if ( ! is_string( $html ) || '' === $html || ! $product instanceof WC_Product ) {
			return $html;
		}
		if ( is_admin() && ! wp_doing_ajax() ) {
			return $html; // Admin product list.
		}
		if ( $product->is_type( array( 'variable', 'grouped' ) ) ) {
			return $html; // Ranges: notices are shown per variation.
		}
		$context = $this->context( $product );
		if ( ! $this->enabled( $context ) ) {
			return $html;
		}
		$notice = $this->renderer->html( $product, $context );
		$result = '' === $notice ? $html : $html . ' ' . $notice;

		/**
		 * Filters the full price HTML after the notice was added.
		 *
		 * Extension point for badge and strikethrough recalculation (Pro).
		 *
		 * @param string     $result   Price HTML with notice.
		 * @param string     $html     Original price HTML.
		 * @param WC_Product $product  Product.
		 * @param string     $context  single, loop or variation.
		 */
		$filtered = apply_filters( 'pnscripts_omnibus_price_html', $result, $html, $product, $context );
		return is_string( $filtered ) ? $filtered : $result;
	}

	/**
	 * When all variations cost the same, WooCommerce leaves price_html empty; still show the notice.
	 *
	 * @param mixed $data           Variation data.
	 * @param mixed $product_parent Variable product.
	 * @param mixed $variation      Variation.
	 * @return mixed
	 */
	public function filter_available_variation( $data, $product_parent, $variation ) {
		unset( $product_parent );
		if ( ! is_array( $data ) || ! $variation instanceof WC_Product || ! $this->enabled( 'variation' ) ) {
			return $data;
		}
		if ( isset( $data['price_html'] ) && '' === $data['price_html'] ) {
			$notice = $this->renderer->html( $variation, 'variation' );
			if ( '' !== $notice ) {
				$data['price_html'] = '<span class="price">' . $notice . '</span>';
			}
		}
		return $data;
	}

	/**
	 * Where the price is printed.
	 *
	 * @param WC_Product $product Product.
	 */
	private function context( WC_Product $product ): string {
		if ( $product->is_type( 'variation' ) ) {
			return 'variation';
		}
		if ( function_exists( 'is_product' ) && is_product() && get_queried_object_id() === $product->get_id() ) {
			return 'single';
		}
		return 'loop';
	}

	/**
	 * Whether a context is enabled in the settings.
	 *
	 * @param string $context Context.
	 */
	private function enabled( string $context ): bool {
		return match ( $context ) {
			'single'    => $this->settings->bool( 'show_single' ),
			'variation' => $this->settings->bool( 'show_variations' ),
			default     => $this->settings->bool( 'show_loop' ),
		};
	}
}
