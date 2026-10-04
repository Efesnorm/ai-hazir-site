<?php
/**
 * AI bot and referral measurement (A0) – WordPress wiring.
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\WordPress\Measurement;

use AIHazirSite\Core\Features;
use AIHazirSite\Core\Measurement\IpRanges;
use AIHazirSite\Core\Measurement\Registry;
use AIHazirSite\Core\Measurement\Tracker;
use AIHazirSite\Core\Measurement\Verifier;
use AIHazirSite\WordPress\Module;
use AIHazirSite\WordPress\Storage\WpdbHitRepository;
use AIHazirSite\WordPress\Measurement\Admin\ReportPage;
use AIHazirSite\WordPress\Measurement\Cli\HitsCommand;
use AIHazirSite\WordPress\Network\NetworkModule;
use AIHazirSite\WordPress\Platform\WpCache;
use AIHazirSite\WordPress\Platform\WpClock;
use AIHazirSite\WordPress\Platform\WpHttpClient;
use AIHazirSite\WordPress\Platform\WpSecret;
use AIHazirSite\WordPress\Platform\WpSettings;
use WP_CLI;

/**
 * Wires the core tracker into WordPress. When the `measurement` feature is off,
 * no counting hooks are registered and the IP list refresh does nothing.
 * The 400-day retention cleanup always runs, so old data never lingers.
 */
final class MeasurementModule implements Module {

	public const REFRESH_HOOK   = 'aihs_refresh_ip_ranges';
	public const PRUNE_HOOK     = 'aihs_prune_hits';
	public const RETENTION_DAYS = 400;

	/**
	 * Registers hooks.
	 */
	public function register(): void {
		add_action( self::REFRESH_HOOK, array( $this, 'on_refresh_ip_ranges' ) );
		add_action( self::PRUNE_HOOK, array( $this, 'on_prune' ) );
		add_action( 'init', array( $this, 'schedule_events' ) );

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			WP_CLI::add_command( 'aihs hits', HitsCommand::class );
		}

		// The report page stays available when measurement is off, so it can be turned back on.
		if ( is_admin() ) {
			( new ReportPage() )->register();
		}

		if ( ! Features::is_enabled( Features::MEASUREMENT ) ) {
			return;
		}

		$listener = new RequestListener( self::tracker() );
		add_action( 'parse_request', array( $listener, 'on_parse_request' ), 0 );
		add_action( 'shutdown', array( $listener, 'on_shutdown' ) );
	}

	/**
	 * Core tracker with the WordPress adapters.
	 */
	public static function tracker(): Tracker {
		return new Tracker(
			new WpdbHitRepository(),
			new WpClock(),
			null,
			new Verifier( self::ip_ranges(), new WpCache(), new WpSecret() ),
			Features::is_enabled( Features::NETWORK_REFERRALS ) && Features::is_enabled( Features::PORTAL_NETWORK )
				? static fn(): array => array_column( NetworkModule::view()->siblings(), 'url' )
				: null
		);
	}

	/**
	 * IP list store with the WordPress adapters.
	 */
	public static function ip_ranges(): IpRanges {
		return new IpRanges( new WpSettings(), new WpHttpClient(), new WpClock() );
	}

	/**
	 * Schedules the weekly retention cleanup and, while measurement is on, the daily
	 * IP list refresh (first run on the next cron tick).
	 */
	public function schedule_events(): void {
		if ( false === wp_next_scheduled( self::PRUNE_HOOK ) ) {
			wp_schedule_event( time(), 'weekly', self::PRUNE_HOOK );
		}
		if ( Features::is_enabled( Features::MEASUREMENT ) && false === wp_next_scheduled( self::REFRESH_HOOK ) ) {
			wp_schedule_event( time(), 'daily', self::REFRESH_HOOK );
		}
	}

	/**
	 * Cron callback.
	 */
	public function on_prune(): void {
		$this->prune();
	}

	/**
	 * Deletes rows older than 400 days.
	 *
	 * @param string|null $today Y-m-d; defaults to today in the site's timezone.
	 * @return int Deleted rows.
	 */
	public function prune( ?string $today = null ): int {
		$today  = $today ?? ( new WpClock() )->today();
		$cutoff = gmdate( 'Y-m-d', (int) strtotime( $today . ' 00:00:00 UTC' ) - self::RETENTION_DAYS * DAY_IN_SECONDS );

		return ( new WpdbHitRepository() )->prune( $cutoff );
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

		return self::ip_ranges()->refresh( $urls );
	}

	/**
	 * Removes scheduled events.
	 */
	public function deactivate(): void {
		wp_clear_scheduled_hook( self::REFRESH_HOOK );
		wp_clear_scheduled_hook( self::PRUNE_HOOK );
	}
}
