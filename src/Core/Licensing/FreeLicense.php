<?php
/**
 * The free tier.
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\Core\Licensing;

/**
 * Everything shipped today is free; no Pro capability exists yet.
 */
final class FreeLicense implements LicenseChecker {

	/**
	 * Current tier.
	 */
	public function tier(): string {
		return self::TIER_FREE;
	}

	/**
	 * No Pro capability is licensed.
	 *
	 * @param string $capability Capability key.
	 */
	public function allows( string $capability ): bool {
		return false;
	}
}
