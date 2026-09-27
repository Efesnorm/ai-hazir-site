<?php
/**
 * In-memory ListingRepository for tests.
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\Tests\Support;

use AIHazirSite\Core\Catalog\Listing;
use AIHazirSite\Core\Contracts\ListingRepository;

/**
 * Array-backed listings with incrementing ids.
 */
final class MemoryListingRepository implements ListingRepository {

	/**
	 * Listings by id.
	 *
	 * @var array<int, Listing>
	 */
	public array $listings = array();

	/**
	 * Next id.
	 *
	 * @var int
	 */
	private int $next = 1;

	/**
	 * Listing by id.
	 *
	 * @param int $id Id.
	 */
	public function find( int $id ): ?Listing {
		return $this->listings[ $id ] ?? null;
	}

	/**
	 * Listings of a type.
	 *
	 * @param string $type  Type.
	 * @param int    $limit Limit.
	 * @return list<Listing>
	 */
	public function all( string $type, int $limit = 200 ): array {
		$list = array_values( array_filter( $this->listings, static fn( Listing $l ): bool => $l->type === $type ) );
		return array_slice( array_reverse( $list ), 0, $limit );
	}

	/**
	 * Stores.
	 *
	 * @param Listing $listing Listing.
	 */
	public function store_listing( Listing $listing ): Listing {
		$id                    = $listing->id ?? $this->next++;
		$this->listings[ $id ] = $listing->stored( $id, '2026-09-27T12:00:00Z' );
		return $this->listings[ $id ];
	}

	/**
	 * Deletes.
	 *
	 * @param int $id Id.
	 */
	public function remove_listing( int $id ): bool {
		$found = isset( $this->listings[ $id ] );
		unset( $this->listings[ $id ] );
		return $found;
	}

	/**
	 * All ids.
	 *
	 * @return list<int>
	 */
	public function ids(): array {
		return array_keys( $this->listings );
	}
}
