<?php
/**
 * TranslatePress stubs for PHPStan (https://translatepress.com/docs/developers/custom-language-switcher/).
 * Never loaded at runtime.
 *
 * @package AIHazirSite
 */

// phpcs:ignoreFile -- Stub file for static analysis only.

/**
 * Published languages keyed by locale.
 *
 * @return array<string, array<string, string>>
 */
function trp_custom_language_switcher() {}

/**
 * TranslatePress's main class.
 */
class TRP_Translate_Press {

	/**
	 * The instance.
	 *
	 * @return TRP_Translate_Press
	 */
	public static function get_trp_instance() {}

	/**
	 * A component (e.g. "settings", whose get_settings() returns the settings array).
	 *
	 * @param string $component Component name.
	 * @return object|null
	 */
	public function get_component( $component ) {}
}
