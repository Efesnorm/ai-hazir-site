<?php
/**
 * When a listing may be published.
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\Core\Catalog;

/**
 * The single rule every AI output uses (Schema.org, llms.txt, the catalog page), so
 * they always show the same listings: a listing without a validity date is valid for
 * DEFAULT_DAYS after its last update; an expired listing is published nowhere.
 */
final class ListingValidity {

	/**
	 * Days after the last update used when a listing has no validity date.
	 */
	public const DEFAULT_DAYS = 90;

	/**
	 * Effective last day (Y-m-d): the listing's date, else last update + DEFAULT_DAYS.
	 *
	 * @param Listing $listing Listing.
	 * @param string  $today   Y-m-d (used when the listing was never stored).
	 */
	public static function valid_until( Listing $listing, string $today ): string {
		if ( null !== $listing->valid_until ) {
			return $listing->valid_until;
		}
		$updated = substr( $listing->updated_at ?? $today, 0, 10 );
		return gmdate( 'Y-m-d', (int) strtotime( $updated . ' 00:00:00 UTC' ) + self::DEFAULT_DAYS * 86400 );
	}

	/**
	 * Whether the listing may be published today.
	 *
	 * @param Listing $listing Listing.
	 * @param string  $today   Y-m-d.
	 */
	public static function is_current( Listing $listing, string $today ): bool {
		return in_array( $listing->type, ListingType::ALL, true ) && self::valid_until( $listing, $today ) >= $today;
	}
}
