<?php
/**
 * Search criteria for current listings.
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\Core\Catalog\Query;

use AIHazirSite\Core\Catalog\Listing;

/**
 * Shared by every channel (REST, abilities/MCP) so the same criteria select the same listings.
 * Text comparisons ignore case and treat the Turkish İ, I, ı and i alike.
 * - type: exact; category, region: exact; keyword: part of title, description, category,
 *   region or an attribute value; attributes: each given attribute equals.
 */
final class ListingSearch {

	public const MAX_PER_PAGE     = 50;
	public const DEFAULT_PER_PAGE = 20;

	/**
	 * Constructor.
	 *
	 * @param string                $type       '' or a listing type.
	 * @param string                $category   '' or a category.
	 * @param string                $region     '' or a region.
	 * @param string                $keyword    '' or a word or phrase.
	 * @param array<string, string> $attributes Attribute key → required value.
	 * @param int                   $page       Page, from 1.
	 * @param int                   $per_page   Items per page, 1…MAX_PER_PAGE.
	 */
	public function __construct(
		public readonly string $type = '',
		public readonly string $category = '',
		public readonly string $region = '',
		public readonly string $keyword = '',
		public readonly array $attributes = array(),
		public readonly int $page = 1,
		public readonly int $per_page = self::DEFAULT_PER_PAGE
	) {
	}

	/**
	 * Whether a listing meets the criteria.
	 *
	 * @param Listing $listing Listing.
	 */
	public function matches( Listing $listing ): bool {
		if ( '' !== $this->type && $this->type !== $listing->type ) {
			return false;
		}
		if ( '' !== $this->category && self::fold( $this->category ) !== self::fold( $listing->category ) ) {
			return false;
		}
		if ( '' !== $this->region && self::fold( $this->region ) !== self::fold( $listing->region ) ) {
			return false;
		}
		foreach ( $this->attributes as $key => $value ) {
			if ( ! isset( $listing->attributes[ $key ] ) || self::fold( $value ) !== self::fold( $listing->attributes[ $key ] ) ) {
				return false;
			}
		}
		if ( '' !== trim( $this->keyword ) ) {
			$haystack = self::fold( implode( ' ', array_merge( array( $listing->title, $listing->description, $listing->category, $listing->region ), array_values( $listing->attributes ) ) ) );
			return str_contains( $haystack, self::fold( $this->keyword ) );
		}
		return true;
	}

	/**
	 * Case-folded text for comparison (İ, I, ı and i are the same letter here).
	 *
	 * @param string $text Text.
	 */
	public static function fold( string $text ): string {
		return mb_strtolower( str_replace( array( 'İ', 'I', 'ı' ), 'i', trim( $text ) ) );
	}
}
