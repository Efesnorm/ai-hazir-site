<?php
/**
 * Listings of one NACE Rev. 2.1 section.
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\Core\Catalog\Query;

use AIHazirSite\Core\Catalog\Listing;
use AIHazirSite\Core\Catalog\Nace;

/**
 * Sector filter (1.22.0). A listing's section is its business's section (portal mode); a listing
 * without a business takes the site profile's section. A listing whose section is unknown never
 * matches a sector search.
 */
final class SectorFilter {

	/**
	 * Listings in the given section.
	 *
	 * @param Listing[]          $listings  Listings.
	 * @param string             $sector    Section letter (already validated; '' = no filter).
	 * @param string             $site_nace Section of the site profile, or ''.
	 * @param array<int, string> $owned     Listing id → section of its business ('' when the business has none).
	 * @return list<Listing>
	 *
	 * @phpstan-param list<Listing> $listings
	 */
	public static function apply( array $listings, string $sector, string $site_nace, array $owned = array() ): array {
		$sector = Nace::section( $sector );
		if ( '' === $sector ) {
			return $listings;
		}
		return array_values(
			array_filter(
				$listings,
				static fn( Listing $l ): bool => in_array( null !== $l->id && array_key_exists( $l->id, $owned ) ? $owned[ $l->id ] : $site_nace, array( $sector ), true )
			)
		);
	}
}
