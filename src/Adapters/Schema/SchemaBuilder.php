<?php
/**
 * Builds Schema.org JSON-LD from catalog data.
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\Adapters\Schema;

use AIHazirSite\Core\Catalog\CompanyProfile;
use AIHazirSite\Core\Catalog\Listing;

/**
 * Platform-neutral producer. Reads core catalog objects only; field placement comes from SchemaMap.
 *
 * - Home page: Organization + WebPage (dateModified lives on WebPage, a CreativeWork).
 * - AI catalog page: DataFeed whose DataFeedItems carry dateModified and wrap a Product (with Offer)
 *   or a Demand. Expired listings are left out.
 */
final class SchemaBuilder {

	public const CONTEXT = 'https://schema.org';

	/**
	 * Constructor.
	 *
	 * @param string $site_url Home URL with trailing slash (used for @id values).
	 * @param bool   $link_organization False when another plugin owns the Organization node:
	 *                                  references then carry the name instead of our @id.
	 */
	public function __construct(
		private readonly string $site_url,
		private readonly bool $link_organization = true
	) {
	}

	/**
	 * The @id of our Organization node.
	 */
	public function organization_id(): string {
		return $this->site_url . '#organization';
	}

	/**
	 * Home page graph: Organization and WebPage.
	 *
	 * @param CompanyProfile $profile       Profile.
	 * @param string         $date_modified ISO 8601 of the latest catalog/profile change.
	 * @return array<string, mixed>
	 */
	public function home( CompanyProfile $profile, string $date_modified ): array {
		$organization = array_filter(
			array(
				'@type'         => 'Organization',
				'@id'           => $this->organization_id(),
				'name'          => $profile->name,
				'url'           => $this->site_url,
				'email'         => $profile->contact_email,
				'telephone'     => $profile->contact_phone,
				'knowsAbout'    => $profile->sector,
				'knowsLanguage' => $profile->languages,
				'address'       => '' === $profile->country ? null : array(
					'@type'          => 'PostalAddress',
					'addressCountry' => $profile->country,
				),
			),
			array( self::class, 'present' )
		);

		return array(
			'@context' => self::CONTEXT,
			'@graph'   => array(
				$organization,
				array(
					'@type'        => 'WebPage',
					'@id'          => $this->site_url . '#webpage',
					'url'          => $this->site_url,
					'name'         => $profile->name,
					'about'        => array( '@id' => $this->organization_id() ),
					'dateModified' => $date_modified,
				),
			),
		);
	}

	/**
	 * AI catalog page: a DataFeed of the valid listings.
	 *
	 * @param CompanyProfile $profile     Profile.
	 * @param Listing[]      $listings    Listings of any type.
	 * @param string         $today       Y-m-d.
	 * @param string         $catalog_url Catalog page URL.
	 * @return array<string, mixed>
	 */
	public function catalog( CompanyProfile $profile, array $listings, string $today, string $catalog_url ): array {
		$entries  = array();
		$modified = array();
		foreach ( $listings as $listing ) {
			$entry = $this->entry( $listing, $today, $profile );
			if ( null !== $entry ) {
				$entries[]  = $entry;
				$modified[] = (string) $entry['dateModified'];
			}
		}
		rsort( $modified );

		return array(
			'@context'        => self::CONTEXT,
			'@type'           => 'DataFeed',
			'@id'             => $catalog_url . '#feed',
			'name'            => 'AI Katalog – ' . $profile->name,
			'description'     => $profile->name . ' firmasının sattığı, aradığı ve tedarik edebildiği ürün ve hizmetler.',
			'url'             => $catalog_url,
			'dateModified'    => $modified[0] ?? $today,
			'publisher'       => $this->organization_reference( $profile ),
			'dataFeedElement' => $entries,
		);
	}

