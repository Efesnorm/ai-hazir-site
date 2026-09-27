<?php
/**
 * WpPageFetcher against the WordPress HTTP API.
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\Tests\Integration\WordPress;

use AIHazirSite\WordPress\Platform\WpPageFetcher;
use WP_Error;
use WP_UnitTestCase;

/**
 * WpPageFetcher integration tests (HTTP mocked with pre_http_request).
 *
 * @covers \AIHazirSite\WordPress\Platform\WpPageFetcher
 */
final class WpPageFetcherTest extends WP_UnitTestCase {

	/**
	 * Status, lower-case headers, body; request headers and timeout are passed on.
	 */
	public function test_response_and_request_arguments(): void {
		$seen = array();
		add_filter(
			'pre_http_request',
			static function ( $pre, array $args, string $url ) use ( &$seen ) {
				$seen = $args + array( 'url' => $url );
				if ( 'https://err.example/' === $url ) {
					return new WP_Error( 'http_request_failed', 'cURL error 28' );
				}
				return array(
					'headers'  => array(
						'Link'         => '<https://ornek.com/wp-json/>; rel="https://api.w.org/"',
						'X-Robots-Tag' => 'noindex',
					),
					'body'     => '<p>merhaba</p>',
					'response' => array(
						'code'    => 503,
						'message' => '',
					),
					'cookies'  => array(),
				);
			},
			10,
			3
		);

		$response = ( new WpPageFetcher() )->fetch( 'https://ornek.com/', array( 'User-Agent' => 'GPTBot/1.4', 'X-AIHS-Scan' => 'imza' ), 5 ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound

		$this->assertSame( 503, $response->status );
		$this->assertSame( '<p>merhaba</p>', $response->body );
		$this->assertSame( 'noindex', $response->header( 'X-ROBOTS-TAG' ) );
		$this->assertStringContainsString( 'api.w.org', $response->header( 'link' ) );
		$this->assertSame( 5, $seen['timeout'] );
		$this->assertSame( 'GPTBot/1.4', $seen['user-agent'] );
		$this->assertSame( 'imza', $seen['headers']['X-AIHS-Scan'] );

		$failed = ( new WpPageFetcher() )->fetch( 'https://err.example/' );
		$this->assertSame( 0, $failed->status );
		$this->assertStringContainsString( 'cURL error 28', $failed->error );
	}
}
