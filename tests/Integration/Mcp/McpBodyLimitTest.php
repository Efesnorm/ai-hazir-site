<?php
/**
 * The MCP endpoint refuses oversized bodies unparsed (1.14.3).
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\Tests\Integration\Mcp;

/**
 * Same limit as A2A (1.14.2).
 *
 * @covers \AIHazirSite\WordPress\Mcp\StatelessHttpTransport
 */
final class McpBodyLimitTest extends McpTestCase {

	/**
	 * Above 64 KB: 413 with JSON-RPC Invalid Request.
	 */
	public function test_oversized_body_is_refused(): void {
		$response = self::rpc( 'tools/list', array( 'pad' => str_repeat( 'a', 70 * 1024 ) ) );

		$this->assertSame( 413, $response->get_status() );
		$this->assertSame( -32600, $response->get_data()['error']['code'] );
	}

	/**
	 * A normal request is answered as before.
	 */
	public function test_normal_request_is_answered(): void {
		$response = self::rpc( 'tools/list' );

		$this->assertSame( 200, $response->get_status() );
		$this->assertNotEmpty( $response->get_data()['result']['tools'] );
	}

	/**
	 * The limit can be lowered with the filter.
	 */
	public function test_filter_lowers_the_limit(): void {
		add_filter( 'aihs_mcp_max_body', static fn(): int => 2048 );

		$this->assertSame( 413, self::rpc( 'tools/list', array( 'pad' => str_repeat( 'a', 3 * 1024 ) ) )->get_status() );
		$this->assertSame( 200, self::rpc( 'tools/list' )->get_status() );
	}
}
