<?php
/**
 * Portal business storage port.
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\Core\Contracts;

use AIHazirSite\Core\Portal\Business;

/**
 * Stores the businesses of a portal. Write methods are called only by PortalService
 * (guarded by PortalWritesOnlyThroughServiceTest).
 */
interface BusinessRepository {

	/**
	 * Every business, ordered by name.
	 *
	 * @return list<Business>
	 */
	public function businesses(): array;

	/**
	 * A business by id, or null.
	 *
	 * @param int $id Id.
	 */
	public function business( int $id ): ?Business;

	/**
	 * A business by slug, or null.
	 *
	 * @param string $slug Slug.
	 */
	public function business_by_slug( string $slug ): ?Business;

	/**
	 * Inserts (id null) or updates; returns the stored business with id and save time.
	 *
	 * @param Business $business Valid business.
	 */
	public function store_business( Business $business ): Business;

	/**
	 * Deletes a business.
	 *
	 * @param int $id Id.
	 */
	public function remove_business( int $id ): bool;
}
