<?php
/**
 * Uninstall: removes data only when the shop opted in (Settings → Data → Uninstall).
 *
 * @package Pnscripts\Omnibus
 */

declare(strict_types=1);

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

/**
 * Remove the plugin's data from the current site when opted in.
 */
function pnscripts_omnibus_uninstall_site(): void {
	global $wpdb;

	$settings = get_option( 'pnscripts_omnibus_settings', array() );
	if ( ! is_array( $settings ) || empty( $settings['delete_data_on_uninstall'] ) ) {
		return;
	}

	$table = $wpdb->prefix . 'pnscripts_omnibus_price_history';
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange -- Dropping the plugin's own table on uninstall.
	$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $table ) );

	foreach ( array(
		'pnscripts_omnibus_settings',
		'pnscripts_omnibus_db_version',
		'pnscripts_omnibus_installed_at',
		'pnscripts_omnibus_paused_at',
		'pnscripts_omnibus_pending_job',
		'pnscripts_omnibus_resume',
		'pnscripts_omnibus_backfill_status',
		'pnscripts_omnibus_import_status',
	) as $option ) {
		delete_option( $option );
	}

	delete_post_meta_by_key( '_pnscripts_omnibus_perishable' );
	delete_metadata( 'term', 0, 'pnscripts_omnibus_perishable', '', true );

	if ( function_exists( 'as_unschedule_all_actions' ) ) {
		as_unschedule_all_actions( '', array(), 'pnscripts-omnibus' );
	}
}

if ( is_multisite() ) {
	foreach ( get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	) as $pnscripts_omnibus_site_id ) {
		switch_to_blog( (int) $pnscripts_omnibus_site_id );
		pnscripts_omnibus_uninstall_site();
		restore_current_blog();
	}
} else {
	pnscripts_omnibus_uninstall_site();
}
