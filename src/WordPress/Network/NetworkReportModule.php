<?php
/**
 * Network report – WordPress wiring (1.24.0).
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\WordPress\Network;

use AIHazirSite\Core\Catalog\ListingValidity;
use AIHazirSite\Core\Features;
use AIHazirSite\Core\Network\NetworkLink;
use AIHazirSite\Core\Network\NetworkReport;
use AIHazirSite\Core\Network\NetworkSettings;
use AIHazirSite\Core\Network\NetworkStats;
use AIHazirSite\WordPress\Catalog\CatalogReader;
use AIHazirSite\WordPress\Compliance\ComplianceModule;
use AIHazirSite\WordPress\Inquiry\WpInquiryRepository;
use AIHazirSite\WordPress\Module;
use AIHazirSite\WordPress\Platform\SodiumCipher;
use AIHazirSite\WordPress\Platform\WpClock;
use AIHazirSite\WordPress\Rest\RestModule;
use AIHazirSite\WordPress\Storage\WpdbHitRepository;
use WP_Application_Passwords;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * While `network_report` is on:
 * - every site answers GET /aihs/v1/network/stats with its totals, only to a user holding `aihs_network_stats`
 *   (authenticated with a WordPress Application Password over HTTPS);
 * - a member creates that reader user and its application password with one button and gives it to its mother;
 * - the mother keeps the passwords encrypted, reads every verified member once a day (and on demand) and shows
 *   the report (NetworkReportPage).
 *
 * @phpstan-import-type Stats from NetworkStats
 * @phpstan-import-type Site from NetworkReport
 */
final class NetworkReportModule implements Module {

	public const ROLE       = 'aihs_network_reader';
	public const CAP        = 'aihs_network_stats';
	public const USER_LOGIN = 'aihs-ag-raporu';
	public const KEY_OPTION = 'aihs_network_report_key';
	public const CREDS      = 'aihs_network_report';
	public const DATA       = 'aihs_network_report_data';
	public const HOOK       = 'aihs_network_report_fetch';
	public const ROUTE      = '/network/stats';
	public const APP_NAME   = 'AI Hazır Site ağ raporu';

	/**
	 * Registers hooks.
	 */
	public function register(): void {
		if ( ! self::enabled() ) {
			if ( false !== wp_next_scheduled( self::HOOK ) ) {
				wp_clear_scheduled_hook( self::HOOK );
			}
			return;
		}
		add_action( 'rest_api_init', array( self::class, 'routes' ) );
		add_action( self::HOOK, array( self::class, 'fetch_all' ) );
		if ( false === wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( time() + 300, 'daily', self::HOOK );
		}
		if ( is_admin() ) {
			( new NetworkReportPage() )->register();
		}
	}

	/**
	 * Stops the daily reading; settings, keys and data are kept.
	 */
	public function deactivate(): void {
		wp_clear_scheduled_hook( self::HOOK );
	}

	/**
	 * Whether the report is on.
	 */
	public static function enabled(): bool {
		return Features::is_enabled( Features::PORTAL_NETWORK ) && Features::is_enabled( Features::NETWORK_REPORT );
	}

	/**
	 * GET /aihs/v1/network/stats.
	 */
	public static function routes(): void {
		register_rest_route(
			RestModule::NAMESPACE,
			self::ROUTE,
			array(
				'methods'             => 'GET',
				'callback'            => array( self::class, 'get_stats' ),
				// WordPress answers 401 without a login and 403 for a user without the capability.
				'permission_callback' => static fn(): bool => current_user_can( self::CAP ),
				'args'                => array(
					'days' => array(
						'type'    => 'integer',
						'enum'    => NetworkStats::PERIODS,
						'default' => 28,
					),
				),
			)
		);
	}

