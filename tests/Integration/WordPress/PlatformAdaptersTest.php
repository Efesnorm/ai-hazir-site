<?php
/**
 * WordPress implementations of the core contracts.
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\Tests\Integration\WordPress;

use AIHazirSite\Core\Contracts\HitRepository;
use AIHazirSite\Core\Storage\HitStore;
use AIHazirSite\Tests\Support\MemoryHitRepository;
use AIHazirSite\WordPress\Platform\WpCache;
use AIHazirSite\WordPress\Platform\WpClock;
use AIHazirSite\WordPress\Platform\WpHttpClient;
use AIHazirSite\WordPress\Platform\WpSecret;
use AIHazirSite\WordPress\Platform\WpSettings;
use WP_Error;
use WP_UnitTestCase;

/**
 * Platform adapter integration tests.
 *
 * @covers \AIHazirSite\WordPress\Platform\WpSettings
 * @covers \AIHazirSite\WordPress\Platform\WpCache
 * @covers \AIHazirSite\WordPress\Platform\WpHttpClient
 * @covers \AIHazirSite\WordPress\Platform\WpClock
 * @covers \AIHazirSite\WordPress\Platform\WpSecret
 * @covers \AIHazirSite\Core\Storage\HitStore::totals
 */
final class PlatformAdaptersTest extends WP_UnitTestCase {

	/**
	 * Settings round-trip and autoload flag.
	 */
	public function test_settings(): void {
		$settings = new WpSettings();

		$this->assertSame( 'yok', $settings->get( 'aihs_test_key', 'yok' ) );

		$settings->set( 'aihs_test_key', array( 'a' => 1 ), true );
		$settings->set( 'aihs_test_big', 'x', false );
		$this->assertSame( array( 'a' => 1 ), $settings->get( 'aihs_test_key' ) );
		wp_cache_delete( 'alloptions', 'options' );
		$this->assertArrayHasKey( 'aihs_test_key', wp_load_alloptions() );
		$this->assertArrayNotHasKey( 'aihs_test_big', wp_load_alloptions() );

		$settings->delete( 'aihs_test_key' );
		$this->assertNull( $settings->get( 'aihs_test_key' ) );
	}

	/**
	 * Missing transients read as null.
	 */
	public function test_cache(): void {
		$cache = new WpCache();

		$this->assertNull( $cache->get( 'aihs_test_cache' ) );
		$cache->set( 'aihs_test_cache', '0', 60 );
		$this->assertSame( '0', $cache->get( 'aihs_test_cache' ) );
	}

	/**
	 * Clock follows the site timezone; secret is keyed and deterministic.
	 */
	public function test_clock_and_secret(): void {
		$this->assertSame( current_time( 'Y-m-d' ), ( new WpClock() )->today() );
		$this->assertEqualsWithDelta( time(), ( new WpClock() )->now(), 2 );

		$secret = new WpSecret();
		$this->assertMatchesRegularExpression( '/^[0-9a-f]{64}$/', $secret->hmac( '203.0.113.7' ) );
		$this->assertSame( $secret->hmac( '203.0.113.7' ), $secret->hmac( '203.0.113.7' ) );
		$this->assertNotSame( hash( 'sha256', '203.0.113.7' ), $secret->hmac( '203.0.113.7' ), 'Must be keyed, not a plain hash.' );
	}

	/**
	 * HTTP: body on 200, null on errors and other status codes.
	 */
	public function test_http_client(): void {
		$response  = static fn( int $code, string $body ): array => array(
			'headers'  => array(),
			'cookies'  => array(),
			'body'     => $body,
			'response' => array(
				'code'    => $code,
				'message' => '',
			),
		);
		$responses = array(
			'https://ok.example/'  => $response( 200, 'merhaba' ),
			'https://404.example/' => $response( 404, 'yok' ),
			'https://err.example/' => new WP_Error( 'http_request_failed', 'timeout' ),
		);
		add_filter( 'pre_http_request', static fn( $pre, array $args, string $url ) => $responses[ $url ], 10, 3 );

		$http = new WpHttpClient();
		$this->assertSame( 'merhaba', $http->get( 'https://ok.example/' ) );
		$this->assertNull( $http->get( 'https://404.example/' ) );
		$this->assertNull( $http->get( 'https://err.example/' ) );
	}

	/**
	 * The database repository and the in-memory test double give identical totals.
	 */
	public function test_memory_repository_matches_database(): void {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i', HitStore::table() ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		$database = new HitStore();
		$memory   = new MemoryHitRepository();
		$writes   = array(
			array( '2026-09-20', 'bot', 'gptbot', '/a/', true ),
			array( '2026-09-20', 'bot', 'gptbot', '/a/', true ),
			array( '2026-09-21', 'bot', 'gptbot', '/b/', false ),
			array( '2026-09-21', 'bot', 'claudebot', '/a/', false ),
			array( '2026-09-22', 'bot', 'ccbot', '/c/', false ),
			array( '2026-09-22', 'bot', 'ccbot', '/c/', false ),
			array( '2026-09-10', 'bot', 'gptbot', '/eski/', true ),
			array( '2026-09-22', 'referral', 'chatgpt', '/', false ),
		);
		foreach ( $writes as $write ) {
			$database->increment( ...$write );
			$memory->increment( ...$write );
		}

		foreach ( array( HitRepository::GROUP_SOURCE, HitRepository::GROUP_PATH ) as $group ) {
			foreach ( array( 0, 2 ) as $limit ) {
				$this->assertSame(
					$memory->totals( 'bot', '2026-09-15', $group, $limit ),
					$database->totals( 'bot', '2026-09-15', $group, $limit ),
					"{$group}, limit {$limit}"
				);
			}
		}
		$this->assertSame( $memory->totals( 'referral', '2026-09-15', 'source_id' ), $database->totals( 'referral', '2026-09-15', 'source_id' ) );
		$this->assertSame( $memory->prune( '2026-09-21' ), $database->prune( '2026-09-21' ) );
		$this->assertSame( $memory->totals( 'bot', '2000-01-01', 'path' ), $database->totals( 'bot', '2000-01-01', 'path' ) );
	}
}
