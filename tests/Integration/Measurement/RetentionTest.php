<?php
/**
 * 400-day retention.
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\Tests\Integration\Measurement;

use AIHazirSite\Core\Features;
use AIHazirSite\Core\Lifecycle;
use AIHazirSite\Core\Storage\HitStore;
use AIHazirSite\WordPress\Measurement\MeasurementModule;
use WP_UnitTestCase;

/**
 * Retention integration tests.
 *
 * @covers \AIHazirSite\WordPress\Measurement\MeasurementModule
 */
final class RetentionTest extends WP_UnitTestCase {

	/**
	 * Empty table and no scheduled events.
	 */
	public function set_up(): void {
		parent::set_up();
		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i', HitStore::table() ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		Lifecycle::deactivate();
	}

	/**
	 * Rows older than 400 days are deleted; the 400th day and newer are kept.
	 */
	public function test_prune_keeps_last_400_days(): void {
		global $wpdb;
		$store = new HitStore();
		$store->increment( '2025-08-22', HitStore::KIND_BOT, 'gptbot', '/', false ); // 401 days before 2026-09-27.
		$store->increment( '2025-08-23', HitStore::KIND_BOT, 'gptbot', '/', false ); // 400 days.
		$store->increment( '2026-09-27', HitStore::KIND_BOT, 'gptbot', '/', false );

		$this->assertSame( 1, ( new MeasurementModule() )->prune( '2026-09-27' ) );
		$this->assertSame(
			array( '2025-08-23', '2026-09-27' ),
			$wpdb->get_col( $wpdb->prepare( 'SELECT day FROM %i ORDER BY day', HitStore::table() ) ) // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		);
	}

	/**
	 * Both events are scheduled while measurement is on and removed on deactivation.
	 */
	public function test_events_scheduled_and_cleared(): void {
		( new MeasurementModule() )->schedule_events();

		$this->assertSame( 'weekly', wp_get_schedule( MeasurementModule::PRUNE_HOOK ) );
		$this->assertSame( 'daily', wp_get_schedule( MeasurementModule::REFRESH_HOOK ) );

		Lifecycle::deactivate();

		$this->assertFalse( wp_next_scheduled( MeasurementModule::PRUNE_HOOK ) );
		$this->assertFalse( wp_next_scheduled( MeasurementModule::REFRESH_HOOK ) );
	}

	/**
	 * With measurement off, retention still runs but IP lists are not refreshed.
	 */
	public function test_retention_runs_when_measurement_is_off(): void {
		Features::set( Features::MEASUREMENT, false );

		( new MeasurementModule() )->schedule_events();

		$this->assertSame( 'weekly', wp_get_schedule( MeasurementModule::PRUNE_HOOK ) );
		$this->assertFalse( wp_next_scheduled( MeasurementModule::REFRESH_HOOK ) );
		$this->assertSame( array(), ( new MeasurementModule() )->refresh_ip_ranges() );
	}
}
