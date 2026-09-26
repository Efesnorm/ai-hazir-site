<?php
/**
 * Activation / deactivation inside a real WordPress.
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\Tests\Integration;

use WP_UnitTestCase;

/**
 * Activation and deactivation of the plugin inside WordPress.
 *
 * @covers \AIHazirSite\WordPress\Lifecycle
 */
final class LifecycleTest extends WP_UnitTestCase {

	private const PLUGIN = 'ai-hazir-site/ai-hazir-site.php';

	/**
	 * Loads the admin plugin API.
	 */
	public function set_up(): void {
		parent::set_up();
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
	}

	/**
	 * The plugin was loaded by the bootstrap and booted.
	 */
	public function test_plugin_is_loaded(): void {
		$this->assertTrue( defined( 'AIHS_VERSION' ) );
		$this->assertTrue( class_exists( \AIHazirSite\WordPress\Plugin::class ) );
	}

	/**
	 * Activation and deactivation succeed without output or PHP warnings.
	 *
	 * PHPUnit converts notices/warnings to exceptions and activate_plugin()
	 * returns a WP_Error on unexpected output, so both are covered.
	 */
	public function test_activates_and_deactivates_cleanly(): void {
		$result = activate_plugin( self::PLUGIN );

		$this->assertNull( $result );
		$this->assertTrue( is_plugin_active( self::PLUGIN ) );

		deactivate_plugins( self::PLUGIN );

		$this->assertFalse( is_plugin_active( self::PLUGIN ) );
	}
}
