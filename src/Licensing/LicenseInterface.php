<?php
/**
 * Licence abstraction for a future commercial add-on.
 *
 * @package Pnscripts\Omnibus
 */

declare(strict_types=1);

namespace Pnscripts\Omnibus\Licensing;

defined( 'ABSPATH' ) || exit;

/**
 * A licence never gates features of this (free) plugin; an add-on can provide its own implementation
 * through the pnscripts_omnibus_license filter to unlock the add-on's own features and updates.
 */
interface LicenseInterface {

	/**
	 * Plan identifier, "free" for this plugin.
	 */
	public function plan(): string;

	/**
	 * Whether a paid licence is active.
	 */
	public function is_active(): bool;

	/**
	 * Whether an add-on feature is available (always false in the free plugin).
	 *
	 * @param string $feature Feature id, e.g. "badge_override", "evidence_export", "multi_currency".
	 */
	public function has_feature( string $feature ): bool;
}
