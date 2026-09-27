<?php
/**
 * Shared setup for the MCP server tests.
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\Tests\Integration\Mcp;

use AIHazirSite\Adapters\Abilities\AbilitySchemas;
use AIHazirSite\Core\Features;
use AIHazirSite\Tests\Integration\Rest\RestTestCase;
use AIHazirSite\WordPress\Abilities\AbilitiesModule;
use AIHazirSite\WordPress\Mcp\McpModule;
use AIHazirSite\WordPress\Mcp\StatelessHttpTransport;
use WP\MCP\Core\McpAdapter;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Abilities and MCP on; our server reachable over the REST server of the test.
 * The adapter initializes once per process (a singleton) while WordPress tests reset hooks
 * after each test, so the transport routes are re-attached when missing.
 */
abstract class McpTestCase extends RestTestCase {

	/**
	 * Abilities + MCP on, abilities registered, server routes present.
	 */
	public function set_up(): void {
		parent::set_up();
		Features::set( Features::ABILITIES, true );
		Features::set( Features::MCP, true );
		add_filter( 'aihs_abilities_rate_limit', static fn(): int => 10000 );
		// Test environment only: the abilities registry has already run its init when the adapter
		// starts here, so the adapter's default server could not register its own helper abilities.
		add_filter( 'mcp_adapter_create_default_server', '__return_false' );
		self::register_abilities();
		self::boot_mcp();
	}

	/**
	 * Registers our abilities once (they stay registered for the process: the server keeps them).
	 */
	protected static function register_abilities(): void {
		if ( wp_has_ability( 'aihs/get-profile' ) ) {
			return;
		}
		wp_get_abilities();
		remove_all_actions( 'wp_abilities_api_categories_init' );
		remove_all_actions( 'wp_abilities_api_init' );
		( new AbilitiesModule() )->register();
		if ( wp_has_ability_category( AbilitiesModule::CATEGORY ) ) {
			// Left registered by another test class (registries live for the whole process).
			remove_action( 'wp_abilities_api_categories_init', array( AbilitiesModule::class, 'register_category' ) );
		}
		do_action( 'wp_abilities_api_categories_init' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core hook.
		do_action( 'wp_abilities_api_init' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core hook.
	}

	/**
	 * Starts the adapter (first test) or re-attaches our transport's route (later tests).
	 */
	protected static function boot_mcp(): void {
		( new McpModule() )->register();
		self::boot();
		rest_get_server();

		$server = McpAdapter::instance()->get_server( McpModule::SERVER_ID );
		self::assertNotNull( $server, 'Our MCP server exists.' );
		if ( ! isset( rest_get_server()->get_routes()[ '/' . McpModule::NAMESPACE . '/' . McpModule::ROUTE ] ) ) {
			new StatelessHttpTransport( $server->create_transport_context() );
			self::boot();
			rest_get_server();
		}
	}

	/**
	 * One JSON-RPC request to our server.
	 *
	 * @param string                $method  Method.
	 * @param array<string, mixed>  $params  Params.
	 * @param array<string, string> $headers Extra headers.
	 */
	protected static function rpc( string $method, array $params = array(), array $headers = array() ): WP_REST_Response {
		$response = self::dispatch_rpc( $method, $params, $headers );
		// The adapter returns DTO arrays with stdClass parts; read them as JSON would arrive.
		$response->set_data( json_decode( (string) wp_json_encode( $response->get_data() ), true ) );
		return $response;
	}

	/**
	 * Dispatches one JSON-RPC request.
	 *
	 * @param string                $method  Method.
	 * @param array<string, mixed>  $params  Params.
	 * @param array<string, string> $headers Extra headers.
	 */
	private static function dispatch_rpc( string $method, array $params, array $headers ): WP_REST_Response {
		$request = new WP_REST_Request( 'POST', '/' . McpModule::NAMESPACE . '/' . McpModule::ROUTE );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_header( 'Accept', 'application/json, text/event-stream' );
		foreach ( $headers as $name => $value ) {
			$request->set_header( $name, $value );
		}
		$request->set_body(
			(string) wp_json_encode(
				array(
					'jsonrpc' => '2.0',
					'id'      => 1,
					'method'  => $method,
					'params'  => (object) $params,
				)
			)
		);
		return rest_get_server()->dispatch( $request );
	}

	/**
	 * Structured result of a tool call.
	 *
	 * @param string               $tool      Tool name (ability name with "/" → "-").
	 * @param array<string, mixed> $arguments Arguments.
	 * @return array<string, mixed>
	 */
	protected static function call( string $tool, array $arguments = array() ): array {
		$data = self::rpc(
			'tools/call',
			array(
				'name'      => $tool,
				'arguments' => (object) $arguments,
			)
		)->get_data();
		self::assertIsArray( $data );
		self::assertArrayHasKey( 'result', $data, (string) wp_json_encode( $data ) );
		return (array) ( $data['result']['structuredContent'] ?? array() );
	}

	/**
	 * Tool names of our abilities.
	 *
	 * @return list<string>
	 */
	protected static function tool_names(): array {
		return array_map( static fn( string $name ): string => str_replace( '/', '-', $name ), array_keys( AbilitySchemas::all() ) );
	}
}
