<?php
/**
 * Human-readable admin labels.
 *
 * @package Pnscripts\Omnibus
 */

declare(strict_types=1);

namespace Pnscripts\Omnibus\Admin;

use Pnscripts\Omnibus\Domain\ReferenceResult;

/**
 * Translated labels for statuses, reasons and sources.
 */
final class Labels {

	/**
	 * Status label.
	 *
	 * @param string $status Status.
	 */
	public static function status( string $status ): string {
		return match ( $status ) {
			ReferenceResult::KNOWN       => __( 'Shown', 'pnscripts-omnibus' ),
			ReferenceResult::NOT_ON_SALE => __( 'Not on sale', 'pnscripts-omnibus' ),
			ReferenceResult::EXEMPT      => __( 'Exempt', 'pnscripts-omnibus' ),
			default                      => __( 'Unknown, hidden', 'pnscripts-omnibus' ),
		};
	}

	/**
	 * Explanation of a result.
	 *
	 * @param ReferenceResult $result Result.
	 */
	public static function explain( ReferenceResult $result ): string {
		return match ( $result->reason ) {
			ReferenceResult::REASON_NO_HISTORY          => __( 'No price history has been recorded yet.', 'pnscripts-omnibus' ),
			ReferenceResult::REASON_GAP                 => __( 'Prices during part of the period are unknown (the plugin was inactive or prices were changed outside WooCommerce).', 'pnscripts-omnibus' ),
			ReferenceResult::REASON_INCOMPLETE          => __( 'The recorded history does not cover the whole period before the discount yet.', 'pnscripts-omnibus' ),
			ReferenceResult::REASON_NEW_PRODUCT         => __( 'The product was launched less than the period before the discount and the current rules hide the notice for such products.', 'pnscripts-omnibus' ),
			ReferenceResult::REASON_START_UNKNOWN       => __( 'The product was already on sale when recording started, so the start of the discount is unknown.', 'pnscripts-omnibus' ),
			ReferenceResult::REASON_NO_PRIOR_PRICE      => __( 'There is no price before the discount (the product was launched on sale or had no price).', 'pnscripts-omnibus' ),
			ReferenceResult::REASON_NOT_CAPTURED        => __( 'WooCommerce shows a discount that is not in the recorded prices (for example a price changed by another plugin on the fly).', 'pnscripts-omnibus' ),
			ReferenceResult::REASON_PERISHABLE          => __( 'Marked as perishable and the perishable-goods exemption is enabled.', 'pnscripts-omnibus' ),
			ReferenceResult::REASON_UNSUPPORTED_PRODUCT => __( 'Variable and grouped products are evaluated per variation or child product.', 'pnscripts-omnibus' ),
			ReferenceResult::REASON_TAX_CHANGED         => __( 'The tax settings or rates changed during the period, so earlier prices cannot be converted with today\'s taxes.', 'pnscripts-omnibus' ),
			default                                     => '',
		};
	}

	/**
	 * Source label.
	 *
	 * @param string $source Source id.
	 */
	public static function source( string $source ): string {
		$base = explode( ':meta', $source )[0];
		$map  = array(
			'admin'                   => __( 'Product editor', 'pnscripts-omnibus' ),
			'quick_edit'              => __( 'Quick edit', 'pnscripts-omnibus' ),
			'bulk_edit'               => __( 'Bulk edit', 'pnscripts-omnibus' ),
			'csv_import'              => __( 'CSV import', 'pnscripts-omnibus' ),
			'rest'                    => __( 'REST API', 'pnscripts-omnibus' ),
			'cli'                     => __( 'WP-CLI', 'pnscripts-omnibus' ),
			'cron'                    => __( 'Background task', 'pnscripts-omnibus' ),
			'scheduled_sale'          => __( 'Scheduled sale', 'pnscripts-omnibus' ),
			'ajax'                    => __( 'AJAX', 'pnscripts-omnibus' ),
			'programmatic'            => __( 'Code', 'pnscripts-omnibus' ),
			'baseline'                => __( 'First record', 'pnscripts-omnibus' ),
			'resume'                  => __( 'After inactivity', 'pnscripts-omnibus' ),
			'repair'                  => __( 'Repair', 'pnscripts-omnibus' ),
			'import:omnibus'          => __( 'Imported (Omnibus by iWorks)', 'pnscripts-omnibus' ),
			'import:wc-price-history' => __( 'Imported (WC Price History)', 'pnscripts-omnibus' ),
			'import:omnibus-by-ilabs' => __( 'Imported (Omnibus by iLabs)', 'pnscripts-omnibus' ),
		);
		return $map[ $base ] ?? $source;
	}
}
