<?php
/**
 * Stand-ins for Polylang's public functions (https://polylang.pro/doc/function-reference/),
 * driven by $GLOBALS['aihs_test_polylang']; with no global set they behave like Polylang
 * without any configured language.
 *
 * @package AIHazirSite
 */

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- Polylang's own names.

if ( ! function_exists( 'pll_languages_list' ) ) {
	/**
	 * Language slugs.
	 *
	 * @param array<string, mixed> $args Arguments.
	 * @return list<string>
	 */
	function pll_languages_list( $args = array() ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Same signature as Polylang.
		return $GLOBALS['aihs_test_polylang']['languages'] ?? array();
	}

	/**
	 * Default language.
	 *
	 * @param string $field Field.
	 * @return string|false
	 */
	function pll_default_language( $field = 'slug' ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Same signature as Polylang.
		return $GLOBALS['aihs_test_polylang']['default'] ?? false;
	}

	/**
	 * Current language.
	 *
	 * @param string $field Field.
	 * @return string|false
	 */
	function pll_current_language( $field = 'slug' ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Same signature as Polylang.
		return $GLOBALS['aihs_test_polylang']['current'] ?? false;
	}
}
