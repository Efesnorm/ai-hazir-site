<?php
/**
 * Tests for the seven U1 checks.
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\Tests\Unit\Compliance;

use AIHazirSite\Core\Compliance\Check;
use AIHazirSite\Core\Compliance\Checks\AdvancedCheck;
use AIHazirSite\Core\Compliance\Checks\BotAccessCheck;
use AIHazirSite\Core\Compliance\Checks\FreshnessCheck;
use AIHazirSite\Core\Compliance\Checks\LlmsTxtCheck;
use AIHazirSite\Core\Compliance\Checks\MachineInterfaceCheck;
use AIHazirSite\Core\Compliance\Checks\ReadabilityCheck;
use AIHazirSite\Core\Compliance\Checks\StructuredDataCheck;
use AIHazirSite\Core\Compliance\Scanner;
use AIHazirSite\Core\Contracts\PageResponse;
use AIHazirSite\Tests\Support\ComplianceSites;
use AIHazirSite\Tests\Support\FakePageFetcher;
use AIHazirSite\Tests\Support\FixedClock;
use PHPUnit\Framework\TestCase;

/**
 * Full-score, zero-score and unreachable samples for every check, plus partial cases.
 *
 * @covers \AIHazirSite\Core\Compliance\Checks\StructuredDataCheck
 * @covers \AIHazirSite\Core\Compliance\Checks\ReadabilityCheck
 * @covers \AIHazirSite\Core\Compliance\Checks\MachineInterfaceCheck
 * @covers \AIHazirSite\Core\Compliance\Checks\BotAccessCheck
 * @covers \AIHazirSite\Core\Compliance\Checks\LlmsTxtCheck
 * @covers \AIHazirSite\Core\Compliance\Checks\FreshnessCheck
 * @covers \AIHazirSite\Core\Compliance\Checks\AdvancedCheck
 * @covers \AIHazirSite\Core\Compliance\Scanner::default_checks
 */
final class ChecksTest extends TestCase {

	/**
	 * All default checks.
	 *
	 * @return array<string, array{Check}>
	 */
	public static function checks(): array {
		$list = array();
		foreach ( Scanner::default_checks() as $check ) {
			$list[ $check->id() ] = array( $check );
		}
		return $list;
	}

	/**
	 * The ideal site earns the full weight on every check.
	 *
	 * @dataProvider checks
	 *
	 * @param Check $check Check.
	 */
	public function test_full_score( Check $check ): void {
		$result = $check->run( ComplianceSites::site( ComplianceSites::perfect() ) );

		$this->assertSame( 1.0, $result->ratio, implode( "\n", $result->findings ) );
		$this->assertSame( '', $result->fix );
	}

	/**
	 * The weak site earns nothing on every check.
	 *
	 * @dataProvider checks
	 *
	 * @param Check $check Check.
	 */
	public function test_zero_score( Check $check ): void {
		$result = $check->run( ComplianceSites::site( ComplianceSites::weak() ) );

		$this->assertSame( 0.0, $result->ratio, implode( "\n", $result->findings ) );
		$this->assertNotSame( '', $result->fix, 'A failing check explains how to fix it.' );
	}

	/**
	 * An unreachable site is "not measured" everywhere; nothing throws.
	 *
	 * @dataProvider checks
	 *
	 * @param Check $check Check.
	 */
	public function test_unreachable_is_not_measured( Check $check ): void {
		$result = $check->run( ComplianceSites::site( ComplianceSites::unreachable() ) );

		$this->assertNull( $result->ratio );
		$this->assertFalse( $result->is_measured() );
	}

	/**
	 * Weights are the PRD weights and sum to 100.
	 */
	public function test_weights_match_prd(): void {
		$weights = array();
		foreach ( Scanner::default_checks() as $check ) {
			$weights[ $check->id() ] = $check->weight();
		}

		$this->assertSame(
			array(
				'structured_data'   => 20,
				'readability'       => 20,
				'machine_interface' => 20,
				'bot_access'        => 15,
				'llms_txt'          => 10,
				'freshness'         => 10,
				'advanced'          => 5,
			),
			$weights
		);
		$this->assertSame( 100, array_sum( $weights ) );
	}

