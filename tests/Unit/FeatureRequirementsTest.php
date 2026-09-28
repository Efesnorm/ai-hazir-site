<?php
/**
 * Feature requirements for the settings screen (1.9.0).
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\Tests\Unit;

use AIHazirSite\Core\Features;
use AIHazirSite\Tests\Support\MemorySettings;

/**
 * REQUIRES / REQUIRES_ANY: declared keys only, no cycles, and the two questions the screen asks.
 *
 * @covers \AIHazirSite\Core\Features
 */
final class FeatureRequirementsTest extends UnitTestCase {

	/**
	 * Fresh in-memory settings.
	 */
	protected function setUp(): void {
		parent::setUp();
		Features::use_settings( new MemorySettings() );
	}

	/**
	 * Every key in the tables is declared; nothing requires itself or a default-off key requiring it back.
	 */
	public function test_tables_are_consistent(): void {
		$declared = array_keys( Features::defaults() );
		$edges    = array();
		foreach ( array( Features::REQUIRES, Features::REQUIRES_ANY ) as $table ) {
			foreach ( $table as $key => $requirements ) {
				$this->assertContains( $key, $declared );
				$this->assertNotSame( array(), $requirements );
				foreach ( $requirements as $required ) {
					$this->assertContains( $required, $declared );
					$this->assertNotSame( $key, $required );
					$edges[ $key ][] = $required;
				}
			}
		}

		// No cycles (depth-first search).
		$visit = static function ( string $key, array $path ) use ( &$visit, $edges ): bool {
			if ( in_array( $key, $path, true ) ) {
				return false;
			}
			foreach ( $edges[ $key ] ?? array() as $next ) {
				if ( ! $visit( $next, array_merge( $path, array( $key ) ) ) ) {
					return false;
				}
			}
			return true;
		};
		foreach ( array_keys( $edges ) as $key ) {
			$this->assertTrue( $visit( $key, array() ), $key . ' is part of a cycle.' );
		}
	}

	/**
	 * "All" requirements: what is missing, and what blocks turning off.
	 */
	public function test_all_requirements(): void {
		$this->assertSame(
			array(
				'all' => array( Features::ABILITIES ),
				'any' => array(),
			),
			Features::missing_requirements( Features::MCP )
		);
		$this->assertSame(
			array(
				'all' => array(),
				'any' => array(),
			),
			Features::missing_requirements( Features::CATALOG )
		);

		Features::set( Features::CATALOG, true );
		Features::set( Features::ABILITIES, true );
		Features::set( Features::MCP, true );
		$this->assertSame( array(), Features::missing_requirements( Features::MCP )['all'] );
		$this->assertSame( array( Features::MCP ), Features::enabled_dependents( Features::ABILITIES ) );
		$this->assertSame( array( Features::ABILITIES ), Features::enabled_dependents( Features::CATALOG ) );
		$this->assertSame( array(), Features::enabled_dependents( Features::MCP ) );
	}

	/**
	 * "Any" requirements: one is enough; turning off is blocked only for the last one on.
	 */
	public function test_any_requirements(): void {
		$this->assertSame( array( Features::LLMS_TXT, Features::REST_API ), Features::missing_requirements( Features::DISCOVERY )['any'] );

		Features::set( Features::REST_API, true );
		$this->assertSame( array(), Features::missing_requirements( Features::DISCOVERY )['any'] );

		Features::set( Features::DISCOVERY, true );
		$this->assertContains( Features::DISCOVERY, Features::enabled_dependents( Features::REST_API ) );

		Features::set( Features::LLMS_TXT, true );
		$this->assertNotContains( Features::DISCOVERY, Features::enabled_dependents( Features::REST_API ), 'llms.txt still serves it.' );
		$this->assertNotContains( Features::DISCOVERY, Features::enabled_dependents( Features::LLMS_TXT ) );
	}
}
