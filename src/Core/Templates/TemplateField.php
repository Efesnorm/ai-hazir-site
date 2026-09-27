<?php
/**
 * One sector-specific listing field.
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\Core\Templates;

use AIHazirSite\Core\Catalog\ListingValidator;
use InvalidArgumentException;

/**
 * A field of a template, read from data/templates/*.json. Its value is stored as a
 * listing attribute under `name`.
 *
 * Schema.org mapping (`schema`): `node` is "item" (the Product / Service / TouristTrip) or
 * "deal" (the Offer); `as` is how the value is written: text, number, date, country
 * (a Country node), quantity (QuantitativeValue), min_quantity (QuantitativeValue.minValue)
 * or property (a PropertyValue under `property`). Without `schema` the field becomes a
 * PropertyValue in additionalProperty (with `unit_code`, a UN/CEFACT code, when given).
 */
final class TemplateField {

	public const TYPES = array( 'text', 'integer', 'decimal', 'date', 'enum', 'list' );
	public const NODES = array( 'item', 'deal' );
	public const AS    = array( 'text', 'number', 'date', 'country', 'quantity', 'min_quantity', 'property' );
	public const CASES = array( '', 'upper', 'lower' );

	/**
	 * Constructor.
	 *
	 * @param string                                                 $name        Attribute key.
	 * @param string                                                 $label       Label.
	 * @param string                                                 $type        One of self::TYPES.
	 * @param string                                                 $unit        Unit shown to people (e.g. "mm²").
	 * @param string                                                 $unit_code   UN/CEFACT unit code (e.g. "MMK").
	 * @param bool                                                   $required    Whether a value is required.
	 * @param string[]                                               $allowed     Allowed values (enum, list).
	 * @param string                                                 $pattern     Regular expression (without delimiters) each value must match.
	 * @param string                                                 $letter_case '' | upper | lower (JSON key "case"): applied before checks.
	 * @param string                                                 $help        Help text for the form.
	 * @param bool                                                   $llms        Whether the value appears in llms.txt.
	 * @param int|null                                               $fresh_hours Hours after the listing's last save until the value is "not verified".
	 * @param array{node: string, property: string, as: string}|null $schema      Schema.org mapping.
	 *
	 * @phpstan-param list<string> $allowed
	 */
	public function __construct(
		public readonly string $name,
		public readonly string $label,
		public readonly string $type,
		public readonly string $unit = '',
		public readonly string $unit_code = '',
		public readonly bool $required = false,
		public readonly array $allowed = array(),
		public readonly string $pattern = '',
		public readonly string $letter_case = '',
		public readonly string $help = '',
		public readonly bool $llms = true,
		public readonly ?int $fresh_hours = null,
		public readonly ?array $schema = null
	) {
	}

	/**
	 * From decoded JSON.
	 *
	 * @param mixed $data Decoded field.
	 * @throws InvalidArgumentException When the definition is invalid (message names the problem).
	 */
	public static function from_array( mixed $data ): self {
		if ( ! is_array( $data ) ) {
			throw new InvalidArgumentException( 'alan bir nesne olmalı' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Not output; the admin notice escapes it.
		}
		$string = static fn( string $k ): string => isset( $data[ $k ] ) && is_scalar( $data[ $k ] ) ? trim( (string) $data[ $k ] ) : '';

		$name = $string( 'name' );
		if ( ! preg_match( ListingValidator::ATTRIBUTE_KEY, $name ) ) {
			throw new InvalidArgumentException( sprintf( 'alan adı "%s" geçersiz (küçük harf, rakam, alt çizgi)', $name ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Not output; the admin notice escapes it.
		}
		$fail = static function ( string $message ) use ( $name ): never {
			throw new InvalidArgumentException( sprintf( '"%s" alanı: %s', $name, $message ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Not output; the admin notice escapes it.
		};

		$label = $string( 'label' );
		if ( '' === $label ) {
			$fail( 'etiket (label) zorunlu' );
		}
		$type = $string( 'type' );
		if ( ! in_array( $type, self::TYPES, true ) ) {
			$fail( 'tür (type) şunlardan biri olmalı: ' . implode( ', ', self::TYPES ) );
		}

		$allowed = array();
		if ( isset( $data['allowed'] ) ) {
			if ( ! is_array( $data['allowed'] ) ) {
				$fail( 'izin verilen değerler (allowed) bir liste olmalı' );
			}
			$allowed = array_values( array_filter( array_map( static fn( $v ): string => is_scalar( $v ) ? trim( (string) $v ) : '', $data['allowed'] ), static fn( string $v ): bool => '' !== $v ) );
		}
		if ( 'enum' === $type && array() === $allowed ) {
			$fail( 'enum türünde izin verilen değerler (allowed) zorunlu' );
		}

		$pattern = $string( 'pattern' );
		if ( '' !== $pattern && false === @preg_match( self::regex( $pattern ), '' ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Invalid patterns are reported below.
			$fail( 'desen (pattern) geçersiz' );
		}

		$case = $string( 'case' );
		if ( ! in_array( $case, self::CASES, true ) ) {
			$fail( 'case "upper" ya da "lower" olmalı' );
		}

		$fresh = null;
		if ( isset( $data['fresh_hours'] ) ) {
			if ( ! is_int( $data['fresh_hours'] ) || $data['fresh_hours'] < 1 ) {
				$fail( 'fresh_hours pozitif bir tam sayı olmalı' );
			}
			$fresh = $data['fresh_hours'];
		}

		$schema = null;
		if ( isset( $data['schema'] ) ) {
			$raw = is_array( $data['schema'] ) ? $data['schema'] : array();
			$map = array();
			foreach ( array( 'node', 'property', 'as' ) as $key ) {
				$map[ $key ] = isset( $raw[ $key ] ) && is_string( $raw[ $key ] ) ? $raw[ $key ] : '';
			}
			if ( ! in_array( $map['node'], self::NODES, true ) || ! in_array( $map['as'], self::AS, true ) || ! preg_match( '/^[a-zA-Z]{1,64}$/', $map['property'] ) ) {
				$fail( 'schema eşlemesi geçersiz (node: item|deal, property: Schema.org özelliği, as: ' . implode( '|', self::AS ) . ')' );
			}
			$schema = $map;
		}

		return new self(
			$name,
			$label,
			$type,
			$string( 'unit' ),
			$string( 'unit_code' ),
			true === ( $data['required'] ?? false ),
			$allowed,
			$pattern,
			$case,
			$string( 'help' ),
			false !== ( $data['llms'] ?? true ),
			$fresh,
			$schema
		);
	}

	/**
	 * The pattern as a PCRE with delimiters (UTF-8).
	 *
	 * @param string $pattern Pattern without delimiters.
	 */
	public static function regex( string $pattern ): string {
		return '~' . str_replace( '~', '\\~', $pattern ) . '~u';
	}
}
