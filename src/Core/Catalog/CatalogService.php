<?php
/**
 * The only place catalog data is written.
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\Core\Catalog;

use AIHazirSite\Core\Contracts\Clock;
use AIHazirSite\Core\Contracts\ListingRepository;
use AIHazirSite\Core\Contracts\ListingTranslationRepository;
use AIHazirSite\Core\Contracts\ProfileRepository;
use AIHazirSite\Core\Contracts\ProfileTranslationRepository;
use AIHazirSite\Core\I18n\LanguageSettings;
use AIHazirSite\Core\I18n\Localizer;
use AIHazirSite\Core\Templates\TemplateRegistry;

/**
 * Validates before every write. Admin forms, imports and future channels all go through here.
 */
final class CatalogService {

	/**
	 * Longest translated text per field.
	 */
	public const TRANSLATION_MAX = array(
		'title'       => ListingValidator::TITLE_MAX,
		'description' => 20000,
		'category'    => 200,
		'region'      => 200,
		'sector'      => 200,
	);

	/**
	 * Constructor.
	 *
	 * @param ListingRepository                 $listings             Listing storage.
	 * @param ProfileRepository                 $profiles             Profile storage.
	 * @param Clock                             $clock                Provides today for date rules.
	 * @param TemplateRegistry|null             $templates            Sector templates; null when templates are off.
	 * @param ListingTranslationRepository|null $listing_translations Listing translations (1.1.0); null = none.
	 * @param ProfileTranslationRepository|null $profile_translations Profile translations (1.1.0); null = none.
	 */
	public function __construct(
		private readonly ListingRepository $listings,
		private readonly ProfileRepository $profiles,
		private readonly Clock $clock,
		private readonly ?TemplateRegistry $templates = null,
		private readonly ?ListingTranslationRepository $listing_translations = null,
		private readonly ?ProfileTranslationRepository $profile_translations = null
	) {
	}

	/**
	 * Validates and stores one language's translation of a listing. Empty fields remove that
	 * field's translation; other languages are kept.
	 *
	 * @param int                  $id        Listing id.
	 * @param string               $language  Language (one of the translated languages).
	 * @param array<string, mixed> $input     Field → text.
	 * @param LanguageSettings     $languages Published languages.
	 * @return array<string, string> Errors by field (empty = saved).
	 */
	public function save_listing_translation( int $id, string $language, array $input, LanguageSettings $languages ): array {
		if ( null === $this->listing_translations || null === $this->listings->find( $id ) ) {
			return array( 'id' => 'İlan bulunamadı.' );
		}
		$merged = $this->merge_translation( $this->listing_translations->translations( $id ), $language, $input, Localizer::LISTING_FIELDS, $languages );
		if ( array() !== $merged['errors'] ) {
			return $merged['errors'];
		}
		$this->listing_translations->store_translations( $id, $merged['translations'] );
		return array();
	}

	/**
	 * Validates and stores one language's translation of the profile.
	 *
	 * @param string               $language  Language (one of the translated languages).
	 * @param array<string, mixed> $input     Field → text.
	 * @param LanguageSettings     $languages Published languages.
	 * @return array<string, string> Errors by field (empty = saved).
	 */
	public function save_profile_translation( string $language, array $input, LanguageSettings $languages ): array {
		if ( null === $this->profile_translations ) {
			return array( 'language' => 'Çeviri kaydedilemiyor.' );
		}
		$merged = $this->merge_translation( $this->profile_translations->profile_translations(), $language, $input, Localizer::PROFILE_FIELDS, $languages );
		if ( array() !== $merged['errors'] ) {
			return $merged['errors'];
		}
		$this->profile_translations->store_profile_translations( $merged['translations'] );
		return array();
	}

