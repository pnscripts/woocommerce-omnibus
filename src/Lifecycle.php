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
use Pnscripts\Omnibus\Storage\HistoryRepository;
use Pnscripts\Omnibus\Storage\Schema;

defined( 'ABSPATH' ) || exit;

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
	 * Activation. Network-wide: every site of the network resumes tracking (tables and defaults are created
	 * lazily by install_site() on each site's next request).
	 *
	 * @param bool|mixed $network_wide Whether the plugin was network-activated.
	 */
	public static function activate( $network_wide = false ): void {
		if ( true === (bool) $network_wide && is_multisite() ) {
			self::for_each_site( array( self::class, 'resume_site' ) );
			return;
		}
		self::install_site();
		self::resume_site();
	}

	/**
	 * Run a callback on every site of the current network.
	 *
	 * @param callable $callback Callback.
	 */
	private static function for_each_site( callable $callback ): void {
		$ids = get_sites(
			array(
				'fields'     => 'ids',
				'number'     => 0,
				'network_id' => get_current_network_id(),
			)
		);
		foreach ( $ids as $id ) {
			switch_to_blog( (int) $id );
			$callback();
			restore_current_blog();
		}
	}

	/**
	 * Mark the time since tracking stopped as unchecked and queue the resume job. An earlier inactive period
	 * the resume job has not finished checking is merged in, never replaced.
	 */
	public static function resume_site(): void {
		$paused   = get_option( self::PAUSED_OPTION, false );
		$existing = PriceRecorder::resume_window();
		$from     = is_numeric( $paused ) && (int) $paused > 0 ? (int) $paused : null;
		if ( null !== $existing ) {
			$from = null === $from ? $existing['from'] : min( $from, $existing['from'] );
		}
		if ( null !== $from ) {
			update_option(
				PriceRecorder::RESUME_OPTION,
				array(
					'from' => $from,
					'to'   => time(),
				),
				true
			);
			update_option( self::PENDING_OPTION, Backfill::MODE_RESUME, true );
		}
		delete_option( self::PAUSED_OPTION );
		HistoryRepository::bump_cache();
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
	 * Deactivation: remember when tracking stopped and remove scheduled actions (on every site when
	 * network-deactivated, otherwise their gaps would go unnoticed).
	 *
	 * @param bool|mixed $network_wide Whether the plugin was network-deactivated.
	 */
	public static function deactivate( $network_wide = false ): void {
		if ( true === (bool) $network_wide && is_multisite() ) {
			self::for_each_site( array( self::class, 'pause_site' ) );
			return;
		}
		self::pause_site();
	}

	/**
	 * Remember when tracking stopped on the current site (the earliest pause wins) and remove its actions.
	 */
	public static function pause_site(): void {
		$paused = get_option( self::PAUSED_OPTION, false );
		$now    = time();
		update_option( self::PAUSED_OPTION, is_numeric( $paused ) && (int) $paused > 0 ? min( (int) $paused, $now ) : $now, false );
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
