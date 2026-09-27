<?php
/**
 * Tests for ComplianceReportData, Badge, BadgeSvg and the first-scan record.
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\Tests\Unit\Report;

use AIHazirSite\Adapters\Report\BadgeSvg;
use AIHazirSite\Adapters\Report\ComplianceReportData;
use AIHazirSite\Core\Compliance\Badge;
use AIHazirSite\Core\Compliance\ScanStore;
use AIHazirSite\Core\Compliance\ScoreReport;
use AIHazirSite\Core\Measurement\Report;
use AIHazirSite\Tests\Support\MemorySettings;
use PHPUnit\Framework\TestCase;

/**
 * Single-source report data and the badge rule.
 *
 * @covers \AIHazirSite\Adapters\Report\ComplianceReportData
 * @covers \AIHazirSite\Adapters\Report\BadgeSvg
 * @covers \AIHazirSite\Core\Compliance\Badge
 * @covers \AIHazirSite\Core\Compliance\ScanStore
 */
final class ComplianceReportDataTest extends TestCase {

	/**
	 * A scan with the given ratios (null = not measured).
	 *
	 * @param string                    $at      Time.
	 * @param array<string, float|null> $ratios  Check → ratio.
	 * @param int                       $version Score version.
	 */
	public static function scan( string $at, array $ratios, int $version = 2 ): ScoreReport {
		$weights = array(
			'structured_data'   => 20,
			'readability'       => 20,
			'machine_interface' => 20,
			'bot_access'        => 15,
			'llms_txt'          => 10,
			'freshness'         => 10,
			'advanced'          => 5,
		);
		$rows    = array();
		foreach ( $weights as $id => $weight ) {
			$ratio  = array_key_exists( $id, $ratios ) ? $ratios[ $id ] : 1.0;
			$points = null === $ratio ? 0.0 : round( $weight * $ratio, 2 );
			$rows[] = array(
				'id'       => $id,
				'weight'   => $weight,
				'ratio'    => $ratio,
				'points'   => $points,
				'gain'     => null === $ratio ? 0.0 : round( $weight - $points, 2 ),
				'level'    => 1.0 === $ratio ? 'info' : 'warning',
				'findings' => array( 'Bulgu: ' . $id ),
				'fix'      => '',
			);
		}
		return new ScoreReport( $at, $version, $rows );
	}

	/**
	 * Measurement rows of the A0 report.
	 *
	 * @return list<array{section: string, source: string, path: string, verified: int, unverified: int, total: int}>
	 */
	private static function measurement(): array {
		$row = static fn( string $section, string $source, string $path, int $verified, int $unverified ): array => array(
			'section'    => $section,
			'source'     => $source,
			'path'       => $path,
			'verified'   => $verified,
			'unverified' => $unverified,
			'total'      => $verified + $unverified,
		);
		return array(
			$row( Report::SECTION_BOTS, 'GPTBot', '', 4, 1 ),
			$row( Report::SECTION_BOTS, 'ClaudeBot', '', 0, 3 ),
			$row( Report::SECTION_PAGES, '', '/urunler/', 4, 2 ),
			$row( Report::SECTION_REFERRALS, 'ChatGPT', '', 0, 7 ),
			$row( Report::SECTION_AI_FILES, '', '/llms.txt', 1, 1 ),
			$row( Report::SECTION_MCP, 'aihs-search-listings', '', 0, 9 ),
		);
	}

