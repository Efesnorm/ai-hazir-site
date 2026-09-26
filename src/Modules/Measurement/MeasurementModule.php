<?php
/**
 * AI bot and referral measurement (A0).
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\Modules\Measurement;

use AIHazirSite\Core\Features;
use AIHazirSite\Core\Module;
use AIHazirSite\Core\Storage\HitStore;

/**
 * Wires the tracker into WordPress. When the `measurement` feature is off,
 * no counting hooks are registered and cron callbacks do nothing.
 */
final class MeasurementModule implements Module {

	public const REFRESH_HOOK = 'aihs_refresh_ip_ranges';

	/**
	 * Registers hooks.
	 */
	public function register(): void {
		add_action( self::REFRESH_HOOK, array( $this, 'on_refresh_ip_ranges' ) );

		if ( ! Features::is_enabled( Features::MEASUREMENT ) ) {
			return;
		}

		$tracker = new Tracker( new HitStore(), null, new Verifier( new IpRanges() ) );
		add_action( 'parse_request', array( $tracker, 'capture_current_request' ), 0 );
		add_action( 'shutdown', array( $tracker, 'on_shutdown' ) );
		add_action( 'init', array( $this, 'schedule_events' ) );
	}

	/**
	 * Schedules the daily IP list refresh (first run on the next cron tick).
	 */
	public function schedule_events(): void {
		if ( false === wp_next_scheduled( self::REFRESH_HOOK ) ) {
			wp_schedule_event( time(), 'daily', self::REFRESH_HOOK );
		}
	}

	/**
	 * Cron callback.
	 */
	public function on_refresh_ip_ranges(): void {
		$this->refresh_ip_ranges();
	}

	/**
	 * Downloads the published IP lists of all `ip_ranges` bots.
	 *
	 * @return array<string, int> Prefix count per URL (0 = failed, previous list kept).
	 */
	public function refresh_ip_ranges(): array {
		if ( ! Features::is_enabled( Features::MEASUREMENT ) ) {
			return array();
		}

		$urls = array();
		foreach ( Registry::bots() as $bot ) {
			if ( 'ip_ranges' === $bot->verify ) {
				$urls[] = $bot->verify_source;
			}
		}

		return ( new IpRanges() )->refresh( $urls );
	}

	/**
	 * Removes scheduled events.
	 */
	public function deactivate(): void {
		wp_clear_scheduled_hook( self::REFRESH_HOOK );
	}
}
