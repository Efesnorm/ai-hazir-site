<?php
/**
 * Filters and paging of GET /listings.
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\Adapters\Rest;

use AIHazirSite\Core\Catalog\Listing;
use AIHazirSite\Core\Catalog\ListingType;
use AIHazirSite\Core\Catalog\Query\ListingSearch;

/**
 * Filters: type (offer | demand | supply), category and region (exact, case-insensitive,
 * Turkish dotted/dotless i treated alike),
 * page (1…), per_page (1…50, default 20). Invalid input is reported, never guessed.
 * The selection itself is the core's ListingSearch, shared with the abilities / MCP channel.
 */
final class ListingsQuery {

	public const MAX_PER_PAGE     = ListingSearch::MAX_PER_PAGE;
	public const DEFAULT_PER_PAGE = ListingSearch::DEFAULT_PER_PAGE;

	/**
	 * Constructor.
	 *
	 * @param string $type     '' or a listing type.
	 * @param string $category '' or a category.
	 * @param string $region   '' or a region.
	 * @param int    $page     Page, from 1.
	 * @param int    $per_page Items per page, 1…MAX_PER_PAGE.
	 */
	public function __construct(
		public readonly string $type = '',
		public readonly string $category = '',
		public readonly string $region = '',
		public readonly int $page = 1,
		public readonly int $per_page = self::DEFAULT_PER_PAGE
	) {
	}

	/**
	 * From request parameters: [query, errors]; the query is null when there are errors.
	 *
	 * @param array<string, mixed> $params Parameters.
	 * @return array{0: self|null, 1: array<string, string>}
	 */
	public static function from_params( array $params ): array {
		$text   = static fn( string $k ): string => isset( $params[ $k ] ) && is_scalar( $params[ $k ] ) ? trim( (string) $params[ $k ] ) : '';
		$errors = array();

		$type = $text( 'type' );
		if ( '' !== $type && ! ListingType::is_valid( $type ) ) {
			$errors['type'] = 'type şunlardan biri olmalı: ' . implode( ', ', ListingType::ALL ) . '.';
		}
		$page = '' === $text( 'page' ) ? '1' : $text( 'page' );
		if ( ! ctype_digit( $page ) || (int) $page < 1 ) {
			$errors['page'] = 'page 1 veya daha büyük bir tam sayı olmalı.';
		}
		$per_page = '' === $text( 'per_page' ) ? (string) self::DEFAULT_PER_PAGE : $text( 'per_page' );
		if ( ! ctype_digit( $per_page ) || (int) $per_page < 1 || (int) $per_page > self::MAX_PER_PAGE ) {
			$errors['per_page'] = sprintf( 'per_page 1 ile %d arasında bir tam sayı olmalı.', self::MAX_PER_PAGE );
		}

		if ( array() !== $errors ) {
			return array( null, $errors );
		}
		return array( new self( $type, $text( 'category' ), $text( 'region' ), (int) $page, (int) $per_page ), array() );
	}

	/**
	 * Whether a listing passes the filters.
	 *
	 * @param Listing $listing Listing.
	 */
	public function matches( Listing $listing ): bool {
		return $this->search()->matches( $listing );
	}

	/**
	 * The core search criteria.
	 */
	public function search(): ListingSearch {
		return new ListingSearch( $this->type, $this->category, $this->region, '', array(), $this->page, $this->per_page );
	}
}