	/**
	 * Every figure is the stored one; differences are latest − first.
	 */
	public function test_numbers_come_from_the_records(): void {
		$first  = self::scan(
			'2026-09-01T10:00:00Z',
			array(
				'structured_data' => 0.0,
				'llms_txt'        => 0.0,
				'readability'     => 0.5,
			)
		);
		$latest = self::scan(
			'2026-09-27T10:00:00Z',
			array(
				'readability'       => 0.6,
				'machine_interface' => 0.5,
				'advanced'          => null,
			)
		);
		$data   = ComplianceReportData::build(
			$first,
			$latest,
			self::measurement(),
			array(
				'measurement' => true,
				'llms_txt'    => true,
				'mcp'         => false,
			),
			'Örnek Kablo',
			'2026-09-28T09:00:00Z'
		);

		$this->assertSame( array( $latest->score(), $first->score() ), array( $data['latest']['score'], $data['first']['score'] ) );
		$this->assertSame( (int) $latest->score() - (int) $first->score(), $data['score_change'] );
		$this->assertTrue( $data['comparable'] );

		foreach ( $data['checks'] as $check ) {
			$row = $latest->result( $check['id'] );
			$this->assertNotNull( $row );
			$this->assertSame( array( $row['weight'], $row['ratio'], $row['points'], $row['gain'] ), array( $check['weight'], $check['ratio'], $check['points'], $check['gain'] ), $check['id'] );
		}
		$by_id = array_column( $data['checks'], null, 'id' );
		$this->assertSame( 20.0, $by_id['structured_data']['change'] );
		$this->assertSame( 2.0, $by_id['readability']['change'] );
		$this->assertNull( $by_id['advanced']['change'], 'Not measured now: no change shown.' );

		$this->assertSame( 8, $data['measurement_totals'][ Report::SECTION_BOTS ] );
		$this->assertSame( 9, $data['measurement_totals'][ Report::SECTION_MCP ] );
		$this->assertSame( array( 'GPTBot', 'ClaudeBot' ), array_column( $data['measurement'][ Report::SECTION_BOTS ], 'source' ) );
		$this->assertSame( array( 'measurement', 'llms_txt' ), $data['features'] );
	}

	/**
	 * Different scoring versions are not compared; no scan at all is an empty report.
	 */
	public function test_not_comparable_and_empty(): void {
		$data = ComplianceReportData::build( self::scan( '2026-01-01T00:00:00Z', array(), 1 ), self::scan( '2026-09-27T00:00:00Z', array() ), array(), array(), 'Site', 'now' );
		$this->assertFalse( $data['comparable'] );
		$this->assertNull( $data['score_change'] );
		$this->assertNull( $data['checks'][0]['change'] );

		$empty = ComplianceReportData::build( null, null, array(), array(), 'Site', 'now' );
		$this->assertSame( array( null, null, array() ), array( $empty['latest'], $empty['first'], $empty['checks'] ) );
	}

	/**
	 * Badge: at or above the threshold only, never without a measured scan.
	 */
	public function test_badge_threshold(): void {
		$seventy    = self::scan( 't', array( 'structured_data' => 0.0, 'readability' => 0.5 ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		$sixty_nine = self::scan( 't', array( 'structured_data' => 0.0, 'readability' => 0.45 ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		$this->assertSame( array( 70, 69 ), array( $seventy->score(), $sixty_nine->score() ) );

		$this->assertTrue( Badge::earned( $seventy ) );
		$this->assertFalse( Badge::earned( $sixty_nine ) );
		$this->assertFalse( Badge::earned( null ) );
		$this->assertFalse( Badge::earned( new ScoreReport( 't', 2, array() ) ), 'Nothing measured.' );
		$this->assertTrue( Badge::earned( $sixty_nine, 60 ) );
		$this->assertFalse( Badge::earned( $seventy, 500 ), 'Threshold clamped to 100.' );
	}

	/**
	 * The SVG: score, date, accessible name, escaped text.
	 */
	public function test_badge_svg(): void {
		$svg = BadgeSvg::render( 'AI Hazır', 82, '27.09.2026', 'AI Hazır <rozeti>: 82/100' );

		$this->assertStringStartsWith( '<svg ', $svg );
		$this->assertStringContainsString( 'role="img"', $svg );
		$this->assertStringContainsString( 'aria-label="AI Hazır &lt;rozeti&gt;: 82/100"', $svg );
		$this->assertStringContainsString( '>82</text>', $svg );
		$this->assertStringContainsString( '>27.09.2026</text>', $svg );
		$this->assertNotFalse( simplexml_load_string( $svg ), 'Well-formed XML.' );
	}

	/**
	 * The first scan survives the 20-scan history.
	 */
	public function test_first_scan_kept(): void {
		$store = new ScanStore( new MemorySettings() );
		$this->assertNull( $store->first() );
		for ( $i = 1; $i <= 25; $i++ ) {
			$store->add( self::scan( sprintf( '2026-09-%02dT00:00:00Z', $i ), array() ) );
		}
		$this->assertCount( ScanStore::KEEP, $store->all() );
		$this->assertSame( '2026-09-01T00:00:00Z', $store->first()?->scanned_at );

		$legacy = new MemorySettings();
		$legacy->set( ScanStore::OPTION, array( self::scan( 'b', array() )->to_array(), self::scan( 'a', array() )->to_array() ) );
		$this->assertSame( 'a', ( new ScanStore( $legacy ) )->first()?->scanned_at, 'Before 1.0.0: the oldest stored scan.' );
	}
}
