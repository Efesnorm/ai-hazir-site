<?php
/**
 * Tests for SetupProfiles (1.11.0).
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\Tests\Unit\Setup;

use AIHazirSite\Core\Features;
use AIHazirSite\Core\Setup\SetupProfiles;
use AIHazirSite\Tests\Support\MemorySettings;
use AIHazirSite\Tests\Unit\UnitTestCase;

/**
 * Profiles are valid, ordered by requirement, never include the quote box; the plan skips what is on.
 *
 * @covers \AIHazirSite\Core\Setup\SetupProfiles
 */
final class SetupProfilesTest extends UnitTestCase {

	/**
	 * Fresh in-memory settings.
	 */
	protected function setUp(): void {
		parent::setUp();
		Features::use_settings( new MemorySettings() );
	}

	/**
	 * Declared keys only, once each; every requirement comes earlier in the same list.
	 */
	public function test_profiles_are_valid_and_ordered(): void {
		$declared = array_keys( Features::defaults() );
		$this->assertSame( array( 'urun', 'ihracat', 'tur', 'portal', 'hizmet' ), array_keys( SetupProfiles::all() ) );
		foreach ( SetupProfiles::all() as $id => $keys ) {
			$this->assertSame( $keys, array_values( array_unique( $keys ) ), $id );
			foreach ( $keys as $i => $key ) {
				$this->assertContains( $key, $declared, $id );
				$before = array_slice( $keys, 0, $i );
				foreach ( Features::REQUIRES[ $key ] ?? array() as $required ) {
					$this->assertContains( $required, $before, "{$id}: {$required} before {$key}" );
				}
				if ( isset( Features::REQUIRES_ANY[ $key ] ) ) {
					$this->assertNotSame( array(), array_intersect( Features::REQUIRES_ANY[ $key ], $before ), "{$id}: a requirement of {$key}" );
				}
			}
			$this->assertNotContains( Features::INQUIRIES, $keys, $id . ': the quote box is turned on on its own screen.' );
			$this->assertNotContains( Features::MATCHING, $keys, $id );
		}
	}

	/**
	 * PRD pilot table: the service (law firm) profile is read-only; export adds languages, portal the portal mode.
	 */
	public function test_prd_rules(): void {
		$all = SetupProfiles::all();
		$this->assertNotContains( Features::A2A, $all['hizmet'] );
		$this->assertContains( Features::A2A, $all['urun'] );
		$this->assertContains( Features::MULTILINGUAL, $all['ihracat'] );
		$this->assertNotContains( Features::MULTILINGUAL, $all['urun'] );
		$this->assertContains( Features::PORTAL_MODE, $all['portal'] );
		$this->assertNotContains( Features::PORTAL_MODE, $all['tur'] );
	}

	/**
	 * The plan leaves out what is already on; unknown profiles plan nothing.
	 */
	public function test_plan(): void {
		$this->assertNotContains( Features::MEASUREMENT, SetupProfiles::plan( 'urun' ), 'On by default.' );
		Features::set( Features::CATALOG, true );
		$this->assertNotContains( Features::CATALOG, SetupProfiles::plan( 'urun' ) );
		$this->assertContains( Features::A2A, SetupProfiles::plan( 'urun' ) );
		$this->assertSame( array(), SetupProfiles::plan( 'olmayan' ) );
	}
}
