<?php
/**
 * Listing translation storage port.
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\Core\Contracts;

/**
 * Entered translations of a listing, as language → field → text. The write method is called
 * only by CatalogService (guarded by TranslationWritesOnlyThroughServiceTest).
 */
interface ListingTranslationRepository {

	/**
	 * Stored translations of a listing (empty when none).
	 *
	 * @param int $id Listing id.
	 * @return array<string, array<string, string>>
	 */
	public function translations( int $id ): array;

	/**
	 * Replaces the translations of a listing (an empty array removes them).
	 *
	 * @param int                                  $id           Listing id.
	 * @param array<string, array<string, string>> $translations Clean translations.
	 */
	public function store_translations( int $id, array $translations ): void;
}
