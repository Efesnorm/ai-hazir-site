<?php
/**
 * Inquiry box (A7) – WordPress wiring.
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\WordPress\Inquiry;

use AIHazirSite\Core\Catalog\Listing;
use AIHazirSite\Core\Features;
use AIHazirSite\Core\Inquiry\InquiryService;
use AIHazirSite\Core\Inquiry\InquirySettings;
use AIHazirSite\Core\Security\AuditLog;
use AIHazirSite\Core\Security\TokenBucketLimiter;
use AIHazirSite\WordPress\Catalog\CatalogReader;
use AIHazirSite\WordPress\Inquiry\Admin\InquiryAdmin;
use AIHazirSite\WordPress\Module;
use AIHazirSite\WordPress\Platform\WpCache;
use AIHazirSite\WordPress\Platform\WpClock;
use AIHazirSite\WordPress\Platform\WpSecret;
use AIHazirSite\WordPress\Platform\WpSettings;

/**
 * The inquiry box: admin screen (always, to switch it on after a warning), channels while
 * `inquiries` is on (MCP ability with `abilities`, REST with `rest_api`), the daily retention purge.
 * The service is built here for every channel.
 * The purge also runs while the feature is off, so stored personal data never outlives its retention.
 */
final class InquiryModule implements Module {

	public const PURGE_HOOK = 'aihs_purge_inquiries';

	/**
	 * Registers hooks.
	 */
	public function register(): void {
		add_action( self::PURGE_HOOK, array( self::class, 'purge' ) );
		add_action( 'init', array( self::class, 'schedule' ) );
		if ( is_admin() ) {
			( new InquiryAdmin() )->register();
		}
		if ( InquiryChannels::abilities_enabled() && function_exists( 'wp_register_ability' ) ) {
			add_action( 'wp_abilities_api_init', array( InquiryChannels::class, 'register_ability' ) );
		}
		if ( InquiryChannels::rest_enabled() ) {
			add_action( 'rest_api_init', array( InquiryChannels::class, 'register_route' ) );
		}
	}

	/**
	 * Clears the scheduled purge.
	 */
	public function deactivate(): void {
		wp_clear_scheduled_hook( self::PURGE_HOOK );
	}

	/**
	 * Schedules the daily purge once inquiries were ever enabled (data may exist).
	 */
	public static function schedule(): void {
		if ( ( Features::is_enabled( Features::INQUIRIES ) || null !== Features::stored( Features::INQUIRIES ) ) && false === wp_next_scheduled( self::PURGE_HOOK ) ) {
			wp_schedule_event( time(), 'daily', self::PURGE_HOOK );
		}
	}

	/**
	 * Daily cron: deletes inquiries and audit entries past the retention period.
	 */
	public static function purge(): void {
		self::service()->purge();
	}

	/**
	 * Stored settings.
	 */
	public static function settings(): InquirySettings {
		return InquirySettings::from_array( ( new WpSettings() )->get( InquirySettings::OPTION, array() ) );
	}

	/**
	 * The single write point, wired for this site.
	 */
	public static function service(): InquiryService {
		$settings = self::settings();
		$cache    = new WpCache();
		$clock    = new WpClock();
		$secret   = new WpSecret();
		$query    = CatalogReader::query();

		return new InquiryService(
			new WpInquiryRepository(),
			new AuditLog( new WpAuditRepository(), $clock ),
			$secret,
			$clock,
			$settings,
			array(
				new TokenBucketLimiter( $cache, $clock, $secret, $settings->per_minute, 60, 'inquiry-minute' ),
				new TokenBucketLimiter( $cache, $clock, $secret, $settings->per_day, 86400, 'inquiry-day' ),
			),
			static fn( int $id ): ?Listing => $query->find( $id ),
			new WpInquiryNotifier()
		);
	}
}
