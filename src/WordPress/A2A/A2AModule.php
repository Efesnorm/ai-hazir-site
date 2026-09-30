<?php
/**
 * A2A Agent Card and agent endpoint (A12).
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\WordPress\A2A;

use AIHazirSite\Adapters\A2A\A2ASkills;
use AIHazirSite\Adapters\A2A\AgentCardBuilder;
use AIHazirSite\Adapters\A2A\AgentCardValidator;
use AIHazirSite\Adapters\A2A\JsonRpcServer;
use AIHazirSite\Core\Catalog\Query\Availability;
use AIHazirSite\Core\Features;
use AIHazirSite\Core\Inquiry\Inquiry;
use AIHazirSite\Core\RateLimit\FixedWindowLimiter;
use AIHazirSite\Core\Security\AuditLog;
use AIHazirSite\Core\Templates\Template;
use AIHazirSite\WordPress\Catalog\CatalogReader;
use AIHazirSite\WordPress\Catalog\WpProfileRepository;
use AIHazirSite\WordPress\Inquiry\InquiryChannels;
use AIHazirSite\WordPress\Inquiry\InquiryModule;
use AIHazirSite\WordPress\Inquiry\WpAuditRepository;
use AIHazirSite\WordPress\Module;
use AIHazirSite\WordPress\Platform\PageCache;
use AIHazirSite\WordPress\Platform\WpCache;
use AIHazirSite\WordPress\Platform\WpClock;
use AIHazirSite\WordPress\Platform\WpSecret;
use AIHazirSite\WordPress\Templates\TemplatesModule;
use WP_REST_Request;
use WP_REST_Response;

/**
 * While `a2a` is on and at least one skill works (availability needs the catalog, quotes need the
 * inquiry box): POST /wp-json/aihs/a2a (JSON-RPC, A2A 1.0) and /.well-known/agent-card.json.
 * The card is published only while the endpoint exists and the card passes AgentCardValidator.
 * Every incoming message is written to the audit log (channel "a2a").
 */
final class A2AModule implements Module {

	public const NAMESPACE     = 'aihs';
	public const ROUTE         = '/a2a';
	public const CARD_PATH     = '.well-known/agent-card.json';
	public const DEFAULT_LIMIT = 30;

	/**
	 * Largest request body parsed, in bytes (1.14.2; filter `aihs_a2a_max_body`, at least 1 KB).
	 */
	public const MAX_BODY = 65536;

	/**
	 * Registers hooks.
	 */
	public function register(): void {
		if ( ! Features::is_enabled( Features::A2A ) ) {
			return;
		}
		add_action( 'rest_api_init', array( self::class, 'routes' ) );
		add_action( 'parse_request', array( self::class, 'maybe_serve_card' ), 0 );
		if ( is_admin() ) {
			( new A2AAdmin() )->register();
		}
	}

	/**
	 * Nothing scheduled.
	 */
	public function deactivate(): void {
	}

	/**
	 * Skills that work on this site now.
	 *
	 * @return list<string>
	 */
	public static function skills(): array {
		if ( ! Features::is_enabled( Features::A2A ) ) {
			return array();
		}
		$skills = array();
		if ( Features::is_enabled( Features::CATALOG ) ) {
			$skills[] = A2ASkills::AVAILABILITY;
		}
		if ( Features::is_enabled( Features::INQUIRIES ) ) {
			$skills[] = A2ASkills::QUOTE;
		}
		return $skills;
	}

	/**
	 * Endpoint URL.
	 */
	public static function endpoint(): string {
		return rest_url( self::NAMESPACE . self::ROUTE );
	}

