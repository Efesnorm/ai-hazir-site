<?php
/**
 * Read-only REST API (A5) – WordPress wiring.
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\WordPress\Rest;

use AIHazirSite\Adapters\Abilities\InquirySchemas;
use AIHazirSite\Adapters\Rest\ListingsQuery;
use AIHazirSite\Adapters\Rest\OpenApiBuilder;
use AIHazirSite\Adapters\Rest\RestResponder;
use AIHazirSite\Adapters\Rest\RestSchemas;
use AIHazirSite\Core\Catalog\CompanyProfile;
use AIHazirSite\Core\Catalog\ListingType;
use AIHazirSite\Core\Catalog\Nace;
use AIHazirSite\Core\Features;
use AIHazirSite\Core\RateLimit\FixedWindowLimiter;
use AIHazirSite\WordPress\Catalog\CatalogReader;
use AIHazirSite\WordPress\Catalog\WpListingRepository;
use AIHazirSite\WordPress\Catalog\WpProfileRepository;
use AIHazirSite\WordPress\I18n\Multilingual;
use AIHazirSite\WordPress\Inquiry\InquiryChannels;
use AIHazirSite\WordPress\Network\NetworkCatalog;
use AIHazirSite\WordPress\Network\NetworkModule;
use AIHazirSite\WordPress\Network\NetworkSharing;
use AIHazirSite\WordPress\Module;
use AIHazirSite\WordPress\Platform\WpCache;
use AIHazirSite\WordPress\Platform\WpClock;
use AIHazirSite\WordPress\Platform\WpSecret;
use AIHazirSite\WordPress\Portal\Portal;
use AIHazirSite\WordPress\Schema\SchemaModule;
use AIHazirSite\WordPress\Templates\TemplatesModule;
use WP_REST_Request;
use WP_REST_Response;

/**
 * The aihs/v1 routes while `rest_api` is on (off: the routes do not exist, so WordPress answers 404).
 * Public GET only (permission_callback __return_true, as the REST handbook asks for public
 * routes). Every answer carries ETag, Last-Modified and Cache-Control; a matching If-None-Match
 * gets 304 without a body. A fixed per-client limit answers 429 with Retry-After.
 */
final class RestModule implements Module {

	public const NAMESPACE     = 'aihs/v1';
	public const DEFAULT_LIMIT = 60;
	public const MAX_AGE       = 300;

	/**
	 * Registers hooks.
	 */
	public function register(): void {
		if ( ! Features::is_enabled( Features::REST_API ) ) {
			return;
		}
		add_action( 'rest_api_init', array( self::class, 'routes' ) );
		add_filter( 'rest_pre_serve_request', array( self::class, 'no_body_for_304' ), 10, 2 );
		add_action( 'wp_head', array( self::class, 'discovery' ), 20 );
	}

	/**
	 * Nothing to clean up.
	 */
	public function deactivate(): void {
	}

	/**
	 * Registers the routes.
	 */
	public static function routes(): void {
		$get = static fn( callable $callback, array $args = array() ): array => array(
			'methods'             => 'GET',
			'callback'            => $callback,
			'permission_callback' => '__return_true',
			'args'                => $args,
		);

		register_rest_route( self::NAMESPACE, '/profile', $get( array( self::class, 'get_profile' ) ) );
		register_rest_route(
			self::NAMESPACE,
			'/listings',
			$get(
				array( self::class, 'get_listings' ),
				array(
					'type'     => array(
						'type' => 'string',
						'enum' => ListingType::ALL,
					),
					'category' => array( 'type' => 'string' ),
					'region'   => array( 'type' => 'string' ),
					'sector'   => array(
						'type' => 'string',
						'enum' => array_keys( Nace::SECTIONS ),
					),
					'network'  => array( 'type' => 'boolean' ),
					'page'     => array(
						'type'    => 'integer',
						'minimum' => 1,
						'default' => 1,
					),
					'per_page' => array(
						'type'    => 'integer',
						'minimum' => 1,
						'maximum' => ListingsQuery::MAX_PER_PAGE,
						'default' => ListingsQuery::DEFAULT_PER_PAGE,
					),
				)
			)
		);
		register_rest_route( self::NAMESPACE, '/listings/(?P<id>\d+)', $get( array( self::class, 'get_listing' ) ) );
		register_rest_route( self::NAMESPACE, '/templates', $get( array( self::class, 'get_templates' ) ) );
		register_rest_route( self::NAMESPACE, '/openapi.json', $get( array( self::class, 'get_openapi' ) ) );
		register_rest_route( self::NAMESPACE, '/schema/(?P<name>' . implode( '|', RestSchemas::NAMES ) . ')', $get( array( self::class, 'get_schema' ) ) );
		if ( Portal::active() ) {
			register_rest_route( self::NAMESPACE, '/businesses', $get( array( self::class, 'get_businesses' ) ) );
			register_rest_route( self::NAMESPACE, '/schema/businesses', $get( static fn( WP_REST_Request $request ): WP_REST_Response => self::respond( $request, RestSchemas::with_portal( 'businesses', array() ), null ) ) );
		}
	}

