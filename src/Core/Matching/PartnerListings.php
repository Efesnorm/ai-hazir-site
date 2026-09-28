<?php
/**
 * Listings of an authorised partner site.
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\Core\Matching;

use AIHazirSite\Core\Catalog\Listing;
use AIHazirSite\Core\Catalog\ListingType;
use AIHazirSite\Core\Contracts\HttpClient;

/**
 * Reads a partner's A5 REST catalog (GET {base}listings?type=…&per_page=50). The answer is
 * untrusted: only known fields are taken, as strings/numbers; anything else is ignored. A partner
 * that cannot be reached gives no candidates (local matching goes on).
 */
final class PartnerListings {

	/**
	 * Constructor.
	 *
	 * @param HttpClient $http Outgoing GET.
	 */
	public function __construct( private readonly HttpClient $http ) {
	}

	/**
	 * Candidates of a partner, or null when it could not be read.
	 *
	 * @param string $base Partner REST base, e.g. https://fabrika.example/wp-json/aihs/v1/ (https only).
	 * @return list<Candidate>|null
	 */
	public function candidates( string $base ): ?array {
		if ( ! str_starts_with( $base, 'https://' ) ) {
			return null;
		}
		$base       = rtrim( $base, '/' ) . '/';
		$candidates = array();
		$reached    = false;
		foreach ( array( ListingType::OFFER, ListingType::SUPPLY ) as $type ) {
			$body = $this->http->get( $base . 'listings?type=' . $type . '&per_page=50' );
			$data = null === $body ? null : json_decode( $body, true );
			if ( ! is_array( $data ) || ! is_array( $data['items'] ?? null ) ) {
				continue;
			}
			$reached = true;
			foreach ( $data['items'] as $item ) {
				$candidate = self::candidate( $item, $base );
				if ( null !== $candidate ) {
					$candidates[] = $candidate;
				}
			}
		}
		return $reached ? $candidates : null;
	}

	/**
	 * One REST item as a candidate, or null when it is not a usable listing.
	 *
	 * @param mixed  $item REST item.
	 * @param string $base Partner base.
	 */
	public static function candidate( mixed $item, string $base ): ?Candidate {
		if ( ! is_array( $item ) || ! is_numeric( $item['id'] ?? null ) || ! is_string( $item['title'] ?? null ) || ! ListingType::is_valid( is_string( $item['type'] ?? null ) ? $item['type'] : '' ) ) {
			return null;
		}
		$text       = static fn( mixed $v ): string => is_scalar( $v ) ? (string) $v : '';
		$decimal    = static fn( mixed $v ): ?string => is_scalar( $v ) && is_numeric( (string) $v ) ? (string) $v : null;
		$attributes = array();
		foreach ( is_array( $item['attributes'] ?? null ) ? $item['attributes'] : array() as $attribute ) {
			if ( is_array( $attribute ) && is_string( $attribute['name'] ?? null ) && is_scalar( $attribute['value'] ?? null ) ) {
				$attributes[ $attribute['name'] ] = (string) $attribute['value'];
			}
		}
		$quantity = is_array( $item['quantity'] ?? null ) ? $item['quantity'] : array();
		$price    = is_array( $item['price'] ?? null ) ? $item['price'] : array();
		$listing  = new Listing(
			(int) $item['id'],
			(string) $item['type'],
			$item['title'],
			$text( $item['description'] ?? '' ),
			$text( $item['category'] ?? '' ),
			$decimal( $quantity['value'] ?? null ),
			$text( $quantity['unit'] ?? '' ),
			$decimal( $price['min'] ?? null ),
			$decimal( $price['max'] ?? null ),
			$text( $price['currency'] ?? '' ),
			$text( $item['region'] ?? '' ),
			is_int( $item['lead_time_days'] ?? null ) ? $item['lead_time_days'] : null,
			is_string( $item['valid_until'] ?? null ) ? $item['valid_until'] : null,
			is_string( $item['updated_at'] ?? null ) ? $item['updated_at'] : null,
			$attributes,
			is_string( $item['template'] ?? null ) && '' !== $item['template'] ? $item['template'] : 'general'
		);
		$url      = $text( $item['url'] ?? '' );
		return new Candidate( $listing, $base, str_starts_with( $url, 'https://' ) ? $url : '' );
	}
}
