<?php
/**
 * Listing validation.
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\Core\Catalog;

use AIHazirSite\Core\Templates\Template;
use AIHazirSite\Core\Templates\TemplateRegistry;
use AIHazirSite\Core\Templates\TemplateValidator;
use DateTimeImmutable;

/**
 * Turns raw form input into a valid Listing or field errors (Turkish messages).
 */
final class ListingValidator {

	public const TITLE_MAX     = 200;
	public const DECIMAL       = '/^\d{1,12}(\.\d{1,4})?$/';
	public const ATTRIBUTE_KEY = '/^[a-z0-9_]{1,64}$/';

	/**
	 * Constructor.
	 *
	 * @param TemplateRegistry|null $templates Sector templates; null when templates are off
	 *                                         (new listings are "general", stored templates are kept unchecked).
	 */
	public function __construct( private readonly ?TemplateRegistry $templates = null ) {
	}

	/**
	 * Validates input.
	 *
	 * @param array<string, mixed> $input    Raw input (strings; attributes as array or "anahtar: değer" lines;
	 *                                        "template" for a new listing).
	 * @param string               $today    Y-m-d.
	 * @param Listing|null         $existing Stored listing when editing.
	 */
	public function validate( array $input, string $today, ?Listing $existing = null ): ValidationResult {
		$text   = static fn( string $k ): string => isset( $input[ $k ] ) && is_scalar( $input[ $k ] ) ? trim( (string) $input[ $k ] ) : '';
		$errors = array();

		$type = $text( 'type' );
		if ( ! ListingType::is_valid( $type ) ) {
			$errors['type'] = 'Geçersiz ilan türü.';
		}
		if ( null !== $existing && $existing->type !== $type ) {
			$errors['type'] = 'İlanın türü değiştirilemez.';
		}

		$title = $text( 'title' );
		if ( '' === $title ) {
			$errors['title'] = 'Başlık boş olamaz.';
		} elseif ( mb_strlen( $title ) > self::TITLE_MAX ) {
			$errors['title'] = sprintf( 'Başlık en fazla %d karakter olabilir.', self::TITLE_MAX );
		}

		$quantity  = self::decimal( $text( 'quantity' ), 'quantity', 'Miktar', $errors );
		$price_min = self::decimal( $text( 'price_min' ), 'price_min', 'En düşük fiyat', $errors );
		$price_max = self::decimal( $text( 'price_max' ), 'price_max', 'En yüksek fiyat', $errors );
		if ( null !== $price_min && null !== $price_max && self::compare( $price_min, $price_max ) > 0 ) {
			$errors['price_max'] = 'En düşük fiyat en yüksek fiyattan büyük olamaz.';
		}

		$currency = strtoupper( $text( 'currency' ) );
		if ( '' !== $currency && ! preg_match( '/^[A-Z]{3}$/', $currency ) ) {
			$errors['currency'] = 'Para birimi üç harfli ISO 4217 kodu olmalı (ör. TRY, USD, EUR).';
		} elseif ( '' === $currency && ( null !== $price_min || null !== $price_max ) ) {
			$errors['currency'] = 'Fiyat girildiğinde para birimi zorunludur.';
		}

		$lead_time = null;
		if ( '' !== $text( 'lead_time_days' ) ) {
			if ( ! ctype_digit( $text( 'lead_time_days' ) ) || (int) $text( 'lead_time_days' ) > 3650 ) {
				$errors['lead_time_days'] = 'Teslim süresi 0 ile 3650 gün arasında tam sayı olmalı.';
			} else {
				$lead_time = (int) $text( 'lead_time_days' );
			}
		}

		$valid_until = null;
		if ( '' !== $text( 'valid_until' ) ) {
			$valid_until = $text( 'valid_until' );
			$date        = DateTimeImmutable::createFromFormat( '!Y-m-d', $valid_until );
			if ( false === $date || $date->format( 'Y-m-d' ) !== $valid_until ) {
				$errors['valid_until'] = 'Geçerlilik tarihi YYYY-AA-GG biçiminde olmalı.';
			} elseif ( $valid_until < $today && ( null === $existing || $existing->valid_until !== $valid_until ) ) {
				$errors['valid_until'] = 'Geçerlilik tarihi geçmişte olamaz.';
			}
		}

		$attributes = self::attributes( $input['attributes'] ?? array(), $errors );

		// A listing keeps its template, like its type; a new one takes the requested template.
		$template = $existing->template ?? ( '' === $text( 'template' ) ? Template::GENERAL : $text( 'template' ) );
		if ( null !== $existing && '' !== $text( 'template' ) && $text( 'template' ) !== $existing->template ) {
			$errors['template'] = 'İlanın şablonu değiştirilemez.';
		}
		if ( null === $this->templates ) {
			$template = $existing->template ?? Template::GENERAL;
		} elseif ( null === $existing && ! $this->templates->has( $template ) ) {
			$errors['template'] = 'Bilinmeyen sektör şablonu.';
		} elseif ( ! isset( $errors['attributes'] ) ) {
			$checked    = ( new TemplateValidator() )->validate( $this->templates->get( $template ), $attributes, null !== $price_min || null !== $price_max );
			$attributes = $checked['attributes'];
			$errors     = array_merge( $errors, $checked['errors'] );
		}

		if ( array() !== $errors ) {
			return new ValidationResult( null, $errors );
		}

		return new ValidationResult(
			new Listing(
				$existing?->id,
				$type,
				$title,
				trim( (string) preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $text( 'description' ) ) ),
				$text( 'category' ),
				$quantity,
				$text( 'unit' ),
				$price_min,
				$price_max,
				$currency,
				$text( 'region' ),
				$lead_time,
				$valid_until,
				$existing?->updated_at,
				$attributes,
				$template
			)
		);
	}

	/**
	 * Decimal string or null; comma accepted as decimal separator.
	 *
	 * @param string                $value  Raw value.
	 * @param string                $field  Field name.
	 * @param string                $label  Label for the message.
	 * @param array<string, string> $errors Errors (by reference).
	 */
	private static function decimal( string $value, string $field, string $label, array &$errors ): ?string {
		if ( '' === $value ) {
			return null;
		}
		$value = str_replace( ',', '.', $value );
		if ( ! preg_match( self::DECIMAL, $value ) ) {
			$errors[ $field ] = $label . ' sıfır veya pozitif bir sayı olmalı (en fazla 4 ondalık).';
			return null;
		}
		return $value;
	}

	/**
	 * Compares two decimal strings without floating point.
	 *
	 * @param string $a Decimal.
	 * @param string $b Decimal.
	 * @return int -1, 0 or 1.
	 */
	public static function compare( string $a, string $b ): int {
		$normalize = static function ( string $d ): string {
			[ $int, $frac ] = array_pad( explode( '.', $d, 2 ), 2, '' );
			$int            = ltrim( $int, '0' );
			return str_pad( '' === $int ? '0' : $int, 13, '0', STR_PAD_LEFT ) . str_pad( $frac, 4, '0' );
		};
		return strcmp( $normalize( $a ), $normalize( $b ) ) <=> 0;
	}

	/**
	 * Attributes from an array or "anahtar: değer" lines.
	 *
	 * @param mixed                 $raw    Raw attributes.
	 * @param array<string, string> $errors Errors (by reference).
	 * @return array<string, string>
	 */
	private static function attributes( mixed $raw, array &$errors ): array {
		$pairs = array();
		if ( is_string( $raw ) ) {
			$lines = preg_split( '/\R/', $raw );
			foreach ( is_array( $lines ) ? $lines : array() as $line ) {
				if ( '' === trim( $line ) ) {
					continue;
				}
				[ $key, $value ] = array_pad( explode( ':', $line, 2 ), 2, '' );
				$pairs[]         = array( trim( $key ), trim( $value ) );
			}
		} elseif ( is_array( $raw ) ) {
			foreach ( $raw as $key => $value ) {
				$pairs[] = array( trim( (string) $key ), is_scalar( $value ) ? trim( (string) $value ) : '' );
			}
		}

		$attributes = array();
		foreach ( $pairs as [ $key, $value ] ) {
			$key = strtolower( $key );
			if ( ! preg_match( self::ATTRIBUTE_KEY, $key ) ) {
				$errors['attributes'] = sprintf( 'Özellik adı "%s" geçersiz: yalnızca küçük harf, rakam ve alt çizgi (en fazla 64).', $key );
				continue;
			}
			$attributes[ $key ] = mb_substr( $value, 0, 500 );
		}
		return $attributes;
	}
}
