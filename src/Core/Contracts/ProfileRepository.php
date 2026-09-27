<?php
/**
 * Company profile storage port.
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\Core\Contracts;

use AIHazirSite\Core\Catalog\CompanyProfile;

/**
 * Stores the single company profile. Write methods are called only by CatalogService.
 */
interface ProfileRepository {

	/**
	 * Stored profile (empty profile when none).
	 */
	public function get(): CompanyProfile;

	/**
	 * Replaces the profile.
	 *
	 * @param CompanyProfile $profile Valid profile.
	 */
	public function store_profile( CompanyProfile $profile ): void;

	/**
	 * Removes the profile.
	 */
	public function remove_profile(): void;

	/**
	 * Puts back an earlier stored state exactly: the profile and its save time
	 * (null profile = no profile). Used to undo a change.
	 *
	 * @param CompanyProfile|null $profile    Earlier profile.
	 * @param string|null         $updated_at Earlier save time.
	 */
	public function restore_profile( ?CompanyProfile $profile, ?string $updated_at ): void;

	/**
	 * When the profile was last saved (ISO 8601 UTC), or null.
	 */
	public function updated_at(): ?string;
}
