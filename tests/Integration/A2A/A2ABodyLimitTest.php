<?php
/**
 * A2A refuses oversized bodies unparsed (1.14.2).
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\Tests\Integration\A2A;

use AIHazirSite\WordPress\A2A\A2AModule;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * Found in the system check: a 2 MB body was accepted and parsed.
 *
 * @covers \AIHazirSite\WordPress\A2A\A2AModule
 */
final class A2ABodyLimitTest extends WP_UnitTestCase {

	/**
	 * No rate limit in the way.
	 */
	public function set_up(): void {
		parent::set_up();
		add_filter( 'aihs_a2a_rate_limit', static fn(): int => 10000 );
		$_SERVER['REMOTE_ADDR'] = '198.51.100.' . wp_rand( 1, 250 );
	}

	/**
	 * Above 64 KB: 413 with JSON-RPC Invalid Request.
	 */
	public function test_oversized_body_is_refused(): void {
		$response = A2AModule::handle( $this->request( 70 * 1024 ) );

		$this->assertSame( 413, $response->get_status() );
		$this->assertSame( -32600, $response->get_data()['error']['code'] );
	}

	/**
	 * Within the limit the body is parsed as before (here: an invalid message, answered with 200).
	 */
	public function test_body_within_limit_is_parsed(): void {
		$response = A2AModule::handle( $this->request( 60 * 1024 ) );

		$this->assertNotSame( 413, $response->get_status() );
		$this->assertArrayHasKey( 'error', $response->get_data() );
	}

	/**
	 * The limit can be lowered with the filter.
	 */
	public function test_filter_lowers_the_limit(): void {
		add_filter( 'aihs_a2a_max_body', static fn(): int => 2048 );

		$this->assertSame( 413, A2AModule::handle( $this->request( 3 * 1024 ) )->get_status() );
		$this->assertNotSame( 413, A2AModule::handle( $this->request( 1024 ) )->get_status() );
	}

	/**
	 * A JSON body of about the given size.
	 *
	 * @param int $bytes Size.
	 */
	private function request( int $bytes ): WP_REST_Request {
		$request = new WP_REST_Request( 'POST', '/aihs/a2a' );
		$request->set_header( 'content-type', 'application/json' );
		$request->set_body(
			(string) wp_json_encode(
				array(
					'jsonrpc' => '2.0',
					'id'      => 1,
					'method'  => 'x',
					'params'  => array( 'pad' => str_repeat( 'a', $bytes ) ),
				)
			)
		);
		return $request;
	}
}
