<?php
/**
 * Tests for IpRanges and Verifier.
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\Tests\Unit\Measurement;

use AIHazirSite\Core\Contracts\HttpClient;
use AIHazirSite\Core\Contracts\Settings;
use AIHazirSite\Core\Measurement\Bot;
use AIHazirSite\Core\Measurement\IpRanges;
use AIHazirSite\Core\Measurement\Verifier;
use AIHazirSite\Tests\Support\FakeHttpClient;
use AIHazirSite\Tests\Support\FixedClock;
use AIHazirSite\Tests\Support\MemoryCache;
use AIHazirSite\Tests\Support\MemorySettings;
use AIHazirSite\Tests\Support\StaticSecret;
use AIHazirSite\Tests\Unit\UnitTestCase;
use RuntimeException;

/**
 * Verification unit tests with in-memory adapters (no WordPress).
 *
 * @covers \AIHazirSite\Core\Measurement\IpRanges
 * @covers \AIHazirSite\Core\Measurement\Verifier
 */
final class VerifierTest extends UnitTestCase {

	private const LIST_URL = 'https://example.com/bot.json';
	private const LIST     = '{"prefixes":[{"ipv4Prefix":"20.125.66.80/28"}]}';

	/**
	 * Shared settings (like the options table).
	 *
	 * @var MemorySettings
	 */
	private MemorySettings $settings;

	/**
	 * Shared cache (like transients).
	 *
	 * @var MemoryCache
	 */
	private MemoryCache $cache;

	/**
	 * Fresh adapters.
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->settings = new MemorySettings();
		$this->cache    = new MemoryCache();
	}

	/**
	 * IP list store over the shared settings.
	 *
	 * @param array<string, string> $bodies URL → body served by the fake HTTP client.
	 */
	private function ranges( array $bodies = array() ): IpRanges {
		return new IpRanges( $this->settings, new FakeHttpClient( $bodies ), new FixedClock() );
	}

	/**
	 * Verifier over the shared settings and cache.
	 *
	 * @param callable|null $reverse Reverse lookup.
	 * @param callable|null $forward Forward lookup.
	 */
	private function verifier( ?callable $reverse = null, ?callable $forward = null ): Verifier {
		return new Verifier( $this->ranges(), $this->cache, new StaticSecret(), $reverse, $forward );
	}

	/**
	 * A bot definition.
	 *
	 * @param string $verify        Verification method.
	 * @param string $verify_source Source.
	 */
	private function bot( string $verify = 'ip_ranges', string $verify_source = self::LIST_URL ): Bot {
		return new Bot( 'testbot', 'TestBot', 'Test', 'TestBot', 'search', $verify, $verify_source, '' );
	}

	/**
	 * CIDR samples.
	 *
	 * @return array<string, array{string, string, bool}>
	 */
	public static function cidrs(): array {
		return array(
			'v4 inside /28'   => array( '20.125.66.80/28', '20.125.66.95', true ),
			'v4 outside /28'  => array( '20.125.66.80/28', '20.125.66.96', false ),
			'v4 /22 edge'     => array( '216.73.216.0/22', '216.73.219.255', true ),
			'v4 /32'          => array( '3.224.62.45/32', '3.224.62.45', true ),
			'bare v4'         => array( '3.81.245.78', '3.81.245.78', true ),
			'bare v4 other'   => array( '3.81.245.78', '3.81.245.79', false ),
			'v6 inside /56'   => array( '2600:1f28:365:8000::/56', '2600:1f28:365:80ff::1', true ),
			'v6 outside /56'  => array( '2600:1f28:365:8000::/56', '2600:1f28:365:8100::1', false ),
			'family mismatch' => array( '20.125.66.80/28', '::ffff:20.125.66.81', false ),
			'invalid ip'      => array( '20.125.66.80/28', 'not-an-ip', false ),
			'prefix too long' => array( '20.125.66.80/40', '20.125.66.80', false ),
		);
	}

	/**
	 * CIDR matching for IPv4 and IPv6.
	 *
	 * @dataProvider cidrs
	 *
	 * @param string $cidr     Block.
	 * @param string $ip       Address.
	 * @param bool   $expected Expected result.
	 */
	public function test_cidr_contains( string $cidr, string $ip, bool $expected ): void {
		$this->assertSame( $expected, IpRanges::cidr_contains( $cidr, $ip ) );
	}

	/**
	 * Parses published JSON and JSON embedded in HTML.
	 */
	public function test_parse_json_and_embedded_html(): void {
		$json = '{"creationTime":"x","prefixes":[{"ipv4Prefix":"20.125.66.80/28"},{"ipv6Prefix":"2600:1f28:365:8000::/56"},{"ipv4Prefix":"bogus"}]}';
		$html = '<pre><code>{ &quot;prefixes&quot;: [ { &quot;ipv4Prefix&quot;: &quot;3.81.245.78&quot; } ] }</code></pre>';

		$this->assertSame( array( '20.125.66.80/28', '2600:1f28:365:8000::/56' ), IpRanges::parse( $json ) );
		$this->assertSame( array( '3.81.245.78' ), IpRanges::parse( $html ) );
		$this->assertSame( array(), IpRanges::parse( '<html>Not found</html>' ) );
	}

