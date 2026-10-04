<?php
/**
 * Maps other plugins' stored price data to records.
 *
 * @package Pnscripts\Omnibus
 */

declare(strict_types=1);

namespace Pnscripts\Omnibus\Import;

use DateTimeImmutable;
use DateTimeZone;
use Exception;
use Pnscripts\Omnibus\Domain\Money;
use Pnscripts\Omnibus\Domain\PriceRecord;

defined( 'ABSPATH' ) || exit;

/**
 * Pure mapping functions (no WordPress), one per known data layout. Unparseable entries are skipped.
 */
final class ImportMapper {

	/**
	 * "Omnibus" by iWorks, version 2 post meta (_iwo_price_lowest, _iwo_price_log, _iwo_price_last_change):
	 * serialized arrays with price, optional price_regular / price_sale, and a Unix timestamp.
	 *
	 * @param mixed  $data   Unserialized meta value.
	 * @param string $source Source label.
	 */
	public static function iworks_meta( mixed $data, string $source ): ?PriceRecord {
		if ( ! is_array( $data ) || ! isset( $data['timestamp'] ) || ! is_numeric( $data['timestamp'] ) ) {
			return null;
		}
		return self::iworks_values( $data, (int) $data['timestamp'], $source );
	}

	/**
	 * "Omnibus" by iWorks, version 3 price log post (post type iw_omnibus_price_log): meta values plus post date.
	 *
	 * @param array<string, mixed> $meta          Single meta values of the log post.
	 * @param string               $post_date_gmt Log post date (UTC).
	 * @param string               $source        Source label.
	 */
	public static function iworks_log( array $meta, string $post_date_gmt, string $source ): ?PriceRecord {
		$at = null;
		if ( isset( $meta['timestamp'] ) && is_numeric( $meta['timestamp'] ) ) {
			$at = (int) $meta['timestamp'];
		} elseif ( '' !== $post_date_gmt && ! str_starts_with( $post_date_gmt, '0000' ) ) {
			$at = self::utc( $post_date_gmt );
		}
		return null === $at ? null : self::iworks_values( $meta, $at, $source );
	}

	/**
	 * "WC Price History" table row (wp_wc_price_history): price = regular price, sale_price nullable, date_gmt.
	 *
	 * @param array<string, mixed> $row    Row.
	 * @param string               $source Source label.
	 */
	public static function wc_price_history_row( array $row, string $source ): ?PriceRecord {
		$at      = is_string( $row['date_gmt'] ?? null ) ? self::utc( $row['date_gmt'] ) : null;
		$regular = Money::normalize( $row['price'] ?? null );
		if ( null === $at || null === $regular ) {
			return null;
		}
		if ( isset( $row['include_in_history'] ) && is_numeric( $row['include_in_history'] ) && 0 === (int) $row['include_in_history'] ) {
			return null;
		}
		$sale  = Money::normalize( $row['sale_price'] ?? null );
		$price = null !== $sale && Money::compare( $sale, $regular ) < 0 ? $sale : $regular;
		return new PriceRecord( $at, $regular, $sale, null, null, $price, null, null, $source );
	}

	/**
	 * "WC Price History" legacy post meta (_wc_price_history): array of local-time timestamp => effective price.
	 *
	 * The plugin stored time() + gmt_offset hours, so the offset is subtracted again.
	 *
	 * @param mixed  $history     Unserialized meta value.
	 * @param float  $gmt_offset  Shop UTC offset in hours as used by that plugin.
	 * @param string $source      Source label.
	 * @return list<PriceRecord>
	 */
	public static function wc_price_history_meta( mixed $history, float $gmt_offset, string $source ): array {
		if ( ! is_array( $history ) ) {
			return array();
		}
		$records = array();
		foreach ( $history as $timestamp => $price ) {
			$price = Money::normalize( $price );
			if ( ! is_numeric( $timestamp ) || null === $price || 0 === Money::compare( $price, '0' ) ) {
				continue;
			}
			$at        = (int) $timestamp - (int) round( $gmt_offset * 3600 );
			$records[] = new PriceRecord( $at, null, null, null, null, $price, null, null, $source );
		}
		return $records;
	}

	/**
	 * "Omnibus by iLabs" post meta (omnibus_by_ilabs_prices_history): list of
	 * [0 => price, 1 => 'Y-m-d H:i:s' (UTC), 2 => is_on_sale, 3 => is_purchasable].
	 *
	 * @param mixed  $entries Unserialized meta value.
	 * @param string $source  Source label.
	 * @return list<PriceRecord>
	 */
	public static function ilabs_meta( mixed $entries, string $source ): array {
		if ( ! is_array( $entries ) ) {
			return array();
		}
		$records = array();
		foreach ( $entries as $entry ) {
			if ( ! is_array( $entry ) || ! isset( $entry[0], $entry[1] ) || ! is_string( $entry[1] ) ) {
				continue;
			}
			$price = Money::normalize( $entry[0] );
			$at    = self::utc( $entry[1] );
			if ( null === $price || null === $at ) {
				continue;
			}
			$on_sale   = isset( $entry[2] ) ? (bool) $entry[2] : null;
			$records[] = new PriceRecord( $at, null, null, null, null, $price, $on_sale, null, $source );
		}
		return $records;
	}

	/**
	 * Shared iWorks value mapping.
	 *
	 * @param array<mixed> $data   Values.
	 * @param int          $at     Timestamp.
	 * @param string       $source Source label.
	 */
	private static function iworks_values( array $data, int $at, string $source ): ?PriceRecord {
		if ( $at <= 0 ) {
			return null;
		}
		$regular = Money::normalize( $data['price_regular'] ?? null );
		$sale    = Money::normalize( $data['price_sale'] ?? null );
		$price   = Money::normalize( $data['price'] ?? null );
		if ( null !== $regular ) {
			$effective = null !== $sale && Money::compare( $sale, $regular ) < 0 ? $sale : $regular;
			return new PriceRecord( $at, $regular, $sale, null, null, $effective, null, null, $source );
		}
		if ( null === $price ) {
			return null;
		}
		return new PriceRecord( $at, null, null, null, null, $price, null, null, $source );
	}

	/**
	 * Parse a UTC datetime string.
	 *
	 * @param string $value Datetime.
	 */
	private static function utc( string $value ): ?int {
		if ( '' === trim( $value ) || str_starts_with( $value, '0000' ) ) {
			return null;
		}
		try {
			return ( new DateTimeImmutable( $value, new DateTimeZone( 'UTC' ) ) )->getTimestamp();
		} catch ( Exception $e ) {
			return null;
		}
	}
}