	/**
	 * Stored translations with one language replaced, or the errors.
	 *
	 * @param array<string, array<string, string>> $stored    Stored translations.
	 * @param string                               $language  Language.
	 * @param array<string, mixed>                 $input     Field → text.
	 * @param string[]                             $fields    Translatable fields.
	 * @param LanguageSettings                     $languages Published languages.
	 * @return array{errors: array<string, string>, translations: array<string, array<string, string>>}
	 */
	private function merge_translation( array $stored, string $language, array $input, array $fields, LanguageSettings $languages ): array {
		if ( ! in_array( $language, $languages->translated(), true ) ) {
			return array(
				'errors'       => array( 'language' => 'Bu dil için çeviri girilemez (varsayılan dil ya da listede olmayan dil).' ),
				'translations' => array(),
			);
		}
		$errors = array();
		$values = array();
		foreach ( $fields as $field ) {
			$value = $input[ $field ] ?? '';
			if ( ! is_scalar( $value ) ) {
				$errors[ $field ] = 'Geçersiz değer.';
				continue;
			}
			$value = trim( (string) $value );
			if ( mb_strlen( $value ) > self::TRANSLATION_MAX[ $field ] ) {
				$errors[ $field ] = sprintf( 'En fazla %d karakter olabilir.', self::TRANSLATION_MAX[ $field ] );
			}
			$values[ $field ] = $value;
		}
		$stored[ $language ] = $values;
		return array(
			'errors'       => $errors,
			'translations' => ( new Localizer( $languages ) )->clean( $stored, $fields ),
		);
	}

	/**
	 * Validates and stores a listing. With an id, only a listing of the same type is updated.
	 *
	 * @param array<string, mixed> $input Raw input.
	 * @param int|null             $id    Listing to update; null to create.
	 */
	public function save_listing( array $input, ?int $id = null ): ValidationResult {
		$existing = null;
		if ( null !== $id ) {
			$existing = $this->listings->find( $id );
			if ( null === $existing ) {
				return new ValidationResult( null, array( 'id' => 'İlan bulunamadı.' ) );
			}
		}

		$result  = ( new ListingValidator( $this->templates ) )->validate( $input, $this->clock->today(), $existing );
		$listing = $result->listing();
		if ( null === $listing ) {
			return $result;
		}

		return $result->with_value( $this->listings->store_listing( $listing ) );
	}

	/**
	 * Deletes a listing; refuses when it does not exist or is of another type.
	 *
	 * @param int         $id   Id.
	 * @param string|null $type Expected type (protects other types from a crafted request).
	 */
	public function delete_listing( int $id, ?string $type = null ): bool {
		$existing = $this->listings->find( $id );
		if ( null === $existing || ( null !== $type && $existing->type !== $type ) ) {
			return false;
		}
		return $this->listings->remove_listing( $id );
	}

	/**
	 * Validates and stores the company profile.
	 *
	 * @param array<string, mixed> $input Raw input.
	 */
	public function save_profile( array $input ): ValidationResult {
		// Templates off: the stored choice is kept (it is not shown, so it cannot be changed).
		// Templates on: a form without the choice keeps the stored one too.
		if ( null === $this->templates || ! isset( $input['template'] ) ) {
			$input['template'] = $this->profiles->get()->template;
		}
		$result  = ( new ProfileValidator( $this->templates ) )->validate( $input );
		$profile = $result->profile();
		if ( null !== $profile ) {
			$this->profiles->store_profile( $profile );
		}
		return $result;
	}

	/**
	 * Puts back an earlier profile state exactly (undo of a change; the earlier state was valid).
	 *
	 * @param CompanyProfile|null $profile    Earlier profile (null = none).
	 * @param string|null         $updated_at Earlier save time.
	 */
	public function revert_profile( ?CompanyProfile $profile, ?string $updated_at ): void {
		$this->profiles->restore_profile( $profile, $updated_at );
	}

	/**
	 * Stored profile and its save time (null profile when none).
	 *
	 * @return array{0: CompanyProfile|null, 1: string|null}
	 */
	public function profile_state(): array {
		$updated = $this->profiles->updated_at();
		return array( null === $updated ? null : $this->profiles->get(), $updated );
	}

	/**
	 * Removes all catalog data (uninstall with the site owner's opt-in).
	 *
	 * @return int Listings deleted.
	 */
	public function purge(): int {
		$deleted = 0;
		foreach ( $this->listings->ids() as $id ) {
			$deleted += (int) $this->listings->remove_listing( $id );
		}
		$this->profiles->remove_profile();
		$this->profile_translations?->store_profile_translations( array() );
		return $deleted;
	}
}
