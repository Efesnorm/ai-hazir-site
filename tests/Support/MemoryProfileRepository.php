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
	public function store_profile( CompanyProfile $profile ): void {
		$this->profile = $profile;
	}

	/**
	 * Removes.
	 */
	public function remove_profile(): void {
		$this->profile = null;
	}

	/**
	 * Earlier save time put back by restore_profile() (null: the fixed time applies).
	 *
	 * @var string|null
	 */
	public ?string $restored_at = null;

	/**
	 * Puts back an earlier state.
	 *
	 * @param CompanyProfile|null $profile    Profile.
	 * @param string|null         $updated_at Save time.
	 */
	public function restore_profile( ?CompanyProfile $profile, ?string $updated_at ): void {
		$this->profile     = $profile;
		$this->restored_at = $updated_at;
	}

	/**
	 * Fixed save time when a profile exists.
	 */
	public function updated_at(): ?string {
		return null === $this->profile ? null : ( $this->restored_at ?? '2026-09-27T12:00:00Z' );
	}
}
