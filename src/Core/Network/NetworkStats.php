<?php
/**
 * A site's totals for the network report (1.24.0).
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\Core\Network;

use AIHazirSite\Core\Contracts\HitRepository;
use AIHazirSite\Core\Contracts\InquiryRepository;
use AIHazirSite\Core\Measurement\Hit;

/**
 * Totals only: no measurement rows, page paths, inquiry texts or contact data ever leave the site. A member builds it
 * for GET /aihs/v1/network/stats; the mother reads it with from_array(), which treats the answer as untrusted data.
 *
 * @phpstan-type Stats array{version: string, days: int, since: string, features: list<string>, bots: array{total: int, verified: int, by_bot: array<string, int>}, referrals: array{total: int, by_source: array<string, int>}, network: array{total: int, by_sibling: array<string, int>}, mcp: array{total: int}, inquiries: array{total: int, by_kind: array<string, int>}, listings: int, compliance: array{score: int, scanned_at: string}|null}
 */
final class NetworkStats {

	public const PERIODS  = array( 7, 28, 90 );
	public const KEY      = '/^[a-z0-9._-]{1,64}$/';
	public const MAX_KEYS = 100;

	/**
	 * Period from input: one of PERIODS, else 28.
	 *
	 * @param mixed $days Input.
	 */
	public static function period( mixed $days ): int {
		$days = is_numeric( $days ) ? (int) $days : 0;
		return in_array( $days, self::PERIODS, true ) ? $days : 28;
	}

	/**
	 * First day of a period ending today (Y-m-d).
	 *
	 * @param string $today Y-m-d.
	 * @param int    $days  Days including today.
	 */
	public static function since( string $today, int $days ): string {
		return gmdate( 'Y-m-d', (int) strtotime( $today . ' 00:00:00 UTC' ) - ( max( 1, $days ) - 1 ) * 86400 );
	}

	/**
	 * This site's totals.
	 *
	 * @param HitRepository          $hits       Measurement counters.
	 * @param InquiryRepository|null $inquiries  Inquiries (null when the inquiry box was never set up).
	 * @param string                 $today      Y-m-d.
	 * @param int                    $days       Period (one of PERIODS).
	 * @param string                 $version    Plugin version.
	 * @param string[]               $features   Enabled feature keys.
	 * @param int                    $listings   Current listings.
	 * @param int|null               $score      Latest compliance score, or null.
	 * @param string                 $scanned_at Its scan time.
	 * @return array<string, mixed>
	 *
	 * @phpstan-param list<string> $features
	 * @phpstan-return Stats
	 */
	public static function build( HitRepository $hits, ?InquiryRepository $inquiries, string $today, int $days, string $version, array $features, int $listings, ?int $score, string $scanned_at ): array {
		$days  = self::period( $days );
		$since = self::since( $today, $days );
		$by    = static function ( string $kind ) use ( $hits, $since ): array {
			$map = array();
			foreach ( $hits->totals( $kind, $since, HitRepository::GROUP_SOURCE ) as $row ) {
				$map[ $row['key'] ] = $row['total'];
			}
			return $map;
		};

		$verified = 0;
		foreach ( $hits->totals( Hit::KIND_BOT, $since, HitRepository::GROUP_SOURCE ) as $row ) {
			$verified += $row['verified'];
		}
		$bots    = $by( Hit::KIND_BOT );
		$refs    = $by( Hit::KIND_REFERRAL );
		$network = $by( Hit::KIND_NETWORK );
		$kinds   = null === $inquiries ? array() : $inquiries->count_by_kind( $since . 'T00:00:00Z' );

		return array(
			'version'    => $version,
			'days'       => $days,
			'since'      => $since,
			'features'   => $features,
			'bots'       => array(
				'total'    => array_sum( $bots ),
				'verified' => $verified,
				'by_bot'   => $bots,
			),
			'referrals'  => array(
				'total'     => array_sum( $refs ),
				'by_source' => $refs,
			),
			'network'    => array(
				'total'      => array_sum( $network ),
				'by_sibling' => $network,
			),
			'mcp'        => array( 'total' => array_sum( $by( Hit::KIND_MCP ) ) ),
			'inquiries'  => array(
				'total'   => array_sum( $kinds ),
				'by_kind' => $kinds,
			),
			'listings'   => $listings,
			'compliance' => null === $score ? null : array(
				'score'      => $score,
				'scanned_at' => $scanned_at,
			),
		);
	}

	/**
	 * Stats from another site's answer, cleaned (counts are non-negative integers, keys are short ids), or null.
	 *
	 * @param mixed $data Decoded answer.
	 * @return array<string, mixed>|null
	 *
	 * @phpstan-return Stats|null
	 */
	public static function from_array( mixed $data ): ?array {
		if ( ! is_array( $data ) || ! isset( $data['days'], $data['bots'] ) ) {
			return null;
		}
		$int   = static fn( mixed $v ): int => is_numeric( $v ) ? max( 0, (int) $v ) : 0;
		$map   = static function ( mixed $values ) use ( $int ): array {
			$map = array();
			foreach ( is_array( $values ) ? $values : array() as $key => $value ) {
				$key = strtolower( (string) $key );
				if ( count( $map ) < self::MAX_KEYS && 1 === preg_match( self::KEY, $key ) ) {
					$map[ $key ] = $int( $value );
				}
			}
			return $map;
		};
		$part  = static fn( string $k ): array => is_array( $data[ $k ] ?? null ) ? $data[ $k ] : array();
		$score = $part( 'compliance' );

		return array(
			'version'    => NetworkCheck::text( $data['version'] ?? '', 20 ),
			'days'       => self::period( $data['days'] ),
			'since'      => 1 === preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) ( $data['since'] ?? '' ) ) ? (string) $data['since'] : '',
			'features'   => array_values( array_filter( array_map( static fn( $f ): string => is_string( $f ) ? strtolower( $f ) : '', array_slice( is_array( $data['features'] ?? null ) ? $data['features'] : array(), 0, self::MAX_KEYS ) ), static fn( string $f ): bool => 1 === preg_match( self::KEY, $f ) ) ),
			'bots'       => array(
				'total'    => $int( $part( 'bots' )['total'] ?? 0 ),
				'verified' => $int( $part( 'bots' )['verified'] ?? 0 ),
				'by_bot'   => $map( $part( 'bots' )['by_bot'] ?? array() ),
			),
			'referrals'  => array(
				'total'     => $int( $part( 'referrals' )['total'] ?? 0 ),
				'by_source' => $map( $part( 'referrals' )['by_source'] ?? array() ),
			),
			'network'    => array(
				'total'      => $int( $part( 'network' )['total'] ?? 0 ),
				'by_sibling' => $map( $part( 'network' )['by_sibling'] ?? array() ),
			),
			'mcp'        => array( 'total' => $int( $part( 'mcp' )['total'] ?? 0 ) ),
			'inquiries'  => array(
				'total'   => $int( $part( 'inquiries' )['total'] ?? 0 ),
				'by_kind' => $map( $part( 'inquiries' )['by_kind'] ?? array() ),
			),
			'listings'   => $int( $data['listings'] ?? 0 ),
			'compliance' => isset( $score['score'] ) && is_numeric( $score['score'] ) ? array(
				'score'      => min( 100, $int( $score['score'] ) ),
				'scanned_at' => NetworkCheck::text( $score['scanned_at'] ?? '', 40 ),
			) : null,
		);
	}
}
