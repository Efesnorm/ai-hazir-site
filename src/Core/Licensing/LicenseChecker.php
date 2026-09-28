<?php
/**
 * License check port (infrastructure only).
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\Core\Licensing;

/**
 * Whether a paid (Pro) capability is licensed. Pricing is an open decision (PRD), so no feature
 * is locked yet: the only implementation, FreeLicense, reports the free tier and licenses nothing.
 */
interface LicenseChecker {

	public const TIER_FREE = 'free';
	public const TIER_PRO  = 'pro';

	/**
	 * Current tier.
	 */
	public function tier(): string;

	/**
	 * Whether a Pro capability may be used.
	 *
	 * @param string $capability Capability key.
	 */
	public function allows( string $capability ): bool;
}
