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
use AIHazirSite\Core\Catalog\ListingValidity;
use AIHazirSite\Core\Templates\Freshness;
use AIHazirSite\Core\Templates\Template;
use AIHazirSite\Core\Templates\TemplateField;
use AIHazirSite\Core\Templates\TemplateRegistry;

/**
 * Platform-neutral producer. Reads core catalog objects only; field placement comes from SchemaMap.
 *
 * - Home page: Organization + WebPage (dateModified lives on WebPage, a CreativeWork).
 * - AI catalog page: DataFeed whose DataFeedItems carry dateModified and wrap a Product (with Offer)
 *   or a Demand. Expired listings are left out.
 * - Sector templates (0.8.0) set the item type (Product, Service, TouristTrip), map template fields to
 *   Schema.org properties, hide prices where the template forbids them and mark stale short-lived values.
 *   Without a registry every listing uses the "general" template (the 0.7.0 output).
 */
final class SchemaBuilder {

	public const CONTEXT = 'https://schema.org';

	/**
	 * Constructor.
	 *
	 * @param string                $site_url Home URL with trailing slash (used for @id values).
	 * @param bool                  $link_organization False when another plugin owns the Organization node:
	 *                                                 references then carry the name instead of our @id.
	 * @param TemplateRegistry|null $templates Sector templates; null = every listing is "general".
	 * @param string                $site_name Used when the profile has no name (as llms.txt and the A2A card do).
	 */
	public function __construct(
		private readonly string $site_url,
		private readonly bool $link_organization = true,
		private readonly ?TemplateRegistry $templates = null,
		private readonly string $site_name = ''
	) {
	}

	/**
	 * The company's name: the profile's, else the site name.
	 *
	 * @param CompanyProfile $profile Profile.
	 */
	private function company_name( CompanyProfile $profile ): string {
		return '' === $profile->name ? $this->site_name : $profile->name;
	}