	/**
	 * GET /profile.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public static function get_profile( WP_REST_Request $request ): WP_REST_Response {
		$limited = self::limited();
		if ( null !== $limited ) {
			return $limited;
		}
		$profiles = new WpProfileRepository();
		$updated  = $profiles->updated_at();
		$language = self::language( $request );
		if ( null === $language ) {
			return self::respond( $request, self::responder()->profile( $profiles->get(), $updated ), $updated );
		}
		$localized = Multilingual::profile( $profiles->get(), $language );
		$record    = $localized->record instanceof CompanyProfile ? $localized->record : $profiles->get();
		$body      = RestResponder::translated( self::responder()->profile( $record, $updated ), $language, array( 'profile' => $localized->marker() ) );
		return self::in_language( self::respond( $request, $body, $updated ), $language );
	}

	/**
	 * GET /listings.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public static function get_listings( WP_REST_Request $request ): WP_REST_Response {
		$limited = self::limited();
		if ( null !== $limited ) {
			return $limited;
		}
		[ $query, $errors ] = ListingsQuery::from_params( $request->get_params() );
		if ( null === $query ) {
			return self::error( 'rest_invalid_param', implode( ' ', $errors ), 400 );
		}

		$language = self::language( $request );
		$listings = SchemaModule::listings();
		$all      = $listings;
		$markers  = array();
		if ( null !== $language ) {
			[ $listings, $markers ] = CatalogReader::localized( $listings, $language );
		}
		$slug = $request->get_param( 'business' );
		if ( Portal::active() && is_string( $slug ) && '' !== $slug ) {
			$listings = Portal::filter( $listings, sanitize_title( $slug ) );
			if ( null === $listings ) {
				return self::error( 'aihs_business_not_found', __( 'İşletme bulunamadı.', 'ai-hazir-site' ), 404 );
			}
		}
		$listings = CatalogReader::by_sector( $listings, $query->sector );
		$body     = self::responder()->listings( $listings, $query, ( new WpClock() )->today(), TemplatesModule::now() );
		if ( null !== $language ) {
			$body = RestResponder::translated( $body, $language, $markers );
		}
		if ( Portal::active() ) {
			$body = RestResponder::with_business( $body, Portal::references( $all ) );
		}
		$body     = NetworkCatalog::decorate( $body, $query->search(), $query->sector, true === $request->get_param( 'network' ) );
		$body     = NetworkSharing::decorate_listings( $body, $query->search(), $query->sector );
		$response = self::respond( $request, $body, $body['updated_at'] );
		$response->header( 'X-WP-Total', (string) $body['total'] );
		$response->header( 'X-WP-TotalPages', (string) $body['total_pages'] );
		return null === $language ? $response : self::in_language( $response, $language );
	}

	/**
	 * GET /listings/{id}.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public static function get_listing( WP_REST_Request $request ): WP_REST_Response {
		$limited = self::limited();
		if ( null !== $limited ) {
			return $limited;
		}
		$listing  = ( new WpListingRepository() )->find( absint( $request->get_param( 'id' ) ) );
		$language = self::language( $request );
		$markers  = array();
		if ( null !== $listing && null !== $language ) {
			[ $records, $markers ] = CatalogReader::localized( array( $listing ), $language );
			$listing               = $records[0] ?? $listing;
		}
		$body = null === $listing ? null : self::responder()->listing( $listing, ( new WpClock() )->today(), TemplatesModule::now() );
		if ( null === $body ) {
			return self::error( 'aihs_listing_not_found', __( 'İlan bulunamadı veya süresi doldu.', 'ai-hazir-site' ), 404 );
		}
		if ( Portal::active() ) {
			$body = RestResponder::with_business( $body, Portal::references( array( $listing ) ) );
		}
		if ( null === $language ) {
			return self::respond( $request, $body, $body['updated_at'] );
		}
		return self::in_language( self::respond( $request, RestResponder::translated( $body, $language, $markers ), $body['updated_at'] ), $language );
	}

	/**
	 * GET /templates.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public static function get_templates( WP_REST_Request $request ): WP_REST_Response {
		$limited = self::limited();
		if ( null !== $limited ) {
			return $limited;
		}
		$responder = self::responder();
		$used      = $responder->used_templates( ( new WpProfileRepository() )->get(), SchemaModule::listings(), ( new WpClock() )->today() );
		return self::respond( $request, $responder->templates( $used ), null );
	}

	/**
	 * GET /openapi.json: the RFC 8631 service description (1.18.0).
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public static function get_openapi( WP_REST_Request $request ): WP_REST_Response {
		$response = self::respond( $request, self::openapi(), null );
		$response->header( 'Content-Type', OpenApiBuilder::MEDIA_TYPE . '; charset=utf-8' );
		return $response;
	}

	/**
	 * OpenAPI 3.1 document of the endpoints that are on now (1.18.0).
	 *
	 * @return array<string, mixed>
	 */
	public static function openapi(): array {
		$request = array();
		$receipt = array();
		if ( InquiryChannels::rest_enabled() ) {
			$request                         = InquirySchemas::input( InquiryChannels::kinds() );
			$request['properties']['source'] = array(
				'type'        => 'string',
				'enum'        => array( 'ai', 'human' ),
				'description' => __( 'Talebi kim yazıyor: bir AI agent mı, bir insan mı (isteğe bağlı).', 'ai-hazir-site' ),
			);
			$receipt                         = InquirySchemas::output();
		}
		$name = ( new WpProfileRepository() )->get()->name;
		return OpenApiBuilder::build(
			'' !== $name ? $name : (string) get_bloginfo( 'name' ),
			defined( 'AIHS_VERSION' ) ? (string) AIHS_VERSION : '0',
			untrailingslashit( self::url() ),
			Multilingual::active(),
			Portal::active(),
			$request,
			$receipt,
			NetworkModule::enabled(),
			NetworkCatalog::enabled(),
			NetworkSharing::enabled()
		);
	}