	/**
	 * `rest_api_init`: the endpoint, only while a skill works.
	 */
	public static function routes(): void {
		if ( array() === self::skills() ) {
			return;
		}
		register_rest_route(
			self::NAMESPACE,
			self::ROUTE,
			array(
				'methods'             => 'POST',
				'callback'            => array( self::class, 'handle' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * Our card, or null when it must not be published (feature off, no working skill, invalid card).
	 *
	 * @return array<string, mixed>|null
	 */
	public static function card(): ?array {
		$skills = self::skills();
		if ( array() === $skills ) {
			return null;
		}
		$card = AgentCardBuilder::build( ( new WpProfileRepository() )->get(), (string) get_bloginfo( 'name' ), home_url( '/' ), self::endpoint(), AIHS_VERSION, $skills );
		return array() === AgentCardValidator::errors( $card ) ? $card : null;
	}

	/**
	 * Path of the card under the site home.
	 */
	public static function card_path(): string {
		$home = wp_parse_url( home_url( '/' ), PHP_URL_PATH );
		return trailingslashit( is_string( $home ) ? $home : '/' ) . self::CARD_PATH;
	}

	/**
	 * `parse_request`: serves the card and stops.
	 */
	public static function maybe_serve_card(): void {
		$uri  = isset( $_SERVER['REQUEST_URI'] ) && is_string( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Only compared with a fixed path.
		$path = wp_parse_url( $uri, PHP_URL_PATH );
		if ( self::card_path() !== $path ) {
			return;
		}
		$card = self::card();
		if ( null === $card ) {
			return; // WordPress answers 404.
		}
		PageCache::exclude();
		status_header( 200 );
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Cache-Control: public, max-age=3600' );
		header( 'Access-Control-Allow-Origin: *' );
		echo wp_json_encode( $card, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON document.
		exit;
	}

	/**
	 * POST /aihs/a2a.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public static function handle( WP_REST_Request $request ): WP_REST_Response {
		$ip    = isset( $_SERVER['REMOTE_ADDR'] ) && is_string( $_SERVER['REMOTE_ADDR'] ) ? (string) filter_var( wp_unslash( $_SERVER['REMOTE_ADDR'] ), FILTER_VALIDATE_IP ) : '';
		$hash  = ( new WpSecret() )->hmac( 'a2a|' . $ip );
		$audit = self::audit();
		$limit = (int) apply_filters( 'aihs_a2a_rate_limit', self::DEFAULT_LIMIT );
		$retry = ( new FixedWindowLimiter( new WpCache(), new WpClock(), new WpSecret(), max( 1, $limit ), 60, 'a2a' ) )->hit( $ip );
		if ( null !== $retry ) {
			$audit->record( Inquiry::CHANNEL_A2A, $hash, 'message', AuditLog::OUTCOME_RATE_LIMITED, null, 'retry=' . $retry );
			$response = new WP_REST_Response( JsonRpcServer::error( null, -32000, 'Çok fazla istek.' ), 429 );
			$response->header( 'Retry-After', (string) $retry );
			return $response;
		}

		// 1.14.2: an oversized body is refused unparsed (HTTP 413, RFC 9110 section 15.5.14).
		$max = max( 1024, (int) apply_filters( 'aihs_a2a_max_body', self::MAX_BODY ) );
		if ( strlen( $request->get_body() ) > $max ) {
			$audit->record( Inquiry::CHANNEL_A2A, $hash, 'message', AuditLog::OUTCOME_INVALID, null, 'error=-32600;too_large' );
			return new WP_REST_Response( JsonRpcServer::error( null, -32600, 'İstek çok büyük.' ), 413 );
		}

		$body   = json_decode( $request->get_body(), true );
		$server = new JsonRpcServer( self::skills(), static fn( string $skill, array $data ): array => self::dispatch( $skill, $data, $ip ), static fn(): string => wp_generate_uuid4() );
		try {
			$result = $server->handle( $body, (string) ( $request->get_header( 'a2a_version' ) ?? $request->get_param( 'A2A-Version' ) ?? '' ) );
		} catch ( \Throwable $e ) {
			// 1.15.1: JSON-RPC 2.0 "Internal error" instead of an HTTP 500 carrying the server's stack trace.
			$result = JsonRpcServer::error( is_array( $body ) && ( is_string( $body['id'] ?? null ) || is_int( $body['id'] ?? null ) ) ? $body['id'] : null, -32603, 'Internal error' );
		}

		$skill = is_array( $body ) && is_array( $body['params']['message']['parts'][0]['data'] ?? null ) && is_string( $body['params']['message']['parts'][0]['data']['skill'] ?? null ) ? $body['params']['message']['parts'][0]['data']['skill'] : '';
		$state = $result['result']['task']['status']['state'] ?? '';
		$audit->record( Inquiry::CHANNEL_A2A, $hash, 'message', isset( $result['error'] ) ? AuditLog::OUTCOME_INVALID : AuditLog::OUTCOME_ACCEPTED, null, 'skill=' . sanitize_key( $skill ) . ';' . ( isset( $result['error'] ) ? 'error=' . (int) $result['error']['code'] : 'state=' . $state ) );

		$response = new WP_REST_Response( $result, 200 );
		$response->header( 'A2A-Version', AgentCardBuilder::PROTOCOL_VERSION );
		return $response;
	}

	/**
	 * Runs a skill.
	 *
	 * @param string               $skill Skill id.
	 * @param array<string, mixed> $data  Input.
	 * @param string               $ip    Client address (only its HMAC is stored).
	 * @return array{state: string, text: string, data: array<string, mixed>}
	 */
	public static function dispatch( string $skill, array $data, string $ip ): array {
		if ( A2ASkills::AVAILABILITY === $skill ) {
			$listing  = CatalogReader::query()->find( absint( $data['listing_id'] ?? 0 ) );
			$registry = TemplatesModule::registry();
			$template = null === $registry || null === $listing ? Template::general() : $registry->get( $listing->template );
			$quantity = isset( $data['quantity'] ) && is_numeric( $data['quantity'] ) ? rtrim( rtrim( sprintf( '%.4F', max( 0.0, (float) $data['quantity'] ) ), '0' ), '.' ) : null;
			$days     = isset( $data['within_days'] ) && is_numeric( $data['within_days'] ) ? (int) $data['within_days'] : null;
			$answer   = Availability::check( $listing, $template, '' === $quantity ? '0' : $quantity, $days, TemplatesModule::now() );
			return array(
				'state' => 'TASK_STATE_COMPLETED',
				'text'  => implode( ' ', $answer['reasons'] ),
				'data'  => $answer,
			);
		}

		$input         = $data;
		$input['kind'] = is_string( $data['kind'] ?? null ) ? $data['kind'] : ( InquiryChannels::referral_only() ? Inquiry::KIND_REFERRAL : Inquiry::KIND_QUOTE_REQUEST );
		$result        = InquiryModule::service()->submit( $input, Inquiry::CHANNEL_A2A, Inquiry::SOURCE_AI, $ip, InquiryChannels::kinds() );
		if ( null === $result->inquiry ) {
			return array(
				'state' => AuditLog::OUTCOME_RATE_LIMITED === $result->outcome ? 'TASK_STATE_FAILED' : 'TASK_STATE_REJECTED',
				'text'  => implode( ' ', $result->errors ),
				'data'  => array( 'errors' => $result->errors ),
			);
		}
		return array(
			'state' => 'TASK_STATE_COMPLETED',
			'text'  => __( 'Talebiniz firmaya iletildi. Otomatik yanıt gönderilmez; firma inceleyip verdiğiniz iletişim bilgisinden size dönecek.', 'ai-hazir-site' ),
			'data'  => array(
				'received'  => true,
				'reference' => (int) $result->inquiry->id,
			),
		);
	}

	/**
	 * Audit log.
	 */
	public static function audit(): AuditLog {
		return new AuditLog( new WpAuditRepository(), new WpClock() );
	}
}
