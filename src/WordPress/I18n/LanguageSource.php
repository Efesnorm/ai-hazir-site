<?php
/**
 * Where the site's languages come from.
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\WordPress\I18n;

use AIHazirSite\Core\I18n\LanguageSettings;

/**
 * With Polylang or WPML active, their language list, default and current language are used
 * (read only here). Otherwise the list is our own `aihs_languages` setting, defaulting to the
 * site language.
 *
 * Polylang: pll_languages_list(), pll_default_language(), pll_current_language()
 * (https://polylang.pro/doc/function-reference/).
 * WPML: wpml_active_languages, wpml_default_language, wpml_current_language filters
 * (https://wpml.org/documentation/support/wpml-coding-api/wpml-hooks-reference/).
 */
final class LanguageSource {

	public const OPTION   = 'aihs_languages';
	public const POLYLANG = 'polylang';
	public const WPML     = 'wpml';

	/**
	 * The active multilingual plugin, or null.
	 */
	public static function plugin(): ?string {
		// Polylang without any language configured provides nothing to follow.
		if ( function_exists( 'pll_languages_list' ) && function_exists( 'pll_default_language' ) && array() !== (array) pll_languages_list( array( 'fields' => 'slug' ) ) ) {
			return self::POLYLANG;
		}
		if ( defined( 'ICL_SITEPRESS_VERSION' ) || has_filter( 'wpml_active_languages' ) ) {
			return self::WPML;
		}
		return null;
	}

	/**
	 * Published languages.
	 */
	public static function settings(): LanguageSettings {
		$site = self::site_language();
		switch ( self::plugin() ) {
			case self::POLYLANG:
				$default = pll_default_language( 'slug' );
				$list    = pll_languages_list( array( 'fields' => 'slug' ) );
				return new LanguageSettings( LanguageSettings::normalise( is_string( $default ) ? $default : '' ) ?? $site, self::strings( $list ) );
			case self::WPML:
				$default = apply_filters( 'wpml_default_language', null ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WPML API.
				$active  = apply_filters( 'wpml_active_languages', null, array( 'skip_missing' => 0 ) ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WPML API.
				return new LanguageSettings( LanguageSettings::normalise( is_string( $default ) ? $default : '' ) ?? $site, self::strings( is_array( $active ) ? array_keys( $active ) : array() ) );
		}
		return LanguageSettings::from_array( get_option( self::OPTION, array() ), $site );
	}

	/**
	 * The multilingual plugin's language for the current page, or null.
	 */
	public static function current(): ?string {
		$current = null;
		switch ( self::plugin() ) {
			case self::POLYLANG:
				$current = function_exists( 'pll_current_language' ) ? pll_current_language( 'slug' ) : null;
				break;
			case self::WPML:
				$current = apply_filters( 'wpml_current_language', null ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WPML API.
				break;
		}
		return is_string( $current ) ? LanguageSettings::normalise( $current ) : null;
	}

	/**
	 * Saves our own language setting (ignored while a multilingual plugin is in charge).
	 *
	 * @param string $default_language Default language code.
	 * @param string $languages        Other codes, separated by commas or spaces.
	 * @return list<string> Codes that were not understood.
	 */
	public static function save( string $default_language, string $languages ): array {
		$codes    = array_values( array_filter( array_map( 'trim', (array) preg_split( '/[\s,;]+/', $languages ) ) ) );
		$invalid  = array_values( array_filter( $codes, static fn( string $c ): bool => null === LanguageSettings::normalise( $c ) ) );
		$default  = LanguageSettings::normalise( $default_language );
		$settings = new LanguageSettings( $default ?? self::site_language(), $codes );
		if ( null === $default && '' !== trim( $default_language ) ) {
			$invalid[] = $default_language;
		}
		if ( null === self::plugin() ) {
			update_option( self::OPTION, $settings->to_array(), true );
		}
		return $invalid;
	}

	/**
	 * The site language as an ISO 639-1 code ("tr_TR" → "tr").
	 */
	public static function site_language(): string {
		return LanguageSettings::normalise( get_locale() ) ?? 'tr';
	}

	/**
	 * String values of a list.
	 *
	 * @param mixed $values Values.
	 * @return list<string>
	 */
	private static function strings( mixed $values ): array {
		return array_values( array_filter( is_array( $values ) ? $values : array(), 'is_string' ) );
	}
}
