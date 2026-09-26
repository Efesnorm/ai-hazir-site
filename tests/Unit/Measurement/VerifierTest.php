<?php
/**
 * Tests for IpRanges and Verifier.
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\Tests\Unit\Measurement;

use AIHazirSite\Modules\Measurement\Bot;
use AIHazirSite\Modules\Measurement\IpRanges;
use AIHazirSite\Modules\Measurement\Verifier;
use AIHazirSite\Tests\Unit\UnitTestCase;
use Brain\Monkey\Functions;
use RuntimeException;

/**
 * Verification unit tests (options and transients mocked in memory).
 *
 * @covers \AIHazirSite\Modules\Measurement\IpRanges
 * @covers \AIHazirSite\Modules\Measurement\Verifier
 */
final class VerifierTest extends UnitTestCase {

	private const LIST_URL = 'https://example.com/bot.json';

	/**
	 * In-memory options and transients.
	 *
	 * @var array<string, mixed>
	 */
	private array $options = array();

	/**
	 * Mocks the options and transient APIs.
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->options = array();

		Functions\when( 'get_option' )->alias( fn( string $name, $default_value = false ) => $this->options[ $name ] ?? $default_value );
		Functions\when( 'update_option' )->alias(
			function ( string $name, $value ): bool {
				$this->options[ $name ] = $value;
				return true;
			}
		);
		Functions\when( 'get_transient' )->alias( fn( string $name ) => $this->options[ 'transient_' . $name ] ?? false );
		Functions\when( 'set_transient' )->alias(
			function ( string $name, $value ): bool {
				$this->options[ 'transient_' . $name ] = $value;
				return true;
			}
		);
		Functions\when( 'wp_salt' )->justReturn( 'test-salt' );
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
	 * A downloaded list verifies IPs inside it only.
	 */
	public function test_ip_ranges_verification(): void {
		$ranges = new IpRanges( static fn(): string => '{"prefixes":[{"ipv4Prefix":"20.125.66.80/28"}]}' );
		$this->assertSame( array( self::LIST_URL => 1 ), $ranges->refresh( array( self::LIST_URL ) ) );

		$verifier = new Verifier( new IpRanges() );

		$this->assertTrue( $verifier->verify( $this->bot(), '20.125.66.81' ) );
		$this->assertFalse( $verifier->verify( $this->bot(), '198.51.100.1' ) );
		$this->assertFalse( $verifier->verify( $this->bot( 'none', '' ), '20.125.66.81' ) );
	}

	/**
	 * Unreachable source: refresh does not throw, the old list is kept, and without
	 * any list the request is simply unverified.
	 */
	public function test_unreachable_source_never_breaks_and_counts_unverified(): void {
		$failing = new IpRanges(
			static function (): ?string {
				throw new RuntimeException( 'Connection timed out' );
			}
		);
		$this->assertSame( array( self::LIST_URL => 0 ), $failing->refresh( array( self::LIST_URL ) ) );
		$this->assertFalse( ( new Verifier( new IpRanges() ) )->verify( $this->bot(), '20.125.66.81' ) );

		( new IpRanges( static fn(): string => '{"prefixes":[{"ipv4Prefix":"20.125.66.80/28"}]}' ) )->refresh( array( self::LIST_URL ) );
		( new IpRanges( static fn(): ?string => null ) )->refresh( array( self::LIST_URL ) );

		$this->assertTrue( ( new Verifier( new IpRanges() ) )->verify( $this->bot(), '20.125.66.81' ), 'Previous list is kept.' );
	}

	/**
	 * A broken options store makes verification fail closed, not throw.
	 */
	public function test_storage_error_is_unverified(): void {
		Functions\when( 'get_option' )->alias(
			static function (): void {
				throw new RuntimeException( 'Database gone' );
			}
		);

		$this->assertFalse( ( new Verifier( new IpRanges() ) )->verify( $this->bot(), '20.125.66.81' ) );
	}

	/**
	 * Forward-confirmed reverse DNS with a 24 h cache keyed by a salted hash of the IP.
	 */
	public function test_rdns_verification_and_cache(): void {
		$lookups  = 0;
		$verifier = new Verifier(
			new IpRanges(),
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

		foreach ( array_keys( $this->options ) as $key ) {
			$this->assertStringNotContainsString( '192.0.2', $key, 'Cache key must not contain the IP.' );
		}
	}

	/**
	 * A spoofed PTR record fails the forward confirmation.
	 */
	public function test_rdns_spoofed_ptr_is_rejected(): void {
		$verifier = new Verifier(
			new IpRanges(),
			static fn(): string => 'crawl-1.bot.example.com',
			static fn(): array => array( '198.51.100.99' )
		);

		$this->assertFalse( $verifier->verify( $this->bot( 'rdns', 'bot.example.com' ), '192.0.2.10' ) );
	}
}
