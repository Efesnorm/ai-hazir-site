<?php
/**
 * Tests for McpCalls.
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\Tests\Unit\Measurement;

use AIHazirSite\Core\Features;
use AIHazirSite\Core\Measurement\Hit;
use AIHazirSite\Core\Measurement\McpCalls;
use AIHazirSite\Core\Measurement\Report;
use AIHazirSite\Tests\Support\FixedClock;
use AIHazirSite\Tests\Support\MemoryHitRepository;
use AIHazirSite\Tests\Support\MemorySettings;
use PHPUnit\Framework\TestCase;

/**
 * MCP tool calls as kind = mcp counters.
 *
 * @covers \AIHazirSite\Core\Measurement\McpCalls
 * @covers \AIHazirSite\Core\Measurement\Report
 */
final class McpCallsTest extends TestCase {

	/**
	 * Counted per tool into their own report section; nothing else stored.
	 */
	public function test_count_and_report(): void {
		Features::use_settings( new MemorySettings() );
		$hits  = new MemoryHitRepository();
		$calls = new McpCalls( $hits, new FixedClock( '2026-09-27' ) );

		$this->assertTrue( $calls->count( 'aihs-search-listings', '/wp-json/aihs/mcp' ) );
		$this->assertTrue( $calls->count( 'aihs-search-listings', '/wp-json/aihs/mcp?x=1' ) );
		$this->assertTrue( $calls->count( 'aihs-get<script>-profile', '/wp-json/aihs/mcp' ) );
		$this->assertFalse( $calls->count( '<>', '/wp-json/aihs/mcp' ) );

		$rows = ( new Report( $hits, new FixedClock( '2026-09-27' ), 7 ) )->rows();
		$this->assertSame(
			array( array( 'aihs-search-listings', 2 ), array( 'aihs-getscript-profile', 1 ) ),
			array_map( static fn( array $r ): array => array( $r['source'], $r['total'] ), Report::section( $rows, Report::SECTION_MCP ) )
		);
		$this->assertSame( array( Hit::KIND_MCP ), array_values( array_unique( array_column( $hits->rows, 'kind' ) ) ) );
		$this->assertSame( array( '/wp-json/aihs/mcp' ), array_values( array_unique( array_column( $hits->rows, 'path' ) ) ) );
	}

	/**
	 * Measurement off: nothing counted.
	 */
	public function test_measurement_off(): void {
		$settings = new MemorySettings();
		Features::use_settings( $settings );
		Features::set( Features::MEASUREMENT, false );
		$hits = new MemoryHitRepository();

		$this->assertFalse( ( new McpCalls( $hits, new FixedClock() ) )->count( 'aihs-get-profile', '/wp-json/aihs/mcp' ) );
		$this->assertSame( array(), $hits->rows );
	}
}
