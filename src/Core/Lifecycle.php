<?php
/**
 * Activation and deactivation hooks.
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\Core;

use AIHazirSite\Core\Migrations\Migrator;

/**
 * Handles plugin activation and deactivation.
 */
final class Lifecycle {

	/**
	 * Registers activation and deactivation hooks for the main plugin file.
	 *
	 * @param string $plugin_file Absolute path of the main plugin file.
	 */
	public static function register( string $plugin_file ): void {
		register_activation_hook( $plugin_file, array( self::class, 'activate' ) );
		register_deactivation_hook( $plugin_file, array( self::class, 'deactivate' ) );
	}

	/**
	 * Runs pending migrations.
	 */
	public static function activate(): void {
		( new Migrator( Plugin::migrations() ) )->migrate();
	}

	/**
	 * Runs pending migrations after a plugin update (activation hooks do not fire on update).
	 */
	public static function maybe_upgrade(): void {
		$migrator = new Migrator( Plugin::migrations() );
		if ( ! $migrator->is_up_to_date() ) {
			$migrator->migrate();
		}
	}

	/**
	 * Deactivation keeps all data; removal only happens in uninstall.php.
	 */
	public static function deactivate(): void {
		// Nothing to clean up in 0.1.0.
	}
}
