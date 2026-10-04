<?php
/**
 * Builds the "lowest price" notice.
 *
 * @package Pnscripts\Omnibus
 */

declare(strict_types=1);

namespace Pnscripts\Omnibus\Display;

use Pnscripts\Omnibus\Domain\ReferenceResult;
use Pnscripts\Omnibus\Reference\ReferenceService;
use Pnscripts\Omnibus\Settings;
use WC_Product;

defined( 'ABSPATH' ) || exit;

/**
 * Renders the notice HTML for a product.
 */
final class NoticeRenderer {

	public const STYLE_HANDLE = 'pnscripts-omnibus';

	/**
	 * Constructor.
	 *
	 * @param Settings         $settings Settings.
	 * @param ReferenceService $service  Reference prices.
	 */
	public function __construct(
		private readonly Settings $settings,
		private readonly ReferenceService $service
	) {
	}

	/**
	 * Default notice template.
	 */
	public static function default_text(): string {
		/* translators: Keep the placeholders {days} (number of days) and {price} (formatted price) unchanged. */
		return __( 'Lowest price in the {days} days before the discount: {price}', 'pnscripts-omnibus' );
	}

	/**
	 * Default template when the shorter period since launch is used.
	 */
	public static function default_text_short(): string {
		/* translators: Keep the placeholders {days} (number of days) and {price} (formatted price) unchanged. */
		return __( 'Lowest price since this product was launched ({days} days) before the discount: {price}', 'pnscripts-omnibus' );
	}

	/**
	 * Default template when the price is unknown and the shop chose to show a message.
	 */
	public static function default_unknown_text(): string {
		/* translators: Keep the placeholder {days} (number of days) unchanged. */
		return __( 'The lowest price in the {days} days before the discount is not available.', 'pnscripts-omnibus' );
	}

	/**
	 * Notice HTML, empty when nothing should be shown.
	 *
	 * @param WC_Product $product Simple product or variation.
	 * @param string     $context single, loop, variation or shortcode.
	 */
	public function html( WC_Product $product, string $context ): string {
		if ( ! $product->is_on_sale() ) {
			return '';
		}
		$result = $this->service->for_product( $product );
		$html   = '';

		if ( $result->is_known() ) {
			$shortened  = $result->shortened;
			$template   = $this->settings->string( $shortened ? 'notice_text_short' : 'notice_text' );
			$template   = '' !== $template ? $template : ( $shortened ? self::default_text_short() : self::default_text() );
			$price_html = wc_price( $this->service->display_price( $product, $result ) );
			$date       = null !== $result->anchor ? wp_date( (string) get_option( 'date_format' ), $result->anchor ) : '';
			$html       = self::format( $template, $price_html, $result->period_days, (string) $date );
		} elseif ( ReferenceResult::UNKNOWN === $result->status && ! $this->settings->bool( 'hide_when_unknown' ) ) {
			$template = $this->settings->string( 'unknown_text' );
			$template = '' !== $template ? $template : self::default_unknown_text();
			$html     = self::format( $template, '', $result->period_days, '' );
		}

		if ( '' !== $html ) {
			$html = sprintf(
				'<span class="pnscripts-omnibus-notice pnscripts-omnibus-notice--%1$s" data-status="%2$s">%3$s</span>',
				esc_attr( $context ),
				esc_attr( $result->status ),
				$html
			);
			self::enqueue_style();
		}

		/**
		 * Filters the notice HTML.
		 *
		 * @param string          $html    Notice HTML (may be empty).
		 * @param WC_Product      $product Product.
		 * @param ReferenceResult $result  Reference result.
		 * @param string          $context single, loop, variation or shortcode.
		 */
		$filtered = apply_filters( 'pnscripts_omnibus_notice_html', $html, $product, $result, $context );
		return is_string( $filtered ) ? $filtered : $html;
	}

	/**
	 * Enqueue the small stylesheet when a notice is printed. Block themes render templates before wp_head,
	 * so the style is registered here on demand rather than on wp_enqueue_scripts.
	 */
	public static function enqueue_style(): void {
		if ( ! wp_style_is( self::STYLE_HANDLE, 'registered' ) ) {
			wp_register_style( self::STYLE_HANDLE, PNSCRIPTS_OMNIBUS_URL . 'assets/css/frontend.css', array(), PNSCRIPTS_OMNIBUS_VERSION );
		}
		wp_enqueue_style( self::STYLE_HANDLE );
	}

	/**
	 * Replace placeholders. The template is plain text and is escaped; $price_html must already be safe HTML.
	 *
	 * Placeholders: {price}, {days}, {date}.
	 *
	 * @param string $template   Text template.
	 * @param string $price_html Formatted price HTML.
	 * @param int    $days       Days.
	 * @param string $date       Formatted reduction start date.
	 */
	public static function format( string $template, string $price_html, int $days, string $date ): string {
		$parts = explode( '{price}', $template );
		$parts = array_map(
			static fn ( string $part ): string => esc_html(
				strtr(
					$part,
					array(
						'{days}' => (string) $days,
						'{date}' => $date,
					)
				)
			),
			$parts
		);
		return implode( $price_html, $parts );
	}
}
