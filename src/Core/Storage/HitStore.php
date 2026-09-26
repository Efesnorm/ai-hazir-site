<?php
/**
 * The only writer of the `aihs_hits` table.
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\Core\Storage;

/**
 * Aggregated hit counters. Stores no IP address, user agent or query string.
 */
final class HitStore {

	public const TABLE         = 'aihs_hits';
	public const KIND_BOT      = 'bot';
	public const KIND_REFERRAL = 'referral';
	public const PATH_MAX      = 191;

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
	 * Path without query string or fragment, starting with "/", at most 191 characters.
	 *
	 * @param string $path Raw path or request URI.
	 */
	public static function normalize_path( string $path ): string {
		$path = (string) preg_replace( '/[?#].*$/s', '', $path );
		$path = (string) preg_replace( '/[\x00-\x1F\x7F]/', '', $path );
		if ( '' === $path || '/' !== $path[0] ) {
			$path = '/' . $path;
		}
		return mb_substr( $path, 0, self::PATH_MAX );
	}
}
