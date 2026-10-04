<?php
/**
 * Remembers when the tax configuration changed.
 *
 * @package Pnscripts\Omnibus
 */

declare(strict_types=1);

namespace Pnscripts\Omnibus\Capture;

use Pnscripts\Omnibus\Storage\HistoryRepository;

/**
 * Stored prices are kept as entered. They are converted for display with the tax settings of today, so a change
 * of the tax rates (when entered and displayed prices differ in tax treatment) or of the "prices entered with tax"
 * and "enable taxes" settings changes what an older stored amount means. The moment of such a change is
 * remembered; reference prices whose period reaches back before it are not shown.
 */
final class TaxWatcher {

	public const OPTION = 'pnscripts_omnibus_tax_changed_at';

	/**
	 * Hook into WordPress.
	 */
	public function register(): void {
		add_action( 'woocommerce_tax_rate_added', array( $this, 'on_rate_changed' ) );
		add_action( 'woocommerce_tax_rate_updated', array( $this, 'on_rate_changed' ) );
		add_action( 'woocommerce_tax_rate_deleted', array( $this, 'on_rate_changed' ) );
		foreach ( array( 'woocommerce_prices_include_tax', 'woocommerce_calc_taxes' ) as $option ) {
			add_action( 'update_option_' . $option, array( $this, 'mark' ) );
			add_action( 'add_option_' . $option, array( $this, 'mark' ) );
		}
	}

	/**
	 * A tax rate changed. Irrelevant only when prices are entered and shown with tax (the gross amount the
	 * customer paid does not change) or taxes are disabled.
	 */
	public function on_rate_changed(): void {
		if ( 'yes' !== get_option( 'woocommerce_calc_taxes' ) ) {
			return;
		}
		$entered_gross = 'yes' === get_option( 'woocommerce_prices_include_tax' );
		$shown_gross   = 'incl' === get_option( 'woocommerce_tax_display_shop' );
		if ( $entered_gross && $shown_gross ) {
			return;
		}
		$this->mark();
	}

	/**
	 * Remember now as the latest tax change.
	 */
	public function mark(): void {
		update_option( self::OPTION, time(), true );
		HistoryRepository::bump_cache();
	}

	/**
	 * Latest tax change (UTC timestamp), null when none was seen.
	 */
	public static function changed_at(): ?int {
		$value = get_option( self::OPTION, null );
		return is_numeric( $value ) && (int) $value > 0 ? (int) $value : null;
	}
}
