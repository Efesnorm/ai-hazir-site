<?php
/**
 * Puts entered translations in place.
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\Core\I18n;

use AIHazirSite\Core\Catalog\CompanyProfile;
use AIHazirSite\Core\Catalog\Listing;

/**
 * Only translations the site owner entered are published (no machine translation).
 * A field without a translation keeps its default-language text and is reported as missing.
 * In the default language the record is returned unchanged.
 */
final class Localizer {

	/**
	 * Translatable listing fields.
	 */
	public const LISTING_FIELDS = array( 'title', 'description', 'category', 'region' );

	/**
	 * Translatable profile fields.
	 */
	public const PROFILE_FIELDS = array( 'sector' );

	/**
	 * Constructor.
	 *
	 * @param LanguageSettings $settings Published languages.
	 */
	public function __construct( private readonly LanguageSettings $settings ) {
	}

	/**
	 * A listing in a language.
	 *
	 * @param Listing                              $listing      Listing (default language).
	 * @param array<string, array<string, string>> $translations Language → field → text.
	 * @param string                               $language     Requested language.
	 */
	public function listing( Listing $listing, array $translations, string $language ): Localized {
		[ $data, $missing ] = $this->apply( $listing->to_array(), self::LISTING_FIELDS, $translations, $language );
		return new Localized( Listing::from_array( $data ), $language, $this->settings->default, $missing );
	}

	/**
	 * The profile in a language.
	 *
	 * @param CompanyProfile                       $profile      Profile (default language).
	 * @param array<string, array<string, string>> $translations Language → field → text.
	 * @param string                               $language     Requested language.
	 */
	public function profile( CompanyProfile $profile, array $translations, string $language ): Localized {
		[ $data, $missing ] = $this->apply( $profile->to_array(), self::PROFILE_FIELDS, $translations, $language );
		return new Localized( CompanyProfile::from_array( $data ), $language, $this->settings->default, $missing );
	}

	/**
	 * Replaces translated fields; lists non-empty fields that have no translation.
	 *
	 * @param array<string, mixed>                 $data         Record data.
	 * @param string[]                             $fields       Translatable fields.
	 * @param array<string, array<string, string>> $translations Translations.
	 * @param string                               $language     Language.
	 * @return array{0: array<string, mixed>, 1: list<string>}
	 */
	private function apply( array $data, array $fields, array $translations, string $language ): array {
		if ( $language === $this->settings->default || ! $this->settings->has( $language ) ) {
			return array( $data, array() );
		}
		$missing = array();
		foreach ( $fields as $field ) {
			$original   = is_scalar( $data[ $field ] ?? null ) ? (string) $data[ $field ] : '';
			$translated = $translations[ $language ][ $field ] ?? '';
			if ( '' !== $translated ) {
				$data[ $field ] = $translated;
			} elseif ( '' !== $original ) {
				$missing[] = $field;
			}
		}
		return array( $data, $missing );
	}

	/**
	 * Clean translations for storage: only listed non-default languages, only translatable
	 * fields, trimmed non-empty strings.
	 *
	 * @param mixed    $data   Stored or submitted data.
	 * @param string[] $fields Translatable fields.
	 * @return array<string, array<string, string>>
	 */
	public function clean( mixed $data, array $fields ): array {
		$clean = array();
		if ( ! is_array( $data ) ) {
			return $clean;
		}
		foreach ( $this->settings->translated() as $language ) {
			$values = is_array( $data[ $language ] ?? null ) ? $data[ $language ] : array();
			foreach ( $fields as $field ) {
				$value = is_string( $values[ $field ] ?? null ) ? trim( $values[ $field ] ) : '';
				if ( '' !== $value ) {
					$clean[ $language ][ $field ] = $value;
				}
			}
		}
		return $clean;
	}
}
