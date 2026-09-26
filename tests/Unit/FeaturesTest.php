<?php
/**
 * Tests for Features.
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\Tests\Unit;

use AIHazirSite\Core\Features;
use Brain\Monkey\Functions;

/**
 * Features unit tests.
 *
 * @covers \AIHazirSite\Core\Features
 */
final class FeaturesTest extends UnitTestCase {

	/**
	 * Unknown keys are disabled.
	 */
	public function test_unknown_key_is_disabled(): void {
		Functions\when( 'get_option' )->justReturn( array() );

		$this->assertFalse( Features::is_enabled( 'olmayan' ) );
	}

	/**
	 * Unknown keys stay disabled even if the option contains them.
	 */
	public function test_unknown_key_is_disabled_even_if_stored(): void {
		Functions\when( 'get_option' )->justReturn( array( 'olmayan' => true ) );

		$this->assertFalse( Features::is_enabled( 'olmayan' ) );
	}

	/**
	 * Every declared feature defaults to disabled.
	 */
	public function test_all_defaults_are_disabled(): void {
		$this->assertNotContains( true, Features::defaults() );
	}
}
