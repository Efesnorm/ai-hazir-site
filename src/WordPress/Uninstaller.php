<?php
/**
 * Data removal on uninstall.
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\WordPress;

use AIHazirSite\WordPress\Admin\AdminNotices;
use AIHazirSite\Adapters\Llms\LlmsCache;
use AIHazirSite\Core\Compliance\Wizard\WizardJournal;
use AIHazirSite\Core\Inquiry\InquirySettings;
use AIHazirSite\Adapters\Schema\SchemaCache;
use AIHazirSite\Core\Access\PolicyStore;
use AIHazirSite\Core\Compliance\ScanStore;
use AIHazirSite\Core\Features;
use AIHazirSite\Core\Migrations\Migrator;
use AIHazirSite\WordPress\Catalog\CatalogModule;
use AIHazirSite\WordPress\Discovery\DiscoveryModule;
use AIHazirSite\WordPress\IndexNow\IndexNowModule;
use AIHazirSite\WordPress\Inquiry\InquiryModule;
use AIHazirSite\WordPress\Measurement\MeasurementModule;
use AIHazirSite\WordPress\Network\NetworkModule;
use AIHazirSite\WordPress\Integrations\LiteSpeedBypass;
use AIHazirSite\WordPress\Integrations\LiteSpeedServerBypass;
use AIHazirSite\WordPress\Integrations\SecuritySoftware;
use AIHazirSite\Core\IndexNow\IndexNowService;
use AIHazirSite\WordPress\Catalog\WpProfileRepository;
use AIHazirSite\WordPress\I18n\LanguageSource;
use AIHazirSite\WordPress\Portal\Portal;
use AIHazirSite\WordPress\Portal\WpBusinessRepository;
use AIHazirSite\WordPress\Updates\UpdateModule;
use AIHazirSite\WordPress\Matching\MatchingModule;
use AIHazirSite\Core\Telemetry\TelemetryService;
use AIHazirSite\WordPress\Platform\WpSettings;
use AIHazirSite\Core\Measurement\IpRanges;

/**
 * Deletes plugin data ONLY when the site owner opted in via `aihs_delete_data_on_uninstall`.
 */
final class Uninstaller {

	/**
	 * Opt-in option name.
	 */
	public const DELETE_OPTION = 'aihs_delete_data_on_uninstall';

	/**
	 * Options owned by the plugin.
	 *
	 * @return list<string>
	 */
	public static function options(): array {
		return array(
			Features::OPTION,
			Migrator::OPTION,
			self::DELETE_OPTION,
			IpRanges::OPTION,
			ScanStore::OPTION,
			ScanStore::FIRST_OPTION,
			PolicyStore::OPTION,
			SchemaCache::OPTION,
			SchemaCache::ERROR_OPTION,
			LlmsCache::OPTION,
			WizardJournal::OPTION,
			InquirySettings::OPTION,
			LanguageSource::OPTION,
			WpProfileRepository::TRANSLATIONS_OPTION,
			WpBusinessRepository::OPTION,
			UpdateModule::SETTINGS,
			TelemetryService::OPTION,
			MatchingModule::OPTION,
			DiscoveryModule::VISIBLE_OPTION,
			LiteSpeedBypass::ADDED_OPTION,
			IndexNowService::OPTION,
			LiteSpeedServerBypass::OPTION,
			NetworkModule::OPTION,
			NetworkModule::STATE,
			SecuritySoftware::OPTION,
		);
	}

	/**
	 * Whether the site owner opted in to data removal.
	 */
	public static function should_delete_data(): bool {
		return wp_validate_boolean( get_option( self::DELETE_OPTION, false ) );
	}

	/**
	 * Removes plugin data if opted in.
	 *
	 * @return bool True when data was removed.
	 */
	public static function run(): bool {
		// 1.21.0: our wp-config.php block is configuration outside the plugin; it never outlives it.
		SecuritySoftware::revert();

		if ( ! self::should_delete_data() ) {
			return false;
		}

		( new Migrator( Plugin::migrations(), new WpSettings() ) )->rollback( 0 );

		// Listings and the company profile go through the catalog's single write point.
		CatalogModule::service()->purge();

		foreach ( self::options() as $option ) {
			delete_option( $option );
		}

		// Business users' links (1.2.0 portal mode) and the plugin's transients (caches, rate-limit
		// windows, form state, one-time tokens).
		$users = get_users(
			array(
				'meta_key' => Portal::USER_META, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Runs once, on uninstall.
				'fields'   => 'ID',
			)
		);
		foreach ( $users as $user_id ) {
			delete_user_meta( (int) $user_id, Portal::USER_META );
		}
		// 1.15.0: dismissed information notices.
		$users = get_users(
			array(
				'meta_key' => AdminNotices::META, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Runs once, on uninstall.
				'fields'   => 'ID',
			)
		);
		foreach ( $users as $user_id ) {
			delete_user_meta( (int) $user_id, AdminNotices::META );
		}
		self::delete_transients();

		// Last: removing the data above can fire hooks that schedule work (1.14.0: deleting listings made
		// IndexNow schedule a submission after deactivation had cleared it).
		foreach ( self::cron_hooks() as $hook ) {
			wp_unschedule_hook( $hook );
		}

		return true;
	}

	/**
	 * Scheduled events owned by the plugin.
	 *
	 * @return list<string>
	 */
	public static function cron_hooks(): array {
		return array(
			MeasurementModule::REFRESH_HOOK,
			MeasurementModule::PRUNE_HOOK,
			InquiryModule::PURGE_HOOK,
			UpdateModule::TELEMETRY_HOOK,
			IndexNowModule::HOOK,
			NetworkModule::HOOK,
		);
	}

	/**
	 * Deletes every transient named aihs_* through the transient API (so an object cache is cleared too
	 * for the ones stored in the options table).
	 */
	private static function delete_transients(): void {
		global $wpdb;
		$names = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Enumerating the plugin's transients once, on uninstall.
			$wpdb->prepare(
				'SELECT option_name FROM %i WHERE option_name LIKE %s OR option_name LIKE %s',
				$wpdb->options,
				$wpdb->esc_like( '_transient_aihs_' ) . '%',
				$wpdb->esc_like( '_site_transient_aihs_' ) . '%'
			)
		);
		foreach ( $names as $name ) {
			if ( str_starts_with( $name, '_site_transient_' ) ) {
				delete_site_transient( substr( $name, strlen( '_site_transient_' ) ) );
			} else {
				delete_transient( substr( $name, strlen( '_transient_' ) ) );
			}
		}
	}
}
