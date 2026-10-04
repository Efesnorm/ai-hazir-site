<?php
/**
 * Sibling portal catalogs and suggestions (1.22.0).
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\Core\Network;

use AIHazirSite\Core\Catalog\ListingType;
use AIHazirSite\Core\Catalog\Nace;
use AIHazirSite\Core\Catalog\Query\ListingSearch;

/**
 * A compact copy of a verified sibling's public catalog (only the fields a suggestion needs) and the choice of
 * suggestions for a search that found nothing here. Everything a sibling sends is data, never instructions: texts are
 * stripped of markup and control characters and cut short; links must stay on the sibling's own host.
 *
 * @phpstan-type Entry array{title: string, type: string, category: string, region: string, url: string, business: string, nace: string, updated_at: string}
 * @phpstan-type Catalog array{site: string, name: string, country: string, retrieved_at: int, items: list<Entry>}
 * @phpstan-type Suggestion array{site: string, site_name: string, country: string, business: string|null, title: string, type: string, category: string, region: string, url: string, nace: string|null, retrieved_at: string}
 * @phpstan-type Found array{site: string, site_name: string, country: string, business: string|null, title: string, type: string, category: string, region: string, url: string, nace: string|null, retrieved_at: string, updated_at: string}
 */
final class SiblingCatalog {

	public const MAX_ITEMS   = 500;
	public const MAX_AGE     = 7200;
	public const LIMIT       = 3;
	public const BLOCK_MAX   = 12;
	public const PER_PAGE    = 50;
	public const MAX_PAGES   = 10;
	public const TITLE_MAX   = 200;
	public const TEXT_MAX    = 100;
	public const URL_MAX     = 500;
	public const UPDATED_MAX = 40;

	/**
	 * Compact catalog from a sibling's public answers.
	 *
	 * @param array{url: string, name: string, country: string} $site       The sibling (from the verified network).
	 * @param array<mixed>|null                                 $profile    GET /profile body, or null.
	 * @param array<mixed>                                      $listings   Items of GET /listings (all fetched pages).
	 * @param array<mixed>                                      $businesses Items of GET /businesses (empty when not a portal).
	 * @param int                                               $now        Unix time of retrieval.
	 * @return array<string, mixed>
	 *
	 * @phpstan-return Catalog
	 */
	public static function compact( array $site, ?array $profile, array $listings, array $businesses, int $now ): array {
		$site_nace = Nace::section( $profile['nace'] ?? '' );
		$by_slug   = array();
		foreach ( $businesses as $business ) {
			if ( is_array( $business ) && is_string( $business['slug'] ?? null ) ) {
				$by_slug[ $business['slug'] ] = Nace::section( $business['nace'] ?? '' );
			}
		}
		$host  = self::host( $site['url'] );
		$items = array();
		foreach ( $listings as $listing ) {
			if ( count( $items ) >= self::MAX_ITEMS ) {
				break;
			}
			if ( ! is_array( $listing ) ) {
				continue;
			}
			$type  = is_string( $listing['type'] ?? null ) ? $listing['type'] : '';
			$title = NetworkCheck::text( $listing['title'] ?? '', self::TITLE_MAX );
			$url   = is_string( $listing['url'] ?? null ) ? trim( $listing['url'] ) : '';
			if ( ! ListingType::is_valid( $type ) || '' === $title || '' === $host || strlen( $url ) > self::URL_MAX || self::host( $url ) !== $host ) {
				continue;
			}
			$owner   = is_array( $listing['business'] ?? null ) ? $listing['business'] : null;
			$slug    = is_string( $owner['slug'] ?? null ) ? $owner['slug'] : null;
			$items[] = array(
				'title'      => $title,
				'type'       => $type,
				'category'   => NetworkCheck::text( $listing['category'] ?? '', self::TEXT_MAX ),
				'region'     => NetworkCheck::text( $listing['region'] ?? '', self::TEXT_MAX ),
				'url'        => $url,
				'business'   => null === $owner ? '' : NetworkCheck::text( $owner['name'] ?? '', self::TITLE_MAX ),
				'nace'       => null === $slug ? $site_nace : ( $by_slug[ $slug ] ?? '' ),
				'updated_at' => NetworkCheck::text( $listing['updated_at'] ?? '', self::UPDATED_MAX ),
			);
		}
		return array(
			'site'         => $site['url'],
			'name'         => NetworkCheck::text( $site['name'], 100 ),
			'country'      => NetworkCheck::country( $site['country'] ),
			'retrieved_at' => $now,
			'items'        => $items,
		);
	}