	/**
	 * DataFeedItem for one listing, or null when expired (including the 90-day default validity).
	 *
	 * @param Listing             $listing Listing.
	 * @param string              $today   Y-m-d.
	 * @param CompanyProfile|null $profile Profile (named seller when another plugin owns Organization).
	 * @return array<string, mixed>|null
	 */
	public function entry( Listing $listing, string $today, ?CompanyProfile $profile = null ): ?array {
		$updated     = $listing->updated_at ?? $today . 'T00:00:00Z';
		$valid_until = $listing->valid_until ?? gmdate( 'Y-m-d', (int) strtotime( substr( $updated, 0, 10 ) . ' 00:00:00 UTC' ) + SchemaMap::DEFAULT_VALIDITY_DAYS * 86400 );
		if ( $valid_until < $today || ! isset( SchemaMap::DEAL_TYPE[ $listing->type ] ) ) {
			return null;
		}

		$deal_type             = SchemaMap::DEAL_TYPE[ $listing->type ];
		$nodes                 = array(
			'item'  => array( '@type' => 'Product' ),
			'deal'  => array( '@type' => $deal_type ),
			'entry' => array( '@type' => 'DataFeedItem' ),
		);
		$values                = $listing->to_array();
		$values['valid_until'] = $valid_until;
		$values['updated_at']  = $updated;

		foreach ( SchemaMap::FIELDS as $field => [ $node, $property, $transform ] ) {
			$value = $this->transform( $transform, $values[ $field ], $listing );
			if ( null === $value || str_starts_with( $property, '@price' ) ) {
				continue;
			}
			$property                    = '@quantity' === $property ? SchemaMap::QUANTITY_PROPERTY[ $deal_type ] : $property;
			$nodes[ $node ][ $property ] = $value;
		}

		$nodes['deal'] = array_merge( $nodes['deal'], $this->price( $listing, $deal_type, $valid_until ) );

		$availability = SchemaMap::AVAILABILITY[ $listing->type ] ?? null;
		if ( 'Offer' === $deal_type && null === $availability && null !== $listing->quantity ) {
			$availability = (float) $listing->quantity > 0 ? 'https://schema.org/InStock' : 'https://schema.org/OutOfStock';
		}
		if ( null !== $availability ) {
			$nodes['deal']['availability'] = $availability;
		}

		if ( 'Offer' === $deal_type ) {
			$nodes['deal']['seller'] = $this->organization_reference( $profile );
			$nodes['item']['offers'] = $nodes['deal'];
			$item                    = $nodes['item'];
		} else {
			$nodes['deal']['itemOffered'] = $nodes['item'];
			$item                         = $nodes['deal'];
		}

		return array_merge( $nodes['entry'], array( 'item' => $item ) );
	}

	/**
	 * Price properties of the deal node.
	 *
	 * @param Listing $listing     Listing.
	 * @param string  $deal_type   Offer or Demand.
	 * @param string  $valid_until Y-m-d.
	 * @return array<string, mixed>
	 */
	private function price( Listing $listing, string $deal_type, string $valid_until ): array {
		$min = $listing->price_min;
		$max = $listing->price_max;
		if ( null === $min && null === $max ) {
			return array();
		}

		$specification = array_filter(
			array(
				'@type'         => 'PriceSpecification',
				'minPrice'      => null === $min ? null : self::number( $min ),
				'maxPrice'      => null === $max ? null : self::number( $max ),
				'priceCurrency' => $listing->currency,
			),
			array( self::class, 'present' )
		);

		if ( 'Demand' === $deal_type ) {
			return array( 'priceSpecification' => $specification );
		}

		$price = array(
			'price'           => $min ?? $max,
			'priceCurrency'   => $listing->currency,
			'priceValidUntil' => $valid_until,
		);
		if ( null !== $min && null !== $max && $min !== $max ) {
			$price['priceSpecification'] = $specification;
		}
		return $price;
	}

	/**
	 * Applies a SchemaMap transform.
	 *
	 * @param string  $transform Transform name.
	 * @param mixed   $value     Field value.
	 * @param Listing $listing   Listing (for units).
	 */
	private function transform( string $transform, mixed $value, Listing $listing ): mixed {
		if ( null === $value || '' === $value || array() === $value ) {
			return null;
		}
		return match ( $transform ) {
			'text', 'date', 'price' => $value,
			'quantity'              => array_filter(
				array(
					'@type'    => 'QuantitativeValue',
					'value'    => self::number( (string) $value ),
					'unitText' => $listing->unit,
				),
				array( self::class, 'present' )
			),
			'days'                  => array(
				'@type'    => 'QuantitativeValue',
				'value'    => (int) $value,
				'unitCode' => 'DAY',
			),
			'properties'            => array_map(
				static fn( string $name, string $v ): array => array(
					'@type' => 'PropertyValue',
					'name'  => $name,
					'value' => $v,
				),
				array_keys( (array) $value ),
				array_values( (array) $value )
			),
			default                 => null,
		};
	}

	/**
	 * Reference to the company: our @id, or a named node when another plugin owns Organization.
	 *
	 * @param CompanyProfile|null $profile Profile (for the name).
	 * @return array<string, string>
	 */
	private function organization_reference( ?CompanyProfile $profile ): array {
		if ( $this->link_organization || null === $profile ) {
			return array( '@id' => $this->organization_id() );
		}
		return array(
			'@type' => 'Organization',
			'name'  => $profile->name,
			'url'   => $this->site_url,
		);
	}

	/**
	 * Decimal string as a JSON number.
	 *
	 * @param string $decimal Decimal string.
	 */
	private static function number( string $decimal ): int|float {
		return str_contains( $decimal, '.' ) ? (float) $decimal : (int) $decimal;
	}

	/**
	 * Whether a value should be kept in the output.
	 *
	 * @param mixed $value Value.
	 */
	private static function present( mixed $value ): bool {
		return null !== $value && '' !== $value && array() !== $value;
	}
}