	/**
	 * The listing's template.
	 *
	 * @param Listing $listing Listing.
	 */
	private function template( Listing $listing ): Template {
		return null === $this->templates ? Template::general() : $this->templates->get( $listing->template );
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
				'name'          => $this->company_name( $profile ),
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
					'name'         => $this->company_name( $profile ),
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
	 * @param string|null    $now         ISO 8601 date-time for freshness (default: start of today).
	 * @param callable|null  $seller_of   fn( Listing ): ?array, the seller node of a listing (1.2.0 portal:
	 *                                    the business's Organization); null or a null result = the company.
	 * @return array<string, mixed>
	 */
	public function catalog( CompanyProfile $profile, array $listings, string $today, string $catalog_url, ?string $now = null, ?callable $seller_of = null ): array {
		$entries  = array();
		$modified = array();
		foreach ( $listings as $listing ) {
			$entry = $this->entry( $listing, $today, $profile, $now, null === $seller_of ? null : $seller_of( $listing ) );
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
			'name'            => 'AI Katalog – ' . $this->company_name( $profile ),
			'description'     => $this->company_name( $profile ) . ' firmasının sattığı, aradığı ve tedarik edebildiği ürün ve hizmetler.',
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
	 * @param string|null         $now     ISO 8601 date-time for freshness (default: start of today).
	 * @param array|null          $seller  Seller node instead of the company (1.2.0 portal), or null.
	 * @return array<string, mixed>|null
	 *
	 * @phpstan-param array<string, mixed>|null $seller
	 */
	public function entry( Listing $listing, string $today, ?CompanyProfile $profile = null, ?string $now = null, ?array $seller = null ): ?array {
		$updated     = $listing->updated_at ?? $today . 'T00:00:00Z';
		$valid_until = ListingValidity::valid_until( $listing, $today );
		if ( ! ListingValidity::is_current( $listing, $today ) || ! isset( SchemaMap::DEAL_TYPE[ $listing->type ] ) ) {
			return null;
		}

		$template              = $this->template( $listing );
		$deal_type             = SchemaMap::DEAL_TYPE[ $listing->type ];
		$nodes                 = array(
			'item'  => array( '@type' => $template->item_type ),
			'deal'  => array( '@type' => $deal_type ),
			'entry' => array( '@type' => 'DataFeedItem' ),
		);
		$values                = $listing->to_array();
		$values['valid_until'] = $valid_until;
		$values['updated_at']  = $updated;

		foreach ( SchemaMap::FIELDS as $field => [ $node, $property, $transform ] ) {
			if ( 'attributes' === $field ) {
				$this->attributes( $listing, $template, $deal_type, $now ?? $today . 'T00:00:00Z', $nodes );
				continue;
			}
			$value = $this->transform( $transform, $values[ $field ], $listing );
			if ( null === $value || str_starts_with( $property, '@price' ) ) {
				continue;
			}
			$property                    = '@quantity' === $property ? SchemaMap::QUANTITY_PROPERTY[ $deal_type ] : $property;
			$nodes[ $node ][ $property ] = $value;
		}

		if ( $template->price ) {
			$nodes['deal'] = array_merge( $nodes['deal'], $this->price( $listing, $deal_type, $valid_until ) );
		}

		$availability = SchemaMap::AVAILABILITY[ $listing->type ] ?? null;
		if ( 'Offer' === $deal_type && null === $availability && null !== $listing->quantity ) {
			$availability = (float) $listing->quantity > 0 ? 'https://schema.org/InStock' : 'https://schema.org/OutOfStock';
		}
		if ( null !== $availability ) {
			$nodes['deal']['availability'] = $availability;
		}

		if ( 'Offer' === $deal_type ) {
			$nodes['deal']['seller'] = $seller ?? $this->organization_reference( $profile );
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
	 * Writes the listing's attributes: template fields to their mapped property, everything else
	 * (and fields without a mapping) as PropertyValue in additionalProperty. additionalProperty
	 * exists on Product and Offer but not on Service, TouristTrip or Demand, so for those items the
	 * PropertyValues go on the Offer, and for a Demand they are left out (llms.txt still has them).
	 *
	 * @param Listing              $listing   Listing.
	 * @param Template             $template  Template.
	 * @param string               $deal_type Offer or Demand.
	 * @param string               $now       ISO 8601 date-time.
	 * @param array<string, mixed> $nodes     Nodes (by reference).
	 */
	private function attributes( Listing $listing, Template $template, string $deal_type, string $now, array &$nodes ): void {
		$holder     = 'Product' === $template->item_type ? 'item' : ( 'Offer' === $deal_type ? 'deal' : null );
		$properties = array();
		foreach ( $listing->attributes as $key => $value ) {
			$field = $template->field( $key );
			if ( null === $field ) {
				$properties[] = array(
					'@type' => 'PropertyValue',
					'name'  => $key,
					'value' => $value,
				);
				continue;
			}
			if ( Freshness::is_stale( $field, $listing, $now ) ) {
				$properties[] = array_merge( self::property_value( $field, $value ), array( 'description' => 'Doğrulanmadı: son güncelleme ' . (string) $listing->updated_at ) );
				continue;
			}
			$map = $field->schema;
			if ( null === $map || 'property' === $map['as'] ) {
				if ( null === $map || 'item' === $map['node'] ) {
					$properties[] = self::property_value( $field, $value );
				} elseif ( 'Offer' === $deal_type ) {
					$nodes['deal']['additionalProperty'][] = self::property_value( $field, $value );
				}
				continue;
			}
			if ( ! isset( $nodes[ $map['node'] ][ $map['property'] ] ) ) {
				$nodes[ $map['node'] ][ $map['property'] ] = self::mapped( $field, $map['as'], $value, $listing );
			}
		}
		if ( array() !== $properties && null !== $holder ) {
			$nodes[ $holder ]['additionalProperty'] = array_merge( $nodes[ $holder ]['additionalProperty'] ?? array(), $properties );
		}
	}

	/**
	 * A template field as a PropertyValue (numbers as numbers, with its UN/CEFACT unit code).
	 *
	 * @param TemplateField $field Field.
	 * @param string        $value Value.
	 * @return array<string, mixed>
	 */
	private static function property_value( TemplateField $field, string $value ): array {
		return array_filter(
			array(
				'@type'    => 'PropertyValue',
				'name'     => $field->label,
				'value'    => in_array( $field->type, array( 'integer', 'decimal' ), true ) ? self::number( $value ) : $value,
				'unitCode' => $field->unit_code,
				'unitText' => '' === $field->unit_code ? $field->unit : '',
			),
			array( self::class, 'present' )
		);
	}

	/**
	 * A template field value in the form its mapping asks for.
	 *
	 * @param TemplateField $field   Field.
	 * @param string        $form    text | number | date | country | quantity | min_quantity.
	 * @param string        $value   Value.
	 * @param Listing       $listing Listing (for its unit).
	 */
	private static function mapped( TemplateField $field, string $form, string $value, Listing $listing ): mixed {
		$items  = 'list' === $field->type ? array_map( 'trim', explode( ',', $value ) ) : array( $value );
		$one    = static fn( string $v ): mixed => match ( $form ) {
			'number'       => self::number( $v ),
			'country'      => array(
				'@type' => 'Country',
				'name'  => $v,
			),
			'quantity'     => array_filter(
				array(
					'@type'    => 'QuantitativeValue',
					'value'    => self::number( $v ),
					'unitCode' => $field->unit_code,
				),
				array( self::class, 'present' )
			),
			'min_quantity' => array_filter(
				array(
					'@type'    => 'QuantitativeValue',
					'minValue' => self::number( $v ),
					'unitText' => $listing->unit,
				),
				array( self::class, 'present' )
			),
			default        => $v,
		};
		$mapped = array_map( $one, $items );
		return 1 === count( $mapped ) ? $mapped[0] : $mapped;
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
			'name'  => $this->company_name( $profile ),
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
