<?php
/**
 * Labels where a price write came from.
 *
 * @package Pnscripts\Omnibus
 */

declare(strict_types=1);

namespace Pnscripts\Omnibus\Capture;

/**
 * Best-effort, informational source label stored with each record.
 */
final class SourceDetector {

	/**
	 * Current source label.
	 */
	public function detect(): string {
		if ( doing_action( 'woocommerce_scheduled_sales' ) || doing_action( 'wc_product_start_scheduled_sale' ) || doing_action( 'wc_product_end_scheduled_sale' ) ) {
			return 'scheduled_sale';
		}
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			return 'cli';
		}
		if ( wp_doing_cron() || doing_action( 'action_scheduler_run_queue' ) ) {
			return 'cron';
		}
		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			return 'rest';
		}
		// phpcs:disable WordPress.Security.NonceVerification -- Read only to label the record; the request itself is verified by WooCommerce/WordPress.
		$action = isset( $_REQUEST['action'] ) && is_string( $_REQUEST['action'] ) ? sanitize_key( wp_unslash( $_REQUEST['action'] ) ) : '';
		if ( wp_doing_ajax() ) {
			if ( 'woocommerce_do_ajax_product_import' === $action ) {
				return 'csv_import';
			}
			if ( 'inline-save' === $action ) {
				return 'quick_edit';
			}
			if ( str_starts_with( $action, 'woocommerce_' ) && str_contains( $action, 'variation' ) ) {
				return 'admin';
			}
			return 'ajax';
		}
		if ( is_admin() ) {
			if ( isset( $_REQUEST['bulk_edit'] ) || doing_action( 'woocommerce_product_bulk_edit_save' ) ) {
				return 'bulk_edit';
			}
			return 'admin';
		}
		// phpcs:enable WordPress.Security.NonceVerification
		return 'programmatic';
	}
}
