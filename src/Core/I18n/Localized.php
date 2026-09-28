<?php
/**
 * A catalog record in a requested language.
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\Core\I18n;

use AIHazirSite\Core\Catalog\CompanyProfile;
use AIHazirSite\Core\Catalog\Listing;

/**
 * The record with translated fields in place, the language it was asked in, and the
 * translatable fields that had no translation (those kept the default-language text).
 */
final class Localized {

	/**
	 * Constructor.
	 *
	 * @param Listing|CompanyProfile $record   Record with translated values.
	 * @param string                 $language Requested language.
	 * @param string                 $fallback Default language (the language of the missing fields).
	 * @param string[]               $missing  Fields shown in the default language.
	 *
	 * @phpstan-param list<string> $missing
	 */
	public function __construct(
		public readonly Listing|CompanyProfile $record,
		public readonly string $language,
		public readonly string $fallback,
		public readonly array $missing
	) {
	}

	/**
	 * Machine-readable marker: {language, missing, fallback_language}.
	 *
	 * @return array{language: string, missing: list<string>, fallback_language: string|null}
	 */
	public function marker(): array {
		return array(
			'language'          => $this->language,
			'missing'           => $this->missing,
			'fallback_language' => array() === $this->missing ? null : $this->fallback,
		);
	}
}
