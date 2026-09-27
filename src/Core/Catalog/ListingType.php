<?php
/**
 * Listing types.
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\Core\Catalog;

/**
 * What the company sells (offer), looks for (demand) or can supply (supply).
 */
final class ListingType {

	public const OFFER  = 'offer';
	public const DEMAND = 'demand';
	public const SUPPLY = 'supply';

	public const ALL = array( self::OFFER, self::DEMAND, self::SUPPLY );

	/**
	 * Whether a value is a listing type.
	 *
	 * @param string $type Value.
	 */
	public static function is_valid( string $type ): bool {
		return in_array( $type, self::ALL, true );
	}
}