	/**
	 * A downloaded list verifies IPs inside it only; the list is stored without autoload.
	 */
	public function test_ip_ranges_verification(): void {
		$this->assertSame( array( self::LIST_URL => 1 ), $this->ranges( array( self::LIST_URL => self::LIST ) )->refresh( array( self::LIST_URL ) ) );
		$this->assertFalse( $this->settings->autoload[ IpRanges::OPTION ] );

		$verifier = $this->verifier();

		$this->assertTrue( $verifier->verify( $this->bot(), '20.125.66.81' ) );
		$this->assertFalse( $verifier->verify( $this->bot(), '198.51.100.1' ) );
		$this->assertFalse( $verifier->verify( $this->bot( 'none', '' ), '20.125.66.81' ) );
	}

	/**
	 * Unreachable source: refresh does not throw, the old list is kept, and without
	 * any list the request is simply unverified.
	 */
	public function test_unreachable_source_never_breaks_and_counts_unverified(): void {
		$this->assertSame( array( self::LIST_URL => 0 ), $this->ranges()->refresh( array( self::LIST_URL ) ) );
		$this->assertFalse( $this->verifier()->verify( $this->bot(), '20.125.66.81' ) );

		$this->ranges( array( self::LIST_URL => self::LIST ) )->refresh( array( self::LIST_URL ) );
		$this->ranges()->refresh( array( self::LIST_URL ) );

		$this->assertTrue( $this->verifier()->verify( $this->bot(), '20.125.66.81' ), 'Previous list is kept.' );
	}

	/**
	 * A throwing HTTP client is handled like a failed download.
	 */
	public function test_throwing_http_client_is_a_failed_download(): void {
		$http = new class() implements HttpClient {
			/**
			 * Always throws.
			 *
			 * @param string $url URL.
			 * @throws RuntimeException Always.
			 */
			public function get( string $url ): ?string {
				throw new RuntimeException( 'Connection timed out' );
			}
		};

		$ranges = new IpRanges( $this->settings, $http, new FixedClock() );
		$this->assertSame( array( self::LIST_URL => 0 ), $ranges->refresh( array( self::LIST_URL ) ) );
	}

	/**
	 * A broken settings store makes verification fail closed, not throw.
	 */
	public function test_storage_error_is_unverified(): void {
		$broken = new class() implements Settings {
			/**
			 * Always throws.
			 *
			 * @param string $key           Key.
			 * @param mixed  $default_value Default.
			 * @throws RuntimeException Always.
			 */
			public function get( string $key, mixed $default_value = null ): mixed {
				throw new RuntimeException( 'Database gone' );
			}

			/**
			 * Unused.
			 *
			 * @param string $key      Key.
			 * @param mixed  $value    Value.
			 * @param bool   $autoload Autoload.
			 */
			public function set( string $key, mixed $value, bool $autoload = false ): void {
			}

			/**
			 * Unused.
			 *
			 * @param string $key Key.
			 */
			public function delete( string $key ): void {
			}
		};

		$verifier = new Verifier( new IpRanges( $broken, new FakeHttpClient(), new FixedClock() ), $this->cache, new StaticSecret() );

		$this->assertFalse( $verifier->verify( $this->bot(), '20.125.66.81' ) );
	}

	/**
	 * Forward-confirmed reverse DNS with a 24 h cache keyed by a salted hash of the IP.
	 */
	public function test_rdns_verification_and_cache(): void {
		$lookups  = 0;
		$verifier = $this->verifier(
			function ( string $ip ) use ( &$lookups ): string {
				++$lookups;
				return '192.0.2.10' === $ip ? 'crawl-1.bot.example.com' : 'evil.example.net';
			},
			static fn( string $host ): array => 'crawl-1.bot.example.com' === $host ? array( '192.0.2.10' ) : array( '192.0.2.11' )
		);
		$bot      = $this->bot( 'rdns', 'bot.example.com' );

		$this->assertTrue( $verifier->verify( $bot, '192.0.2.10' ) );
		$this->assertTrue( $verifier->verify( $bot, '192.0.2.10' ) );
		$this->assertSame( 1, $lookups, 'Second check is served from cache.' );
		$this->assertFalse( $verifier->verify( $bot, '192.0.2.11' ), 'Wrong host suffix.' );

		foreach ( array_keys( $this->cache->values ) as $key ) {
			$this->assertStringNotContainsString( '192.0.2', $key, 'Cache key must not contain the IP.' );
		}
		$this->assertSame( array( Verifier::RDNS_TTL ), array_values( array_unique( $this->cache->ttl ) ) );
		$this->assertSame( 86400, Verifier::RDNS_TTL );
	}

	/**
	 * A spoofed PTR record fails the forward confirmation.
	 */
	public function test_rdns_spoofed_ptr_is_rejected(): void {
		$verifier = $this->verifier(
			static fn(): string => 'crawl-1.bot.example.com',
			static fn(): array => array( '198.51.100.99' )
		);

		$this->assertFalse( $verifier->verify( $this->bot( 'rdns', 'bot.example.com' ), '192.0.2.10' ) );
	}
}
