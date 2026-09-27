<?php
/**
 * In-memory audit storage.
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\Tests\Support;

use AIHazirSite\Core\Contracts\AuditRepository;

/**
 * Array-backed AuditRepository.
 */
final class MemoryAuditRepository implements AuditRepository {

	/**
	 * Entries in order.
	 *
	 * @var list<array{at: string, channel: string, client_hash: string, action: string, outcome: string, inquiry_id: int|null, detail: string}>
	 */
	public array $entries = array();

	/**
	 * Appends.
	 *
	 * @param array{at: string, channel: string, client_hash: string, action: string, outcome: string, inquiry_id: int|null, detail: string} $entry Entry.
	 */
	public function append( array $entry ): void {
		$this->entries[] = $entry;
	}

	/**
	 * Latest first.
	 *
	 * @param int $limit Limit.
	 * @return list<array{at: string, channel: string, client_hash: string, action: string, outcome: string, inquiry_id: int|null, detail: string}>
	 */
	public function latest( int $limit = 50 ): array {
		return array_slice( array_reverse( $this->entries ), 0, $limit );
	}

	/**
	 * Deletes older ones.
	 *
	 * @param string $before ISO 8601.
	 */
	public function remove_before( string $before ): int {
		$kept          = array_values( array_filter( $this->entries, static fn( array $e ): bool => $e['at'] >= $before ) );
		$deleted       = count( $this->entries ) - count( $kept );
		$this->entries = $kept;
		return $deleted;
	}

	/**
	 * Outcomes in order.
	 *
	 * @return list<string>
	 */
	public function outcomes(): array {
		return array_column( $this->entries, 'outcome' );
	}
}
