<?php
/**
 * Listing attributes checked against a template.
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\Core\Templates;

use AIHazirSite\Core\Catalog\ListingValidator;
use DateTimeImmutable;

/**
 * Checks and normalizes the template's fields among a listing's attributes (required,
 * type, allowed values, pattern) and the template's price rule. Attributes that are not
 * template fields are kept as they are (free "key: value" extras). Messages are Turkish;
 * error keys are "attributes.<field>" or "price_min".
 */
final class TemplateValidator {

	/**
	 * Validates.
	 *
	 * @param Template              $template   Template.
	 * @param array<string, string> $attributes Attributes (keys already checked by ListingValidator).
	 * @param bool                  $has_price  Whether a price was given.
	 * @return array{attributes: array<string, string>, errors: array<string, string>}
	 */
	public function validate( Template $template, array $attributes, bool $has_price ): array {
		$errors = array();
		if ( ! $template->price && $has_price ) {
			$errors['price_min'] = sprintf( '"%s" şablonunda fiyat girilmez; fiyat alanlarını boş bırakın.', $template->name );
		}

		foreach ( $template->fields as $field ) {
			$value = trim( $attributes[ $field->name ] ?? '' );
			if ( '' === $value ) {
				unset( $attributes[ $field->name ] );
				if ( $field->required ) {
					$errors[ 'attributes.' . $field->name ] = sprintf( '%s zorunludur.', $field->label );
				}
				continue;
			}

			$result = $this->value( $field, $value );
			if ( is_array( $result ) ) {
				$errors[ 'attributes.' . $field->name ] = $result[0];
				continue;
			}
			$attributes[ $field->name ] = $result;
		}

		return array(
			'attributes' => $attributes,
			'errors'     => $errors,
		);
	}

	/**
	 * Normalized value, or [message] when invalid.
	 *
	 * @param TemplateField $field Field.
	 * @param string        $value Non-empty raw value.
	 * @return string|array{0: string}
	 */
	private function value( TemplateField $field, string $value ): string|array {
		$value = match ( $field->letter_case ) {
			'upper' => mb_strtoupper( $value ),
			'lower' => mb_strtolower( $value ),
			default => $value,
		};

		switch ( $field->type ) {
			case 'integer':
				return ctype_digit( $value ) && strlen( $value ) <= 9 ? (string) (int) $value : array( sprintf( '%s sıfır veya pozitif bir tam sayı olmalı.', $field->label ) );
			case 'decimal':
				$value = str_replace( ',', '.', $value );
				return preg_match( ListingValidator::DECIMAL, $value ) ? $value : array( sprintf( '%s sıfır veya pozitif bir sayı olmalı (en fazla 4 ondalık).', $field->label ) );
			case 'date':
				$date = DateTimeImmutable::createFromFormat( '!Y-m-d', $value );
				return false !== $date && $date->format( 'Y-m-d' ) === $value ? $value : array( sprintf( '%s YYYY-AA-GG biçiminde olmalı.', $field->label ) );
			case 'enum':
				return $this->allowed( $field, $value ) ?? array( sprintf( '%s şunlardan biri olmalı: %s.', $field->label, implode( ', ', $field->allowed ) ) );
			case 'list':
				$items = array_values( array_filter( array_map( 'trim', explode( ',', $value ) ), static fn( string $v ): bool => '' !== $v ) );
				$clean = array();
				foreach ( $items as $item ) {
					$item = array() === $field->allowed ? $item : $this->allowed( $field, $item );
					if ( null === $item ) {
						return array( sprintf( '%s yalnızca şunları içerebilir: %s.', $field->label, implode( ', ', $field->allowed ) ) );
					}
					if ( ! $this->matches( $field, $item ) ) {
						return array( sprintf( '%s değeri "%s" beklenen biçimde değil%s.', $field->label, $item, '' === $field->help ? '' : ' (' . $field->help . ')' ) );
					}
					$clean[] = $item;
				}
				return implode( ', ', array_values( array_unique( $clean ) ) );
			default:
				return $this->matches( $field, $value ) ? $value : array( sprintf( '%s beklenen biçimde değil%s.', $field->label, '' === $field->help ? '' : ' (' . $field->help . ')' ) );
		}
	}

	/**
	 * The allowed value equal to $value ignoring case, or null.
	 *
	 * @param TemplateField $field Field.
	 * @param string        $value Value.
	 */
	private function allowed( TemplateField $field, string $value ): ?string {
		foreach ( $field->allowed as $allowed ) {
			if ( mb_strtolower( $allowed ) === mb_strtolower( $value ) ) {
				return $allowed;
			}
		}
		return null;
	}

	/**
	 * Whether the value matches the field's pattern (always true without one).
	 *
	 * @param TemplateField $field Field.
	 * @param string        $value Value.
	 */
	private function matches( TemplateField $field, string $value ): bool {
		return '' === $field->pattern || 1 === preg_match( TemplateField::regex( $field->pattern ), $value );
	}
}
