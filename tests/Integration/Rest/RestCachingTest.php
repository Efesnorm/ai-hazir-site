<?php
/**
 * Cache headers, rate limit, discovery and the U1 machine interface check.
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\Tests\Integration\Rest;

use AIHazirSite\Core\Features;
use AIHazirSite\Tests\Support\RateLimitWindow;
use AIHazirSite\WordPress\Catalog\CatalogModule;
use AIHazirSite\WordPress\Compliance\ComplianceModule;
use AIHazirSite\WordPress\Llms\LlmsModule;
use AIHazirSite\WordPress\Rest\RestModule;
use WP_REST_Request;
use WP_REST_Response;

/**
 * ETag / Last-Modified / 304, 429 + Retry-After, head link, llms.txt link, U1.
 *
 * @covers \AIHazirSite\WordPress\Rest\RestModule
 * @covers \AIHazirSite\Core\RateLimit\FixedWindowLimiter
 */
final class RestCachingTest extends RestTestCase {

	/**
	 * ETag and Last-Modified; a matching If-None-Match (strong or weak) gets 304; a change gives a new ETag.
	 */
	public function test_etag_and_304(): void {
		$ids      = self::catalog();
		$response = self::get( '/listings/' . $ids['cable'] );
		$headers  = $response->get_headers();

		$this->assertMatchesRegularExpression( '/^"[0-9a-f]{32}"$/', $headers['ETag'] );
		$this->assertMatchesRegularExpression( '/^[A-Z][a-z]{2}, \d{2} [A-Z][a-z]{2} \d{4} \d{2}:\d{2}:\d{2} GMT$/', $headers['Last-Modified'] );
		$this->assertSame( 'public, max-age=300', $headers['Cache-Control'] );

		foreach ( array( $headers['ETag'], 'W/' . $headers['ETag'], '"x", ' . $headers['ETag'] ) as $match ) {
			$cached = self::get( '/listings/' . $ids['cable'], array(), array( 'If-None-Match' => $match ) );
			$this->assertSame( 304, $cached->get_status(), $match );
			$this->assertNull( $cached->get_data() );
			$this->assertSame( $headers['ETag'], $cached->get_headers()['ETag'] );
		}
		$this->assertSame( 200, self::get( '/listings/' . $ids['cable'], array(), array( 'If-None-Match' => '"eski"' ) )->get_status() );

		CatalogModule::service()->save_listing(
			array(
				'type'  => 'offer',
				'title' => 'NYY kablo (güncel)',
			),
			$ids['cable']
		);
		$this->assertNotSame( $headers['ETag'], self::get( '/listings/' . $ids['cable'] )->get_headers()['ETag'] );
	}

	/**
	 * A 304 is served without a body.
	 */
	public function test_304_has_no_body(): void {
		$this->assertTrue( RestModule::no_body_for_304( false, new WP_REST_Response( null, 304 ) ) );
		$this->assertFalse( RestModule::no_body_for_304( false, new WP_REST_Response( array(), 200 ) ) );
	}

	/**
	 * Over the limit: 429 with Retry-After; the counter does not keep the raw IP.
	 */
	public function test_rate_limit(): void {
		remove_all_filters( 'aihs_rest_rate_limit' );
		add_filter( 'aihs_rest_rate_limit', static fn(): int => 3 );
		$_SERVER['REMOTE_ADDR'] = '198.51.100.77';
		RateLimitWindow::away_from_edge();

		for ( $i = 0; $i < 3; $i++ ) {
			$this->assertSame( 200, self::get( '/profile' )->get_status() );
		}
		$limited = self::get( '/listings' );
		$this->assertSame( 429, $limited->get_status() );
		$this->assertSame( 'aihs_rate_limited', $limited->get_data()['code'] );
		$this->assertGreaterThan( 0, (int) $limited->get_headers()['Retry-After'] );

		$_SERVER['REMOTE_ADDR'] = '198.51.100.78';
		$this->assertSame( 200, self::get( '/profile' )->get_status(), 'Another client is not limited.' );

		global $wpdb;
		$stored = (string) $wpdb->get_var( $wpdb->prepare( "SELECT GROUP_CONCAT(CONCAT(option_name, '=', option_value)) FROM {$wpdb->options} WHERE option_name LIKE %s", '%aihs\_rl\_%' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$this->assertNotSame( '', $stored );
		$this->assertStringNotContainsString( '198.51.100.7', $stored );
	}

	/**
	 * The endpoints are announced in the front page head and in llms.txt.
	 */
	public function test_discovery(): void {
		$this->go_to( home_url( '/' ) );
		ob_start();
		RestModule::discovery();
		$this->assertSame( '<link rel="alternate" type="application/json" title="AI Katalog API" href="' . esc_url( rest_url( 'aihs/v1/listings' ) ) . '">' . "\n", (string) ob_get_clean() );

		Features::set( Features::LLMS_TXT, true );
		delete_option( 'aihs_llms_cache' );
		$text = (string) LlmsModule::response( '/llms.txt' );
		$this->assertStringContainsString( '- [AI Katalog API (JSON)](' . rest_url( 'aihs/v1/listings' ) . '): Geçerli ilanlar, sayfalı; şema: ' . rest_url( 'aihs/v1/schema/listings' ), $text );

		Features::set( Features::REST_API, false );
		$this->assertStringNotContainsString( 'AI Katalog API', (string) LlmsModule::response( '/llms.txt' ), 'Off: not announced.' );
	}

	/**
	 * U1 machine_interface: REST discoverable (partial score until MCP arrives in A6).
	 */
	public function test_u1_machine_interface(): void {
		Features::set( Features::COMPLIANCE_SCAN, true );
		$this->go_to( home_url( '/' ) );
		ob_start();
		rest_output_link_wp_head();
		RestModule::discovery();
		$head = (string) ob_get_clean();

		add_filter(
			'pre_http_request',
			static function ( $pre, array $args, string $url ) use ( $head ) {
				$path = (string) wp_parse_url( $url, PHP_URL_PATH );
				$body = null;
				$type = 'text/html; charset=utf-8';
				if ( '/' === $path ) {
					$body = '<!doctype html><html><head>' . $head . '</head><body><h1>Örnek</h1></body></html>';
				} elseif ( str_starts_with( $path, '/wp-json/' ) || str_contains( $url, 'rest_route=' ) ) {
					$route = str_starts_with( $path, '/wp-json/' ) ? substr( $path, 8 ) : '/';
					$body  = (string) wp_json_encode( rest_get_server()->dispatch( new WP_REST_Request( 'GET', '' === $route ? '/' : $route ) )->get_data() );
					$type  = 'application/json; charset=UTF-8';
				}
				return array(
					'headers'  => array( 'content-type' => $type ),
					'cookies'  => array(),
					'body'     => $body ?? 'yok',
					'response' => array(
						'code'    => null === $body ? 404 : 200,
						'message' => '',
					),
				);
			},
			10,
			3
		);

		$row = ComplianceModule::run()->result( 'machine_interface' );
		$this->assertGreaterThanOrEqual( 0.5, (float) $row['ratio'], implode( ' | ', $row['findings'] ) );
	}
}
