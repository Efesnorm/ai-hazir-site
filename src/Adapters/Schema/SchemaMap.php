<?php
/**
 * Listing → Schema.org field mapping (the single place where it is defined).
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\Adapters\Schema;

use AIHazirSite\Core\Catalog\ListingType;
use AIHazirSite\Core\Catalog\ListingValidity;

/**
 * Which listing field goes to which Schema.org property, per listing type.
 *
 * Nodes: "item" = the Product (offered or demanded), "deal" = the Offer (sell/supply)
 * or the Demand (demand), "entry" = the DataFeedItem wrapping it.
 * Sources: schema.org/Product, /Offer, /Demand, /DataFeedItem, /ItemAvailability.
 */
final class SchemaMap {

	/**
	 * Schema.org type of the "deal" node per listing type.
	 */
	public const DEAL_TYPE = array(
		ListingType::OFFER  => 'Offer',
		ListingType::SUPPLY => 'Offer',
		ListingType::DEMAND => 'Demand',
	);

	/**
	 * Availability per listing type (null = derived from quantity for offers).
	 */
	public const AVAILABILITY = array(
		ListingType::OFFER  => null,
		ListingType::SUPPLY => 'https://schema.org/MadeToOrder',
		ListingType::DEMAND => null,
	);

	/**
	 * Listing field → [node, property, transform].
	 *
	 * Transforms: text, decimal, quantity (QuantitativeValue with unitText), days (QuantitativeValue,
	 * unitCode DAY), properties (PropertyValue list), date.
	 * The quantity property differs by deal type; see QUANTITY_PROPERTY.
	 */
	public const FIELDS = array(
		'title'          => array( 'item', 'name', 'text' ),
		'description'    => array( 'item', 'description', 'text' ),
		'category'       => array( 'item', 'category', 'text' ),
		'attributes'     => array( 'item', 'additionalProperty', 'properties' ),
		'quantity'       => array( 'deal', '@quantity', 'quantity' ),
		'price_min'      => array( 'deal', '@price', 'price' ),
		'price_max'      => array( 'deal', '@price', 'price' ),
		'currency'       => array( 'deal', '@price', 'price' ),
		'region'         => array( 'deal', 'areaServed', 'text' ),
		'lead_time_days' => array( 'deal', 'deliveryLeadTime', 'days' ),
		'valid_until'    => array( 'deal', 'validThrough', 'date' ),
		'updated_at'     => array( 'entry', 'dateModified', 'date' ),
	);

	/**
	 * How the "price" transform is written (price fields are handled together):
	 * Offer: price = price_min (or price_max), priceCurrency, priceValidUntil = validThrough,
	 * and priceSpecification{minPrice, maxPrice} when both differ.
	 * Demand: priceSpecification{minPrice, maxPrice, priceCurrency}.
	 */
	public const PRICE = array(
		'Offer'  => array( 'price', 'priceCurrency', 'priceValidUntil', 'priceSpecification' ),
		'Demand' => array( 'priceSpecification' ),
	);

	/**
	 * Where a quantity goes: stock on an Offer, wanted amount on a Demand.
	 */
	public const QUANTITY_PROPERTY = array(
		'Offer'  => 'inventoryLevel',
		'Demand' => 'eligibleQuantity',
	);

	/**
	 * Days after the last update used as validThrough when a listing has no validity date
	 * (the rule itself lives in ListingValidity).
	 */
	public const DEFAULT_VALIDITY_DAYS = ListingValidity::DEFAULT_DAYS;
}
