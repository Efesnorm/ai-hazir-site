<?php
/**
 * In-memory portal storage for tests.
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\Tests\Support;

use AIHazirSite\Core\Contracts\BusinessRepository;
use AIHazirSite\Core\Contracts\ListingBusinessRepository;
use AIHazirSite\Core\Portal\Business;

/**
 * Businesses and listing links in memory.
 */
final class MemoryBusinessRepository implements BusinessRepository, ListingBusinessRepository {

	/**
	 * Businesses by id.
	 *
	 * @var array<int, Business>
	 */
	public array $items = array();

	/**
	 * Listing id → business id.
	 *
	 * @var array<int, int>
	 */
	public array $links = array();

	/**
	 * Next id.
	 *
	 * @var int
	 */
	private int $next = 1;

	/**
	 * All, by name.
	 *
	 * @return list<Business>
	 */
	public function businesses(): array {
		$all = array_values( $this->items );
		usort( $all, static fn( Business $a, Business $b ): int => strcmp( $a->profile->name, $b->profile->name ) );
		return $all;
	}

	/**
	 * By id.
	 *
	 * @param int $id Id.
	 */
	public function business( int $id ): ?Business {
		return $this->items[ $id ] ?? null;
	}

	/**
	 * By slug.
	 *
	 * @param string $slug Slug.
	 */
	public function business_by_slug( string $slug ): ?Business {
		foreach ( $this->items as $business ) {
			if ( $slug === $business->slug ) {
				return $business;
			}
		}
		return null;
	}

	/**
	 * Stores.
	 *
	 * @param Business $business Business.
	 */
	public function store_business( Business $business ): Business {
		$id                 = $business->id ?? $this->next++;
		$stored             = new Business( $id, $business->slug, $business->profile, '2026-09-28T00:00:00Z' );
		$this->items[ $id ] = $stored;
		return $stored;
	}

	/**
	 * Removes.
	 *
	 * @param int $id Id.
	 */
	public function remove_business( int $id ): bool {
		$found = isset( $this->items[ $id ] );
		unset( $this->items[ $id ] );
		return $found;
	}

	/**
	 * Business of a listing.
	 *
	 * @param int $listing_id Listing id.
	 */
	public function business_of( int $listing_id ): ?int {
		return $this->links[ $listing_id ] ?? null;
	}

	/**
	 * Listings of a business.
	 *
	 * @param int $business_id Business id.
	 * @return list<int>
	 */
	public function listings_of( int $business_id ): array {
		return array_values( array_map( 'intval', array_keys( $this->links, $business_id, true ) ) );
	}

	/**
	 * Links.
	 *
	 * @param int      $listing_id  Listing id.
	 * @param int|null $business_id Business id.
	 */
	public function store_listing_business( int $listing_id, ?int $business_id ): void {
		if ( null === $business_id ) {
			unset( $this->links[ $listing_id ] );
			return;
		}
		$this->links[ $listing_id ] = $business_id;
	}
}
