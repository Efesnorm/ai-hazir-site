<?php
/**
 * Plugin entry point.
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\Core;

use AIHazirSite\Core\Migrations\MigrationInterface;
use AIHazirSite\Core\Migrations\Migration_0_2_0;
use AIHazirSite\Modules\Measurement\MeasurementModule;

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

		add_action( 'plugins_loaded', array( Lifecycle::class, 'maybe_upgrade' ) );

		foreach ( self::modules() as $module ) {
			$module->register();
		}
	}

	/**
	 * Modules to register.
	 *
	 * @return list<Module>
	 */
	public static function modules(): array {
		return array(
			new MeasurementModule(),
		);
	}

	/**
	 * Database migrations, in any order.
	 *
	 * @return list<MigrationInterface>
	 */
	public static function migrations(): array {
		return array(
			new Migration_0_2_0(),
		);
	}
}
