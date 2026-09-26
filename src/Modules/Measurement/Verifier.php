<?php
/**
 * Bot identity verification.
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\Modules\Measurement;

use Throwable;

/**
 * Checks a claimed bot against its operator's published IP list or reverse DNS.
 * Any failure (missing list, DNS error, exception) means "not verified"; it never
 * breaks the request.
 */
final class Verifier {

	/**
	 * Reverse DNS results are cached this long, keyed by a salted hash of the IP.
	 */
	public const RDNS_TTL = DAY_IN_SECONDS;

	/**
	 * Reverse lookup: fn( string $ip ): string|false.
	 *
	 * @var callable(string): (string|false)
	 */
	private $reverse;

	/**
	 * Forward lookup: fn( string $host ): list<string> IPs.
	 *
	 * @var callable(string): list<string>
	 */
	private $forward;

	/**
	 * Constructor.
	 *
	 * @param IpRanges      $ranges  Published IP lists.
	 * @param callable|null $reverse fn( string $ip ): string|false; defaults to gethostbyaddr().
	 * @param callable|null $forward fn( string $host ): list<string>; defaults to A/AAAA lookup.
	 *
	 * @phpstan-param (callable(string): (string|false))|null $reverse
	 * @phpstan-param (callable(string): list<string>)|null $forward
	 */
	public function __construct(
		private readonly IpRanges $ranges,
		?callable $reverse = null,
		?callable $forward = null
	) {
		$this->reverse = $reverse ?? static fn( string $ip ): string|false => gethostbyaddr( $ip );
		$this->forward = $forward ?? array( self::class, 'resolve' );
	}

	/**
	 * Tracker callback.
	 *
	 * @param Bot    $bot Claimed bot.
	 * @param string $ip  Client IP.
	 */
	public function __invoke( Bot $bot, string $ip ): bool {
		return $this->verify( $bot, $ip );
	}

	/**
	 * Whether the request really comes from the claimed bot.
	 *
	 * @param Bot    $bot Claimed bot.
	 * @param string $ip  Client IP.
	 */
	public function verify( Bot $bot, string $ip ): bool {
		if ( false === filter_var( $ip, FILTER_VALIDATE_IP ) ) {
			return false;
		}

		try {
			return match ( $bot->verify ) {
				'ip_ranges' => $this->ranges->contains( $bot->verify_source, $ip ),
				'rdns'      => $this->rdns( $bot->verify_source, $ip ),
				default     => false,
			};
		} catch ( Throwable ) {
			return false;
		}
	}

	/**
	 * Forward-confirmed reverse DNS, cached for 24 hours under a salted hash of the IP.
	 *
	 * @param string $suffixes Comma-separated allowed host suffixes.
	 * @param string $ip       Client IP.
	 */
	private function rdns( string $suffixes, string $ip ): bool {
		$key    = 'aihs_rdns_' . substr( hash_hmac( 'sha256', $ip . '|' . $suffixes, wp_salt( 'auth' ) ), 0, 32 );
		$cached = get_transient( $key );
		if ( '1' === $cached || '0' === $cached ) {
			return '1' === $cached;
		}

		$verified = $this->lookup( $suffixes, $ip );
		set_transient( $key, $verified ? '1' : '0', self::RDNS_TTL );

		return $verified;
	}

	/**
	 * Uncached forward-confirmed reverse DNS check.
	 *
	 * @param string $suffixes Comma-separated allowed host suffixes.
	 * @param string $ip       Client IP.
	 */
	private function lookup( string $suffixes, string $ip ): bool {
		$host = ( $this->reverse )( $ip );
		if ( ! is_string( $host ) || '' === $host || $host === $ip ) {
			return false;
		}

		$host    = strtolower( rtrim( $host, '.' ) );
		$allowed = false;
		foreach ( explode( ',', strtolower( $suffixes ) ) as $suffix ) {
			$suffix = trim( $suffix, " .\t" );
			if ( '' !== $suffix && ( $host === $suffix || str_ends_with( $host, '.' . $suffix ) ) ) {
				$allowed = true;
				break;
			}
		}

		return $allowed && in_array( inet_pton( $ip ), array_map( 'inet_pton', ( $this->forward )( $host ) ), true );
	}

	/**
	 * A and AAAA records of a host.
	 *
	 * @param string $host Host name.
	 * @return list<string>
	 */
	public static function resolve( string $host ): array {
		$records = dns_get_record( $host, DNS_A | DNS_AAAA );
		$ips     = array();
		foreach ( is_array( $records ) ? $records : array() as $record ) {
			if ( isset( $record['ip'] ) ) {
				$ips[] = (string) $record['ip'];
			} elseif ( isset( $record['ipv6'] ) ) {
				$ips[] = (string) $record['ipv6'];
			}
		}
		return $ips;
	}
}
