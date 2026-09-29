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
 * With Polylang, WPML or TranslatePress active, their language list, default and current language are
 * used (read only here). Otherwise the list is our own `aihs_languages` setting, defaulting to the
 * site language.
 *
 * Polylang: pll_languages_list(), pll_default_language(), pll_current_language()
 * (https://polylang.pro/doc/function-reference/).
 * WPML: wpml_active_languages, wpml_default_language, wpml_current_language filters
 * (https://wpml.org/documentation/support/wpml-coding-api/wpml-hooks-reference/).
 * TranslatePress (1.12.0): trp_custom_language_switcher() lists the published languages by locale
 * (https://translatepress.com/docs/developers/custom-language-switcher/); the default language is the
 * `default-language` of its settings component (TranslatePress puts it first among the published ones);
 * the current language is its global $TRP_LANGUAGE (as its own trp_translate() uses it).
 */
final class LanguageSource {

	public const OPTION         = 'aihs_languages';
	public const POLYLANG       = 'polylang';
	public const WPML           = 'wpml';
	public const TRANSLATEPRESS = 'translatepress';

	/**
	 * Display names of the multilingual plugins.
	 */
	public const NAMES = array(
		self::POLYLANG       => 'Polylang',
		self::WPML           => 'WPML',
		self::TRANSLATEPRESS => 'TranslatePress',
	);

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
		if ( array() !== self::translatepress_languages() ) {
			return self::TRANSLATEPRESS;
		}
		return null;
	}

	/**
	 * TranslatePress's published languages (locales, e.g. "tr_TR"), default first; empty without it.
	 *
	 * @return list<string>
	 */
	private static function translatepress_languages(): array {
		if ( ! function_exists( 'trp_custom_language_switcher' ) || ! class_exists( 'TRP_Translate_Press' ) ) {
			return array();
		}
		$languages = array_keys( (array) trp_custom_language_switcher() );
		$default   = self::translatepress_default();
		if ( '' !== $default && in_array( $default, $languages, true ) ) {
			$languages = array_merge( array( $default ), array_diff( $languages, array( $default ) ) );
		}
		return self::strings( $languages );
	}

	/**
	 * TranslatePress's default language (locale), or ''.
	 */
	private static function translatepress_default(): string {
		$settings = class_exists( 'TRP_Translate_Press' ) ? \TRP_Translate_Press::get_trp_instance()->get_component( 'settings' ) : null;
		$values   = is_object( $settings ) && method_exists( $settings, 'get_settings' ) ? $settings->get_settings() : array();
		return is_array( $values ) && is_string( $values['default-language'] ?? null ) ? $values['default-language'] : '';
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
			case self::TRANSLATEPRESS:
				$list = self::translatepress_languages();
				return new LanguageSettings( LanguageSettings::normalise( $list[0] ?? '' ) ?? $site, $list );
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
			case self::TRANSLATEPRESS:
				$current = $GLOBALS['TRP_LANGUAGE'] ?? null; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- TranslatePress's global.
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
