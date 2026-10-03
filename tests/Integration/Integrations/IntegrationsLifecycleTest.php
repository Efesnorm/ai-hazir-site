<?php
/**
 * Integration settings survive a deactivate/activate cycle (1.19.1).
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\Tests\Integration\Integrations;

use AIHazirSite\Core\Features;
use AIHazirSite\WordPress\Integrations\LiteSpeedServerBypass;
use AIHazirSite\WordPress\Lifecycle;
use WP_UnitTestCase;

/**
 * Found live (intekarglobal.com): after an update the LiteSpeed server setting was off and GPTBot was served from the
 * server cache again.
 *
 * @covers \AIHazirSite\WordPress\Lifecycle
 * @covers \AIHazirSite\WordPress\Integrations\IntegrationsModule
 */
final class IntegrationsLifecycleTest extends WP_UnitTestCase {

	/**
	 * Default features; no stored server rule state.
	 */
	public function set_up(): void {
		parent::set_up();
		delete_option( Features::OPTION );
		delete_option( LiteSpeedServerBypass::OPTION );
	}

	/**
	 * Keys off: activation writes nothing.
	 */
	public function test_activation_with_keys_off_writes_nothing(): void {
		Lifecycle::activate();
		$this->assertNull( LiteSpeedServerBypass::last() );
		$this->assertFalse( Features::is_enabled( Features::LITESPEED_SERVER_BYPASS ) );
	}

	/**
	 * Keys on: deactivation reverts, the key stays on, activation writes the rule again.
	 */
	public function test_cycle_keeps_the_setting(): void {
		Features::set( Features::LITESPEED_SERVER_BYPASS, true );

		Lifecycle::deactivate();
		$this->assertTrue( Features::is_enabled( Features::LITESPEED_SERVER_BYPASS ) );
		$this->assertFalse( LiteSpeedServerBypass::last()['on'] ?? null );

		Lifecycle::activate();
		$this->assertTrue( Features::is_enabled( Features::LITESPEED_SERVER_BYPASS ) );
		$this->assertTrue( LiteSpeedServerBypass::last()['on'] ?? null );
	}
}
