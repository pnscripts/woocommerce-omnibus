<?php
/**
 * Product edit screen: price history metabox and perishable flag.
 *
 * @package Pnscripts\Omnibus
 */

declare(strict_types=1);

namespace Pnscripts\Omnibus\Admin;

use Pnscripts\Omnibus\Domain\PriceRecord;
use Pnscripts\Omnibus\Domain\ReferenceResult;
use Pnscripts\Omnibus\Plugin;
use Pnscripts\Omnibus\Reference\ReferenceService;
use Pnscripts\Omnibus\Storage\HistoryRepository;
use WC_Product;
use WP_Post;

defined( 'ABSPATH' ) || exit;

/**
 * Read-only history view plus the per-product perishable checkbox.
 */
final class ProductPanel {

	/**
	 * Constructor.
	 *
	 * @param Plugin $plugin Services.
	 */
	public function __construct( private readonly Plugin $plugin ) {
	}

	/**
	 * Hook into WordPress.
	 */
	public function register(): void {
		add_action( 'add_meta_boxes_product', array( $this, 'add_meta_box' ) );
		add_action( 'woocommerce_product_options_general_product_data', array( $this, 'perishable_field' ) );
		add_action( 'woocommerce_admin_process_product_object', array( $this, 'save_perishable' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	/**
	 * Admin stylesheet on product screens and the settings page.
	 *
	 * @param string $hook_suffix Screen hook.
	 */
	public function enqueue( $hook_suffix ): void {
		$screen = get_current_screen();
		if ( ( $screen && 'product' === $screen->id ) || str_contains( (string) $hook_suffix, 'pnscripts-pricetrail' ) ) {
			wp_enqueue_style( 'pnscripts-omnibus-admin', PNSCRIPTS_OMNIBUS_URL . 'assets/css/admin.css', array(), PNSCRIPTS_OMNIBUS_VERSION );
		}
		if ( str_contains( (string) $hook_suffix, 'pnscripts-pricetrail' ) ) {
			wp_enqueue_script( 'pnscripts-omnibus-admin', PNSCRIPTS_OMNIBUS_URL . 'assets/js/admin.js', array(), PNSCRIPTS_OMNIBUS_VERSION, true );
		}
	}

	/**
	 * Register the metabox.
	 */
	public function add_meta_box(): void {
		add_meta_box(
			'pnscripts-omnibus-history',
			__( 'Price history and lowest prior price', 'pnscripts-pricetrail' ),
			array( $this, 'render_meta_box' ),
			'product',
			'normal',
			'default'
		);
	}

	/**
	 * Render the metabox.
	 *
	 * @param WP_Post $post Product post.
	 */
	public function render_meta_box( $post ): void {
		$product = wc_get_product( $post->ID );
		if ( ! $product instanceof WC_Product ) {
			return;
		}
		echo '<div class="pnscripts-omnibus-panel">';
		echo '<p class="description">' . esc_html__( 'Recorded automatically on every price change. This plugin helps display prices; you remain responsible for compliance.', 'pnscripts-pricetrail' ) . '</p>';

		if ( $product->is_type( 'variable' ) ) {
			$children = $product->get_children();
			if ( array() === $children ) {
				echo '<p>' . esc_html__( 'No variations yet.', 'pnscripts-pricetrail' ) . '</p>';
			}
			foreach ( $children as $child_id ) {
				$variation = wc_get_product( $child_id );
				if ( $variation instanceof WC_Product ) {
					printf( '<h4>%s</h4>', esc_html( wp_strip_all_tags( $variation->get_formatted_name() ) ) );
					$this->render_product( $variation );
				}
			}
		} elseif ( $product->is_type( 'grouped' ) ) {
			echo '<p>' . esc_html__( 'Grouped products have no own price; see each child product.', 'pnscripts-pricetrail' ) . '</p>';
		} else {
			$this->render_product( $product );
		}
		echo '</div>';
	}

	/**
	 * Result and history table of one product or variation.
	 *
	 * @param WC_Product $product Product.
	 */
	private function render_product( WC_Product $product ): void {
		$result = $this->plugin->reference->for_product( $product );
		$this->render_result( $product, $result );

		$records = $this->plugin->repository->recent( $product->get_id(), 30 );
		if ( array() === $records ) {
			echo '<p>' . esc_html__( 'No prices recorded yet.', 'pnscripts-pricetrail' ) . '</p>';
			return;
		}
		echo '<table class="widefat striped pnscripts-omnibus-history"><thead><tr>';
		foreach ( array(
			__( 'From', 'pnscripts-pricetrail' ),
			__( 'Regular', 'pnscripts-pricetrail' ),
			__( 'Sale', 'pnscripts-pricetrail' ),
			__( 'Sale schedule', 'pnscripts-pricetrail' ),
			__( 'Price', 'pnscripts-pricetrail' ),
			__( 'Source', 'pnscripts-pricetrail' ),
		) as $heading ) {
			printf( '<th scope="col">%s</th>', esc_html( $heading ) );
		}
		echo '</tr></thead><tbody>';
		foreach ( $records as $record ) {
			$this->render_row( $record );
		}
		echo '</tbody></table>';
		echo '<p class="description">' . esc_html__( 'Prices as entered in the shop (before the tax display setting). Newest first, up to 30 rows.', 'pnscripts-pricetrail' ) . '</p>';
	}

	/**
	 * One history row.
	 *
	 * @param PriceRecord $record Record.
	 */
	private function render_row( PriceRecord $record ): void {
		$format   = get_option( 'date_format' ) . ' ' . get_option( 'time_format' );
		$schedule = '';
		if ( null !== $record->sale_from || null !== $record->sale_to ) {
			$schedule = sprintf(
				'%s – %s',
				null !== $record->sale_from ? wp_date( (string) get_option( 'date_format' ), $record->sale_from ) : '…',
				null !== $record->sale_to ? wp_date( (string) get_option( 'date_format' ), $record->sale_to ) : '…'
			);
		}
		echo '<tr>';
		printf( '<td>%s</td>', esc_html( (string) wp_date( $format, $record->changed_at ) ) );
		printf( '<td>%s</td>', null !== $record->regular ? wp_kses_post( wc_price( (float) $record->regular ) ) : '&mdash;' );
		printf( '<td>%s</td>', null !== $record->sale ? wp_kses_post( wc_price( (float) $record->sale ) ) : '&mdash;' );
		printf( '<td>%s</td>', '' !== $schedule ? esc_html( $schedule ) : '&mdash;' );
		printf( '<td>%s</td>', null !== $record->price ? wp_kses_post( wc_price( (float) $record->price ) ) : '&mdash;' );
		printf(
			'<td>%s%s</td>',
			esc_html( Labels::source( $record->source ) ),
			null !== $record->unknown_since ? ' <span class="pnscripts-omnibus-gap">' . esc_html__( '(unknown before)', 'pnscripts-pricetrail' ) . '</span>' : ''
		);
		echo '</tr>';
	}

	/**
	 * Computed result summary.
	 *
	 * @param WC_Product      $product Product.
	 * @param ReferenceResult $result  Result.
	 */
	private function render_result( WC_Product $product, ReferenceResult $result ): void {
		echo '<p class="pnscripts-omnibus-result pnscripts-omnibus-result--' . esc_attr( $result->status ) . '"><strong>' . esc_html( Labels::status( $result->status ) ) . '</strong>';
		if ( $result->is_known() ) {
			printf(
				' — %s %s',
				esc_html(
					sprintf(
						/* translators: 1: number of days, 2: date when the discount started */
						__( 'lowest price in the %1$d days before the discount that started on %2$s:', 'pnscripts-pricetrail' ),
						$result->period_days,
						null !== $result->anchor ? (string) wp_date( (string) get_option( 'date_format' ), $result->anchor ) : ''
					)
				),
				wp_kses_post( wc_price( $this->plugin->reference->display_price( $product, $result ) ) )
			);
		} elseif ( '' !== Labels::explain( $result ) ) {
			echo ' — ' . esc_html( Labels::explain( $result ) );
		}
		echo '</p>';
	}

	/**
	 * Perishable checkbox in the General tab.
	 */
	public function perishable_field(): void {
		if ( ! function_exists( 'woocommerce_wp_checkbox' ) ) {
			return;
		}
		woocommerce_wp_checkbox(
			array(
				'id'          => ReferenceService::PERISHABLE_META,
				'label'       => __( 'Perishable goods', 'pnscripts-pricetrail' ),
				'description' => __( 'Goods that perish or expire quickly. Only used when the perishable-goods exemption is enabled in Pricetrail settings.', 'pnscripts-pricetrail' ),
			)
		);
	}

	/**
	 * Save the perishable flag (WooCommerce verified the product form nonce before this action).
	 *
	 * @param WC_Product $product Product being saved.
	 */
	public function save_perishable( $product ): void {
		if ( ! $product instanceof WC_Product || ! current_user_can( 'edit_post', $product->get_id() ) ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified by WooCommerce (woocommerce_meta_nonce) before woocommerce_admin_process_product_object.
		$checked = isset( $_POST[ ReferenceService::PERISHABLE_META ] ) && 'yes' === sanitize_key( wp_unslash( $_POST[ ReferenceService::PERISHABLE_META ] ) );
		$product->update_meta_data( ReferenceService::PERISHABLE_META, $checked ? 'yes' : 'no' );
		HistoryRepository::bump_cache();
	}
}
