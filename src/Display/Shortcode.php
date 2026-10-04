<?php
/**
 * [pnscripts_omnibus_price] shortcode.
 *
 * @package Pnscripts\Omnibus
 */

declare(strict_types=1);

namespace Pnscripts\Omnibus\Display;

use WC_Product;

/**
 * Prints the notice anywhere: [pnscripts_omnibus_price] for the current product or [pnscripts_omnibus_price id="123"].
 */
final class Shortcode {

	public const TAG = 'pnscripts_omnibus_price';

	/**
	 * Constructor.
	 *
	 * @param NoticeRenderer $renderer Renderer.
	 */
	public function __construct( private readonly NoticeRenderer $renderer ) {
	}

	/**
	 * Hook into WordPress.
	 */
	public function register(): void {
		add_shortcode( self::TAG, array( $this, 'render' ) );
	}

	/**
	 * Render the shortcode.
	 *
	 * @param array<string, string>|string $atts Attributes.
	 */
	public function render( $atts ): string {
		$atts = shortcode_atts( array( 'id' => '' ), is_array( $atts ) ? $atts : array(), self::TAG );
		$id   = absint( $atts['id'] );

		if ( 0 === $id ) {
			global $product;
			$current = $product instanceof WC_Product ? $product : wc_get_product( get_the_ID() );
		} else {
			$current = wc_get_product( $id );
		}

		if ( ! $current instanceof WC_Product || $current->is_type( array( 'variable', 'grouped' ) ) ) {
			return '';
		}
		if ( ! self::visible( $current ) ) {
			return '';
		}
		return $this->renderer->html( $current, 'shortcode' );
	}

	/**
	 * Whether the current visitor may see the product's price: the product and, for variations, its parent must
	 * be published and not password-protected, unless the visitor can edit them.
	 *
	 * @param WC_Product $product Product or variation.
	 */
	private static function visible( WC_Product $product ): bool {
		foreach ( array_filter( array( $product->get_id(), $product->get_parent_id() ) ) as $post_id ) {
			$post = get_post( $post_id );
			if ( ! $post instanceof \WP_Post ) {
				return false;
			}
			if ( current_user_can( 'edit_post', $post_id ) ) {
				continue;
			}
			if ( 'publish' !== $post->post_status || post_password_required( $post ) ) {
				return false;
			}
		}
		return true;
	}
}
