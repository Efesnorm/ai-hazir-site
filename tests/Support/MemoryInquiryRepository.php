<?php
/**
 * In-memory inquiry storage.
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\Tests\Support;

use AIHazirSite\Core\Contracts\InquiryRepository;
use AIHazirSite\Core\Inquiry\Inquiry;

/**
 * Array-backed InquiryRepository.
 */
final class MemoryInquiryRepository implements InquiryRepository {

	/**
	 * Inquiries by id.
	 *
	 * @var array<int, Inquiry>
	 */
	public array $items = array();

	/**
	 * Next id.
	 *
	 * @var int
	 */
	private int $next = 1;

	/**
	 * Stores.
	 *
	 * @param Inquiry $inquiry Inquiry.
	 */
	public function store_inquiry( Inquiry $inquiry ): Inquiry {
		$stored                           = $inquiry->with_id( $this->next++ );
		$this->items[ (int) $stored->id ] = $stored;
		return $stored;
	}

	/**
	 * Finds.
	 *
	 * @param int $id Id.
	 */
	public function find( int $id ): ?Inquiry {
		return $this->items[ $id ] ?? null;
	}

	/**
	 * Lists newest first.
	 *
	 * @param string $status Status.
	 * @param string $source Source.
	 * @param int    $limit  Limit.
	 * @param int    $offset Offset.
	 * @return list<Inquiry>
	 */
	public function list_inquiries( string $status = '', string $source = '', int $limit = 50, int $offset = 0 ): array {
		$items = array_values( array_filter( $this->items, static fn( Inquiry $i ): bool => ( '' === $status || $status === $i->status ) && ( '' === $source || $source === $i->source ) ) );
		usort( $items, static fn( Inquiry $a, Inquiry $b ): int => array( $b->created_at, $b->id ) <=> array( $a->created_at, $a->id ) );
		return array_slice( $items, $offset, $limit );
	}

	/**
	 * Changes status.
	 *
	 * @param int    $id     Id.
	 * @param string $status Status.
	 */
	public function change_status( int $id, string $status ): bool {
		if ( ! isset( $this->items[ $id ] ) ) {
			return false;
		}
		$this->items[ $id ] = $this->items[ $id ]->with_status( $status );
		return true;
	}

	/**
	 * Deletes.
	 *
	 * @param int $id Id.
	 */
	public function remove_inquiry( int $id ): bool {
		$found = isset( $this->items[ $id ] );
		unset( $this->items[ $id ] );
		return $found;
	}

	/**
	 * Deletes older ones.
	 *
	 * @param string $before ISO 8601.
	 */
	public function remove_before( string $before ): int {
		$old = array_filter( $this->items, static fn( Inquiry $i ): bool => $i->created_at < $before );
		foreach ( array_keys( $old ) as $id ) {
			unset( $this->items[ $id ] );
		}
		return count( $old );
	}

	/**
	 * Recent ones of a client.
	 *
	 * @param string $client_hash Client.
	 * @param string $since       ISO 8601.
	 */
	public function count_recent( string $client_hash, string $since ): int {
		return count( array_filter( $this->items, static fn( Inquiry $i ): bool => $client_hash === $i->client_hash && $i->created_at >= $since ) );
	}
}