	/**
	 * Stored catalog, or null when it is not one (a damaged or foreign value).
	 *
	 * @param mixed $data Stored value.
	 * @return array<string, mixed>|null
	 *
	 * @phpstan-return Catalog|null
	 */
	public static function from_array( mixed $data ): ?array {
		if ( ! is_array( $data ) || ! is_string( $data['site'] ?? null ) || ! is_int( $data['retrieved_at'] ?? null ) || ! is_array( $data['items'] ?? null ) ) {
			return null;
		}
		$items = array();
		foreach ( $data['items'] as $item ) {
			if ( is_array( $item ) ) {
				$items[] = array(
					'title'      => (string) ( $item['title'] ?? '' ),
					'type'       => (string) ( $item['type'] ?? '' ),
					'category'   => (string) ( $item['category'] ?? '' ),
					'region'     => (string) ( $item['region'] ?? '' ),
					'url'        => (string) ( $item['url'] ?? '' ),
					'business'   => (string) ( $item['business'] ?? '' ),
					'nace'       => (string) ( $item['nace'] ?? '' ),
					'updated_at' => (string) ( $item['updated_at'] ?? '' ),
				);
			}
		}
		return array(
			'site'         => $data['site'],
			'name'         => (string) ( $data['name'] ?? '' ),
			'country'      => (string) ( $data['country'] ?? '' ),
			'retrieved_at' => $data['retrieved_at'],
			'items'        => $items,
		);
	}

	/**
	 * Up to LIMIT sibling listings matching the search (type, category, region, keyword, sector), newest first.
	 * Catalogs older than MAX_AGE are skipped. A search on template attributes gets none (siblings' attributes
	 * are not copied).
	 *
	 * @param array<mixed>  $catalogs Catalogs (compact()).
	 * @param ListingSearch $search   Search.
	 * @param string        $sector   NACE section letter or ''.
	 * @param int           $now      Unix time.
	 * @return list<array<string, mixed>>
	 *
	 * @phpstan-param list<Catalog> $catalogs
	 * @phpstan-return list<Suggestion>
	 */
	public static function suggest( array $catalogs, ListingSearch $search, string $sector, int $now ): array {
		$found = self::matching( $catalogs, $search, $sector, $now );
		usort( $found, static fn( array $a, array $b ): int => strcmp( $b['updated_at'], $a['updated_at'] ) );
		return self::without_updated( array_slice( $found, 0, self::LIMIT ) );
	}

	/**
	 * Listings for the "Komşu ülkelerde" block (1.23.0): the same matching as suggest(), optionally one sibling only;
	 * newest first within each sibling and taken from the siblings in turn, so one portal does not fill the block.
	 * Siblings are ordered by their newest matching listing.
	 *
	 * @param array<mixed>  $catalogs Catalogs (compact()).
	 * @param ListingSearch $search   Search (type, category, region, keyword).
	 * @param string        $sector   NACE section letter or ''.
	 * @param string        $site     Only this sibling (its URL), or ''.
	 * @param int           $count    Number of listings (1…BLOCK_MAX).
	 * @param int           $now      Unix time.
	 * @return list<array<string, mixed>>
	 *
	 * @phpstan-param list<Catalog> $catalogs
	 * @phpstan-return list<Suggestion>
	 */
	public static function pick( array $catalogs, ListingSearch $search, string $sector, string $site, int $count, int $now ): array {
		$count  = max( 1, min( self::BLOCK_MAX, $count ) );
		$groups = array();
		foreach ( self::matching( $catalogs, $search, $sector, $now ) as $item ) {
			if ( '' === $site || $site === $item['site'] ) {
				$groups[ $item['site'] ][] = $item;
			}
		}
		foreach ( $groups as $key => $items ) {
			usort( $items, static fn( array $a, array $b ): int => strcmp( $b['updated_at'], $a['updated_at'] ) );
			$groups[ $key ] = $items;
		}
		uasort( $groups, static fn( array $a, array $b ): int => strcmp( $b[0]['updated_at'], $a[0]['updated_at'] ) );
		$rounds = array() === $groups ? 0 : max( array_map( 'count', $groups ) );
		$picked = array();
		for ( $round = 0; $round < $rounds; $round++ ) {
			foreach ( $groups as $items ) {
				if ( isset( $items[ $round ] ) ) {
					$picked[] = $items[ $round ];
				}
			}
		}
		return self::without_updated( array_slice( $picked, 0, $count ) );
	}

