<?php
/**
 * Read-only readers for price history stored by other plugins.
 *
 * @package Pnscripts\Omnibus
 */

declare(strict_types=1);

namespace Pnscripts\Omnibus\Import;

use Pnscripts\Omnibus\Domain\PriceRecord;

defined( 'ABSPATH' ) || exit;

/**
 * Knows where three popular plugins store their history and reads it in id-ordered batches.
 * Nothing is ever written to their data.
 *
 * Layouts were taken from the public plugin source on WordPress.org:
 * - omnibus (iWorks) 3.0.4: post type iw_omnibus_price_log (v3) and post meta _iwo_price_* (v2);
 * - wc-price-history 3.2.6: table {prefix}wc_price_history (2.0+) and post meta _wc_price_history (legacy);
 * - omnibus-by-ilabs 2.0.2: post meta omnibus_by_ilabs_prices_history.
 *
 * phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- One-off batched reads of other plugins' data.
 */
final class Importers {

	/**
	 * Groups shown in the admin: group id => reader ids.
	 */
	public const GROUPS = array(
		'omnibus'          => array( 'iworks-v3', 'iworks-v2' ),
		'wc-price-history' => array( 'wcph-table', 'wcph-meta' ),
		'omnibus-by-ilabs' => array( 'ilabs' ),
	);

	private const IWORKS_V2_KEYS = array( '_iwo_price_lowest', '_iwo_price_log', '_iwo_price_last_change' );

	/**
	 * Human group labels.
	 *
	 * @return array<string, string>
	 */
	public static function labels(): array {
		return array(
			'omnibus'          => __( 'Omnibus — show the lowest price (by iWorks)', 'pnscripts-price-history' ),
			'wc-price-history' => __( 'WC Price History for Omnibus', 'pnscripts-price-history' ),
			'omnibus-by-ilabs' => __( 'Omnibus by iLabs', 'pnscripts-price-history' ),
		);
	}

	/**
	 * Source label stored with imported records.
	 *
	 * @param string $group Group id.
	 */
	public static function source( string $group ): string {
		return 'import:' . $group;
	}

	/**
	 * Number of source items found for a group (posts, meta rows or table rows).
	 *
	 * @param string $group Group id.
	 */
	public function count( string $group ): int {
		$total = 0;
		foreach ( self::GROUPS[ $group ] ?? array() as $reader ) {
			$total += $this->count_reader( $reader );
		}
		return $total;
	}

