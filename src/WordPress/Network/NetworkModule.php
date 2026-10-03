<?php
/**
 * Portal network – WordPress wiring (1.20.0).
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\WordPress\Network;

use AIHazirSite\Adapters\Schema\NetworkSchema;
use AIHazirSite\Core\Features;
use AIHazirSite\Core\Network\NetworkCheck;
use AIHazirSite\Core\Network\NetworkSettings;
use AIHazirSite\Core\Network\NetworkView;
use AIHazirSite\WordPress\Catalog\WpProfileRepository;
use AIHazirSite\WordPress\Module;
use AIHazirSite\WordPress\Rest\RestModule;
use WP_REST_Request;
use WP_REST_Response;

/**
 * While `portal_network` is on: GET /aihs/v1/network, an hourly check of the other side's consent, and the network in
 * the home and catalog JSON-LD and in llms.txt (only verified sites). Requests to other sites carry
 * `X-AIHS-Network: <our URL>`; a verified site calling us is remembered with its observed server IP (for allow-lists).
 */
final class NetworkModule implements Module {

	public const OPTION     = 'aihs_network';
	public const STATE      = 'aihs_network_state';
	public const HOOK       = 'aihs_network_check';
	public const HEADER     = 'X-AIHS-Network';
	public const ROUTE      = '/network';
	public const MAX_BYTES  = 1048576;
	public const TIMEOUT    = 10;
	public const USER_AGENT = 'AIHazirSite-Network';

	/**
	 * Registers hooks.
	 */
	public function register(): void {
		if ( ! Features::is_enabled( Features::PORTAL_NETWORK ) ) {
			// Turned off from the settings: no scheduled check is left behind.
			if ( false !== wp_next_scheduled( self::HOOK ) ) {
				wp_clear_scheduled_hook( self::HOOK );
			}
			return;
		}
		add_action( 'rest_api_init', array( self::class, 'routes' ) );
		add_action( self::HOOK, array( self::class, 'check' ) );
		if ( false === wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( time() + 60, 'hourly', self::HOOK );
		}
		if ( is_admin() ) {
			( new NetworkPage() )->register();
		}
	}

	/**
	 * Stops the hourly check; settings and state are kept.
	 */
	public function deactivate(): void {
		wp_clear_scheduled_hook( self::HOOK );
	}

	/**
	 * Whether the network feature is on.
	 */
	public static function enabled(): bool {
		return Features::is_enabled( Features::PORTAL_NETWORK );
	}

	/**
	 * Stored settings.
	 */
	public static function settings(): NetworkSettings {
		return NetworkSettings::from_array( get_option( self::OPTION, array() ) );
	}

	/**
	 * Stored state.
	 *
	 * @return array<string, mixed>
	 */
	public static function state(): array {
		$state = get_option( self::STATE, array() );
		return is_array( $state ) ? $state : array();
	}

	/**
	 * This site's URL, normalized like the members' URLs.
	 */
	public static function self_url(): string {
		$home = home_url( '/' );
		return NetworkSettings::normalize_url( $home ) ?? trailingslashit( $home );
	}

