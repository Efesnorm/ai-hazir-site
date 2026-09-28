<?php
/**
 * Must-pass conditions of a match.
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\Core\Matching;

use AIHazirSite\Core\Catalog\Listing;
use AIHazirSite\Core\Catalog\ListingType;
use AIHazirSite\Core\Catalog\Query\ListingSearch;
use AIHazirSite\Core\Templates\Template;

/**
 * A candidate is left out when any condition the need states is not met: type (a need is met by an
 * offer or a supply), category, template, standard(s), region, lead time limit. A condition the
 * need does not state is not checked; region and lead time are checked only when both sides state them.
 */
final class HardFilter {

	/**
	 * Attribute holding standards (product template, list field).
	 */
	public const STANDARD = 'standart';

	/**
	 * Why a candidate is left out, or null when it passes.
	 *
	 * @param Listing $need      Demand listing.
	 * @param Listing $candidate Offer or supply listing.
	 */
	public static function reason( Listing $need, Listing $candidate ): ?string {
		if ( ! in_array( $candidate->type, array( ListingType::OFFER, ListingType::SUPPLY ), true ) ) {
			return 'Tür: aranan ilan yalnızca satılan veya tedarik edilebilen ilanla eşleşir.';
		}
		if ( '' !== $need->category && ListingSearch::fold( $need->category ) !== ListingSearch::fold( $candidate->category ) ) {
			return 'Kategori farklı.';
		}
		if ( Template::GENERAL !== $need->template && Template::GENERAL !== $candidate->template && $need->template !== $candidate->template ) {
			return 'Şablon farklı.';
		}
		$have    = self::values( $candidate->attributes[ self::STANDARD ] ?? '' );
		$missing = array_filter( self::values( $need->attributes[ self::STANDARD ] ?? '', false ), static fn( string $s ): bool => ! in_array( ListingSearch::fold( $s ), $have, true ) );
		if ( array() !== $missing ) {
			return 'Standart karşılanmıyor: ' . implode( ', ', $missing ) . '.';
		}
		if ( '' !== $need->region && '' !== $candidate->region && ListingSearch::fold( $need->region ) !== ListingSearch::fold( $candidate->region ) ) {
			return 'Bölge farklı.';
		}
		if ( null !== $need->lead_time_days && null !== $candidate->lead_time_days && $candidate->lead_time_days > $need->lead_time_days ) {
			return sprintf( 'Teslim süresi sınırı aşılıyor (%d gün > %d gün).', $candidate->lead_time_days, $need->lead_time_days );
		}
		return null;
	}

	/**
	 * Values of a list attribute ("IEC 60502-1, TS 212" → two values), folded for comparison.
	 *
	 * @param string $value Attribute value.
	 * @param bool   $fold  Fold case (for comparison); false keeps the original text.
	 * @return list<string>
	 */
	public static function values( string $value, bool $fold = true ): array {
		return array_values( array_filter( array_map( static fn( string $v ): string => $fold ? ListingSearch::fold( $v ) : trim( $v ), (array) preg_split( '/[,;\n]+/', $value ) ) ) );
	}
}
