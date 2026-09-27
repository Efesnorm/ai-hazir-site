<?php
/**
 * Plugin entry point.
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\WordPress;

use AIHazirSite\Core\Features;
use AIHazirSite\Core\Migrations\MigrationInterface;
use AIHazirSite\WordPress\Migrations\Migration_0_2_0;
use AIHazirSite\WordPress\Access\AccessModule;
use AIHazirSite\WordPress\Catalog\CatalogModule;
use AIHazirSite\WordPress\Compliance\ComplianceModule;
use AIHazirSite\WordPress\Compliance\Wizard\WizardModule;
use AIHazirSite\WordPress\Llms\LlmsModule;
use AIHazirSite\WordPress\Measurement\MeasurementModule;
use AIHazirSite\WordPress\Abilities\AbilitiesModule;
use AIHazirSite\WordPress\Mcp\McpModule;
use AIHazirSite\WordPress\Rest\RestModule;
use AIHazirSite\WordPress\Schema\SchemaModule;
use AIHazirSite\WordPress\Templates\TemplatesModule;
use AIHazirSite\WordPress\Platform\WpSettings;

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

		Features::use_settings( new WpSettings() );

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
			new ComplianceModule(),
			new WizardModule(),
			new TemplatesModule(),
			new CatalogModule(),
			new AccessModule(),
			new SchemaModule(),
			new LlmsModule(),
			new RestModule(),
			new AbilitiesModule(),
			new McpModule(),
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