	/**
	 * Ideal site scores 100, weak site 0, unreachable site has no score.
	 */
	public function test_full_scans(): void {
		$scanner = new Scanner( Scanner::default_checks(), new FixedClock() );

		$this->assertSame( 100, $scanner->scan( ComplianceSites::site( ComplianceSites::perfect() ) )->score() );
		$this->assertSame( 0, $scanner->scan( ComplianceSites::site( ComplianceSites::weak() ) )->score() );
		$this->assertNull( $scanner->scan( ComplianceSites::site( ComplianceSites::unreachable() ) )->score() );
	}

	/**
	 * Even when every request takes the full 5 s timeout, the scan stays within 60 s.
	 */
	public function test_scan_stays_within_60_seconds(): void {
		$fetcher          = ComplianceSites::perfect();
		$fetcher->elapsed = 5000;
		$site             = ComplianceSites::site( $fetcher );

		$report = ( new Scanner( Scanner::default_checks(), new FixedClock() ) )->scan( $site );

		$this->assertLessThanOrEqual( 60000, $site->elapsed_ms() );
		$this->assertSame( 60000, $report->elapsed_ms );
		$this->assertCount( 12, $fetcher->requests );
		$this->assertCount( 7, $report->results );
	}

	/**
	 * Partial results.
	 */
	public function test_partial_results(): void {
		$site = static function ( array $responses ): \AIHazirSite\Core\Compliance\Site {
			return ComplianceSites::site( new FakePageFetcher( $responses ) );
		};
		$b    = ComplianceSites::BASE;

		// Product without offers → half credit.
		$page = '<script type="application/ld+json">{"@type":"Product","name":"Kablo"}</script>';
		$this->assertSame( 0.5, ( new StructuredDataCheck() )->run( $site( array( $b => $page ) ) )->ratio );

		// llms.txt without H1 → half credit.
		$this->assertSame( 0.5, ( new LlmsTxtCheck() )->run( $site( array( $b . 'llms.txt' => new PageResponse( 200, array(), "Firma özeti\n" ) ) ) )->ratio );

		// Agent card with missing required fields → half credit.
		$this->assertSame( 0.5, ( new AdvancedCheck() )->run( $site( array( $b . '.well-known/agent-card.json' => new PageResponse( 200, array(), '{"name":"x"}' ) ) ) )->ratio );

		// REST only by convention, no MCP → quarter credit.
		$this->assertSame(
			0.25,
			( new MachineInterfaceCheck() )->run(
				$site(
					array(
						$b              => '<p>x</p>',
						$b . 'wp-json/' => new PageResponse( 200, array(), '{}' ),
					)
				)
			)->ratio
		);

		// JSON-LD without dates → freshness 0; readable page without contact → 0.8.
		$words = str_repeat( 'kelime ', 60 );
		$this->assertSame( 0.0, ( new FreshnessCheck() )->run( $site( array( $b => $page ) ) )->ratio );
		$this->assertEqualsWithDelta( 0.8, ( new ReadabilityCheck() )->run( $site( array( $b => '<p>' . $words . '</p>' ) ) )->ratio, 0.0001 );

		// X-Robots-Tag: noindex header counts like the meta tag.
		$this->assertSame( 0.0, ( new ReadabilityCheck() )->run( $site( array( $b => new PageResponse( 200, array( 'x-robots-tag' => 'noindex' ), '<p>' . $words . '</p>' ) ) ) )->ratio );

		// robots.txt 5xx → all bots disallowed (RFC 9309 §2.3.1.4); pages still answer → 0.5.
		$this->assertSame(
			0.5,
			( new BotAccessCheck() )->run(
				$site(
					array(
						$b                => '<p>x</p>',
						$b . 'robots.txt' => new PageResponse( 503 ),
					)
				)
			)->ratio
		);

		// Only GPTBot blocked: 17/18 bots allowed (1.4.0 bot list).
		$result = ( new BotAccessCheck() )->run(
			$site(
				array(
					$b                => '<p>x</p>',
					$b . 'robots.txt' => new PageResponse( 200, array(), "User-agent: GPTBot\nDisallow: /\n" ),
				)
			)
		);
		$this->assertEqualsWithDelta( 0.5 * 17 / 18 + 0.5, $result->ratio, 0.0001 );
		$this->assertStringContainsString( 'GPTBot', implode( ' ', $result->findings ) );
	}
}
