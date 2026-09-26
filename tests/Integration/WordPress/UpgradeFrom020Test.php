<?php
/**
 * 0.2.0 → 0.2.1: stored data, settings and scheduled events survive the refactor.
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\Tests\Integration\WordPress;

use AIHazirSite\Core\Features;
use AIHazirSite\Core\Measurement\Hit;
use AIHazirSite\Core\Measurement\IpRanges;
use AIHazirSite\Core\Migrations\Migrator;
use AIHazirSite\WordPress\Lifecycle;
use AIHazirSite\WordPress\Measurement\Admin\ReportPage;
use AIHazirSite\WordPress\Measurement\MeasurementModule;
use AIHazirSite\WordPress\Plugin;
use AIHazirSite\WordPress\Storage\WpdbHitRepository;
use AIHazirSite\WordPress\Uninstaller;
use WP_UnitTestCase;

/**
 * Upgrade integration tests.
 *
 * @coversNothing
 */
final class UpgradeFrom020Test extends WP_UnitTestCase {

	/**
	 * Names written by 0.2.0 must never change (sites already store them).
	 */
	public function test_stored_names_are_unchanged(): void {
		$this->assertSame( 'aihs_features', Features::OPTION );
		$this->assertSame( 'aihs_db_version', Migrator::OPTION );
		$this->assertSame( 'aihs_ip_ranges', IpRanges::OPTION );
		$this->assertSame( 'aihs_delete_data_on_uninstall', Uninstaller::DELETE_OPTION );
		$this->assertSame( 'aihs_hits', WpdbHitRepository::TABLE );
		$this->assertSame( 'aihs_refresh_ip_ranges', MeasurementModule::REFRESH_HOOK );
		$this->assertSame( 'aihs_prune_hits', MeasurementModule::PRUNE_HOOK );
		$this->assertSame( 'aihs-measurement', ReportPage::SLUG );
		$this->assertSame( array( 'bot', 'referral' ), array( Hit::KIND_BOT, Hit::KIND_REFERRAL ) );
		$this->assertSame( 200, max( array_map( static fn( $m ): int => $m->version(), Plugin::migrations() ) ), 'No new migration in 0.2.1.' );
	}

	/**
	 * A site in the exact state 0.2.0 leaves behind keeps everything after loading 0.2.1.
	 */
	public function test_state_written_by_020_is_read_back_unchanged(): void {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i', WpdbHitRepository::table() ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		Lifecycle::deactivate();

		// State as written by 0.2.0 (raw WordPress calls, not the new classes).
		$today = current_time( 'Y-m-d' );
		update_option( 'aihs_db_version', 200, true );
		update_option( 'aihs_features', array( 'measurement' => false ), true );
		update_option(
			'aihs_ip_ranges',
			array(
				'https://openai.com/gptbot.json' => array(
					'fetched'  => 1758900000,
					'prefixes' => array( '20.125.66.80/28' ),
				),
			),
			false
		);
		$wpdb->query( $wpdb->prepare( 'INSERT INTO %i (day, kind, source_id, path, verified, hits) VALUES (%s, %s, %s, %s, 1, 5), (%s, %s, %s, %s, 0, 2)', WpdbHitRepository::table(), $today, 'bot', 'gptbot', '/urunler/', $today, 'referral', 'chatgpt', '/' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$refresh_at = time() + 3600;
		$prune_at   = time() + 7200;
		wp_schedule_event( $refresh_at, 'daily', 'aihs_refresh_ip_ranges' );
		wp_schedule_event( $prune_at, 'weekly', 'aihs_prune_hits' );

		// 0.2.1 loads: upgrade check and event scheduling run.
		Lifecycle::maybe_upgrade();
		( new MeasurementModule() )->schedule_events();

		$this->assertSame( 200, (int) get_option( 'aihs_db_version' ), 'No migration re-run.' );
		$this->assertFalse( Features::is_enabled( Features::MEASUREMENT ), 'Owner choice kept.' );
		$this->assertTrue( MeasurementModule::ip_ranges()->contains( 'https://openai.com/gptbot.json', '20.125.66.81' ), 'IP lists kept.' );
		$this->assertSame( $refresh_at, wp_next_scheduled( 'aihs_refresh_ip_ranges' ), 'Event not rescheduled.' );
		$this->assertSame( $prune_at, wp_next_scheduled( 'aihs_prune_hits' ), 'Event not rescheduled.' );

		$rows = ReportPage::report( 7 )->rows();
		$this->assertSame(
			array(
				array( 'bots', 'GPTBot', '', 5, 0, 5 ),
				array( 'pages', '', '/urunler/', 5, 0, 5 ),
				array( 'referrals', 'ChatGPT', '', 0, 2, 2 ),
			),
			array_map( 'array_values', $rows )
		);
	}
}
