<?php
/**
 * Plugin Name:       AI Hazır Site
 * Description:       Sitenin sunduğu, aradığı ve tedarik edebildiği bilgiyi AI agentların okuyabileceği biçimde yayınlar.
 * Version:           0.11.0
 * Requires at least: 6.9
 * Requires PHP:      8.1
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       ai-hazir-site
 * Domain Path:       /languages
 *
 * IMPORTANT: This file must parse on old PHP versions so the version notice can
 * be shown. Keep PHP 7.4+ syntax out of it; real code lives in src/.
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'AIHS_VERSION', '0.11.0' );
define( 'AIHS_FILE', __FILE__ );

require_once __DIR__ . '/src/WordPress/Requirements.php';

( new \AIHazirSite\WordPress\Requirements( PHP_VERSION, (string) get_bloginfo( 'version' ) ) )->run(
	static function () {
		// Jetpack Autoloader (used by the bundled MCP Adapter) keeps a single, newest copy of shared
		// packages when several plugins bundle them; plain Composer autoloading otherwise.
		require_once is_readable( __DIR__ . '/vendor/autoload_packages.php' ) ? __DIR__ . '/vendor/autoload_packages.php' : __DIR__ . '/vendor/autoload.php';

		\AIHazirSite\WordPress\Lifecycle::register( AIHS_FILE );
		\AIHazirSite\WordPress\Plugin::boot();
	}
);