	/**
	 * The stats answer (never cached by intermediaries).
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public static function get_stats( WP_REST_Request $request ): WP_REST_Response {
		$response = new WP_REST_Response( self::stats( NetworkStats::period( $request->get_param( 'days' ) ) ) );
		$response->header( 'Cache-Control', 'private, no-store' );
		return $response;
	}

	/**
	 * This site's totals.
	 *
	 * @param int $days Period.
	 * @return array<string, mixed>
	 *
	 * @phpstan-return Stats
	 */
	public static function stats( int $days ): array {
		$today    = ( new WpClock() )->today();
		$listings = count( array_filter( CatalogReader::query()->all(), static fn( $l ): bool => ListingValidity::is_current( $l, $today ) ) );
		$latest   = ComplianceModule::store()->latest();
		$features = array();
		foreach ( array_keys( Features::defaults() ) as $feature ) {
			if ( Features::is_enabled( (string) $feature ) ) {
				$features[] = (string) $feature;
			}
		}
		return NetworkStats::build(
			new WpdbHitRepository(),
			new WpInquiryRepository(),
			$today,
			$days,
			defined( 'AIHS_VERSION' ) ? (string) AIHS_VERSION : '0',
			$features,
			$listings,
			$latest?->score(),
			(string) ( $latest->scanned_at ?? '' )
		);
	}

	/**
	 * Member: the key's record ({user_id, uuid, created_at}) or null.
	 *
	 * @return array{user_id: int, uuid: string, created_at: int}|null
	 */
	public static function key(): ?array {
		$key = get_option( self::KEY_OPTION );
		if ( ! is_array( $key ) || ! isset( $key['user_id'], $key['uuid'] ) ) {
			return null;
		}
		return array(
			'user_id'    => (int) $key['user_id'],
			'uuid'       => (string) $key['uuid'],
			'created_at' => (int) ( $key['created_at'] ?? 0 ),
		);
	}

	/**
	 * Member: creates (or replaces) the reader user's application password. Returns [login, password] or an error.
	 *
	 * @return array{0: string, 1: string}|WP_Error
	 */
	public static function create_key(): array|WP_Error {
		if ( ! wp_is_application_passwords_available() ) {
			return new WP_Error( 'aihs_app_passwords_off', __( 'Bu sitede WordPress uygulama parolaları kapalı (ör. Wordfence ayarı veya bir güvenlik eklentisi). Açılmadan rapor anahtarı oluşturulamaz.', 'ai-hazir-site' ) );
		}
		if ( null === get_role( self::ROLE ) ) {
			add_role( self::ROLE, __( 'AI Hazır Site ağ raporu', 'ai-hazir-site' ), array( self::CAP => true ) );
		}
		$user = get_user_by( 'login', self::USER_LOGIN );
		if ( false === $user ) {
			$id = wp_insert_user(
				array(
					'user_login'   => self::USER_LOGIN,
					'user_pass'    => wp_generate_password( 64, true, true ),
					'display_name' => __( 'AI Hazır Site ağ raporu', 'ai-hazir-site' ),
					'role'         => self::ROLE,
				)
			);
			if ( is_wp_error( $id ) ) {
				return $id;
			}
			$user = get_user_by( 'id', $id );
		}
		if ( false === $user ) {
			return new WP_Error( 'aihs_report_user', __( 'Rapor kullanıcısı oluşturulamadı.', 'ai-hazir-site' ) );
		}
		// The reader holds our capability only (also if the login existed with another role).
		$user->set_role( self::ROLE );
		self::revoke_key();

		$created = WP_Application_Passwords::create_new_application_password( $user->ID, array( 'name' => self::APP_NAME ) );
		if ( is_wp_error( $created ) ) {
			return $created;
		}
		update_option(
			self::KEY_OPTION,
			array(
				'user_id'    => (int) $user->ID,
				'uuid'       => (string) $created[1]['uuid'],
				'created_at' => time(),
			),
			false
		);
		return array( self::USER_LOGIN, (string) $created[0] );
	}

	/**
	 * Member: deletes the application password (the reader user stays, without any password).
	 */
	public static function revoke_key(): void {
		$key = self::key();
		if ( null !== $key ) {
			WP_Application_Passwords::delete_application_password( $key['user_id'], $key['uuid'] );
		}
		delete_option( self::KEY_OPTION );
	}

