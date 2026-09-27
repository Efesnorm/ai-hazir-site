<?php
/**
 * Audit log storage port.
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\Core\Contracts;

/**
 * Append-only record of write attempts. No content, no contact data, no raw IP.
 *
 * @phpstan-type AuditEntry array{at: string, channel: string, client_hash: string, action: string, outcome: string, inquiry_id: int|null, detail: string}
 */
interface AuditRepository {

	/**
	 * Appends an entry.
	 *
	 * @param array{at: string, channel: string, client_hash: string, action: string, outcome: string, inquiry_id: int|null, detail: string} $entry Entry.
	 */
	public function append( array $entry ): void;

	/**
	 * Latest entries, newest first.
	 *
	 * @param int $limit Limit.
	 * @return list<array{at: string, channel: string, client_hash: string, action: string, outcome: string, inquiry_id: int|null, detail: string}>
	 */
	public function latest( int $limit = 50 ): array;

	/**
	 * Deletes entries before a time.
	 *
	 * @param string $before ISO 8601 UTC.
	 * @return int Deleted.
	 */
	public function remove_before( string $before ): int;
}
