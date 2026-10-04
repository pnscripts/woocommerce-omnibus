<?php
/**
 * Rules used to compute the prior (reference) price.
 *
 * @package Pnscripts\Omnibus
 */

declare(strict_types=1);

namespace Pnscripts\Omnibus\Domain;

/**
 * Immutable set of rules.
 */
final class ReferencePolicy {

	/**
	 * Products on the market for less than the period: show nothing (strict default).
	 */
	public const NEW_PRODUCT_HIDE = 'hide';

	/**
	 * Products on the market for less than the period: use the period since launch.
	 */
	public const NEW_PRODUCT_SINCE_LAUNCH = 'since_launch';

	public const MIN_PERIOD_DAYS = 30;
	public const MAX_PERIOD_DAYS = 365;

	/**
	 * Period length in days.
	 *
	 * @var int
	 */
	public readonly int $period_days;

	/**
	 * Constructor.
	 *
	 * @param int    $period_days       Days before the reduction (at least 30).
	 * @param string $new_product_mode  One of the NEW_PRODUCT_* constants.
	 * @param int    $launch_tolerance  Seconds allowed between launch and the first record.
	 */
	public function __construct(
		int $period_days = self::MIN_PERIOD_DAYS,
		public readonly string $new_product_mode = self::NEW_PRODUCT_HIDE,
		public readonly int $launch_tolerance = 3600
	) {
		$this->period_days = max( self::MIN_PERIOD_DAYS, min( self::MAX_PERIOD_DAYS, $period_days ) );
	}
}
