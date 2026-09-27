<?php
/**
 * In-memory ProfileRepository for tests.
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\Tests\Support;

use AIHazirSite\Core\Catalog\CompanyProfile;
use AIHazirSite\Core\Contracts\ProfileRepository;

/**
 * Holds one profile in memory.
 */
final class MemoryProfileRepository implements ProfileRepository {

	/**
	 * Stored profile.
	 *
	 * @var CompanyProfile|null
	 */
	public ?CompanyProfile $profile = null;

	/**
	 * Stored or empty profile.
	 */
	public function get(): CompanyProfile {
		return $this->profile ?? new CompanyProfile();
	}

	/**
	 * Stores.
	 *
	 * @param CompanyProfile $profile Profile.
	 */
	public function save_profile( CompanyProfile $profile ): void {
		$this->profile = $profile;
	}

	/**
	 * Removes.
	 */
	public function delete_profile(): void {
		$this->profile = null;
	}
}
