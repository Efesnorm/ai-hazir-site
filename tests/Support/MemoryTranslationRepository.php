<?php
/**
 * In-memory translation storage for tests.
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\Tests\Support;

use AIHazirSite\Core\Contracts\ListingTranslationRepository;
use AIHazirSite\Core\Contracts\ProfileTranslationRepository;

/**
 * Listing and profile translations in memory.
 */
final class MemoryTranslationRepository implements ListingTranslationRepository, ProfileTranslationRepository {

	/**
	 * Listing id → translations.
	 *
	 * @var array<int, array<string, array<string, string>>>
	 */
	public array $listings = array();

	/**
	 * Profile translations.
	 *
	 * @var array<string, array<string, string>>
	 */
	public array $profile = array();

	/**
	 * Listing translations.
	 *
	 * @param int $id Id.
	 * @return array<string, array<string, string>>
	 */
	public function translations( int $id ): array {
		return $this->listings[ $id ] ?? array();
	}

	/**
	 * Stores listing translations.
	 *
	 * @param int                                  $id           Id.
	 * @param array<string, array<string, string>> $translations Translations.
	 */
	public function store_translations( int $id, array $translations ): void {
		$this->listings[ $id ] = $translations;
	}

	/**
	 * Profile translations.
	 *
	 * @return array<string, array<string, string>>
	 */
	public function profile_translations(): array {
		return $this->profile;
	}

	/**
	 * Stores profile translations.
	 *
	 * @param array<string, array<string, string>> $translations Translations.
	 */
	public function store_profile_translations( array $translations ): void {
		$this->profile = $translations;
	}
}
