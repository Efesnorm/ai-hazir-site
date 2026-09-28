<?php
/**
 * Report, admin page and CSV export.
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\Tests\Integration\Measurement;

use AIHazirSite\Core\Features;
use AIHazirSite\Core\Measurement\Hit;
use AIHazirSite\WordPress\Storage\WpdbHitRepository;
use AIHazirSite\Core\Measurement\CsvExport;
use AIHazirSite\Core\Measurement\Report;
use AIHazirSite\WordPress\Measurement\Admin\ReportPage;
use AIHazirSite\Tests\Support\FixedClock;
use DOMDocument;
use DOMXPath;
use WP_UnitTestCase;
use WPDieException;

/**
 * Report integration tests.
 *
 * @covers \AIHazirSite\Core\Measurement\Report
 * @covers \AIHazirSite\WordPress\Measurement\Admin\ReportPage
 * @covers \AIHazirSite\Core\Measurement\CsvExport
 */
final class ReportTest extends WP_UnitTestCase {

	/**
	 * Today in the site timezone.
	 *
	 * @var string
	 */
	private string $today;

	/**
	 * Seeds a known data set.
	 */
	public function set_up(): void {
		parent::set_up();
		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i', WpdbHitRepository::table() ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		$this->today = current_time( 'Y-m-d' );
		$store       = new WpdbHitRepository();
		$ago         = fn( int $days ): string => gmdate( 'Y-m-d', (int) strtotime( $this->today . ' 00:00:00 UTC' ) - $days * DAY_IN_SECONDS );

		// GPTBot: 2 verified + 1 unverified on /urunler/ today.
		$store->increment( $this->today, Hit::KIND_BOT, 'gptbot', '/urunler/', true );
		$store->increment( $this->today, Hit::KIND_BOT, 'gptbot', '/urunler/', true );
		$store->increment( $this->today, Hit::KIND_BOT, 'gptbot', '/urunler/', false );
		// ClaudeBot: 12 distinct pages 6 days ago (inside 7 days) → top-10 limit.
		for ( $i = 1; $i <= 12; $i++ ) {
			$store->increment( $ago( 6 ), Hit::KIND_BOT, 'claudebot', '/sayfa-' . str_pad( (string) $i, 2, '0', STR_PAD_LEFT ) . '/', false );
		}
		// CCBot 20 days ago: only in the 28-day view.
		$store->increment( $ago( 20 ), Hit::KIND_BOT, 'ccbot', '/eski/', false );
		// A second CCBot page.
		$store->increment( $ago( 20 ), Hit::KIND_BOT, 'ccbot', '/=cmd', false );
		// Referrals.
		$store->increment( $this->today, Hit::KIND_REFERRAL, 'chatgpt', '/', false );
		$store->increment( $ago( 1 ), Hit::KIND_REFERRAL, 'chatgpt', '/hakkimizda/', false );
		$store->increment( $ago( 2 ), Hit::KIND_REFERRAL, 'perplexity', '/', false );
		// Outside 28 days.
		$store->increment( $ago( 28 ), Hit::KIND_BOT, 'gptbot', '/cok-eski/', true );
	}

	/**
	 * The 7-day report aggregates per bot, limits pages to 10 and groups referrals.
	 */
	public function test_seven_day_rows(): void {
		$rows = ( new Report( new WpdbHitRepository(), new FixedClock( $this->today ), 7 ) )->rows();

		$this->assertSame(
			array(
				array( 'ClaudeBot', 0, 12, 12 ),
				array( 'GPTBot', 2, 1, 3 ),
			),
			array_map( static fn( array $r ): array => array( $r['source'], $r['verified'], $r['unverified'], $r['total'] ), Report::section( $rows, Report::SECTION_BOTS ) )
		);

		$pages = Report::section( $rows, Report::SECTION_PAGES );
		$this->assertCount( 10, $pages );
		$this->assertSame( array( '/urunler/', 3 ), array( $pages[0]['path'], $pages[0]['total'] ) );

		$this->assertSame(
			array( array( 'ChatGPT', 2 ), array( 'Perplexity', 1 ) ),
			array_map( static fn( array $r ): array => array( $r['source'], $r['total'] ), Report::section( $rows, Report::SECTION_REFERRALS ) )
		);
	}

	/**
	 * The 28-day report includes older rows but not rows from 28 days ago.
	 */
	public function test_twenty_eight_day_window(): void {
		$bots = Report::section( ( new Report( new WpdbHitRepository(), new FixedClock( $this->today ), 28 ) )->rows(), Report::SECTION_BOTS );

		$this->assertSame( array( 'ClaudeBot', 'GPTBot', 'CCBot' ), array_column( $bots, 'source' ) );
		$this->assertSame( 3, $bots[1]['total'], 'The row from 28 days ago is outside the window.' );
	}