	/**
	 * GET /schema/{name}.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public static function get_schema( WP_REST_Request $request ): WP_REST_Response {
		$name   = (string) $request->get_param( 'name' );
		$schema = RestSchemas::get( $name, Multilingual::active() );
		if ( 'listings' === $name && NetworkCatalog::enabled() ) {
			$schema = RestSchemas::with_suggestions( $schema );
		}
		if ( NetworkSharing::enabled() ) {
			$schema = RestSchemas::with_share( $name, $schema );
		}
		return self::respond( $request, Portal::active() ? RestSchemas::with_portal( $name, $schema ) : $schema, null );
	}

	/**
	 * GET /businesses (1.2.0 portal mode).
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public static function get_businesses( WP_REST_Request $request ): WP_REST_Response {
		$limited = self::limited();
		if ( null !== $limited ) {
			return $limited;
		}
		$body = self::responder()->businesses( Portal::businesses()->businesses(), array( Portal::class, 'page_url' ) );
		return self::respond( $request, $body, $body['updated_at'] );
	}

	/**
	 * Answer language of a request (?lang=, then Accept-Language), or null while multilingual
	 * output is inactive.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public static function language( WP_REST_Request $request ): ?string {
		$requested = $request->get_param( 'lang' );
		return Multilingual::language( is_string( $requested ) ? sanitize_text_field( $requested ) : null, (string) $request->get_header( 'accept_language' ) );
	}

	/**
	 * Language headers (RFC 9110 §8.5 Content-Language, §12.5.5 Vary).
	 *
	 * @param WP_REST_Response $response Response.
	 * @param string           $language Answer language.
	 */
	private static function in_language( WP_REST_Response $response, string $language ): WP_REST_Response {
		$response->header( 'Content-Language', $language );
		$response->header( 'Vary', 'Accept-Language' );
		return $response;
	}