	/**
	 * Every listing of the fresh catalogs that matches, as suggestions with their update time.
	 *
	 * @param array<mixed>  $catalogs Catalogs.
	 * @param ListingSearch $search   Search.
	 * @param string        $sector   NACE section letter or ''.
	 * @param int           $now      Unix time.
	 * @return list<array<string, mixed>>
	 *
	 * @phpstan-param list<Catalog> $catalogs
	 * @phpstan-return list<Found>
	 */
	private static function matching( array $catalogs, ListingSearch $search, string $sector, int $now ): array {
		if ( array() !== $search->attributes ) {
			return array();
		}
		$fold    = array( ListingSearch::class, 'fold' );
		$keyword = $fold( $search->keyword );
		$found   = array();
		foreach ( $catalogs as $catalog ) {
			if ( $now - $catalog['retrieved_at'] > self::MAX_AGE ) {
				continue;
			}
			foreach ( $catalog['items'] as $item ) {
				if ( ( '' !== $search->type && $search->type !== $item['type'] )
					|| ( '' !== $search->category && $fold( $search->category ) !== $fold( $item['category'] ) )
					|| ( '' !== $search->region && $fold( $search->region ) !== $fold( $item['region'] ) )
					|| ( '' !== $sector && $sector !== $item['nace'] )
					|| ( '' !== $keyword && ! str_contains( $fold( implode( ' ', array( $item['title'], $item['category'], $item['region'], $item['business'] ) ) ), $keyword ) ) ) {
					continue;
				}
				$found[] = array(
					'site'         => $catalog['site'],
					'site_name'    => $catalog['name'],
					'country'      => $catalog['country'],
					'business'     => '' === $item['business'] ? null : $item['business'],
					'title'        => $item['title'],
					'type'         => $item['type'],
					'category'     => $item['category'],
					'region'       => $item['region'],
					'url'          => $item['url'],
					'nace'         => '' === $item['nace'] ? null : $item['nace'],
					'retrieved_at' => gmdate( 'Y-m-d\TH:i:s\Z', $catalog['retrieved_at'] ),
					'updated_at'   => $item['updated_at'],
				);
			}
		}
		return $found;
	}

	/**
	 * Drops the internal update time.
	 *
	 * @param list<array<string, mixed>> $items Items.
	 * @return list<array<string, mixed>>
	 *
	 * @phpstan-param list<Found> $items
	 * @phpstan-return list<Suggestion>
	 */
	private static function without_updated( array $items ): array {
		return array_map(
			static function ( array $s ): array {
				unset( $s['updated_at'] );
				return $s;
			},
			$items
		);
	}

	/**
	 * Lower-case host of an https URL, or ''.
	 *
	 * @param string $url URL.
	 */
	private static function host( string $url ): string {
		$parts = parse_url( $url ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- Platform-neutral core (no WordPress functions).
		return is_array( $parts ) && 'https' === ( $parts['scheme'] ?? '' ) && is_string( $parts['host'] ?? null ) ? strtolower( $parts['host'] ) : '';
	}
}
