<?php
/**
 * Sibling portal catalogs – WordPress side (1.22.0).
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\WordPress\Network;

use AIHazirSite\Core\Catalog\Query\ListingSearch;
use AIHazirSite\Core\Features;
use AIHazirSite\Core\Network\SiblingCatalog;

/**
 * While `network_suggestions` is on: after the hourly network check, each verified sibling's public /profile,
 * /listings (paged, at most SiblingCatalog::MAX_ITEMS) and /businesses are read and kept as a compact transient per
 * sibling (at most MAX_BYTES). A 429 is respected (Retry-After); an unreachable sibling's old copy is dropped.
 * Searches that found nothing here (or ask with network=true) get up to three suggestions from these copies.
 *
 * @phpstan-import-type Catalog from SiblingCatalog
 */
final class NetworkCatalog {

	public const PREFIX    = 'aihs_nc_';
	public const RETRY     = 'aihs_nc_retry_';
	public const TTL       = 10800;
	public const MAX_BYTES = 1048576;

	/**
	 * Whether suggestions are on.
	 */
	public static function enabled(): bool {
		return Features::is_enabled( Features::PORTAL_NETWORK ) && Features::is_enabled( Features::NETWORK_SUGGESTIONS );
	}

	/**
	 * Whether sibling catalogs are read: for suggestions or for the "Komşu ülkelerde" block (1.23.0).
	 */
	public static function collecting(): bool {
		return Features::is_enabled( Features::PORTAL_NETWORK ) && ( Features::is_enabled( Features::NETWORK_SUGGESTIONS ) || Features::is_enabled( Features::NETWORK_BLOCK ) || Features::is_enabled( Features::NETWORK_SHARE ) );
	}

	/**
	 * Copies of the verified siblings' catalogs (freshness is checked by SiblingCatalog).
	 *
	 * @return list<array<string, mixed>>
	 *
	 * @phpstan-return list<Catalog>
	 */
	public static function catalogs(): array {
		$catalogs = array();
		foreach ( NetworkModule::view()->siblings() as $site ) {
			$catalog = SiblingCatalog::from_array( get_transient( self::PREFIX . md5( $site['url'] ) ) );
			if ( null !== $catalog && $catalog['site'] === $site['url'] ) {
				$catalogs[] = $catalog;
			}
		}
		return $catalogs;
	}

	/**
	 * Re-reads every verified sibling (hooked after NetworkModule::check()).
	 */
	public static function refresh(): void {
		if ( ! self::collecting() ) {
			return;
		}
		foreach ( NetworkModule::view()->siblings() as $site ) {
			$key = md5( $site['url'] );
			if ( false !== get_transient( self::RETRY . $key ) ) {
				continue;
			}
			$catalog = self::read( $site );
			if ( is_int( $catalog ) ) {
				set_transient( self::RETRY . $key, 1, $catalog );
			} elseif ( null === $catalog ) {
				delete_transient( self::PREFIX . $key );
			} else {
				set_transient( self::PREFIX . $key, $catalog, self::TTL );
			}
		}
	}

	/**
	 * Suggestions for a search (only verified siblings' fresh copies).
	 *
	 * @param ListingSearch $search Search.
	 * @param string        $sector NACE section letter or ''.
	 * @return list<array<string, mixed>>
	 */
	public static function suggestions( ListingSearch $search, string $sector ): array {
		return SiblingCatalog::suggest( self::catalogs(), $search, $sector, time() );
	}

	/**
	 * Adds `network_suggestions` to a listings body when the search found nothing here or the caller asked.
	 *
	 * @param array<string, mixed> $body    Listings body.
	 * @param ListingSearch        $search  Search.
	 * @param string               $sector  NACE section letter or ''.
	 * @param bool                 $network Whether the caller asked for suggestions anyway (network=true).
	 * @return array<string, mixed>
	 */
	public static function decorate( array $body, ListingSearch $search, string $sector, bool $network ): array {
		if ( self::enabled() && ( $network || 0 === (int) ( $body['total'] ?? 0 ) ) ) {
			$body['network_suggestions'] = self::suggestions( $search, $sector );
		}
		return $body;
	}

	/**
	 * One sibling's compact catalog; an int is the seconds to wait after a 429, null means unreachable.
	 *
	 * @param array{url: string, name: string, country: string} $site Sibling.
	 * @return array<string, mixed>|int|null
	 */
	private static function read( array $site ): array|int|null {
		$api                 = $site['url'] . 'wp-json/aihs/v1/';
		[ $profile, $retry ] = NetworkModule::fetch_json( $api . 'profile' );
		if ( null !== $retry ) {
			return $retry;
		}
		if ( null === $profile ) {
			return null;
		}
		$items = array();
		for ( $page = 1; $page <= SiblingCatalog::MAX_PAGES; $page++ ) {
			[ $body, $retry ] = NetworkModule::fetch_json( $api . 'listings?per_page=' . SiblingCatalog::PER_PAGE . '&page=' . $page );
			if ( null !== $retry ) {
				return $retry;
			}
			if ( null === $body || ! is_array( $body['items'] ?? null ) ) {
				return null;
			}
			$items = array_merge( $items, array_values( $body['items'] ) );
			if ( $page >= (int) ( $body['total_pages'] ?? 0 ) || count( $items ) >= SiblingCatalog::MAX_ITEMS ) {
				break;
			}
		}
		// Not a portal (404) or no businesses: listings take the site profile's section.
		[ $businesses ] = NetworkModule::fetch_json( $api . 'businesses' );
		$catalog        = SiblingCatalog::compact( $site, $profile, $items, is_array( $businesses['items'] ?? null ) ? $businesses['items'] : array(), time() );
		$bytes          = strlen( (string) wp_json_encode( $catalog ) );
		while ( array() !== $catalog['items'] && $bytes > self::MAX_BYTES ) {
			$catalog['items'] = array_slice( $catalog['items'], 0, intdiv( count( $catalog['items'] ), 2 ) );
			$bytes            = strlen( (string) wp_json_encode( $catalog ) );
		}
		return $catalog;
	}
}