	/**
	 * A 200 answer with cache headers, or 304 when the client already has it.
	 *
	 * @param WP_REST_Request      $request       Request.
	 * @param array<string, mixed> $body          Body.
	 * @param string|null          $last_modified ISO 8601 of the latest change, or null.
	 */
	public static function respond( WP_REST_Request $request, array $body, ?string $last_modified ): WP_REST_Response {
		$etag    = '"' . md5( (string) wp_json_encode( $body ) ) . '"';
		$headers = array(
			'ETag'          => $etag,
			'Cache-Control' => 'public, max-age=' . self::MAX_AGE,
		);
		$time    = null === $last_modified ? false : strtotime( $last_modified );
		if ( false !== $time ) {
			$headers['Last-Modified'] = gmdate( 'D, d M Y H:i:s', $time ) . ' GMT';
		}

		$match = (string) $request->get_header( 'if_none_match' );
		foreach ( array_map( 'trim', explode( ',', $match ) ) as $candidate ) {
			// Weak comparison (RFC 9110 §13.1.2): W/ prefixes are ignored.
			if ( '*' === $candidate || preg_replace( '#^W/#', '', $candidate ) === $etag ) {
				return new WP_REST_Response( null, 304, $headers );
			}
		}
		return new WP_REST_Response( $body, 200, $headers );
	}

	/**
	 * `rest_pre_serve_request`: a 304 has no body (headers were already sent by the server).
	 *
	 * @param mixed $served Whether the request was already served.
	 * @param mixed $result Response.
	 */
	public static function no_body_for_304( mixed $served, mixed $result ): mixed {
		return $result instanceof WP_REST_Response && 304 === $result->get_status() ? true : $served;
	}

	/**
	 * `wp_head` on the front page: where agents find the JSON catalog.
	 */
	public static function discovery(): void {
		if ( ! is_front_page() ) {
			return;
		}
		printf( '<link rel="alternate" type="application/json" title="%s" href="%s">' . "\n", esc_attr__( 'AI Katalog API', 'ai-hazir-site' ), esc_url( self::url( 'listings' ) ) );
	}

	/**
	 * URL of an endpoint.
	 *
	 * @param string $path Path under aihs/v1.
	 */
	public static function url( string $path = '' ): string {
		return rest_url( self::NAMESPACE . '/' . $path );
	}

	/**
	 * 429 with Retry-After when the client is over the limit, else null.
	 */
	private static function limited(): ?WP_REST_Response {
		$ip    = isset( $_SERVER['REMOTE_ADDR'] ) && is_string( $_SERVER['REMOTE_ADDR'] ) ? (string) filter_var( wp_unslash( $_SERVER['REMOTE_ADDR'] ), FILTER_VALIDATE_IP ) : '';
		$limit = (int) apply_filters( 'aihs_rest_rate_limit', self::DEFAULT_LIMIT );
		$retry = ( new FixedWindowLimiter( new WpCache(), new WpClock(), new WpSecret(), max( 1, $limit ) ) )->hit( $ip );
		if ( null === $retry ) {
			return null;
		}
		$response = self::error( 'aihs_rate_limited', __( 'Çok fazla istek. Lütfen biraz sonra tekrar deneyin.', 'ai-hazir-site' ), 429 );
		$response->header( 'Retry-After', (string) $retry );
		return $response;
	}

	/**
	 * Error body in the REST API's own shape.
	 *
	 * @param string $code    Code.
	 * @param string $message Message.
	 * @param int    $status  HTTP status.
	 */
	private static function error( string $code, string $message, int $status ): WP_REST_Response {
		return new WP_REST_Response(
			array(
				'code'    => $code,
				'message' => $message,
				'data'    => array( 'status' => $status ),
			),
			$status
		);
	}

	/**
	 * Responder for this site.
	 */
	private static function responder(): RestResponder {
		return CatalogReader::responder();
	}
}
