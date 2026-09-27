<?php
/**
 * Checks generated JSON-LD before it is published.
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\Adapters\Schema;

use DateTimeImmutable;

/**
 * Errors block publishing (the last valid output is served instead); warnings do not.
 *
 * Errors: wrong @context, unknown @type for our output, required properties missing
 * (Organization/Product name, DataFeedItem dateModified+item, Offer/Demand validThrough),
 * bad dates, currencies, numbers or availability values.
 * Warnings: Google product rich result gaps (Offer without price).
 */
final class SchemaValidator {

	/**
	 * Types our output may contain.
	 */
	public const TYPES = array( 'Organization', 'WebPage', 'DataFeed', 'DataFeedItem', 'Product', 'Offer', 'Demand', 'PriceSpecification', 'QuantitativeValue', 'PropertyValue', 'PostalAddress', 'Service', 'TouristTrip', 'Country' );

	/**
	 * Required properties per type.
	 */
	public const REQUIRED = array(
		'Organization' => array( 'name' ),
		'WebPage'      => array( 'url', 'dateModified' ),
		'DataFeed'     => array( 'name', 'dateModified' ),
		'DataFeedItem' => array( 'dateModified', 'item' ),
		'Product'      => array( 'name' ),
		'Service'      => array( 'name' ),
		'TouristTrip'  => array( 'name' ),
		'Country'      => array( 'name' ),
		'Offer'        => array( 'validThrough' ),
		'Demand'       => array( 'validThrough', 'itemOffered' ),
	);

	public const AVAILABILITY = array( 'BackOrder', 'Discontinued', 'InStock', 'InStoreOnly', 'LimitedAvailability', 'MadeToOrder', 'OnlineOnly', 'OutOfStock', 'PreOrder', 'PreSale', 'Reserved', 'SoldOut' );

	/**
	 * Validates a JSON-LD document.
	 *
	 * @param array<string, mixed> $document JSON-LD.
	 * @return array{errors: list<string>, warnings: list<string>}
	 */
	public function validate( array $document ): array {
		$errors   = array();
		$warnings = array();

		if ( SchemaBuilder::CONTEXT !== ( $document['@context'] ?? null ) ) {
			$errors[] = '@context https://schema.org olmalı.';
		}
		$roots = isset( $document['@graph'] ) && is_array( $document['@graph'] ) ? $document['@graph'] : array( $document );
		foreach ( $roots as $index => $node ) {
			if ( is_array( $node ) ) {
				$this->node( $node, '$' . ( isset( $document['@graph'] ) ? '.@graph[' . $index . ']' : '' ), $errors, $warnings );
			}
		}

		return array(
			'errors'   => $errors,
			'warnings' => $warnings,
		);
	}

	/**
	 * Validates one node and its children.
	 *
	 * @param array<mixed> $node     Node.
	 * @param string       $path     Path for messages.
	 * @param string[]     $errors   Errors (by reference).
	 * @param string[]     $warnings Warnings (by reference).
	 *
	 * @phpstan-param list<string> $errors
	 * @phpstan-param list<string> $warnings
	 */
	private function node( array $node, string $path, array &$errors, array &$warnings ): void {
		$type = $node['@type'] ?? null;
		if ( null === $type ) {
			if ( ! isset( $node['@id'] ) ) {
				$errors[] = $path . ': @type eksik.';
			}
		} elseif ( ! is_string( $type ) || ! in_array( $type, self::TYPES, true ) ) {
			$errors[] = $path . ': beklenmeyen tür ' . (string) json_encode( $type, JSON_UNESCAPED_UNICODE ) . '.'; // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Platform-neutral adapter.
		} else {
			foreach ( self::REQUIRED[ $type ] ?? array() as $property ) {
				if ( ! isset( $node[ $property ] ) || '' === $node[ $property ] || array() === $node[ $property ] ) {
					$errors[] = "{$path} ({$type}): {$property} zorunlu.";
				}
			}
			$this->values( $type, $node, $path, $errors, $warnings );
		}

		foreach ( $node as $key => $value ) {
			if ( ! is_array( $value ) || str_starts_with( (string) $key, '@' ) ) {
				continue;
			}
			if ( array_is_list( $value ) ) {
				foreach ( $value as $i => $child ) {
					if ( is_array( $child ) ) {
						$this->node( $child, "{$path}.{$key}[{$i}]", $errors, $warnings );
					}
				}
			} else {
				$this->node( $value, "{$path}.{$key}", $errors, $warnings );
			}
		}
	}

	/**
	 * Value formats of known properties.
	 *
	 * @param string       $type     Type.
	 * @param array<mixed> $node     Node.
	 * @param string       $path     Path.
	 * @param string[]     $errors   Errors (by reference).
	 * @param string[]     $warnings Warnings (by reference).
	 *
	 * @phpstan-param list<string> $errors
	 * @phpstan-param list<string> $warnings
	 */
	private function values( string $type, array $node, string $path, array &$errors, array &$warnings ): void {
		foreach ( array( 'dateModified', 'validThrough', 'priceValidUntil', 'departureTime' ) as $property ) {
			if ( isset( $node[ $property ] ) && ! self::is_date( $node[ $property ] ) ) {
				$errors[] = "{$path} ({$type}): {$property} geçerli bir tarih değil.";
			}
		}
		if ( isset( $node['priceCurrency'] ) && ! ( is_string( $node['priceCurrency'] ) && preg_match( '/^[A-Z]{3}$/', $node['priceCurrency'] ) ) ) {
			$errors[] = "{$path} ({$type}): priceCurrency ISO 4217 olmalı.";
		}
		foreach ( array( 'price', 'minPrice', 'maxPrice', 'value' ) as $property ) {
			if ( isset( $node[ $property ] ) && 'PropertyValue' !== $type && ! is_numeric( $node[ $property ] ) ) {
				$errors[] = "{$path} ({$type}): {$property} sayı olmalı.";
			}
		}
		if ( isset( $node['availability'] ) && ! in_array( str_replace( 'https://schema.org/', '', (string) $node['availability'] ), self::AVAILABILITY, true ) ) {
			$errors[] = "{$path} ({$type}): availability geçersiz.";
		}
		if ( 'Offer' === $type && isset( $node['price'] ) && ! isset( $node['priceCurrency'] ) ) {
			$errors[] = "{$path} (Offer): price varken priceCurrency zorunlu.";
		}
		if ( 'Offer' === $type && ! isset( $node['price'] ) ) {
			$warnings[] = "{$path} (Offer): fiyat yok; Google ürün zengin sonucuna uygun değil.";
		}
	}

	/**
	 * Whether a value is a Y-m-d date or an ISO 8601 date-time.
	 *
	 * @param mixed $value Value.
	 */
	private static function is_date( mixed $value ): bool {
		if ( ! is_string( $value ) ) {
			return false;
		}
		$date = DateTimeImmutable::createFromFormat( '!Y-m-d', $value );
		if ( false !== $date && $date->format( 'Y-m-d' ) === $value ) {
			return true;
		}
		return 1 === preg_match( '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(Z|[+-]\d{2}:\d{2})$/', $value );
	}
}
