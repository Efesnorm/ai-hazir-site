<?php
/**
 * Tests for Features.
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\Tests\Unit;

use AIHazirSite\Core\Features;
use AIHazirSite\Tests\Support\MemorySettings;

/**
 * Features unit tests.
 *
 * @covers \AIHazirSite\Core\Features
 */
final class FeaturesTest extends UnitTestCase {

	/**
	 * Settings behind Features.
	 *
	 * @var MemorySettings
	 */
	private MemorySettings $settings;

	/**
	 * Fresh in-memory settings.
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->settings = new MemorySettings();
		Features::use_settings( $this->settings );
	}

	/**
	 * Unknown keys are disabled.
	 */
	public function test_unknown_key_is_disabled(): void {
		$this->assertFalse( Features::is_enabled( 'olmayan' ) );
	}

	/**
	 * Unknown keys stay disabled even if the option contains them.
	 */
	public function test_unknown_key_is_disabled_even_if_stored(): void {
		$this->settings->set( Features::OPTION, array( 'olmayan' => true ) );

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
		$this->assertTrue( Features::is_enabled( Features::MEASUREMENT ) );

		$this->settings->set( Features::OPTION, array( 'measurement' => false ) );
		$this->assertFalse( Features::is_enabled( Features::MEASUREMENT ) );
	}

	/**
	 * Only declared keys can be stored; other stored keys are kept; the option is autoloaded.
	 */
	public function test_set_only_accepts_declared_keys(): void {
		$this->settings->set( Features::OPTION, array( 'other' => true ) );

		$this->assertTrue( Features::set( Features::MEASUREMENT, false ) );
		$this->assertFalse( Features::set( 'olmayan', true ) );

		$this->assertSame(
			array(
				'other'       => true,
				'measurement' => false,
			),
			$this->settings->get( Features::OPTION )
		);
		$this->assertTrue( $this->settings->autoload[ Features::OPTION ] );
	}

	/**
	 * Complete key list with its shipped defaults, as stored names (1.0.0 MVP check; later keys appended).
	 * A fresh install enables only measurement; every key is still read with the same name.
	 */
	public function test_mvp_feature_defaults(): void {
		$this->assertSame(
			array(
				'measurement'             => true,
				'compliance_scan'         => false,
				'catalog'                 => false,
				'bot_access'              => false,
				'schema_output'           => false,
				'llms_txt'                => false,
				'templates'               => false,
				'compliance_wizard'       => false,
				'rest_api'                => false,
				'abilities'               => false,
				'mcp'                     => false,
				'inquiries'               => false,
				'compliance_report'       => false,
				'multilingual'            => false,
				'portal_mode'             => false,
				'remote_updates'          => false,
				'telemetry'               => false,
				'matching'                => false,
				'a2a'                     => false,
				'discovery'               => false,
				'bot_cache_bypass'        => false,
				'catalog_sitemap'         => false,
				'indexnow'                => false,
				'litespeed_server_bypass' => false,
			),
			Features::defaults()
		);

		$enabled = array_keys( array_filter( array_map( array( Features::class, 'is_enabled' ), array_combine( array_keys( Features::defaults() ), array_keys( Features::defaults() ) ) ) ) );
		$this->assertSame( array( 'measurement' ), $enabled, 'Fresh install: only measurement is on.' );
	}
}
