<?php
/**
 * Human-readable admin labels.
 *
 * @package Pnscripts\Omnibus
 */

declare(strict_types=1);

namespace Pnscripts\Omnibus\Admin;

use Pnscripts\Omnibus\Domain\ReferenceResult;

defined( 'ABSPATH' ) || exit;

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
			ReferenceResult::KNOWN       => __( 'Shown', 'pnscripts-pricetrail' ),
			ReferenceResult::NOT_ON_SALE => __( 'Not on sale', 'pnscripts-pricetrail' ),
			ReferenceResult::EXEMPT      => __( 'Exempt', 'pnscripts-pricetrail' ),
			default                      => __( 'Unknown, hidden', 'pnscripts-pricetrail' ),
		};
	}

	/**
	 * Explanation of a result.
	 *
	 * @param ReferenceResult $result Result.
	 */
	public static function explain( ReferenceResult $result ): string {
		return match ( $result->reason ) {
			ReferenceResult::REASON_NO_HISTORY          => __( 'No price history has been recorded yet.', 'pnscripts-pricetrail' ),
			ReferenceResult::REASON_GAP                 => __( 'Prices during part of the period are unknown (the plugin was inactive or prices were changed outside WooCommerce).', 'pnscripts-pricetrail' ),
			ReferenceResult::REASON_INCOMPLETE          => __( 'The recorded history does not cover the whole period before the discount yet.', 'pnscripts-pricetrail' ),
			ReferenceResult::REASON_NEW_PRODUCT         => __( 'The product was launched less than the period before the discount and the current rules hide the notice for such products.', 'pnscripts-pricetrail' ),
			ReferenceResult::REASON_START_UNKNOWN       => __( 'The product was already on sale when recording started, so the start of the discount is unknown.', 'pnscripts-pricetrail' ),
			ReferenceResult::REASON_NO_PRIOR_PRICE      => __( 'There is no price before the discount (the product was launched on sale or had no price).', 'pnscripts-pricetrail' ),
			ReferenceResult::REASON_NOT_CAPTURED        => __( 'WooCommerce shows a discount that is not in the recorded prices (for example a price changed by another plugin on the fly).', 'pnscripts-pricetrail' ),
			ReferenceResult::REASON_PERISHABLE          => __( 'Marked as perishable and the perishable-goods exemption is enabled.', 'pnscripts-pricetrail' ),
			ReferenceResult::REASON_UNSUPPORTED_PRODUCT => __( 'Variable and grouped products are evaluated per variation or child product.', 'pnscripts-pricetrail' ),
			ReferenceResult::REASON_TAX_CHANGED         => __( 'The tax settings or rates changed during the period, so earlier prices cannot be converted with today\'s taxes.', 'pnscripts-pricetrail' ),
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
			'admin'                   => __( 'Product editor', 'pnscripts-pricetrail' ),
			'quick_edit'              => __( 'Quick edit', 'pnscripts-pricetrail' ),
			'bulk_edit'               => __( 'Bulk edit', 'pnscripts-pricetrail' ),
			'csv_import'              => __( 'CSV import', 'pnscripts-pricetrail' ),
			'rest'                    => __( 'REST API', 'pnscripts-pricetrail' ),
			'cli'                     => __( 'WP-CLI', 'pnscripts-pricetrail' ),
			'cron'                    => __( 'Background task', 'pnscripts-pricetrail' ),
			'scheduled_sale'          => __( 'Scheduled sale', 'pnscripts-pricetrail' ),
			'ajax'                    => __( 'AJAX', 'pnscripts-pricetrail' ),
			'programmatic'            => __( 'Code', 'pnscripts-pricetrail' ),
			'baseline'                => __( 'First record', 'pnscripts-pricetrail' ),
			'resume'                  => __( 'After inactivity', 'pnscripts-pricetrail' ),
			'repair'                  => __( 'Repair', 'pnscripts-pricetrail' ),
			'import:omnibus'          => __( 'Imported (Omnibus by iWorks)', 'pnscripts-pricetrail' ),
			'import:wc-price-history' => __( 'Imported (WC Price History)', 'pnscripts-pricetrail' ),
			'import:omnibus-by-ilabs' => __( 'Imported (Omnibus by iLabs)', 'pnscripts-pricetrail' ),
		);
		return $map[ $base ] ?? $source;
	}
}
