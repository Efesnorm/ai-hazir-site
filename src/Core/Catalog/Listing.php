<?php
/**
 * A listing: something offered, sought or suppliable.
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\Core\Catalog;

use AIHazirSite\Core\Templates\Template;

/**
 * Immutable listing. Prices and quantities are decimal strings ("12.50") so no
 * rounding happens between the form, the database and the AI outputs.
 * Holds no personal data.
 */
final class Listing {

	/**
	 * Field names, in form order.
	 */
	public const FIELDS = array( 'id', 'type', 'title', 'description', 'category', 'quantity', 'unit', 'price_min', 'price_max', 'currency', 'region', 'lead_time_days', 'valid_until', 'updated_at', 'attributes', 'template' );

	/**
	 * Constructor.
	 *
	 * @param int|null              $id             Storage id; null before the first save.
	 * @param string                $type           ListingType::*.
	 * @param string                $title          Title.
	 * @param string                $description    Plain-text description.
	 * @param string                $category       Category.
	 * @param string|null           $quantity       Decimal string.
	 * @param string                $unit           Unit, e.g. "m", "adet".
	 * @param string|null           $price_min      Decimal string.
	 * @param string|null           $price_max      Decimal string.
	 * @param string                $currency       ISO 4217 code.
	 * @param string                $region         Region served or sought.
	 * @param int|null              $lead_time_days Lead time in days.
	 * @param string|null           $valid_until    Y-m-d.
	 * @param string|null           $updated_at     ISO 8601 UTC, set by storage.
	 * @param array<string, string> $attributes     Sector-specific key–value pairs.
	 * @param string                $template       Sector template id (0.8.0; "general" for older listings).
	 */
	public function __construct(
		public readonly ?int $id,
		public readonly string $type,
		public readonly string $title,
		public readonly string $description = '',
		public readonly string $category = '',
		public readonly ?string $quantity = null,
		public readonly string $unit = '',
		public readonly ?string $price_min = null,
		public readonly ?string $price_max = null,
		public readonly string $currency = '',
		public readonly string $region = '',
		public readonly ?int $lead_time_days = null,
		public readonly ?string $valid_until = null,
		public readonly ?string $updated_at = null,
		public readonly array $attributes = array(),
		public readonly string $template = Template::GENERAL
	) {
	}

	/**
	 * Whether the listing's validity date is before the given day.
	 *
	 * @param string $today Y-m-d.
	 */
	public function is_expired( string $today ): bool {
		return null !== $this->valid_until && $this->valid_until < $today;
	}

	/**
	 * Copy with storage id and update time.
	 *
	 * @param int    $id         Id.
	 * @param string $updated_at ISO 8601 UTC.
	 */
	public function stored( int $id, string $updated_at ): self {
		$data               = $this->to_array();
		$data['id']         = $id;
		$data['updated_at'] = $updated_at;
		return self::from_array( $data );
	}

	/**
	 * Plain array (FIELDS order).
	 *
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		$data = array();
		foreach ( self::FIELDS as $field ) {
			$data[ $field ] = $this->{$field};
		}
		return $data;
	}

	/**
	 * From a plain array produced by to_array() or by storage (no validation).
	 *
	 * @param array<string, mixed> $data Data.
	 */
	public static function from_array( array $data ): self {
		$string   = static fn( string $k ): string => isset( $data[ $k ] ) && is_scalar( $data[ $k ] ) ? (string) $data[ $k ] : '';
		$nullable = static fn( string $k ): ?string => isset( $data[ $k ] ) && is_scalar( $data[ $k ] ) && '' !== (string) $data[ $k ] ? (string) $data[ $k ] : null;

		$attributes = array();
		foreach ( is_array( $data['attributes'] ?? null ) ? $data['attributes'] : array() as $key => $value ) {
			$attributes[ (string) $key ] = is_scalar( $value ) ? (string) $value : '';
		}

		return new self(
			isset( $data['id'] ) && is_numeric( $data['id'] ) ? (int) $data['id'] : null,
			$string( 'type' ),
			$string( 'title' ),
			$string( 'description' ),
			$string( 'category' ),
			$nullable( 'quantity' ),
			$string( 'unit' ),
			$nullable( 'price_min' ),
			$nullable( 'price_max' ),
			$string( 'currency' ),
			$string( 'region' ),
			isset( $data['lead_time_days'] ) && is_numeric( $data['lead_time_days'] ) ? (int) $data['lead_time_days'] : null,
			$nullable( 'valid_until' ),
			$nullable( 'updated_at' ),
			$attributes,
			'' === $string( 'template' ) ? Template::GENERAL : $string( 'template' )
		);
	}
}
