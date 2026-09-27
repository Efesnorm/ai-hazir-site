<?php
/**
 * Listing storage port.
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\Core\Contracts;

use AIHazirSite\Core\Catalog\Listing;

/**
 * Stores listings. Write methods are called only by CatalogService
 * (guarded by CatalogWritesOnlyThroughServiceTest).
 */
interface ListingRepository {

	/**
	 * Listing by id, or null.
	 *
	 * @param int $id Id.
	 */
	public function find( int $id ): ?Listing;

	/**
	 * Listings of one type, most recently updated first.
	 *
	 * @param string $type ListingType::*.
	 * @param int    $limit Maximum number.
	 * @return list<Listing>
	 */
	public function all( string $type, int $limit = 200 ): array;

	/**
	 * Inserts (id null) or updates; returns the stored listing with id and update time.
	 *
	 * @param Listing $listing Valid listing.
	 */
	public function save_listing( Listing $listing ): Listing;

	/**
	 * Deletes a listing permanently.
	 *
	 * @param int $id Id.
	 */
	public function delete_listing( int $id ): bool;

	/**
	 * Ids of all listings of every type.
	 *
	 * @return list<int>
	 */
	public function ids(): array;
}
