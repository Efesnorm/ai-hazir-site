<?php
/**
 * Compliance scan storage, feature key and interaction with A0 measurement.
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\Tests\Integration\Compliance;

use AIHazirSite\Core\Compliance\Scanner;
use AIHazirSite\Core\Compliance\ScanStore;
use AIHazirSite\Core\Compliance\ScanToken;
use AIHazirSite\Core\Compliance\Site;
use AIHazirSite\Core\Features;
use AIHazirSite\Core\Measurement\Tracker;
use AIHazirSite\Tests\Support\ComplianceSites;
use AIHazirSite\WordPress\Measurement\RequestListener;
use AIHazirSite\WordPress\Platform\WpClock;
use AIHazirSite\WordPress\Platform\WpSecret;
use AIHazirSite\WordPress\Platform\WpSettings;
use AIHazirSite\WordPress\Storage\WpdbHitRepository;
use AIHazirSite\WordPress\Uninstaller;
use WP_UnitTestCase;

/**
 * Compliance integration tests.
 *
 * @covers \AIHazirSite\Core\Compliance\ScanStore
 * @covers \AIHazirSite\WordPress\Measurement\RequestListener::is_scan_request
 */
final class ScanIntegrationTest extends WP_UnitTestCase {

	/**
	 * Saved $_SERVER.
	 *
	 * @var array<mixed>
	 */
	private array $server;

	/**
	 * Clean table, saved globals.
	 */
	public function set_up(): void {
		parent::set_up();
		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i', WpdbHitRepository::table() ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		delete_option( Features::OPTION );
		$this->server = $_SERVER;
	}

	/**
	 * Restores globals.
	 */
	public function tear_down(): void {
		$_SERVER = $this->server;
		parent::tear_down();
	}

	/**
	 * The feature ships disabled; its key is declared.
	 */
	public function test_feature_is_off_by_default(): void {
		$this->assertArrayHasKey( 'compliance_scan', Features::defaults() );
		$this->assertFalse( Features::is_enabled( Features::COMPLIANCE_SCAN ) );
	}

	/**
	 * A scan report is stored in aihs_scans (not autoloaded) and removed on opted-in uninstall.
	 */
	public function test_report_is_stored(): void {
		$report = ( new Scanner( Scanner::default_checks(), new WpClock() ) )->scan( ComplianceSites::site( ComplianceSites::perfect() ) );
		( new ScanStore( new WpSettings() ) )->add( $report );

		$this->assertSame( 100, ( new ScanStore( new WpSettings() ) )->latest()?->score() );
		wp_cache_delete( 'alloptions', 'options' );
		$this->assertArrayNotHasKey( ScanStore::OPTION, wp_load_alloptions() );
		$this->assertContains( ScanStore::OPTION, Uninstaller::options() );
	}

	/**
	 * The scanner's own bot-UA requests are not counted by A0; a forged marker is.
	 */
	public function test_scan_requests_are_not_counted_as_bot_visits(): void {
		global $wpdb;
		$tracker  = new Tracker( new WpdbHitRepository(), new WpClock() );
		$listener = new RequestListener( $tracker );
		$request  = array(
			'HTTP_USER_AGENT' => 'Mozilla/5.0 (compatible; GPTBot/1.4)',
			'REQUEST_URI'     => '/',
			'REMOTE_ADDR'     => '203.0.113.9',
		);

		$_SERVER = array_merge( $_SERVER, $request, array( 'HTTP_X_AIHS_SCAN' => ScanToken::value( new WpSecret() ) ) );
		$listener->on_parse_request();
		$this->assertFalse( $tracker->flush(), 'Own scan is not counted.' );

		$_SERVER = array_merge( $_SERVER, array( 'HTTP_X_AIHS_SCAN' => 'sahte' ) );
		$listener->on_parse_request();
		$this->assertTrue( $tracker->flush(), 'A forged marker does not hide a visit.' );

		$this->assertSame( '1', $wpdb->get_var( $wpdb->prepare( 'SELECT SUM(hits) FROM %i', WpdbHitRepository::table() ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$this->assertSame( Site::SCAN_HEADER, 'X-AIHS-Scan' );
	}
}