	/**
	 * Every number in the CSV equals the number shown in the page tables, row by row.
	 */
	public function test_csv_matches_admin_page(): void {
		foreach ( Report::PERIODS as $days ) {
			$rows = ( new Report( new WpdbHitRepository(), new FixedClock( $this->today ), $days ) )->rows();

			$page = $this->page_cells( ReportPage::render_tables( $rows ) );
			$csv  = $this->csv_cells( CsvExport::to_csv( $rows, ReportPage::csv_headers(), ReportPage::section_labels() ) );

			$this->assertSame( $page['bots'], $csv['bots'], "Bots, {$days} days" );
			$this->assertSame( $page['pages'], $csv['pages'], "Pages, {$days} days" );
			$this->assertSame( $page['referrals'], $csv['referrals'], "Referrals, {$days} days" );
			$this->assertNotEmpty( $page['bots'] );
		}
	}

	/**
	 * Bot visits to llms.txt get their own table on the page and their own label in the CSV.
	 */
	public function test_ai_files_table(): void {
		( new WpdbHitRepository() )->increment( $this->today, Hit::KIND_BOT, 'gptbot', '/llms.txt', true );
		$rows = ( new Report( new WpdbHitRepository(), new FixedClock( $this->today ), 7 ) )->rows();

		$dom = new DOMDocument();
		$dom->loadHTML( '<?xml encoding="utf-8"?><body>' . ReportPage::render_tables( $rows ) . '</body>', LIBXML_NOERROR );
		$cells = array();
		foreach ( ( new DOMXPath( $dom ) )->query( "//table[@id='aihs-ai-files']/tbody/tr/td" ) as $td ) {
			$cells[] = $td->textContent; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- DOM API.
		}

		$this->assertSame( array( '/llms.txt', '1', '0', '1' ), $cells );
		$csv = CsvExport::to_csv( $rows, ReportPage::csv_headers(), ReportPage::section_labels() );
		$this->assertStringContainsString( "\"AI dosyası\",,/llms.txt,1,0,1\n", $csv );
	}

	/**
	 * The admin page shows the cache warning and the export link.
	 */
	public function test_admin_page_shows_warning(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		ob_start();
		( new ReportPage() )->render();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'Sayfa önbelleği kullanan sitelerde önbellekten sunulan istekler sayılamaz; sonuçlar alt sınırdır.', $html );
		$this->assertStringContainsString( 'action=aihs_export_hits', $html );
		$this->assertStringContainsString( '_wpnonce=', $html );
	}

	/**
	 * Export and toggle refuse users without the capability and requests without a nonce.
	 */
	public function test_actions_require_capability_and_nonce(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		try {
			( new ReportPage() )->authorize( ReportPage::TOGGLE_ACTION );
			$this->fail( 'Editor must be rejected.' );
		} catch ( WPDieException $e ) {
			$this->assertStringContainsString( 'yetkiniz yok', $e->getMessage() );
		}

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$_REQUEST['_wpnonce'] = 'invalid';
		try {
			( new ReportPage() )->authorize( ReportPage::TOGGLE_ACTION );
			$this->fail( 'Invalid nonce must be rejected.' );
		} catch ( WPDieException $e ) {
			$this->assertTrue( Features::is_enabled( Features::MEASUREMENT ) );
		} finally {
			unset( $_REQUEST['_wpnonce'] );
		}
	}

	/**
	 * Body cells of the three tables.
	 *
	 * @param string $html Tables HTML.
	 * @return array<string, list<list<string>>>
	 */
	private function page_cells( string $html ): array {
		$dom = new DOMDocument();
		$dom->loadHTML( '<?xml encoding="utf-8"?><body>' . $html . '</body>', LIBXML_NOERROR );
		$xpath = new DOMXPath( $dom );

		$cells = array();
		foreach ( array( 'bots', 'pages', 'referrals' ) as $id ) {
			$cells[ $id ] = array();
			foreach ( $xpath->query( "//table[@id='aihs-{$id}']/tbody/tr[not(td[@colspan])]" ) as $tr ) {
				$row = array();
				foreach ( $xpath->query( 'td', $tr ) as $td ) {
					$row[] = $td->textContent; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- DOM API.
				}
				$cells[ $id ][] = $row;
			}
		}
		return $cells;
	}

	/**
	 * CSV rows reshaped like the page tables.
	 *
	 * @param string $csv CSV document.
	 * @return array<string, list<list<string>>>
	 */
	private function csv_cells( string $csv ): array {
		$this->assertStringStartsWith( CsvExport::BOM, $csv );
		// Same CSV rules as CsvExport writes (no escape character); PHP 8.4 requires $escape explicitly.
		$lines  = array_map( static fn( string $line ): array => str_getcsv( $line, ',', '"', '' ), explode( "\n", trim( substr( $csv, strlen( CsvExport::BOM ) ) ) ) );
		$labels = array_flip( ReportPage::section_labels() );

		$cells = array(
			'bots'      => array(),
			'pages'     => array(),
			'referrals' => array(),
		);
		foreach ( array_slice( $lines, 1 ) as $line ) {
			[ $section, $source, $path, $verified, $unverified, $total ] = $line;
			switch ( $labels[ $section ] ) {
				case Report::SECTION_BOTS:
					$cells['bots'][] = array( $source, $verified, $unverified, $total );
					break;
				case Report::SECTION_PAGES:
					$cells['pages'][] = array( ltrim( $path, "'" ), $verified, $unverified, $total );
					break;
				default:
					$cells['referrals'][] = array( $source, $total );
			}
		}
		return $cells;
	}
}
