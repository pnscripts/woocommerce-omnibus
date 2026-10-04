<?php
/**
 * Fixed time source.
 *
 * @package Pnscripts\Omnibus
 */

declare(strict_types=1);

namespace Pnscripts\Omnibus\Domain;

/**
 * Always returns the same moment.
 */
final class FixedClock implements Clock {

	/**
	 * Constructor.
	 *
	 * @param int $timestamp UTC timestamp.
	 */
	public function __construct( private readonly int $timestamp ) {
	}

	/**
	 * Current UTC timestamp.
	 */
	public function now(): int {
		return $this->timestamp;
	}
}
