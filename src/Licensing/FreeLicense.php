<?php
/**
 * No-op licence of the free plugin.
 *
 * @package Pnscripts\Omnibus
 */

declare(strict_types=1);

namespace Pnscripts\Omnibus\Licensing;

/**
 * Free plan: no remote calls, no keys, nothing locked.
 */
final class FreeLicense implements LicenseInterface {

	/**
	 * Plan identifier.
	 */
	public function plan(): string {
		return 'free';
	}

	/**
	 * Whether a paid licence is active.
	 */
	public function is_active(): bool {
		return false;
	}

	/**
	 * Add-on features are not part of the free plugin.
	 *
	 * @param string $feature Feature id.
	 */
	public function has_feature( string $feature ): bool {
		unset( $feature );
		return false;
	}
}
