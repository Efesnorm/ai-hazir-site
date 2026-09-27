<?php
/**
 * Audit log storage in `{prefix}aihs_audit_log`.
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\WordPress\Inquiry;

use AIHazirSite\Core\Contracts\AuditRepository;

/**
 * Append-only; entries are deleted only by the retention purge.
 */
final class WpAuditRepository implements AuditRepository {

	/**
	 * Table name.
	 */
	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'aihs_audit_log';
	}

	/**
	 * Appends.
	 *
	 * @param array{at: string, channel: string, client_hash: string, action: string, outcome: string, inquiry_id: int|null, detail: string} $entry Entry.
	 */
	public function append( array $entry ): void {
		global $wpdb;
		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Own table.
			self::table(),
			array(
				'at'          => gmdate( 'Y-m-d H:i:s', (int) strtotime( $entry['at'] ) ),
				'channel'     => $entry['channel'],
				'client_hash' => $entry['client_hash'],
				'action'      => $entry['action'],
				'outcome'     => $entry['outcome'],
				'inquiry_id'  => $entry['inquiry_id'],
				'detail'      => $entry['detail'],
			),
			array( '%s', '%s', '%s', '%s', '%s', '%d', '%s' )
		);
	}

	/**
	 * Latest first.
	 *
	 * @param int $limit Limit.
	 * @return list<array{at: string, channel: string, client_hash: string, action: string, outcome: string, inquiry_id: int|null, detail: string}>
	 */
	public function latest( int $limit = 50 ): array {
		global $wpdb;
		$rows    = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i ORDER BY id DESC LIMIT %d', self::table(), max( 1, $limit ) ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$entries = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$entries[] = array(
				'at'          => gmdate( 'Y-m-d\TH:i:s\Z', (int) strtotime( (string) $row['at'] . ' UTC' ) ),
				'channel'     => (string) $row['channel'],
				'client_hash' => (string) $row['client_hash'],
				'action'      => (string) $row['action'],
				'outcome'     => (string) $row['outcome'],
				'inquiry_id'  => null === $row['inquiry_id'] ? null : (int) $row['inquiry_id'],
				'detail'      => (string) $row['detail'],
			);
		}
		return $entries;
	}

	/**
	 * Deletes older ones.
	 *
	 * @param string $before ISO 8601 UTC.
	 */
	public function remove_before( string $before ): int {
		global $wpdb;
		return (int) $wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE at < %s', self::table(), gmdate( 'Y-m-d H:i:s', (int) strtotime( $before ) ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}
}
