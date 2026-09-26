<?php
/**
 * Published IP ranges of AI bots.
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\Modules\Measurement;

use Throwable;

/**
 * Downloads the operators' IP lists (daily cron) and answers "is this IP in the list?"
 * on requests without any network access.
 *
 * Stored in the `aihs_ip_ranges` option (not autoloaded):
 * `array<string url, array{fetched: int, prefixes: list<string>}>`.
 */
final class IpRanges {

	public const OPTION = 'aihs_ip_ranges';

	/**
	 * Cached option value for this request.
	 *
	 * @var array<string, array{fetched: int, prefixes: list<string>}>|null
	 */
	private ?array $store = null;

	/**
	 * Downloads a URL: fn( string $url ): ?string.
	 *
	 * @var (callable(string): ?string)|null
	 */
	private $fetcher;

	/**
	 * Constructor.
	 *
	 * @param callable|null $fetcher fn( string $url ): ?string; defaults to wp_remote_get().
	 *
	 * @phpstan-param (callable(string): ?string)|null $fetcher
	 */
	public function __construct( ?callable $fetcher = null ) {
		$this->fetcher = $fetcher;
	}

	/**
	 * Re-downloads the lists. A list that fails to download or parse keeps its previous value.
	 *
	 * @param string[] $urls List URLs.
	 * @return array<string, int> Number of prefixes stored per URL (0 = failed, previous kept).
	 */
	public function refresh( array $urls ): array {
		$store  = $this->load();
		$result = array();

		foreach ( array_unique( $urls ) as $url ) {
			$prefixes = array();
			try {
				$body     = $this->fetch( $url );
				$prefixes = null === $body ? array() : self::parse( $body );
			} catch ( Throwable ) {
				$prefixes = array();
			}

			$result[ $url ] = count( $prefixes );
			if ( array() !== $prefixes ) {
				$store[ $url ] = array(
					'fetched'  => time(),
					'prefixes' => $prefixes,
				);
			}
		}

		$this->store = $store;
		update_option( self::OPTION, $store, false );

		return $result;
	}

	/**
	 * Whether an IP is in the list published at a URL. False when the list is unknown.
	 *
	 * @param string $url List URL.
	 * @param string $ip  Client IP.
	 */
	public function contains( string $url, string $ip ): bool {
		$store = $this->load();
		if ( ! isset( $store[ $url ] ) ) {
			return false;
		}
		foreach ( $store[ $url ]['prefixes'] as $prefix ) {
			if ( self::cidr_contains( $prefix, $ip ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Extracts `ipv4Prefix` / `ipv6Prefix` values from a JSON document or from JSON
	 * embedded in an HTML page (Amazon publishes its list that way).
	 *
	 * @param string $body Response body.
	 * @return list<string>
	 */
	public static function parse( string $body ): array {
		$body = html_entity_decode( $body, ENT_QUOTES | ENT_HTML5 );
		if ( ! preg_match_all( '/"ipv[46]Prefix"\s*:\s*"([0-9a-fA-F:.\/]+)"/', $body, $matches ) ) {
			return array();
		}

		$prefixes = array();
		foreach ( $matches[1] as $prefix ) {
			$ip = explode( '/', $prefix, 2 )[0];
			if ( false !== filter_var( $ip, FILTER_VALIDATE_IP ) ) {
				$prefixes[ $prefix ] = true;
			}
		}
		return array_keys( $prefixes );
	}

	/**
	 * Whether an IP is inside a CIDR block (IPv4 or IPv6). A bare IP matches only itself.
	 *
	 * @param string $cidr Block, e.g. 20.125.66.80/28 or 2600:1f28::/56.
	 * @param string $ip   IP address.
	 */
	public static function cidr_contains( string $cidr, string $ip ): bool {
		$parts   = explode( '/', $cidr, 2 );
		$network = inet_pton( $parts[0] );
		$address = inet_pton( $ip );

		if ( false === $network || false === $address || strlen( $network ) !== strlen( $address ) ) {
			return false;
		}

		$max  = strlen( $network ) * 8;
		$bits = isset( $parts[1] ) && ctype_digit( $parts[1] ) ? (int) $parts[1] : $max;
		if ( $bits > $max ) {
			return false;
		}

		$bytes = intdiv( $bits, 8 );
		if ( 0 !== strncmp( $network, $address, $bytes ) ) {
			return false;
		}

		$rest = $bits % 8;
		if ( 0 === $rest ) {
			return true;
		}

		$mask = 0xFF << ( 8 - $rest ) & 0xFF;
		return ( ord( $network[ $bytes ] ) & $mask ) === ( ord( $address[ $bytes ] ) & $mask );
	}

	/**
	 * Stored lists.
	 *
	 * @return array<string, array{fetched: int, prefixes: list<string>}>
	 */
	private function load(): array {
		if ( null === $this->store ) {
			$value       = get_option( self::OPTION, array() );
			$this->store = is_array( $value ) ? $value : array();
		}
		return $this->store;
	}

	/**
	 * Downloads a URL.
	 *
	 * @param string $url URL.
	 */
	private function fetch( string $url ): ?string {
		if ( null !== $this->fetcher ) {
			return ( $this->fetcher )( $url );
		}

		$response = wp_remote_get(
			$url,
			array(
				'timeout'    => 10,
				'user-agent' => 'AI Hazir Site/' . ( defined( 'AIHS_VERSION' ) ? AIHS_VERSION : '0' ) . '; ' . home_url( '/' ),
			)
		);
		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			return null;
		}

		return wp_remote_retrieve_body( $response );
	}
}