	/**
	 * Count items of one reader.
	 *
	 * @param string $reader Reader id.
	 */
	private function count_reader( string $reader ): int {
		global $wpdb;
		switch ( $reader ) {
			case 'iworks-v3':
				return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = %s", 'iw_omnibus_price_log' ) );
			case 'iworks-v2':
				return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key IN (%s, %s, %s)", self::IWORKS_V2_KEYS[0], self::IWORKS_V2_KEYS[1], self::IWORKS_V2_KEYS[2] ) );
			case 'wcph-table':
				return $this->wcph_table_exists() ? (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $wpdb->prefix . 'wc_price_history' ) ) : 0;
			case 'wcph-meta':
				return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = %s", '_wc_price_history' ) );
			case 'ilabs':
				return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = %s", 'omnibus_by_ilabs_prices_history' ) );
		}
		return 0;
	}

	/**
	 * Read one batch.
	 *
	 * @param string $reader Reader id.
	 * @param string $source Source label for the records.
	 * @param int    $after  Continue after this id (post id, meta id or row id).
	 * @param int    $limit  Batch size.
	 * @return array{next: int|null, items: list<array{product_id: int, record: PriceRecord}>}
	 */
	public function batch( string $reader, string $source, int $after, int $limit ): array {
		global $wpdb;
		$items = array();
		$last  = null;
		$rows  = array();

		switch ( $reader ) {
			case 'iworks-v3':
				$rows = $wpdb->get_results(
					$wpdb->prepare(
						"SELECT ID AS row_id, post_parent AS product_id, post_date_gmt FROM {$wpdb->posts} WHERE post_type = %s AND ID > %d ORDER BY ID ASC LIMIT %d",
						'iw_omnibus_price_log',
						$after,
						$limit
					),
					ARRAY_A
				);
				$rows = is_array( $rows ) ? $rows : array();
				update_meta_cache( 'post', array_map( static fn ( array $row ): int => (int) $row['row_id'], $rows ) );
				foreach ( $rows as $row ) {
					$meta = array();
					foreach ( array( 'price', 'price_regular', 'price_sale', 'timestamp' ) as $key ) {
						$meta[ $key ] = get_post_meta( (int) $row['row_id'], $key, true );
					}
					$record = ImportMapper::iworks_log( $meta, (string) $row['post_date_gmt'], $source );
					if ( null !== $record ) {
						$items[] = array(
							'product_id' => (int) $row['product_id'],
							'record'     => $record,
						);
					}
				}
				break;

			case 'iworks-v2':
				$rows = $wpdb->get_results(
					$wpdb->prepare(
						"SELECT meta_id AS row_id, post_id AS product_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key IN (%s, %s, %s) AND meta_id > %d ORDER BY meta_id ASC LIMIT %d",
						self::IWORKS_V2_KEYS[0],
						self::IWORKS_V2_KEYS[1],
						self::IWORKS_V2_KEYS[2],
						$after,
						$limit
					),
					ARRAY_A
				);
				$rows = is_array( $rows ) ? $rows : array();
				foreach ( $rows as $row ) {
					$record = ImportMapper::iworks_meta( self::unserialize( $row['meta_value'] ), $source );
					if ( null !== $record ) {
						$items[] = array(
							'product_id' => (int) $row['product_id'],
							'record'     => $record,
						);
					}
				}
				break;

			case 'wcph-table':
				if ( ! $this->wcph_table_exists() ) {
					return array(
						'next'  => null,
						'items' => array(),
					);
				}
				$rows = $wpdb->get_results(
					$wpdb->prepare(
						'SELECT id AS row_id, product_id, price, sale_price, date_gmt, include_in_history FROM %i WHERE id > %d ORDER BY id ASC LIMIT %d',
						$wpdb->prefix . 'wc_price_history',
						$after,
						$limit
					),
					ARRAY_A
				);
				$rows = is_array( $rows ) ? $rows : array();
				foreach ( $rows as $row ) {
					$record = ImportMapper::wc_price_history_row( $row, $source );
					if ( null !== $record ) {
						$items[] = array(
							'product_id' => (int) $row['product_id'],
							'record'     => $record,
						);
					}
				}
				break;

			case 'wcph-meta':
			case 'ilabs':
				$key    = 'wcph-meta' === $reader ? '_wc_price_history' : 'omnibus_by_ilabs_prices_history';
				$rows   = $wpdb->get_results(
					$wpdb->prepare(
						"SELECT meta_id AS row_id, post_id AS product_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_id > %d ORDER BY meta_id ASC LIMIT %d",
						$key,
						$after,
						max( 1, intdiv( $limit, 10 ) )
					),
					ARRAY_A
				);
				$rows   = is_array( $rows ) ? $rows : array();
				$offset = (float) get_option( 'gmt_offset', 0 );
				foreach ( $rows as $row ) {
					$value   = self::unserialize( $row['meta_value'] );
					$records = 'wcph-meta' === $reader
						? ImportMapper::wc_price_history_meta( $value, (float) (int) $offset, $source )
						: ImportMapper::ilabs_meta( $value, $source );
					foreach ( $records as $record ) {
						$items[] = array(
							'product_id' => (int) $row['product_id'],
							'record'     => $record,
						);
					}
				}
				$limit = max( 1, intdiv( $limit, 10 ) );
				break;
		}

		if ( array() !== $rows ) {
			$last_row = end( $rows );
			$last     = (int) $last_row['row_id'];
		}

		return array(
			'next'  => count( $rows ) === $limit ? $last : null,
			'items' => $items,
		);
	}

	/**
	 * Whether the WC Price History table exists.
	 */
	private function wcph_table_exists(): bool {
		global $wpdb;
		$table = $wpdb->prefix . 'wc_price_history';
		return $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
	}

	/**
	 * Unserialize foreign data without instantiating objects.
	 *
	 * @param mixed $value Raw meta value.
	 * @return mixed
	 */
	private static function unserialize( mixed $value ): mixed {
		if ( ! is_string( $value ) || ! is_serialized( $value ) ) {
			return $value;
		}
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize,WordPress.PHP.NoSilencedErrors.Discouraged -- Classes are disabled to avoid object injection.
		return @unserialize( trim( $value ), array( 'allowed_classes' => false ) );
	}
}
