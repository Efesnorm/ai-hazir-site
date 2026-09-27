<?php
/**
 * The single read path for current listings.
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\Core\Catalog\Query;

use AIHazirSite\Core\Catalog\Listing;
use AIHazirSite\Core\Catalog\ListingType;
use AIHazirSite\Core\Catalog\ListingValidity;
use AIHazirSite\Core\Contracts\Clock;
use AIHazirSite\Core\Contracts\ListingRepository;

/**
 * REST (A5) and abilities/MCP (A6) read listings only through here: current listings
 * (ListingValidity), filtered by a ListingSearch, newest first (then higher id), paged.
 */
final class CatalogQuery {

	/**
	 * Constructor.
	 *
	 * @param ListingRepository $listings Storage.
	 * @param Clock             $clock    Today.
	 */
	public function __construct(
		private readonly ListingRepository $listings,
		private readonly Clock $clock
	) {
	}

	/**
	 * One page of current listings meeting the criteria.
	 *
	 * @param ListingSearch $search Criteria.
	 * @return array{items: list<Listing>, total: int, updated_at: string|null}
	 */
	public function search( ListingSearch $search ): array {
		return self::select( $this->all(), $search, $this->clock->today() );
	}

	/**
	 * A current listing by id, or null (missing or expired).
	 *
	 * @param int $id Id.
	 */
	public function find( int $id ): ?Listing {
		$listing = $this->listings->find( $id );
		return null !== $listing && ListingValidity::is_current( $listing, $this->clock->today() ) ? $listing : null;
	}

	/**
	 * Every stored listing (all types).
	 *
	 * @return list<Listing>
	 */
	public function all(): array {
		$all = array();
		foreach ( ListingType::ALL as $type ) {
			array_push( $all, ...$this->listings->all( $type ) );
		}
		return $all;
	}

	/**
	 * The selection rule itself (pure): current, matching, ordered, paged.
	 *
	 * @param Listing[]     $listings Listings.
	 * @param ListingSearch $search   Criteria.
	 * @param string        $today    Y-m-d.
	 * @return array{items: list<Listing>, total: int, updated_at: string|null}
	 *
	 * @phpstan-param list<Listing> $listings
	 */
	public static function select( array $listings, ListingSearch $search, string $today ): array {
		$current = array_values( array_filter( $listings, static fn( Listing $l ): bool => ListingValidity::is_current( $l, $today ) ) );
		$matches = array_values( array_filter( $current, array( $search, 'matches' ) ) );
		usort( $matches, static fn( Listing $a, Listing $b ): int => array( (string) $b->updated_at, (int) $b->id ) <=> array( (string) $a->updated_at, (int) $a->id ) );

		$dates = array_filter( array_map( static fn( Listing $l ): ?string => $l->updated_at, $current ) );
		rsort( $dates );

		return array(
			'items'      => array_slice( $matches, ( max( 1, $search->page ) - 1 ) * max( 1, $search->per_page ), max( 1, $search->per_page ) ),
			'total'      => count( $matches ),
			'updated_at' => $dates[0] ?? null,
		);
	}
}
