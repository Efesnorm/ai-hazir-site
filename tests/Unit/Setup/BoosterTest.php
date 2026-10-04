<?php
/**
 * Booster AI choice and order; the release manifest (1.25.0).
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\Tests\Unit\Setup;

use AIHazirSite\Core\Features;
use AIHazirSite\Core\Setup\Booster;
use AIHazirSite\Core\Updates\ReleaseManifest;
use AIHazirSite\Tests\Support\MemorySettings;
use AIHazirSite\Tests\Unit\UnitTestCase;

/**
 * Booster never offers consent-bound features, orders by requirement, pre-ticks by site type and plans without
 * turning anything off; the manifest keeps the new release first and the previous ones for a rollback.
 *
 * @covers \AIHazirSite\Core\Setup\Booster
 * @covers \AIHazirSite\Core\Updates\ReleaseManifest
 */
final class BoosterTest extends UnitTestCase {

	/**
	 * Fresh in-memory settings (only measurement and the test filter on).
	 */
	protected function setUp(): void {
		parent::setUp();
		Features::use_settings( new MemorySettings() );
	}

	/**
	 * Every declared feature except telemetry and the inquiry box, each after what it needs.
	 */
	public function test_candidates_and_order(): void {
		$candidates = Booster::candidates();
		$this->assertNotContains( Features::TELEMETRY, $candidates );
		$this->assertNotContains( Features::INQUIRIES, $candidates );
		$this->assertEqualsCanonicalizing( array_values( array_diff( array_keys( Features::defaults() ), Booster::NEVER ) ), $candidates );

		$position = array_flip( $candidates );
		foreach ( Features::REQUIRES as $key => $requirements ) {
			foreach ( $requirements as $required ) {
				if ( isset( $position[ $key ], $position[ $required ] ) ) {
					$this->assertLessThan( $position[ $key ], $position[ $required ], "$required before $key" );
				}
			}
		}
		foreach ( Features::REQUIRES_ANY as $key => $any ) {
			foreach ( $any as $candidate ) {
				$this->assertLessThan( $position[ $key ], $position[ $candidate ], "$candidate before $key" );
			}
		}
		$this->assertSame( array( Features::CATALOG, Features::ABILITIES, Features::MCP ), Booster::order( array( Features::MCP, Features::ABILITIES, Features::CATALOG ) ) );
	}

	/**
	 * Portal mode and multilingual are pre-ticked only when they fit the site.
	 */
	public function test_preselected(): void {
		$company = Booster::preselected( false, false );
		$this->assertNotContains( Features::PORTAL_MODE, $company );
		$this->assertNotContains( Features::MULTILINGUAL, $company );
		$this->assertContains( Features::MCP, $company );
		$this->assertContains( Features::REMOTE_UPDATES, $company );
		foreach ( Booster::NETWORK as $network ) {
			$this->assertNotContains( $network, $company, 'A company site does not get network features unasked.' );
			$this->assertContains( $network, Booster::preselected( false, false, true ), 'A site with a network role does.' );
		}

		$portal = Booster::preselected( true, true );
		$this->assertContains( Features::PORTAL_MODE, $portal );
		$this->assertContains( Features::MULTILINGUAL, $portal );
		$this->assertContains( Features::PORTAL_NETWORK, $portal );
	}

	/**
	 * The plan turns on in order, reports what is on, and refuses what lacks a requirement; never what is excluded.
	 */
	public function test_plan(): void {
		[ $turn, $have, $cannot ] = Booster::plan( array( Features::MCP, Features::ABILITIES, Features::CATALOG, Features::MEASUREMENT, Features::TELEMETRY, Features::INQUIRIES, 'bilinmeyen' ) );
		$this->assertSame( array( Features::CATALOG, Features::ABILITIES, Features::MCP ), $turn );
		$this->assertSame( array( Features::MEASUREMENT ), $have );
		$this->assertSame( array(), $cannot );

		[ $turn, , $cannot ] = Booster::plan( array( Features::MCP, Features::DISCOVERY ) );
		$this->assertSame( array(), $turn );
		$this->assertSame(
			array(
				Features::MCP       => array( Features::ABILITIES ),
				Features::DISCOVERY => array( Features::LLMS_TXT, Features::REST_API ),
			),
			$cannot
		);
		$this->assertFalse( Features::is_enabled( Features::CATALOG ), 'Planning changes nothing.' );

		[ $turn ] = Booster::plan( Booster::preselected( false, false ) );
		$this->assertSame( Booster::order( $turn ), $turn );
		$this->assertNotContains( Features::PORTAL_MODE, $turn );
	}

	/**
	 * New release first; earlier ones kept (same version replaced, invalid dropped), at most KEEP.
	 */
	public function test_manifest_merge(): void {
		$release = static fn( string $v ): array => array(
			'version'      => $v,
			'download_url' => 'https://github.com/Efesnorm/ai-hazir-site/releases/download/v' . $v . '/ai-hazir-site-' . $v . '.zip',
			'released_at'  => '2026-10-04T20:00:00Z',
			'db_version'   => 1200,
			'requires'     => '6.9',
			'requires_php' => '8.1',
		);
		$first   = ReleaseManifest::merge( '', $release( '1.25.0' ), 'ai-hazir-site' );
		$this->assertSame( array( '1.25.0' ), array_map( static fn( $r ): string => $r->version, ReleaseManifest::parse( $first, 'ai-hazir-site' ) ) );

		$next = ReleaseManifest::merge( $first, $release( '1.25.1' ), 'ai-hazir-site' );
		$this->assertSame( array( '1.25.1', '1.25.0' ), array_map( static fn( $r ): string => $r->version, ReleaseManifest::parse( $next, 'ai-hazir-site' ) ) );

		$again = ReleaseManifest::merge( $next, $release( '1.25.1' ), 'ai-hazir-site' );
		$this->assertCount( 2, ReleaseManifest::parse( $again, 'ai-hazir-site' ), 'Same version replaced.' );

		$this->assertCount( 1, ReleaseManifest::parse( ReleaseManifest::merge( '{"slug":"baska","releases":[]}', $release( '1.0.0' ), 'ai-hazir-site' ), 'ai-hazir-site' ) );

		$many = '';
		for ( $i = 0; $i < 15; $i++ ) {
			$many = ReleaseManifest::merge( $many, $release( '1.' . $i . '.0' ), 'ai-hazir-site' );
		}
		$parsed = ReleaseManifest::parse( $many, 'ai-hazir-site' );
		$this->assertCount( ReleaseManifest::KEEP, $parsed );
		$this->assertSame( '1.14.0', $parsed[0]->version );
	}
}
