<?php
/**
 * Sessionless Streamable HTTP transport for the public, read-only catalog server.
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\WordPress\Mcp;

use WP\MCP\Core\McpVersionNegotiator;
use WP\MCP\Infrastructure\ErrorHandling\McpErrorFactory;
use WP\MCP\Transport\Contracts\McpRestTransportInterface;
use WP\MCP\Transport\Infrastructure\JsonRpcResponseBuilder;
use WP\MCP\Transport\Infrastructure\McpTransportContext;
use WP_REST_Request;
use WP_REST_Response;

/**
 * The adapter's own HttpTransport ties every MCP session to a logged-in WordPress user, so it
 * cannot serve anonymous clients. The MCP specification lets a server work without sessions
 * ("A server … MAY assign a session ID", 2025-06-18 transports), so this transport, plugged in
 * through the adapter's McpRestTransportInterface, answers each POSTed JSON-RPC message on its
 * own and hands it to the adapter's request router without an HTTP session context:
 * - POST: JSON-RPC request → application/json result; notifications / responses → 202.
 * - GET (SSE) and DELETE (session end) → 405, as the specification allows.
 * - A browser Origin header must be this site's own (DNS-rebinding protection the specification
 *   requires); requests without Origin (servers, CLI clients) are allowed.
 * The tools are read-only; each tool call is rate limited by the abilities.
 */
final class StatelessHttpTransport implements McpRestTransportInterface {

	/**
	 * Transport context from the adapter.
	 *
	 * @var McpTransportContext
	 */
	private McpTransportContext $context;

	/**
	 * Constructor (called by the adapter).
	 *
	 * @param McpTransportContext $context Context.
	 */
	public function __construct( McpTransportContext $context ) {
		$this->context = $context;
		add_action( 'rest_api_init', array( $this, 'register_routes' ), 16 );
	}

	/**
	 * Registers the MCP endpoint.
	 */
	public function register_routes(): void {
		$server = $this->context->mcp_server;
		register_rest_route(
			$server->get_server_route_namespace(),
			$server->get_server_route(),
			array(
				'methods'             => array( 'POST', 'GET', 'DELETE' ),
				'callback'            => array( $this, 'handle_request' ),
				'permission_callback' => array( $this, 'check_permission' ),
			)
		);
	}

	/**
	 * Server permission callback and Origin check.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return bool
	 */
	public function check_permission( WP_REST_Request $request ) {
		if ( ! self::origin_allowed( (string) $request->get_header( 'origin' ) ) ) {
			return false;
		}
		$callback = $this->context->transport_permission_callback;
		return null === $callback ? true : true === call_user_func( $callback, $request );
	}

	/**
	 * Whether an Origin header may call the server ('' = no Origin = not a browser page).
	 *
	 * @param string $origin Origin header.
	 */
	public static function origin_allowed( string $origin ): bool {
		if ( '' === $origin ) {
			return true;
		}
		$home = wp_parse_url( home_url() );
		$site = strtolower( ( $home['scheme'] ?? 'https' ) . '://' . ( $home['host'] ?? '' ) . ( isset( $home['port'] ) ? ':' . $home['port'] : '' ) );

		/**
		 * Filters the browser origins allowed to call the public MCP server (besides the site itself).
		 *
		 * @param string[] $origins Origins like "https://example.com".
		 */
		$allowed = array_map( 'strtolower', (array) apply_filters( 'aihs_mcp_allowed_origins', array() ) );
		return in_array( strtolower( rtrim( $origin, '/' ) ), array_merge( array( $site ), $allowed ), true );
	}

	/**
	 * Handles a request.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function handle_request( WP_REST_Request $request ): WP_REST_Response {
		if ( 'POST' !== $request->get_method() ) {
			return new WP_REST_Response( null, 405, array( 'Allow' => 'POST' ) );
		}

		$body = json_decode( $request->get_body(), true );
		if ( ! is_array( $body ) ) {
			return new WP_REST_Response( McpErrorFactory::parse_error( null, 'Invalid JSON in request body' )->toArray(), 400 );
		}

		$version = (string) $request->get_header( 'mcp_protocol_version' );
		if ( '' !== $version && ! McpVersionNegotiator::is_supported( $version ) ) {
			return new WP_REST_Response( McpErrorFactory::invalid_request( null, 'Bad Request: Unsupported protocol version: ' . $version )->toArray(), 400 );
		}

		try {
			$batch    = JsonRpcResponseBuilder::is_batch_request( $body );
			$response = JsonRpcResponseBuilder::process_messages(
				JsonRpcResponseBuilder::normalize_messages( $body ),
				$batch,
				fn( array $message ): ?array => $this->message( $message )
			);
		} catch ( \Throwable $exception ) {
			$this->context->error_handler->log( 'Unexpected error in the AI Hazır Site MCP transport', array( 'error' => $exception->getMessage() ) );
			return new WP_REST_Response( McpErrorFactory::internal_error( null, 'Handler error occurred' )->toArray(), 500 );
		}

		if ( null === $response ) {
			return new WP_REST_Response( null, 202 );
		}
		if ( ! $batch && isset( $response['error'] ) ) {
			return new WP_REST_Response( $response, McpErrorFactory::get_http_status_for_error( $response ) );
		}
		return new WP_REST_Response( $response, 200 );
	}

	/**
	 * One JSON-RPC message: a request gets a response; notifications and responses get none.
	 *
	 * @param array<mixed> $message Message.
	 * @return array<mixed>|null
	 */
	private function message( array $message ): ?array {
		$validation = McpErrorFactory::validate_jsonrpc_message( $message );
		if ( true !== $validation ) {
			return $validation->toArray();
		}
		if ( ! isset( $message['method'], $message['id'] ) ) {
			return null;
		}

		$params = isset( $message['params'] ) && is_array( $message['params'] ) ? $message['params'] : array();
		$result = $this->context->request_router->route_request( (string) $message['method'], $params, $message['id'], 'aihs-http' );
		unset( $result['_session_id'] );

		if ( isset( $result['error'] ) ) {
			return JsonRpcResponseBuilder::create_error_response( $message['id'], $result['error'] );
		}
		return JsonRpcResponseBuilder::create_success_response( $message['id'], $result );
	}
}
