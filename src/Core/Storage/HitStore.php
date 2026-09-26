<?php
/**
 * The only writer of the `aihs_hits` table.
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\Core\Storage;

use AIHazirSite\Core\Contracts\HitRepository;
use AIHazirSite\Core\Measurement\Hit;

/**
 * Aggregated hit counters. Stores no IP address, user agent or query string.
 */
final class HitStore implements HitRepository {

	public const TABLE         = 'aihs_hits';
	public const KIND_BOT      = Hit::KIND_BOT;
	public const KIND_REFERRAL = Hit::KIND_REFERRAL;
	public const PATH_MAX      = Hit::PATH_MAX;

	/**
	 * Full table name with the site prefix.
	 */
	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . self::TABLE;
	}

	/**
	 * Adds one hit with a single query.
	 *
	 * @param string $day       Date in Y-m-d.
	 * @param string $kind      self::KIND_BOT or self::KIND_REFERRAL.
	 * @param string $source_id Bot or referrer id.
	 * @param string $path      Request path without query string.
	 * @param bool   $verified  Whether the bot's identity was verified.
	 * @return bool True when the row was written.
	 */
	public function increment( string $day, string $kind, string $source_id, string $path, bool $verified ): bool {
		global $wpdb;

		$result = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom counter table; single upsert.
			$wpdb->prepare(
				'INSERT INTO %i (day, kind, source_id, path, verified, hits) VALUES (%s, %s, %s, %s, %d, 1) ON DUPLICATE KEY UPDATE hits = hits + 1',
				self::table(),
				$day,
				$kind,
				substr( $source_id, 0, 64 ),
				self::normalize_path( $path ),
				$verified ? 1 : 0
			)
		);

		return false !== $result;
	}

	/**
	 * Deletes rows older than a day.
	 *
	 * @param string $before_day Date in Y-m-d; rows with an earlier day are deleted.
	 * @return int Deleted rows.
	 */
	public function prune( string $before_day ): int {
		global $wpdb;

		$result = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Retention cleanup.
			$wpdb->prepare( 'DELETE FROM %i WHERE day < %s', self::table(), $before_day )
		);

		return false === $result ? 0 : (int) $result;
	}

	/**
	 * Sums per source or per path since a day, largest total first (ties by key).
	 *
	 * @param string $kind     Hit kind.
	 * @param string $since    First day included (Y-m-d).
	 * @param string $group_by self::GROUP_SOURCE or self::GROUP_PATH.
	 * @param int    $limit    Maximum rows; 0 = no limit.
	 * @return array[]
	 *
	 * @phpstan-return list<array{key: string, verified: int, unverified: int, total: int}>
	 */
	public function totals( string $kind, string $since, string $group_by, int $limit = 0 ): array {
		global $wpdb;

		$column = self::GROUP_PATH === $group_by ? 'path' : 'source_id';
		$sql    = 'SELECT %i AS `key`, SUM(CASE WHEN verified = 1 THEN hits ELSE 0 END) AS verified, SUM(CASE WHEN verified = 1 THEN 0 ELSE hits END) AS unverified, SUM(hits) AS total FROM %i WHERE kind = %s AND day >= %s GROUP BY %i ORDER BY total DESC, %i ASC';
		$args   = array( $column, self::table(), $kind, $since, $column, $column );
		if ( $limit > 0 ) {
			$sql   .= ' LIMIT %d';
			$args[] = $limit;
		}

		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $args ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- $sql is built from constants only.

		$totals = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$totals[] = array(
				'key'        => (string) $row['key'],
				'verified'   => (int) $row['verified'],
				'unverified' => (int) $row['unverified'],
				'total'      => (int) $row['total'],
			);
		}
		return $totals;
	}

	/**
	 * Path normalization (see {@see Hit::normalize_path()}).
	 *
	 * @param string $path Raw path or request URI.
	 */
	public static function normalize_path( string $path ): string {
		return Hit::normalize_path( $path );
	}
}
