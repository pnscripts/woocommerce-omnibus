<?php
/**
 * Decimal helpers for prices stored as strings.
 *
 * @package Pnscripts\Omnibus
 */

declare(strict_types=1);

namespace Pnscripts\Omnibus\Domain;

/**
 * Normalises and compares decimal price strings without WordPress.
 */
final class Money {

	/**
	 * Number of decimals kept in storage.
	 */
	public const SCALE = 6;

	/**
	 * Normalise a raw price into a fixed-scale decimal string.
	 *
	 * Returns null for empty, non-numeric or negative values (WooCommerce stores "no price" as an empty string).
	 *
	 * @param mixed $value Raw value.
	 */
	public static function normalize( mixed $value ): ?string {
		if ( null === $value || is_bool( $value ) || is_array( $value ) || is_object( $value ) ) {
			return null;
		}
		$value = trim( (string) $value );
		if ( '' === $value || ! is_numeric( $value ) ) {
			return null;
		}
		$number = (float) $value;
		if ( $number < 0 || is_nan( $number ) || is_infinite( $number ) ) {
			return null;
		}
		return number_format( round( $number, self::SCALE ), self::SCALE, '.', '' );
	}

	/**
	 * Compare two normalised prices.
	 *
	 * @param string $a First price.
	 * @param string $b Second price.
	 * @return int -1, 0 or 1.
	 */
	public static function compare( string $a, string $b ): int {
		$diff = round( (float) $a - (float) $b, self::SCALE );
		if ( abs( $diff ) < 0.0000005 ) {
			return 0;
		}
		return $diff < 0 ? -1 : 1;
	}

	/**
	 * Lower of two prices (null-safe).
	 *
	 * @param string|null $a First price.
	 * @param string      $b Second price.
	 */
	public static function min( ?string $a, string $b ): string {
		if ( null === $a ) {
			return $b;
		}
		return self::compare( $a, $b ) <= 0 ? $a : $b;
	}
}