	/**
	 * What this site publishes now.
	 */
	public static function view(): NetworkView {
		$profile = ( new WpProfileRepository() )->get();
		return new NetworkView(
			self::settings(),
			self::state(),
			self::self_url(),
			'' !== $profile->name ? $profile->name : wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES ),
			$profile->country,
			time()
		);
	}

	/**
	 * `rest_api_init`: GET /aihs/v1/network.
	 */
	public static function routes(): void {
		register_rest_route(
			RestModule::NAMESPACE,
			self::ROUTE,
			array(
				'methods'             => 'GET',
				'callback'            => array( self::class, 'get_network' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * GET /network.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public static function get_network( WP_REST_Request $request ): WP_REST_Response {
		self::remember_caller( (string) $request->get_header( 'x_aihs_network' ) );
		return RestModule::respond( $request, self::view()->document(), null );
	}

	/**
	 * Records the observed server IP of a verified site that called us.
	 *
	 * @param string $caller The URL the caller sent in the X-AIHS-Network header.
	 */
	public static function remember_caller( string $caller ): void {
		$url = NetworkSettings::normalize_url( $caller );
		$ip  = isset( $_SERVER['REMOTE_ADDR'] ) && is_string( $_SERVER['REMOTE_ADDR'] ) ? (string) filter_var( wp_unslash( $_SERVER['REMOTE_ADDR'] ), FILTER_VALIDATE_IP ) : '';
		if ( null === $url || '' === $ip ) {
			return;
		}
		$view  = self::view();
		$known = array_column( $view->siblings(), 'url' );
		if ( $url !== $view->mother() && ! in_array( $url, $known, true ) ) {
			return;
		}
		$state = self::state();
		if ( ( $state['seen'][ $url ] ?? '' ) !== $ip ) {
			$state['seen']         = is_array( $state['seen'] ?? null ) ? $state['seen'] : array();
			$state['seen'][ $url ] = $ip;
			update_option( self::STATE, $state, false );
		}
	}

	/**
	 * The hourly check: the mother asks every member; a member asks its mother.
	 */
	public static function check(): void {
		$settings = self::settings();
		$state    = self::state();
		$now      = time();
		$own_url  = self::self_url();

		if ( NetworkSettings::ROLE_MOTHER === $settings->role ) {
			$rows = array();
			foreach ( $settings->members as $url ) {
				$row = is_array( $state['members'][ $url ] ?? null ) ? $state['members'][ $url ] : array();
				if ( (int) ( $row['retry_at'] ?? 0 ) > $now ) {
					$rows[ $url ] = $row;
					continue;
				}
				[ $document, $retry ] = self::fetch( $url . 'wp-json/aihs/v1/network' );
				$status               = NetworkCheck::member_status( $own_url, $document );
				$profile              = NetworkCheck::VERIFIED === $status ? self::fetch( $url . 'wp-json/aihs/v1/profile', false )[0] : null;
				$rows[ $url ]         = array(
					'status'      => $status,
					'name'        => is_array( $profile ) ? NetworkCheck::text( $profile['name'] ?? '', 100 ) : (string) ( $row['name'] ?? '' ),
					'country'     => is_array( $profile ) ? NetworkCheck::country( $profile['country'] ?? '' ) : (string) ( $row['country'] ?? '' ),
					'checked_at'  => $now,
					'verified_at' => NetworkCheck::VERIFIED === $status ? $now : ( $row['verified_at'] ?? null ),
					'retry_at'    => null === $retry ? 0 : $now + $retry,
				);
			}
			$state['members'] = $rows;
			unset( $state['self'], $state['mother_doc'] );
		} elseif ( NetworkSettings::ROLE_MEMBER === $settings->role ) {
			$row = is_array( $state['self'] ?? null ) ? $state['self'] : array();
			if ( (int) ( $row['retry_at'] ?? 0 ) <= $now ) {
				[ $document, $retry ] = self::fetch( $settings->mother . 'wp-json/aihs/v1/network' );
				$status               = NetworkCheck::self_status( $own_url, $settings->mother, $document );
				$state['self']        = array(
					'status'      => $status,
					'checked_at'  => $now,
					'verified_at' => NetworkCheck::VERIFIED === $status ? $now : ( $row['verified_at'] ?? null ),
					'retry_at'    => null === $retry ? 0 : $now + $retry,
				);
				if ( null !== $document ) {
					$state['mother_doc'] = $document;
				}
			}
			unset( $state['members'] );
		} else {
			$state = array();
		}

		update_option( self::STATE, $state, false );
	}

	/**
	 * GET a JSON answer of another site: [decoded network document (or raw array when $network is false), retry seconds].
	 *
	 * @param string $url     URL.
	 * @param bool   $network Whether the answer must be a /network document.
	 * @return array{0: array<string, mixed>|null, 1: int|null}
	 */
	private static function fetch( string $url, bool $network = true ): array {
		$response = wp_remote_get(
			$url,
			array(
				'timeout'             => self::TIMEOUT,
				'redirection'         => 2,
				'limit_response_size' => self::MAX_BYTES,
				'headers'             => array(
					self::HEADER => self::self_url(),
					'Accept'     => 'application/json',
				),
				'user-agent'          => self::USER_AGENT . '/' . ( defined( 'AIHS_VERSION' ) ? (string) AIHS_VERSION : '0' ) . ' (+' . self::self_url() . ')',
			)
		);
		if ( is_wp_error( $response ) ) {
			return array( null, null );
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( 429 === $code ) {
			$retry = (int) wp_remote_retrieve_header( $response, 'retry-after' );
			return array( null, max( 60, min( 86400, $retry > 0 ? $retry : 3600 ) ) );
		}
		if ( 200 !== $code ) {
			return array( null, null );
		}
		$data = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( ! $network ) {
			return array( is_array( $data ) ? $data : null, null );
		}
		return array( NetworkCheck::document( $data ), null );
	}

	/**
	 * Home JSON-LD with the network (unchanged when not in a verified network).
	 *
	 * @param array<string, mixed> $document Home document.
	 * @return array<string, mixed>
	 */
	public static function decorate_home( array $document ): array {
		if ( ! self::enabled() ) {
			return $document;
		}
		$view = self::view();
		if ( ! $view->active() ) {
			return $document;
		}
		$profile = ( new WpProfileRepository() )->get();
		$mother  = NetworkSettings::ROLE_MOTHER === self::settings()->role;
		return NetworkSchema::home(
			$document,
			$view->network_name(),
			$view->mother(),
			self::self_url(),
			'' !== $profile->name ? $profile->name : wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES ),
			$mother ? $view->siblings() : null
		);
	}

	/**
	 * Catalog JSON-LD with the network on its publisher.
	 *
	 * @param array<string, mixed> $document Catalog document.
	 * @return array<string, mixed>
	 */
	public static function decorate_catalog( array $document ): array {
		if ( ! self::enabled() ) {
			return $document;
		}
		$view = self::view();
		return $view->active() ? NetworkSchema::catalog( $document, $view->network_name(), $view->mother() ) : $document;
	}

	/**
	 * The llms.txt network block ([] when not in a verified network).
	 *
	 * @return array{name?: string, sites?: list<array{url: string, name: string, country: string}>}
	 */
	public static function llms(): array {
		if ( ! self::enabled() ) {
			return array();
		}
		$view = self::view();
		return $view->active() ? array(
			'name'  => $view->network_name(),
			'sites' => $view->siblings(),
		) : array();
	}
}
