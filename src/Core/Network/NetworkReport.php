<?php
/**
 * The mother's network report (1.24.0).
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\Core\Network;

use AIHazirSite\Core\Measurement\CsvExport;

/**
 * Turns every site's totals (NetworkStats) into the summary table (one row per site and a network total) and the
 * network referral matrix (row: the site that received visitors; column: the site that sent them).
 *
 * @phpstan-import-type Stats from NetworkStats
 * @phpstan-type Site array{url: string, name: string, status: string, fetched_at: int, stats: Stats|null}
 * @phpstan-type Row array{site: string, url: string, status: string, fetched_at: int, version: string, score: int|null, bots: int, verified: int, referrals: int, network: int, mcp: int, inquiries: int, listings: int}
 */
final class NetworkReport {

	public const OK           = 'ok';
	public const SELF         = 'self';
	public const NO_KEY       = 'no_key';
	public const UNAUTHORIZED = 'unauthorized';
	public const LIMITED      = 'limited';
	public const UNREACHABLE  = 'unreachable';

	/**
	 * One row per site, then the network total (site '').
	 *
	 * @param array<mixed> $sites Sites with their stats.
	 * @return list<array<string, mixed>>
	 *
	 * @phpstan-param list<Site> $sites
	 * @phpstan-return list<Row>
	 */
	public static function summary( array $sites ): array {
		$rows  = array();
		$total = array(
			'site'       => '',
			'url'        => '',
			'status'     => '',
			'fetched_at' => 0,
			'version'    => '',
			'score'      => null,
			'bots'       => 0,
			'verified'   => 0,
			'referrals'  => 0,
			'network'    => 0,
			'mcp'        => 0,
			'inquiries'  => 0,
			'listings'   => 0,
		);
		foreach ( $sites as $site ) {
			$stats = $site['stats'];
			$row   = array(
				'site'       => '' !== $site['name'] ? $site['name'] : NetworkLink::host( $site['url'] ),
				'url'        => $site['url'],
				'status'     => $site['status'],
				'fetched_at' => $site['fetched_at'],
				'version'    => $stats['version'] ?? '',
				'score'      => $stats['compliance']['score'] ?? null,
				'bots'       => $stats['bots']['total'] ?? 0,
				'verified'   => $stats['bots']['verified'] ?? 0,
				'referrals'  => $stats['referrals']['total'] ?? 0,
				'network'    => $stats['network']['total'] ?? 0,
				'mcp'        => $stats['mcp']['total'] ?? 0,
				'inquiries'  => $stats['inquiries']['total'] ?? 0,
				'listings'   => $stats['listings'] ?? 0,
			);
			foreach ( array( 'bots', 'verified', 'referrals', 'network', 'mcp', 'inquiries', 'listings' ) as $key ) {
				$total[ $key ] += $row[ $key ];
			}
			$rows[] = $row;
		}
		$rows[] = $total;
		return $rows;
	}

	/**
	 * Network referrals between the sites: [hosts in site order, receiver host → sender host → visits].
	 *
	 * @param array<mixed> $sites Sites with their stats.
	 * @return array{0: list<string>, 1: array<string, array<string, int>>}
	 *
	 * @phpstan-param list<Site> $sites
	 */
	public static function matrix( array $sites ): array {
		$hosts = array_values( array_unique( array_map( static fn( array $s ): string => NetworkLink::host( $s['url'] ), $sites ) ) );
		$cells = array();
		foreach ( $sites as $site ) {
			$to = NetworkLink::host( $site['url'] );
			foreach ( $hosts as $from ) {
				$cells[ $to ][ $from ] = $from === $to ? 0 : (int) ( $site['stats']['network']['by_sibling'][ $from ] ?? 0 );
			}
		}
		return array( $hosts, $cells );
	}

	/**
	 * The summary as CSV (status as given in $labels).
	 *
	 * @param array<mixed>          $sites   Sites with their stats.
	 * @param array<string, string> $headers Column key → header text (summary keys, in order).
	 * @param array<string, string> $labels  Status → text.
	 * @param string                $total   Text of the total row's site cell.
	 *
	 * @phpstan-param list<Site> $sites
	 */
	public static function csv( array $sites, array $headers, array $labels, string $total ): string {
		$lines = array();
		foreach ( self::summary( $sites ) as $row ) {
			$line = array();
			foreach ( array_keys( $headers ) as $key ) {
				$value  = $row[ $key ] ?? '';
				$line[] = match ( $key ) {
					'site'       => '' === $row['site'] ? $total : $row['site'],
					'status'     => $labels[ $row['status'] ] ?? $row['status'],
					'fetched_at' => 0 === $row['fetched_at'] ? '' : gmdate( 'Y-m-d H:i', $row['fetched_at'] ) . ' UTC',
					default      => (string) $value,
				};
			}
			$lines[] = $line;
		}
		return CsvExport::table( array_values( $headers ), $lines );
	}
}
