<?php
/**
 * Custom table schema.
 *
 * @package Pnscripts\Omnibus
 */

declare(strict_types=1);

namespace Pnscripts\Omnibus\Storage;

/**
 * Creates, upgrades and drops the price history table.
 */
final class Schema {

	public const VERSION_OPTION = 'pnscripts_omnibus_db_version';

	/**
	 * Fully qualified table name.
	 */
	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'pnscripts_omnibus_price_history';
	}

	/**
	 * Create or upgrade the table when the stored schema version differs.
	 */
	public static function maybe_upgrade(): void {
		if ( (string) get_option( self::VERSION_OPTION, '' ) === PNSCRIPTS_OMNIBUS_DB_VERSION ) {
			return;
		}
		self::install();
	}

	/**
	 * Create or upgrade the table (dbDelta).
	 */
	public static function install(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table   = self::table();
		$collate = $wpdb->get_charset_collate();

		// All datetime columns are UTC.
		$sql = "CREATE TABLE {$table} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  product_id bigint(20) unsigned NOT NULL,
  parent_id bigint(20) unsigned NOT NULL DEFAULT 0,
  changed_at datetime NOT NULL,
  regular_price decimal(19,6) DEFAULT NULL,
  sale_price decimal(19,6) DEFAULT NULL,
  sale_from datetime DEFAULT NULL,
  sale_to datetime DEFAULT NULL,
  price decimal(19,6) DEFAULT NULL,
  on_sale tinyint(1) DEFAULT NULL,
  unknown_since datetime DEFAULT NULL,
  currency varchar(3) NOT NULL DEFAULT '',
  source varchar(40) NOT NULL DEFAULT '',
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY product_changed (product_id,changed_at),
  KEY parent_id (parent_id),
  KEY changed_at (changed_at)
) {$collate};";

		dbDelta( $sql );
		update_option( self::VERSION_OPTION, PNSCRIPTS_OMNIBUS_DB_VERSION, true );
	}
}
