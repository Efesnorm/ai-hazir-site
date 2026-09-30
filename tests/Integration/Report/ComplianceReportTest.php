<?php
/**
 * Compliance report: every number from the records; Turkish in the PDF.
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\Tests\Integration\Report;

use AIHazirSite\Core\Compliance\ScanStore;
use AIHazirSite\Core\Features;
use AIHazirSite\Core\Measurement\Hit;
use AIHazirSite\Core\Measurement\Report;
use AIHazirSite\Tests\Unit\Report\ComplianceReportDataTest;
use AIHazirSite\WordPress\Compliance\ComplianceModule;
use AIHazirSite\WordPress\Measurement\Admin\ReportPage;
use AIHazirSite\WordPress\Report\ComplianceReportView;
use AIHazirSite\WordPress\Storage\WpdbHitRepository;
use DOMDocument;
use DOMXPath;
use Smalot\PdfParser\Parser;
use WP_UnitTestCase;

/**
 * Report HTML and PDF against the stored scans and measurement.
 *
 * @covers \AIHazirSite\WordPress\Report\ComplianceReportView
 * @covers \AIHazirSite\Adapters\Report\ComplianceReportData
 */
final class ComplianceReportTest extends WP_UnitTestCase {

	/**
	 * Two scans, some hits, a Turkish site name.
	 */
	public function set_up(): void {
		parent::set_up();
		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i', WpdbHitRepository::table() ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		foreach ( array( ScanStore::OPTION, ScanStore::FIRST_OPTION, Features::OPTION ) as $option ) {
			delete_option( $option );
		}
		update_option( 'blogname', 'Örnek Kablo A.Ş. – ğşİıçöü' );
		Features::set( Features::COMPLIANCE_REPORT, true );
		Features::set( Features::LLMS_TXT, true );

		$store = ComplianceModule::store();
		$store->add(
			ComplianceReportDataTest::scan(
				'2026-09-01T10:00:00Z',
				array(
					'structured_data' => 0.0,
					'llms_txt'        => 0.0,
					'readability'     => 0.45,
				)
			)
		);
		$store->add(
			ComplianceReportDataTest::scan(
				'2026-09-27T10:00:00Z',
				array(
					'readability'       => 0.6,
					'machine_interface' => 0.5,
					'advanced'          => null,
				)
			)
		);

