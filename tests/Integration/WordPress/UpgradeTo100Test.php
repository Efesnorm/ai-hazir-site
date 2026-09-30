<?php
/**
 * 0.2.x → 1.0.0: a site left by 0.2.0 upgrades straight to the MVP release.
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\Tests\Integration\WordPress;

use AIHazirSite\Core\Compliance\ScanStore;
use AIHazirSite\Core\Features;
use AIHazirSite\Core\Migrations\Migrator;
use AIHazirSite\WordPress\Inquiry\WpAuditRepository;
use AIHazirSite\WordPress\Inquiry\WpInquiryRepository;
use AIHazirSite\WordPress\Lifecycle;
use AIHazirSite\WordPress\Measurement\Admin\ReportPage;
use AIHazirSite\WordPress\Platform\WpSettings;
use AIHazirSite\WordPress\Plugin;
use AIHazirSite\WordPress\Report\BadgeModule;
use AIHazirSite\WordPress\Report\ComplianceReportView;
use AIHazirSite\WordPress\Storage\WpdbHitRepository;
use WP_UnitTestCase;

/**
 * Schema, data and settings after jumping from 0.2.0 to 1.0.0.
 *
 * @coversNothing
 */
final class UpgradeTo100Test extends WP_UnitTestCase {

	/**
	 * Real DDL (the test case otherwise turns CREATE/DROP into temporary tables).
	 */
	public function set_up(): void {
		parent::set_up();
		remove_filter( 'query', array( $this, '_create_temporary_tables' ) );
		remove_filter( 'query', array( $this, '_drop_temporary_tables' ) );
	}

	/**
	 * Leaves the schema at the latest version for the other tests.
	 */
	public function tear_down(): void {
		( new Migrator( Plugin::migrations(), new WpSettings() ) )->migrate();
		delete_option( Features::OPTION );
		parent::tear_down();
	}

	/**
	 * Whether a table exists.
	 *
	 * @param string $table Table name.
	 */
	private static function exists( string $table ): bool {
		global $wpdb;
		return $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Every later migration runs once, 0.2.0 data and choices stay, new features arrive switched off.
	 */
	public function test_upgrade_from_020_to_100(): void {
		global $wpdb;
		Lifecycle::deactivate();

		// The schema 0.2.0 leaves behind: only the hits table (version 200). Start from a known
		// state first, whatever earlier tests left in the version option.
		$migrator = new Migrator( Plugin::migrations(), new WpSettings() );
		$migrator->migrate();
		$migrator->rollback( 200 );
		$this->assertSame( 200, (int) get_option( 'aihs_db_version' ) );
		$this->assertFalse( self::exists( WpInquiryRepository::table() ) );
		$this->assertFalse( self::exists( WpAuditRepository::table() ) );

		// Data and settings as 0.2.0 writes them (raw WordPress calls).
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i', WpdbHitRepository::table() ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$today = current_time( 'Y-m-d' );
		$wpdb->query( $wpdb->prepare( 'INSERT INTO %i (day, kind, source_id, path, verified, hits) VALUES (%s, %s, %s, %s, 1, 4)', WpdbHitRepository::table(), $today, 'bot', 'claudebot', '/hakkimizda/' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		update_option( 'aihs_features', array( 'measurement' => true ), true );
		foreach ( array( ScanStore::OPTION, ScanStore::FIRST_OPTION ) as $option ) {
			delete_option( $option );
		}

		// 1.0.0 loads.
		Lifecycle::maybe_upgrade();

		$this->assertSame( 1200, (int) get_option( 'aihs_db_version' ) );
		$this->assertTrue( self::exists( WpInquiryRepository::table() ) );
		$this->assertTrue( self::exists( WpAuditRepository::table() ) );
		$this->assertSame(
			array( array( 'bots', 'ClaudeBot', '', 4, 0, 4 ), array( 'pages', '', '/hakkimizda/', 4, 0, 4 ) ),
			array_map( 'array_values', ReportPage::report( 7 )->rows() ),
			'0.2.0 measurement data read back unchanged.'
		);

		$enabled = array_keys( array_filter( array_combine( array_keys( Features::defaults() ), array_map( array( Features::class, 'is_enabled' ), array_keys( Features::defaults() ) ) ) ) );
		$this->assertSame( array( 'measurement', 'measurement_test_filter' ), $enabled, 'Every feature added after 0.2.0 arrives switched off, except the approved measurement_test_filter (1.17.0).' );

		// Turning the report on for a site without scans: empty report, no badge, honest page.
		Features::set( Features::COMPLIANCE_REPORT, true );
		$data = ComplianceReportView::data();
		$this->assertNull( $data['latest'] );
		$this->assertStringContainsString( 'id="aihs-report-empty"', ComplianceReportView::html( $data ) );
		$this->assertSame( '', do_shortcode( '[aihs_rozet]' ) );
		$this->assertStringContainsString( 'henüz ölçülmüş bir uyum taraması yok', BadgeModule::page_html() );

		// Running the upgrade again changes nothing.
		Lifecycle::maybe_upgrade();
		$this->assertSame( 1200, (int) get_option( 'aihs_db_version' ) );
	}
}
