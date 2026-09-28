<?php
/**
 * Central updates and the report panel summary (A10).
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\WordPress\Updates;

use AIHazirSite\Core\Contracts\Clock;
use AIHazirSite\Core\Contracts\HitRepository;
use AIHazirSite\Core\Features;
use AIHazirSite\Core\Measurement\Hit;
use AIHazirSite\Core\Migrations\Migrator;
use AIHazirSite\Core\Telemetry\TelemetryService;
use AIHazirSite\Core\Telemetry\TelemetrySummary;
use AIHazirSite\Core\Updates\CanaryPolicy;
use AIHazirSite\Core\Updates\Release;
use AIHazirSite\Core\Updates\ReleaseManifest;
use AIHazirSite\Core\Updates\RollbackService;
use AIHazirSite\WordPress\Compliance\ComplianceModule;
use AIHazirSite\WordPress\Inquiry\WpInquiryRepository;
use AIHazirSite\WordPress\Module;
use AIHazirSite\WordPress\Platform\WpClock;
use AIHazirSite\WordPress\Platform\WpHttpClient;
use AIHazirSite\WordPress\Platform\WpHttpPoster;
use AIHazirSite\WordPress\Platform\WpSettings;
use AIHazirSite\WordPress\Plugin;
use AIHazirSite\WordPress\Storage\WpdbHitRepository;

/**
 * `remote_updates`: our releases in WordPress's own update screen (canary channel respected) and a
 * one-step rollback. `telemetry`: the weekly summary, sent only with the owner's consent and a
 * configured report panel address. Without a server address nothing is ever requested.
 */
final class UpdateModule implements Module {

	public const SLUG            = 'ai-hazir-site';
	public const SETTINGS        = 'aihs_update_settings';
	public const MANIFEST_CACHE  = 'aihs_update_manifest';
	public const TELEMETRY_HOOK  = 'aihs_send_telemetry';
	public const PAGE            = 'aihs-updates';
	public const SAVE            = 'aihs_save_update_settings';
	public const ROLLBACK        = 'aihs_rollback';
	public const CONSENT         = 'aihs_telemetry_consent_action';
	public const MANIFEST_EXPIRY = 12 * 3600;

	/**
	 * Clock (replaceable in tests, like Features::use_settings()).
	 *
	 * @var Clock|null
	 */
	private static ?Clock $clock = null;

	/**
	 * Sets the clock (null = the WordPress clock).
	 *
	 * @param Clock|null $clock Clock.
	 */
	public static function use_clock( ?Clock $clock ): void {
		self::$clock = $clock;
	}

	/**
	 * Current clock.
	 */
	private static function clock(): Clock {
		return self::$clock ?? new WpClock();
	}

	/**
	 * Registers hooks.
	 */
	public function register(): void {
		add_action( self::TELEMETRY_HOOK, array( self::class, 'on_telemetry_event' ) );
		add_action( 'init', array( self::class, 'schedule' ) );
		if ( Features::is_enabled( Features::REMOTE_UPDATES ) ) {
			add_filter( 'pre_set_site_transient_update_plugins', array( self::class, 'inject_update' ) );
			add_filter( 'plugins_api', array( self::class, 'plugin_information' ), 10, 3 );
		}
		if ( is_admin() && ( Features::is_enabled( Features::REMOTE_UPDATES ) || Features::is_enabled( Features::TELEMETRY ) ) ) {
			( new UpdatesAdmin() )->register();
		}
	}

	/**
	 * Stops the weekly summary.
	 */
	public function deactivate(): void {
		wp_clear_scheduled_hook( self::TELEMETRY_HOOK );
	}

	/**
	 * Stored settings: server address and channel.
	 *
	 * @return array{server: string, channel: string, telemetry_url: string}
	 */
	public static function settings(): array {
		$stored = get_option( self::SETTINGS, array() );
		$stored = is_array( $stored ) ? $stored : array();
		$text   = static fn( string $k ): string => isset( $stored[ $k ] ) && is_string( $stored[ $k ] ) ? $stored[ $k ] : '';
		return array(
			'server'        => $text( 'server' ),
			'channel'       => in_array( $text( 'channel' ), CanaryPolicy::CHANNELS, true ) ? $text( 'channel' ) : CanaryPolicy::GENERAL,
			'telemetry_url' => $text( 'telemetry_url' ),
		);
	}

	/**
	 * Manifest URL: the AIHS_UPDATE_SERVER constant wins over the setting; '' = none.
	 */
	public static function server(): string {
		$server = defined( 'AIHS_UPDATE_SERVER' ) && is_string( constant( 'AIHS_UPDATE_SERVER' ) ) ? (string) constant( 'AIHS_UPDATE_SERVER' ) : self::settings()['server'];
		return str_starts_with( $server, 'https://' ) ? $server : '';
	}

	/**
	 * Report panel URL: the AIHS_TELEMETRY_URL constant wins over the setting; '' = none.
	 */
	public static function telemetry_url(): string {
		$url = defined( 'AIHS_TELEMETRY_URL' ) && is_string( constant( 'AIHS_TELEMETRY_URL' ) ) ? (string) constant( 'AIHS_TELEMETRY_URL' ) : self::settings()['telemetry_url'];
		return str_starts_with( $url, 'https://' ) ? $url : '';
	}

	/**
	 * Releases from the manifest (cached for 12 hours); empty without a server.
	 *
	 * @return list<Release>
	 */
	public static function releases(): array {
		$server = self::server();
		if ( '' === $server ) {
			return array();
		}
		$body = get_site_transient( self::MANIFEST_CACHE );
		if ( ! is_string( $body ) ) {
			$body = ( new WpHttpClient() )->get( $server ) ?? '';
			set_site_transient( self::MANIFEST_CACHE, $body, self::MANIFEST_EXPIRY );
		}
		return ReleaseManifest::parse( $body, self::SLUG );
	}

