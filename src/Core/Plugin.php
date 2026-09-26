<?php
/**
 * Plugin entry point.
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\Core;

use AIHazirSite\Core\Migrations\MigrationInterface;

/**
 * Single entry point: registers modules and exposes the migration list.
 */
final class Plugin {

	/**
	 * Whether {@see Plugin::boot()} already ran.
	 *
	 * @var bool
	 */
	private static bool $booted = false;

	/**
	 * Boots the plugin once.
	 */
	public static function boot(): void {
		if ( self::$booted ) {
			return;
		}
		self::$booted = true;

		foreach ( self::modules() as $module ) {
			$module->register();
		}
	}

	/**
	 * Modules to register. Empty in 0.1.0.
	 *
	 * @return list<Module>
	 */
	public static function modules(): array {
		return array();
	}

	/**
	 * Database migrations, in any order. Empty in 0.1.0.
	 *
	 * @return list<MigrationInterface>
	 */
	public static function migrations(): array {
		return array();
	}
}
