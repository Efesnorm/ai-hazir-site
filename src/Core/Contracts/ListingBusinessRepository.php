<?php
/**
 * Listing → business link storage port.
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\Core\Contracts;

/**
 * Which business a listing belongs to (none = the portal itself). The write method is called
 * only by PortalService (guarded by PortalWritesOnlyThroughServiceTest).
 */
interface ListingBusinessRepository {

	/**
	 * Business id of a listing, or null when it belongs to the portal.
	 *
	 * @param int $listing_id Listing id.
	 */
	public function business_of( int $listing_id ): ?int;

	/**
	 * Listing ids of a business.
	 *
	 * @param int $business_id Business id.
	 * @return list<int>
	 */
	public function listings_of( int $business_id ): array;

	/**
	 * Links a listing to a business (null = back to the portal).
	 *
	 * @param int      $listing_id  Listing id.
	 * @param int|null $business_id Business id or null.
	 */
	public function store_listing_business( int $listing_id, ?int $business_id ): void;
}