		$hits  = new WpdbHitRepository();
		$today = current_time( 'Y-m-d' );
		$hits->increment( $today, Hit::KIND_BOT, 'gptbot', '/urunler/', true );
		$hits->increment( $today, Hit::KIND_BOT, 'gptbot', '/llms.txt', false );
		$hits->increment( $today, Hit::KIND_BOT, 'claudebot', '/urunler/', false );
		$hits->increment( $today, Hit::KIND_REFERRAL, 'chatgpt', '/', false );
		$hits->increment( $today, Hit::KIND_MCP, 'aihs-search-listings', '/wp-json/aihs/mcp', false );
	}

	/**
	 * A report cell's number ("8,5" → 8.5).
	 *
	 * @param DOMXPath $xpath XPath.
	 * @param string   $query Query.
	 */
	private static function number( DOMXPath $xpath, string $query ): ?float {
		$node = $xpath->query( $query )->item( 0 );
		$text = null === $node ? '' : trim( (string) $node->textContent ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- DOM API.
		return is_numeric( str_replace( ',', '.', $text ) ) ? (float) str_replace( ',', '.', $text ) : null;
	}

	/**
	 * Every number in the report equals the stored record.
	 */
	public function test_numbers_match_the_records(): void {
		$dom = new DOMDocument();
		$dom->loadHTML( '<?xml encoding="utf-8"?><body>' . ComplianceReportView::html( ComplianceReportView::data() ) . '</body>', LIBXML_NOERROR );
		$xpath = new DOMXPath( $dom );

		$store  = ComplianceModule::store();
		$latest = $store->latest();
		$first  = $store->first();
		$this->assertNotNull( $latest );
		$this->assertNotNull( $first );

		$this->assertSame( (float) $latest->score(), self::number( $xpath, "//td[@data-value='latest-score']" ) );
		$this->assertSame( (float) $first->score(), self::number( $xpath, "//td[@data-value='first-score']" ) );
		$this->assertSame( (float) ( (int) $latest->score() - (int) $first->score() ), self::number( $xpath, "//td[@data-value='score-change']" ) );

		foreach ( $latest->results as $row ) {
			$base = "//tr[@data-check='{$row['id']}']";
			$this->assertSame( (float) $row['weight'], self::number( $xpath, "{$base}/td[@data-value='weight']" ), $row['id'] );
			$this->assertSame( (float) $row['gain'], self::number( $xpath, "{$base}/td[@data-value='gain']" ), $row['id'] );
			$this->assertSame( null === $row['ratio'] ? null : (float) $row['points'], self::number( $xpath, "{$base}/td[@data-value='points']" ), $row['id'] );
			$before = $first->result( $row['id'] );
			$this->assertSame( null === $before || null === $before['ratio'] ? null : (float) $before['points'], self::number( $xpath, "{$base}/td[@data-value='first']" ), $row['id'] );
		}

		$rows = ReportPage::report( 28 )->rows();
		$this->assertNotEmpty( $rows );
		foreach ( array( Report::SECTION_BOTS, Report::SECTION_PAGES, Report::SECTION_REFERRALS, Report::SECTION_AI_FILES, Report::SECTION_MCP ) as $section ) {
			$expected = array_column( Report::section( $rows, $section ), 'total' );
			$shown    = array();
			foreach ( $xpath->query( "//tr[@data-section='{$section}']/td[@data-value='total']" ) as $cell ) {
				$shown[] = (int) $cell->textContent; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- DOM API.
			}
			$this->assertSame( $expected, $shown, $section );
			$this->assertSame( (float) array_sum( $expected ), self::number( $xpath, "//tr[@data-section-total='{$section}']/td[@data-value='total']" ), $section );
		}

		$features = array();
		foreach ( $xpath->query( '//li[@data-feature]' ) as $item ) {
			$features[] = $item->getAttribute( 'data-feature' );
		}
		$this->assertSame( array( 'measurement', 'llms_txt', 'compliance_report', 'measurement_test_filter' ), $features );
	}

	/**
	 * The PDF: valid, DejaVu embedded, Turkish text and the same score.
	 */
	public function test_pdf_turkish(): void {
		$bytes = ComplianceReportView::pdf( ComplianceReportView::data() );
		$this->assertStringStartsWith( '%PDF-', $bytes );

		$pdf  = ( new Parser() )->parseContent( $bytes );
		$text = $pdf->getText();
		foreach ( array( 'AI Uyum Raporu', 'Örnek Kablo A.Ş. – ğşİıçöü', 'İçerik okunabilirliği', 'Doğrulanmış', 'Açık yetenekler', 'ölçülemedi' ) as $expected ) {
			$this->assertStringContainsString( $expected, $text );
		}
		$this->assertStringContainsString( (string) ComplianceModule::store()->latest()?->score(), $text );

		$fonts = array_map( static fn( $font ): string => (string) $font->getName(), $pdf->getFonts() );
		$this->assertNotEmpty( array_filter( $fonts, static fn( string $name ): bool => str_contains( $name, 'DejaVu' ) ), implode( ', ', $fonts ) );
	}

	/**
	 * No scan yet: the report says so.
	 */
	public function test_empty(): void {
		delete_option( ScanStore::OPTION );
		delete_option( ScanStore::FIRST_OPTION );
		$this->assertStringContainsString( 'id="aihs-report-empty"', ComplianceReportView::html( ComplianceReportView::data() ) );
	}
}
