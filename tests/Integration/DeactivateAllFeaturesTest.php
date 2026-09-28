<?php
/**
 * Deactivation with every feature on leaves no scheduled event behind.
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\Tests\Integration;

use AIHazirSite\Core\Features;
use AIHazirSite\WordPress\Inquiry\InquiryModule;
use AIHazirSite\WordPress\Lifecycle;
use AIHazirSite\WordPress\Measurement\MeasurementModule;
use AIHazirSite\WordPress\Updates\UpdateModule;
use WP_UnitTestCase;

/**
 * Every aihs_* WP-Cron event is gone after Lifecycle::deactivate().
 *
 * @coversNothing
 */
final class DeactivateAllFeaturesTest extends WP_UnitTestCase {

	/**
	 * All modules scheduled, then deactivated.
	 */
	public function test_no_event_left(): void {
		foreach ( array_keys( Features::defaults() ) as $key ) {
			Features::set( $key, true );
		}
		update_option( UpdateModule::SETTINGS, array( 'telemetry_url' => 'https://panel.example/ozet' ) );
		UpdateModule::telemetry()->give_consent();

		// Every place that schedules (see wp_schedule_event in src/). Registering all modules here would
		// start process-wide singletons (the MCP adapter) that other test classes set up themselves.
		( new MeasurementModule() )->schedule_events();
		InquiryModule::schedule();
		UpdateModule::schedule();

		$this->assertSame(
			array( MeasurementModule::PRUNE_HOOK, InquiryModule::PURGE_HOOK, MeasurementModule::REFRESH_HOOK, UpdateModule::TELEMETRY_HOOK ),
			self::ours(),
			'Everything was scheduled, so the check below means something.'
		);

		Lifecycle::deactivate();
		$this->assertSame( array(), self::ours(), 'Left after deactivation.' );
	}

	/**
	 * Our scheduled hooks.
	 *
	 * @return list<string>
	 */
	private static function ours(): array {
		$hooks = array();
		foreach ( (array) _get_cron_array() as $events ) {
			foreach ( array_keys( (array) $events ) as $hook ) {
				if ( str_starts_with( (string) $hook, 'aihs_' ) ) {
					$hooks[] = (string) $hook;
				}
			}
		}
		sort( $hooks );
		return array_values( array_unique( $hooks ) );
	}
}
