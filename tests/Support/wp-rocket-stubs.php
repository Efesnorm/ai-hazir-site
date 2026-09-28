<?php
/**
 * WP Rocket's regeneration functions, simulated for tests (counted in $GLOBALS).
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

if ( ! function_exists( 'flush_rocket_htaccess' ) ) {
	/**
	 * Simulated WP Rocket function.
	 */
	function flush_rocket_htaccess(): void { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- WP Rocket's function.
		$GLOBALS['aihs_test_rocket_regenerated'] = ( $GLOBALS['aihs_test_rocket_regenerated'] ?? 0 ) + 1;
	}
}

if ( ! function_exists( 'rocket_generate_config_file' ) ) {
	/**
	 * Simulated WP Rocket function.
	 */
	function rocket_generate_config_file(): void { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- WP Rocket's function.
		$GLOBALS['aihs_test_rocket_regenerated'] = ( $GLOBALS['aihs_test_rocket_regenerated'] ?? 0 ) + 1;
	}
}
