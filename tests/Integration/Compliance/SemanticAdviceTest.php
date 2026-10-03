<?php
/**
 * Semantic structure advice on the scan screen and in the report (1.19.0).
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\Tests\Integration\Compliance;

use AIHazirSite\Core\Compliance\Scanner;
use AIHazirSite\Core\Compliance\ScoreReport;
use AIHazirSite\Core\Compliance\SemanticStructure;
use AIHazirSite\Core\Compliance\Site;
use AIHazirSite\Core\Contracts\PageFetcher;
use AIHazirSite\Core\Contracts\PageResponse;
use AIHazirSite\WordPress\Compliance\Admin\CompliancePage;
use AIHazirSite\WordPress\Platform\WpClock;
use AIHazirSite\WordPress\Report\ComplianceReportView;
use WP_UnitTestCase;

/**
 * Shown apart from the score, escaped, and absent when there is nothing to advise.
 *
 * @covers \AIHazirSite\Core\Compliance\Scanner
 * @covers \AIHazirSite\WordPress\Compliance\Admin\CompliancePage
 * @covers \AIHazirSite\WordPress\Report\ComplianceReportView
 */
final class SemanticAdviceTest extends WP_UnitTestCase {

	/**
	 * A scan of a site without <main> carries advice; the score and its version are those of the checks alone.
	 */
	public function test_scan_adds_advice_without_changing_the_score(): void {
		$fetcher = new class() implements PageFetcher {
			/**
			 * Every URL answers the same page without <main> and with two <h1>.
			 *
			 * @param string                $url     URL.
			 * @param array<string, string> $headers Headers.
			 * @param int                   $timeout Timeout.
			 */
			public function fetch( string $url, array $headers = array(), int $timeout = 5 ): PageResponse {
				return new PageResponse( 200, array( 'content-type' => 'text/html' ), '<html lang="tr"><head><title>Örnek</title></head><body><h1>A</h1><h1>B</h1><p>' . str_repeat( 'kelime ', 60 ) . '</p></body></html>', 1 );
			}
		};
		$report  = ( new Scanner( Scanner::default_checks(), new WpClock() ) )->scan( new Site( 'https://ornek.example/', $fetcher ) );

		$this->assertSame( 4, $report->score_version, 'Scoring rules unchanged.' );
		$this->assertContains( 'https://ornek.example/: Ana içerik <main> ile işaretlenmemiş; agentlar menü, altbilgi ve içeriği ayıramaz.', $report->advice );
		$this->assertContains( 'https://ornek.example/: 2 tane <h1> var; bir tane olmalı.', $report->advice );

		$plain = new ScoreReport( $report->scanned_at, $report->score_version, $report->results, $report->elapsed_ms );
		$this->assertSame( $plain->score(), $report->score(), 'Advice never changes the score.' );
	}

	/**
	 * Scan screen and report: section with escaped findings; nothing without advice.
	 */
	public function test_screen_and_report(): void {
		$report = new ScoreReport( '2026-10-04T00:00:00Z', 4, array(), 0, array( 'https://ornek.example/: Ana başlık (<h1>) yok.' ) );

		$screen = CompliancePage::render_report( $report );
		$this->assertStringContainsString( 'id="aihs-semantic"', $screen );
		$this->assertStringContainsString( 'Ana başlık (&lt;h1&gt;) yok.', $screen );
		$this->assertStringNotContainsString( '(<h1>)', $screen );
		$this->assertStringContainsString( 'puana dahil değil', $screen );

		$this->assertSame( '', CompliancePage::render_advice( new ScoreReport( '2026-10-04T00:00:00Z', 4, array() ) ) );

		$data           = ComplianceReportView::data();
		$data['latest'] = array(
			'score'         => 50,
			'scanned_at'    => '2026-10-04T00:00:00Z',
			'score_version' => 4,
		);
		$data['advice'] = $report->advice;
		$html           = ComplianceReportView::html( $data );
		$this->assertStringContainsString( 'id="aihs-report-advice"', $html );
		$this->assertStringContainsString( esc_html( SemanticStructure::FIX ), $html );
	}
}
