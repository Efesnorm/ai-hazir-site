<?php
/**
 * Runs when the plugin is deleted from the Plugins screen.
 *
 * Data is removed ONLY if the `aihs_delete_data_on_uninstall` option is enabled.
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

require_once __DIR__ . '/vendor/autoload.php';

\AIHazirSite\WordPress\Uninstaller::run();
