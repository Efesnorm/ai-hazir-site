<?php
/**
 * Stand-ins for TranslatePress's public API (trp_custom_language_switcher(), TRP_Translate_Press settings
 * component, $TRP_LANGUAGE), driven by $GLOBALS['aihs_test_trp']; with no global set they behave like
 * TranslatePress without published languages.
 *
 * @package AIHazirSite
 */

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals, Generic.Files.OneObjectStructurePerFile, Universal.Files.SeparateFunctionsFromOO -- TranslatePress's own names, in one stand-in file.

if ( ! function_exists( 'trp_custom_language_switcher' ) ) {
	/**
	 * Published languages keyed by locale.
	 *
	 * @return array<string, array<string, string>>
	 */
	function trp_custom_language_switcher() {
		$out = array();
		foreach ( $GLOBALS['aihs_test_trp']['published'] ?? array() as $locale ) {
			$out[ $locale ] = array(
				'language_name'       => $locale,
				'language_code'       => $locale,
				'short_language_name' => strtolower( substr( $locale, 0, 2 ) ),
			);
		}
		return $out;
	}
}

if ( ! class_exists( 'TRP_Translate_Press' ) ) {
	/**
	 * TranslatePress's main class (only what LanguageSource reads).
	 */
	class TRP_Translate_Press {

		/**
		 * The instance.
		 */
		public static function get_trp_instance(): self {
			return new self();
		}

		/**
		 * A component: here only "settings".
		 *
		 * @param string $name Component.
		 */
		public function get_component( string $name ): object { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Same signature as TranslatePress.
			return new class() {
				/**
				 * Settings.
				 *
				 * @return array<string, mixed>
				 */
				public function get_settings(): array {
					return array( 'default-language' => $GLOBALS['aihs_test_trp']['default'] ?? 'en_US' );
				}
			};
		}
	}
}
