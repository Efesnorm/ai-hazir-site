<?php
/**
 * Matching engine (A11).
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\WordPress\Matching;

use AIHazirSite\Core\Catalog\Listing;
use AIHazirSite\Core\Catalog\ListingType;
use AIHazirSite\Core\Catalog\ListingValidity;
use AIHazirSite\Core\Contracts\HttpClient;
use AIHazirSite\Core\Features;
use AIHazirSite\Core\Matching\Candidate;
use AIHazirSite\Core\Matching\Matcher;
use AIHazirSite\Core\Matching\MatchWeights;
use AIHazirSite\Core\Matching\PartnerListings;
use AIHazirSite\WordPress\Catalog\CatalogReader;
use AIHazirSite\WordPress\Module;
use AIHazirSite\WordPress\Platform\WpClock;
use AIHazirSite\WordPress\Platform\WpHttpClient;
use AIHazirSite\WordPress\Schema\SchemaModule;

/**
 * While `matching` (and the catalog) is on: the Eşleşmeler screen. Candidates are this site's
 * current offer/supply listings plus the listings of partner sites entered by hand (https, read
 * over their A5 REST API, cached for an hour). Matches are suggestions; nothing is sent.
 */
final class MatchingModule implements Module {

	public const OPTION      = 'aihs_matching';
	public const CACHE       = 'aihs_partner_';
	public const CACHE_HOURS = 1;

	/**
	 * Registers hooks.
	 */
	public function register(): void {
		if ( Features::is_enabled( Features::MATCHING ) && Features::is_enabled( Features::CATALOG ) && is_admin() ) {
			( new MatchingAdmin() )->register();
		}
	}

	/**
	 * Nothing scheduled.
	 */
	public function deactivate(): void {
	}

	/**
	 * Settings: weights and partner REST bases.
	 *
	 * @return array{weights: array<string, float>, partners: list<string>}
	 */
	public static function settings(): array {
		$stored   = get_option( self::OPTION, array() );
		$stored   = is_array( $stored ) ? $stored : array();
		$weights  = is_array( $stored['weights'] ?? null ) ? $stored['weights'] : array();
		$partners = array_values( array_filter( is_array( $stored['partners'] ?? null ) ? $stored['partners'] : array(), static fn( $p ): bool => is_string( $p ) && str_starts_with( $p, 'https://' ) ) );
		return array(
			'weights'  => ( new MatchWeights( $weights ) )->weights,
			'partners' => $partners,
		);
	}

	/**
	 * Current listings of a type on this site.
	 *
	 * @param string $type ListingType::*.
	 * @return list<Listing>
	 */
	public static function current( string $type ): array {
		$today = ( new WpClock() )->today();
		return array_values( array_filter( SchemaModule::listings(), static fn( Listing $l ): bool => $type === $l->type && ListingValidity::is_current( $l, $today ) ) );
	}

	/**
	 * HTTP client that keeps partner answers for an hour (also failures, so a closed partner is not
	 * asked on every page view).
	 */
	public static function http(): HttpClient {
		return new class() implements HttpClient {
			/**
			 * Cached GET.
			 *
			 * @param string $url URL.
			 */
			public function get( string $url ): ?string {
				$key    = MatchingModule::CACHE . md5( $url );
				$cached = get_transient( $key );
				if ( is_array( $cached ) ) {
					return is_string( $cached['body'] ?? null ) ? $cached['body'] : null;
				}
				$body = ( new WpHttpClient() )->get( $url );
				set_transient( $key, array( 'body' => $body ), MatchingModule::CACHE_HOURS * HOUR_IN_SECONDS );
				return $body;
			}
		};
	}

	/**
	 * Matches for one of this site's needs.
	 *
	 * @param Listing $need Demand listing.
	 * @return array{matches: list<array{candidate: Candidate, score: float, breakdown: list<array{criterion: string, weight: float, value: float, points: float}>}>, excluded: array<string, string>, unreachable: list<string>}
	 */
	public static function matches( Listing $need ): array {
		$catalog    = SchemaModule::catalog_url();
		$candidates = array();
		foreach ( array( ListingType::OFFER, ListingType::SUPPLY ) as $type ) {
			foreach ( self::current( $type ) as $listing ) {
				$candidates[] = new Candidate( $listing, '', $catalog . '#ilan-' . (int) $listing->id );
			}
		}
		$unreachable = array();
		$reader      = new PartnerListings( self::http() );
		foreach ( self::settings()['partners'] as $partner ) {
			$found = $reader->candidates( $partner );
			if ( null === $found ) {
				$unreachable[] = $partner;
				continue;
			}
			array_push( $candidates, ...$found );
		}
		$result = ( new Matcher( new MatchWeights( self::settings()['weights'] ) ) )->match( $need, $candidates );
		return array_merge( $result, array( 'unreachable' => $unreachable ) );
	}

	/**
	 * A need of this site by id (current demand listings only), or null.
	 *
	 * @param int $id Listing id.
	 */
	public static function need( int $id ): ?Listing {
		$listing = CatalogReader::query()->find( $id );
		return null !== $listing && ListingType::DEMAND === $listing->type ? $listing : null;
	}
}
