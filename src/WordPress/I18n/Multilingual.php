<?php
/**
 * The channels' entry point to translated catalog data.
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\WordPress\I18n;

use AIHazirSite\Core\Catalog\CompanyProfile;
use AIHazirSite\Core\Catalog\Listing;
use AIHazirSite\Core\Features;
use AIHazirSite\Core\I18n\LanguageNegotiator;
use AIHazirSite\Core\I18n\LanguageSettings;
use AIHazirSite\Core\I18n\Localized;
use AIHazirSite\Core\I18n\Localizer;
use AIHazirSite\WordPress\Catalog\WpListingRepository;
use AIHazirSite\WordPress\Catalog\WpProfileRepository;

/**
 * Multilingual output is active only while `multilingual` is on AND at least two languages are
 * published. When inactive, language() returns null and every channel keeps its exact
 * single-language output.
 */
final class Multilingual {

	/**
	 * Whether translated output is served.
	 */
	public static function active(): bool {
		return Features::is_enabled( Features::MULTILINGUAL ) && self::settings()->is_multilingual();
	}

	/**
	 * Published languages.
	 */
	public static function settings(): LanguageSettings {
		return LanguageSource::settings();
	}

	/**
	 * The answer language for a request, or null when multilingual output is inactive.
	 *
	 * @param string|null $requested       Explicit choice (parameter), or null.
	 * @param string      $accept_language Accept-Language header ('' when absent).
	 */
	public static function language( ?string $requested, string $accept_language = '' ): ?string {
		if ( ! self::active() ) {
			return null;
		}
		return ( new LanguageNegotiator( self::settings() ) )->negotiate( $requested, $accept_language );
	}

	/**
	 * A listing in a language.
	 *
	 * @param Listing $listing  Listing.
	 * @param string  $language Language.
	 */
	public static function listing( Listing $listing, string $language ): Localized {
		$translations = null === $listing->id ? array() : ( new WpListingRepository() )->translations( $listing->id );
		return ( new Localizer( self::settings() ) )->listing( $listing, $translations, $language );
	}

	/**
	 * Listings in a language (same order).
	 *
	 * @param Listing[] $listings Listings.
	 * @param string    $language Language.
	 * @return list<Localized>
	 *
	 * @phpstan-param list<Listing> $listings
	 */
	public static function listings( array $listings, string $language ): array {
		return array_map( static fn( Listing $l ): Localized => self::listing( $l, $language ), $listings );
	}

	/**
	 * The profile in a language.
	 *
	 * @param CompanyProfile $profile  Profile.
	 * @param string         $language Language.
	 */
	public static function profile( CompanyProfile $profile, string $language ): Localized {
		return ( new Localizer( self::settings() ) )->profile( $profile, ( new WpProfileRepository() )->profile_translations(), $language );
	}

	/**
	 * The Accept-Language header of the current request ('' when absent).
	 */
	public static function accept_language(): string {
		return isset( $_SERVER['HTTP_ACCEPT_LANGUAGE'] ) && is_string( $_SERVER['HTTP_ACCEPT_LANGUAGE'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_ACCEPT_LANGUAGE'] ) ) : '';
	}
}
