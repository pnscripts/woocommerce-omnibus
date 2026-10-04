<?php
/**
 * System time source.
 *
 * @package Pnscripts\Omnibus
 */

declare(strict_types=1);

namespace Pnscripts\Omnibus\Domain;

/**
 * Real time.
 */
final class SystemClock implements Clock {

	/**
	 * Current UTC timestamp.
	 */
	public function now(): int {
		return time();
	}
}
