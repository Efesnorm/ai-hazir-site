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
	 * Every declared feature defaults to disabled, except the approved exceptions.
	 *
	 * Adding a new default-on key must fail this test until the exception list
	 * below is updated on purpose (with a CHANGELOG rationale).
	 */
	public function test_all_defaults_are_disabled(): void {
		$this->assertSame( array( 'measurement' ), Features::DEFAULT_ON, 'Only approved keys may default to on.' );

		foreach ( Features::defaults() as $key => $default ) {
			if ( in_array( $key, Features::DEFAULT_ON, true ) ) {
				$this->assertTrue( $default, $key );
				continue;
			}
			$this->assertFalse( $default, $key );
		}
	}

	/**
	 * Measurement is on until the site owner turns it off.
	 */
	public function test_measurement_defaults_on_and_can_be_turned_off(): void {
		Functions\when( 'get_option' )->justReturn( array() );
		$this->assertTrue( Features::is_enabled( Features::MEASUREMENT ) );

		Functions\when( 'get_option' )->justReturn( array( 'measurement' => false ) );
		$this->assertFalse( Features::is_enabled( Features::MEASUREMENT ) );
	}

	/**
	 * Only declared keys can be stored.
	 */
	public function test_set_only_accepts_declared_keys(): void {
		Functions\when( 'get_option' )->justReturn( array( 'other' => true ) );
		Functions\expect( 'update_option' )
			->once()
			->with( Features::OPTION, array( 'other' => true, 'measurement' => false ), true ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound

		$this->assertTrue( Features::set( Features::MEASUREMENT, false ) );
		$this->assertFalse( Features::set( 'olmayan', true ) );
	}
}
