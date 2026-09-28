<?php
/**
 * Profile translation storage port.
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\Core\Contracts;

/**
 * Entered translations of the company profile, as language → field → text. The write method is
 * called only by CatalogService (guarded by TranslationWritesOnlyThroughServiceTest).
 */
interface ProfileTranslationRepository {

	/**
	 * Stored translations of the profile (empty when none).
	 *
	 * @return array<string, array<string, string>>
	 */
	public function profile_translations(): array;

	/**
	 * Replaces the translations of the profile (an empty array removes them).
	 *
	 * @param array<string, array<string, string>> $translations Clean translations.
	 */
	public function store_profile_translations( array $translations ): void;
}
