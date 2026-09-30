<?php
/**
 * Our test requests are counted apart from real traffic (1.17.0).
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\Tests\Integration\Measurement;

use AIHazirSite\Core\Features;
use AIHazirSite\Core\Measurement\CsvExport;
use AIHazirSite\Core\Measurement\McpCalls;
use AIHazirSite\Core\Measurement\Report;
use AIHazirSite\Core\Measurement\Tracker;
use AIHazirSite\WordPress\Measurement\Admin\ReportPage;
use AIHazirSite\WordPress\Measurement\RequestListener;
use AIHazirSite\WordPress\Platform\WpClock;
use AIHazirSite\WordPress\Storage\WpdbHitRepository;
use WP_UnitTestCase;

/**
 * Found in the makedonya.tr export: our live checks (curl as GPTBot, MCP tries) were mixed into the bot counts.
 *
 * @covers \AIHazirSite\Core\Measurement\Tracker
 * @covers \AIHazirSite\Core\Measurement\McpCalls
 * @covers \AIHazirSite\Core\Measurement\Report
 * @covers \AIHazirSite\WordPress\Measurement\Admin\ReportPage
 */
final class TestTrafficMeasurementTest extends WP_UnitTestCase {

	private const GPTBOT_UA = 'Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko; compatible; GPTBot/1.4; +https://openai.com/gptbot)';

	/**
	 * Saved $_SERVER.
	 *
	 * @var array<mixed>
	 */
	private array $server;

	/**
	 * Empty counters; default features.
	 */
	public function set_up(): void {
		parent::set_up();
		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i', WpdbHitRepository::table() ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		delete_option( Features::OPTION );
		$this->server = $_SERVER;
	}

	/**
	 * Restores $_SERVER.
	 */
	public function tear_down(): void {
		$_SERVER = $this->server; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Test isolation.
		parent::tear_down();
	}

	/**
	 * On by default: the marked GPTBot request is a test hit; the same request unmarked is a bot hit.
	 */
	public function test_marked_request_counted_apart(): void {
		$this->assertTrue( Features::is_enabled( Features::MEASUREMENT_TEST_FILTER ) );

		$this->visit( self::GPTBOT_UA . ' AIHazirSite-Test/1.0' );
		$this->visit( self::GPTBOT_UA . ' AIHazirSite-Test/1.0', '/llms.txt' );
		$this->visit( self::GPTBOT_UA );

		$rows = ( new Report( new WpdbHitRepository(), new WpClock(), 7 ) )->rows();
		$bots = Report::section( $rows, Report::SECTION_BOTS );
		$this->assertCount( 1, $bots );
		$this->assertSame( 1, $bots[0]['total'], 'Only the unmarked request is bot traffic.' );
		$this->assertSame( array(), Report::section( $rows, Report::SECTION_AI_FILES ), 'The marked llms.txt read is not an AI file read.' );

		$tests = Report::section( $rows, Report::SECTION_TEST );
		$this->assertCount( 1, $tests );
		$this->assertSame( 'GPTBot', $tests[0]['source'] );
		$this->assertSame( 2, $tests[0]['total'] );

		$this->assertStringContainsString( "\n\"Test (hariç tutuldu)\",GPTBot,,0,2,2", CsvExport::to_csv( $rows ) );
		$this->assertStringContainsString( 'Test istekleri', ReportPage::render_tables( $rows ) );
	}

	/**
	 * A marked MCP call is a test hit too.
	 */
	public function test_marked_mcp_call(): void {
		$calls = new McpCalls( new WpdbHitRepository(), new WpClock() );
		$calls->count( 'aihs-check-availability', '/wp-json/aihs/mcp', 'curl/8.0 AIHazirSite-Test/1.0' );
		$calls->count( 'aihs-check-availability', '/wp-json/aihs/mcp', 'Claude-User/1.0' );

		$rows = ( new Report( new WpdbHitRepository(), new WpClock(), 7 ) )->rows();
		$this->assertSame( 1, Report::section( $rows, Report::SECTION_MCP )[0]['total'] );
		$this->assertSame( 'aihs-check-availability', Report::section( $rows, Report::SECTION_TEST )[0]['source'] );
	}

	/**
	 * Filter off: the token is ignored (the request is bot traffic as before 1.17.0).
	 */
	public function test_filter_off(): void {
		Features::set( Features::MEASUREMENT_TEST_FILTER, false );
		$this->visit( self::GPTBOT_UA . ' AIHazirSite-Test/1.0' );

		$rows = ( new Report( new WpdbHitRepository(), new WpClock(), 7 ) )->rows();
		$this->assertSame( 1, Report::section( $rows, Report::SECTION_BOTS )[0]['total'] );
		$this->assertSame( array(), Report::section( $rows, Report::SECTION_TEST ) );
	}

	/**
	 * Without test requests the export and the screen are unchanged (no test section).
	 */
	public function test_no_test_section_without_tests(): void {
		$this->visit( self::GPTBOT_UA );
		$rows = ( new Report( new WpdbHitRepository(), new WpClock(), 7 ) )->rows();
		$this->assertStringNotContainsString( 'Test', CsvExport::to_csv( $rows ) );
		$this->assertStringNotContainsString( 'Test istekleri', ReportPage::render_tables( $rows ) );
	}

	/**
	 * One request through the listener.
	 *
	 * @param string $user_agent User-Agent.
	 * @param string $uri        Request URI.
	 */
	private function visit( string $user_agent, string $uri = '/' ): void {
		$_SERVER['HTTP_USER_AGENT'] = $user_agent;
		$_SERVER['REQUEST_URI']     = $uri;
		$_SERVER['REMOTE_ADDR']     = '203.0.113.77';
		unset( $_SERVER['HTTP_REFERER'] );
		$tracker = new Tracker( new WpdbHitRepository(), new WpClock() );
		( new RequestListener( $tracker ) )->on_parse_request();
		$tracker->flush();
	}
}
