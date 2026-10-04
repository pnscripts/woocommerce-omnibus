<?php
/**
 * Activation, deactivation, upgrades and WooCommerce feature declarations.
 *
 * @package Pnscripts\Omnibus
 */

declare(strict_types=1);

namespace Pnscripts\Omnibus;

use Automattic\WooCommerce\Utilities\FeaturesUtil;
use Pnscripts\Omnibus\Capture\PriceRecorder;
use Pnscripts\Omnibus\Jobs\Backfill;
use Pnscripts\Omnibus\Jobs\Queue;
use Pnscripts\Omnibus\Jobs\Retention;
use Pnscripts\Omnibus\Storage\Schema;

/**
 * Static lifecycle callbacks.
 */
final class Lifecycle {

	public const PAUSED_OPTION  = 'pnscripts_omnibus_paused_at';
	public const PENDING_OPTION = 'pnscripts_omnibus_pending_job';

	/**
	 * Declare compatibility with High-Performance Order Storage and the cart/checkout blocks.
	 */
	public static function declare_compatibility(): void {
		if ( class_exists( FeaturesUtil::class ) ) {
			FeaturesUtil::declare_compatibility( 'custom_order_tables', PNSCRIPTS_OMNIBUS_FILE, true );
			FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', PNSCRIPTS_OMNIBUS_FILE, true );
		}
	}

	/**
	 * Activation (single site; network sites are handled lazily by install_site()).
	 */
	public static function activate(): void {
		self::install_site();
		$paused = get_option( self::PAUSED_OPTION, false );
		if ( is_numeric( $paused ) && (int) $paused > 0 ) {
			update_option(
				PriceRecorder::RESUME_OPTION,
				array(
					'from' => (int) $paused,
					'to'   => time(),
				),
				true
			);
			update_option( self::PENDING_OPTION, Backfill::MODE_RESUME, true );
		}
		delete_option( self::PAUSED_OPTION );
	}

	/**
	 * Create the table and defaults for the current site when missing or outdated.
	 */
	public static function install_site(): void {
		$first = false === get_option( Schema::VERSION_OPTION, false );
		Schema::maybe_upgrade();
		if ( false === get_option( Settings::OPTION, false ) ) {
			add_option( Settings::OPTION, Settings::defaults(), '', true );
		}
		if ( $first ) {
			update_option( self::PENDING_OPTION, Backfill::MODE_BASELINE, true );
			add_option( 'pnscripts_omnibus_installed_at', time(), '', false );
		}
	}

	/**
	 * Deactivation: remember when tracking stopped and remove scheduled actions.
	 */
	public static function deactivate(): void {
		update_option( self::PAUSED_OPTION, time(), false );
		Queue::clear();
	}

	/**
	 * Start pending background jobs and keep the daily pruning scheduled (admin and cron requests only).
	 */
	public static function maybe_schedule(): void {
		if ( ! Queue::available() ) {
			return;
		}
		$pending = get_option( self::PENDING_OPTION, '' );
		if ( is_string( $pending ) && '' !== $pending ) {
			delete_option( self::PENDING_OPTION );
			Backfill::start( $pending );
		}
		Queue::daily( Retention::HOOK );
	}
}
