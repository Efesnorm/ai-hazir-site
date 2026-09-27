<?php
/**
 * A sector template.
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\Core\Templates;

use InvalidArgumentException;

/**
 * Which fields a listing of a sector has, how they map to Schema.org and whether prices
 * may be given. Read from data/templates/<id>.json; "general" has no fields and is used
 * for listings without a template (including every listing saved before 0.8.0).
 */
final class Template {

	public const GENERAL    = 'general';
	public const ID         = '/^[a-z0-9_]{1,32}$/';
	public const ITEM_TYPES = array( 'Product', 'Service', 'TouristTrip' );

	/**
	 * Constructor.
	 *
	 * @param string          $id          Id (file name without .json).
	 * @param int             $version     Definition version.
	 * @param string          $name        Name shown to people.
	 * @param string          $description Description.
	 * @param string          $item_type   Schema.org type of the offered/demanded item.
	 * @param bool            $price       Whether prices may be given and published.
	 * @param TemplateField[] $fields      Fields.
	 *
	 * @phpstan-param list<TemplateField> $fields
	 */
	public function __construct(
		public readonly string $id,
		public readonly int $version,
		public readonly string $name,
		public readonly string $description = '',
		public readonly string $item_type = 'Product',
		public readonly bool $price = true,
		public readonly array $fields = array()
	) {
	}

	/**
	 * The built-in general template (used when general.json is missing).
	 */
	public static function general(): self {
		return new self( self::GENERAL, 1, 'Genel' );
	}

	/**
	 * Field by attribute key.
	 *
	 * @param string $name Attribute key.
	 */
	public function field( string $name ): ?TemplateField {
		foreach ( $this->fields as $field ) {
			if ( $field->name === $name ) {
				return $field;
			}
		}
		return null;
	}

	/**
	 * Whether any field has a freshness limit.
	 */
	public function has_freshness(): bool {
		foreach ( $this->fields as $field ) {
			if ( null !== $field->fresh_hours ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * From decoded JSON.
	 *
	 * @param mixed $data Decoded template.
	 * @throws InvalidArgumentException When the definition is invalid.
	 */
	public static function from_array( mixed $data ): self {
		if ( ! is_array( $data ) ) {
			throw new InvalidArgumentException( 'şablon bir JSON nesnesi olmalı' );
		}
		$string = static fn( string $k ): string => isset( $data[ $k ] ) && is_scalar( $data[ $k ] ) ? trim( (string) $data[ $k ] ) : '';

		$id = $string( 'id' );
		if ( ! preg_match( self::ID, $id ) ) {
			throw new InvalidArgumentException( sprintf( 'kimlik (id) "%s" geçersiz (küçük harf, rakam, alt çizgi; en fazla 32)', $id ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Not output; the admin notice escapes it.
		}
		if ( '' === $string( 'name' ) ) {
			throw new InvalidArgumentException( 'ad (name) zorunlu' );
		}
		if ( ! is_int( $data['version'] ?? null ) || $data['version'] < 1 ) {
			throw new InvalidArgumentException( 'sürüm (version) pozitif bir tam sayı olmalı' );
		}
		$item_type = '' === $string( 'item_type' ) ? 'Product' : $string( 'item_type' );
		if ( ! in_array( $item_type, self::ITEM_TYPES, true ) ) {
			throw new InvalidArgumentException( 'item_type şunlardan biri olmalı: ' . implode( ', ', self::ITEM_TYPES ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Not output; the admin notice escapes it.
		}
		if ( isset( $data['fields'] ) && ! is_array( $data['fields'] ) ) {
			throw new InvalidArgumentException( 'alanlar (fields) bir liste olmalı' );
		}

		$fields = array();
		$names  = array();
		foreach ( array_values( (array) ( $data['fields'] ?? array() ) ) as $raw ) {
			$field = TemplateField::from_array( $raw );
			if ( isset( $names[ $field->name ] ) ) {
				throw new InvalidArgumentException( sprintf( '"%s" alanı iki kez tanımlanmış', $field->name ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Not output; the admin notice escapes it.
			}
			$names[ $field->name ] = true;
			$fields[]              = $field;
		}

		$version = (int) $data['version'];
		$price   = ! isset( $data['price'] ) || false !== $data['price'];

		return new self( $id, $version, $string( 'name' ), $string( 'description' ), $item_type, $price, $fields );
	}
}