	/**
	 * The release this site may install now, or null.
	 */
	public static function available(): ?Release {
		return CanaryPolicy::available( self::releases(), AIHS_VERSION, self::settings()['channel'], gmdate( 'Y-m-d\TH:i:s\Z', self::clock()->now() ) );
	}

	/**
	 * `pre_set_site_transient_update_plugins`: our update in WordPress's update list.
	 *
	 * @param mixed $transient Update transient.
	 * @return mixed
	 */
	public static function inject_update( mixed $transient ): mixed {
		if ( ! is_object( $transient ) ) {
			return $transient;
		}
		$basename = plugin_basename( AIHS_FILE );
		$release  = self::available();
		$item     = (object) array(
			'id'           => self::SLUG,
			'slug'         => self::SLUG,
			'plugin'       => $basename,
			'new_version'  => null === $release ? AIHS_VERSION : $release->version,
			'url'          => home_url( '/' ),
			'package'      => null === $release ? '' : $release->download_url,
			'requires'     => $release->requires ?? '',
			'requires_php' => $release->requires_php ?? '',
			'tested'       => $release->tested ?? '',
		);
		if ( null === $release ) {
			$transient->no_update[ $basename ] = $item; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Core property.
		} else {
			$transient->response[ $basename ] = $item;
		}
		return $transient;
	}

	/**
	 * `plugins_api`: the "View details" popup for our plugin.
	 *
	 * @param mixed  $result Result so far.
	 * @param string $action API action.
	 * @param mixed  $args   Arguments.
	 * @return mixed
	 */
	public static function plugin_information( mixed $result, string $action, mixed $args ): mixed {
		if ( 'plugin_information' !== $action || ! is_object( $args ) || self::SLUG !== ( $args->slug ?? '' ) ) {
			return $result;
		}
		$release = self::available();
		if ( null === $release ) {
			return $result;
		}
		return (object) array(
			'name'          => 'AI Hazır Site',
			'slug'          => self::SLUG,
			'version'       => $release->version,
			'download_link' => $release->download_url,
			'requires'      => $release->requires,
			'requires_php'  => $release->requires_php,
			'tested'        => $release->tested,
			'last_updated'  => $release->released_at,
			'sections'      => $release->sections,
		);
	}

	/**
	 * Returns to the previous release (schema first, then the package).
	 *
	 * @return array{ok: bool, reverted: list<int>, error: string}
	 */
	public static function rollback(): array {
		$target = CanaryPolicy::previous( self::releases(), AIHS_VERSION );
		if ( null === $target ) {
			return array(
				'ok'       => false,
				'reverted' => array(),
				'error'    => __( 'Güncelleme sunucusunda önceki bir sürüm yok.', 'ai-hazir-site' ),
			);
		}
		$result = ( new RollbackService( new Migrator( Plugin::migrations(), new WpSettings() ), self::installer() ) )->rollback( $target );
		delete_site_transient( 'update_plugins' );
		return $result;
	}

	/**
	 * Package installer (filterable for tests: `aihs_package_installer`).
	 */
	public static function installer(): \AIHazirSite\Core\Contracts\PackageInstaller {
		$installer = apply_filters( 'aihs_package_installer', null );
		return $installer instanceof \AIHazirSite\Core\Contracts\PackageInstaller ? $installer : new WpPackageInstaller();
	}

	/**
	 * Telemetry service.
	 */
	public static function telemetry(): TelemetryService {
		return new TelemetryService( new WpSettings(), new WpHttpPoster(), self::clock() );
	}

	/**
	 * The summary of the last 7 days.
	 *
	 * @return array<string, mixed>
	 */
	public static function summary(): array {
		$since    = gmdate( 'Y-m-d', self::clock()->now() - 6 * DAY_IN_SECONDS );
		$reads    = 0;
		$verified = 0;
		foreach ( ( new WpdbHitRepository() )->totals( Hit::KIND_BOT, $since, HitRepository::GROUP_SOURCE ) as $row ) {
			$reads    += $row['total'];
			$verified += $row['verified'];
		}
		$inquiries = count( array_filter( ( new WpInquiryRepository() )->list_inquiries( '', '', 100000 ), static fn( $i ): bool => substr( $i->created_at, 0, 10 ) >= $since ) );
		return TelemetrySummary::build( ComplianceModule::store()->latest(), $reads, $verified, $inquiries, AIHS_VERSION, (string) get_bloginfo( 'version' ) );
	}

	/**
	 * Whether the weekly summary may run: feature on, consent given, panel address set.
	 */
	public static function telemetry_allowed(): bool {
		return Features::is_enabled( Features::TELEMETRY ) && '' !== self::telemetry_url() && null !== self::telemetry()->consent();
	}

	/**
	 * `init`: the weekly event exists exactly while the summary may be sent.
	 */
	public static function schedule(): void {
		$scheduled = false !== wp_next_scheduled( self::TELEMETRY_HOOK );
		if ( self::telemetry_allowed() && ! $scheduled ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'weekly', self::TELEMETRY_HOOK );
		} elseif ( ! self::telemetry_allowed() && $scheduled ) {
			wp_clear_scheduled_hook( self::TELEMETRY_HOOK );
		}
	}

	/**
	 * `aihs_send_telemetry` (weekly).
	 */
	public static function on_telemetry_event(): void {
		self::send_telemetry();
	}

	/**
	 * Weekly event: sends the summary (the service checks feature, address and consent again).
	 */
	public static function send_telemetry(): bool {
		return self::telemetry()->send( Features::is_enabled( Features::TELEMETRY ), self::telemetry_url(), self::summary() );
	}
}
