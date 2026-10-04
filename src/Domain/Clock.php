<?php
/**
 * Time source.
 *
 * @package Pnscripts\Omnibus
 */

declare(strict_types=1);

namespace Pnscripts\Omnibus\Domain;

/**
 * Returns the current UTC timestamp. Replaceable in tests through the pnscripts_omnibus_now filter.
 */
interface Clock {

	/**
	 * Current UTC timestamp.
	 */
	public function now(): int;
}