	/**
	 * Member: asks its own endpoint with the new password (loopback): 'ok', 'blocked' (401/403: the Authorization header
	 * probably does not reach PHP) or 'unknown' (the site cannot call itself).
	 *
	 * @param string $login    Login.
	 * @param string $password Application password.
	 */
	public static function self_test( string $login, string $password ): string {
		$response = wp_remote_get( rest_url( RestModule::NAMESPACE . self::ROUTE ) . '?days=7', self::request_args( $login, $password ) );
		if ( is_wp_error( $response ) ) {
			return 'unknown';
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		return 200 === $code ? 'ok' : ( in_array( $code, array( 401, 403 ), true ) ? 'blocked' : 'unknown' );
	}

	/**
	 * Mother: stored credentials (password encrypted), by member URL.
	 *
	 * @return array<string, array{user: string, secret: string}>
	 */
	public static function credentials(): array {
		$stored = get_option( self::CREDS );
		$creds  = array();
		foreach ( is_array( $stored ) ? $stored : array() as $url => $row ) {
			if ( is_string( $url ) && is_array( $row ) && is_string( $row['user'] ?? null ) && is_string( $row['secret'] ?? null ) ) {
				$creds[ $url ] = array(
					'user'   => $row['user'],
					'secret' => $row['secret'],
				);
			}
		}
		return $creds;
	}

	/**
	 * Mother: saves a member's credentials (empty password keeps the stored one; empty user removes them).
	 *
	 * @param string $url      Member URL (must be a verified member).
	 * @param string $user     Login.
	 * @param string $password Application password.
	 */
	public static function save_credentials( string $url, string $user, string $password ): bool {
		if ( ! in_array( $url, array_column( NetworkModule::view()->siblings(), 'url' ), true ) ) {
			return false;
		}
		$creds = self::credentials();
		if ( '' === $user ) {
			unset( $creds[ $url ] );
		} elseif ( '' !== $password ) {
			$creds[ $url ] = array(
				'user'   => $user,
				'secret' => SodiumCipher::encrypt( $password ),
			);
		} elseif ( isset( $creds[ $url ] ) ) {
			$creds[ $url ]['user'] = $user;
		} else {
			return false;
		}
		update_option( self::CREDS, $creds, false );
		return true;
	}

	/**
	 * Mother: reads every verified member's totals for every period (daily, and on demand).
	 */
	public static function fetch_all(): void {
		if ( ! self::enabled() || NetworkSettings::ROLE_MOTHER !== NetworkModule::settings()->role ) {
			return;
		}
		$creds = self::credentials();
		$data  = self::data();
		$now   = time();
		$fresh = array();
		foreach ( NetworkModule::view()->siblings() as $site ) {
			$url = $site['url'];
			$row = is_array( $data[ $url ] ?? null ) ? $data[ $url ] : array();
			if ( (int) ( $row['retry_at'] ?? 0 ) > $now ) {
				$fresh[ $url ] = $row;
				continue;
			}
			$password = isset( $creds[ $url ] ) ? SodiumCipher::decrypt( $creds[ $url ]['secret'] ) : null;
			if ( null === $password ) {
				$fresh[ $url ] = array( 'status' => NetworkReport::NO_KEY ) + $row;
				continue;
			}
			foreach ( NetworkStats::PERIODS as $days ) {
				[ $status, $stats, $retry ] = self::read( $url, $creds[ $url ]['user'], $password, $days );
				$row['status']              = $status;
				$row['retry_at']            = null === $retry ? 0 : $now + $retry;
				if ( null !== $stats ) {
					$row['periods'][ $days ] = array(
						'fetched_at' => $now,
						'stats'      => $stats,
					);
				}
				if ( NetworkReport::OK !== $status ) {
					break;
				}
			}
			$fresh[ $url ] = $row;
		}
		update_option( self::DATA, $fresh, false );
	}

	/**
	 * Mother: the report's sites for a period (this site first, then every verified member).
	 *
	 * @param int $days Period.
	 * @return list<array<string, mixed>>
	 *
	 * @phpstan-return list<Site>
	 */
	public static function sites( int $days ): array {
		$view  = NetworkModule::view();
		$sites = array(
			array(
				'url'        => NetworkModule::self_url(),
				'name'       => (string) get_bloginfo( 'name' ),
				'status'     => NetworkReport::SELF,
				'fetched_at' => time(),
				'stats'      => self::stats( $days ),
			),
		);
		$data  = self::data();
		$creds = self::credentials();
		foreach ( $view->siblings() as $site ) {
			$row     = is_array( $data[ $site['url'] ] ?? null ) ? $data[ $site['url'] ] : array();
			$period  = is_array( $row['periods'][ $days ] ?? null ) ? $row['periods'][ $days ] : array();
			$status  = isset( $creds[ $site['url'] ] ) ? (string) ( $row['status'] ?? NetworkReport::UNREACHABLE ) : NetworkReport::NO_KEY;
			$sites[] = array(
				'url'        => $site['url'],
				'name'       => '' !== $site['name'] ? $site['name'] : NetworkLink::host( $site['url'] ),
				'status'     => $status,
				'fetched_at' => (int) ( $period['fetched_at'] ?? 0 ),
				'stats'      => NetworkStats::from_array( $period['stats'] ?? null ),
			);
		}
		return $sites;
	}

	/**
	 * Stored readings.
	 *
	 * @return array<string, mixed>
	 */
	private static function data(): array {
		$data = get_option( self::DATA );
		return is_array( $data ) ? $data : array();
	}

	/**
	 * One reading: [status, stats or null, retry seconds after a 429].
	 *
	 * @param string $url      Member URL.
	 * @param string $user     Login.
	 * @param string $password Application password.
	 * @param int    $days     Period.
	 * @return array{0: string, 1: array<string, mixed>|null, 2: int|null}
	 */
	private static function read( string $url, string $user, string $password, int $days ): array {
		$response = wp_remote_get( $url . 'wp-json/aihs/v1/network/stats?days=' . $days, self::request_args( $user, $password ) );
		if ( is_wp_error( $response ) ) {
			return array( NetworkReport::UNREACHABLE, null, null );
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( 429 === $code ) {
			$retry = (int) wp_remote_retrieve_header( $response, 'retry-after' );
			return array( NetworkReport::LIMITED, null, max( 60, min( 86400, $retry > 0 ? $retry : 3600 ) ) );
		}
		if ( in_array( $code, array( 401, 403 ), true ) ) {
			return array( NetworkReport::UNAUTHORIZED, null, null );
		}
		$stats = 200 === $code ? NetworkStats::from_array( json_decode( (string) wp_remote_retrieve_body( $response ), true ) ) : null;
		return null === $stats ? array( NetworkReport::UNREACHABLE, null, null ) : array( NetworkReport::OK, $stats, null );
	}

	/**
	 * Request arguments with HTTP Basic authentication (Application Passwords) and our usual limits.
	 *
	 * @param string $user     Login.
	 * @param string $password Application password.
	 * @return array<string, mixed>
	 */
	private static function request_args( string $user, string $password ): array {
		return array(
			'timeout'             => NetworkModule::TIMEOUT,
			'redirection'         => 0,
			'limit_response_size' => NetworkModule::MAX_BYTES,
			'headers'             => array(
				'Authorization'       => 'Basic ' . base64_encode( $user . ':' . $password ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- HTTP Basic authentication (RFC 7617).
				NetworkModule::HEADER => NetworkModule::self_url(),
				'Accept'              => 'application/json',
			),
			'user-agent'          => NetworkModule::USER_AGENT . '/' . ( defined( 'AIHS_VERSION' ) ? (string) AIHS_VERSION : '0' ) . ' (+' . NetworkModule::self_url() . ')',
		);
	}
}
