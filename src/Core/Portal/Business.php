<?php
/**
 * A business listed on a portal.
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\Core\Portal;

use AIHazirSite\Core\Catalog\CompanyProfile;

/**
 * One business of a portal (1.2.0): its own company profile and a URL slug. The portal's own
 * profile stays the single site profile; listings without a business belong to the portal.
 */
final class Business {

	/**
	 * Slug format: lowercase letters, digits and single hyphens, 2–60 characters.
	 */
	public const SLUG = '/^[a-z0-9](?:[a-z0-9]|-(?=[a-z0-9])){1,59}$/';

	/**
	 * Constructor.
	 *
	 * @param int|null       $id         Id (null before it is stored).
	 * @param string         $slug       URL slug (unique).
	 * @param CompanyProfile $profile    Company profile of the business.
	 * @param string|null    $updated_at Last save (ISO 8601 UTC).
	 */
	public function __construct(
		public readonly ?int $id,
		public readonly string $slug,
		public readonly CompanyProfile $profile,
		public readonly ?string $updated_at = null
	) {
	}

	/**
	 * Plain array for storage.
	 *
	 * @return array{id: int|null, slug: string, profile: array<string, mixed>, updated_at: string|null}
	 */
	public function to_array(): array {
		return array(
			'id'         => $this->id,
			'slug'       => $this->slug,
			'profile'    => $this->profile->to_array(),
			'updated_at' => $this->updated_at,
		);
	}

	/**
	 * From stored data, or null when the data is not a business.
	 *
	 * @param mixed $data Stored data.
	 */
	public static function from_array( mixed $data ): ?self {
		if ( ! is_array( $data ) || ! is_string( $data['slug'] ?? null ) || '' === $data['slug'] ) {
			return null;
		}
		$id = isset( $data['id'] ) && is_numeric( $data['id'] ) ? (int) $data['id'] : null;
		return new self( $id, $data['slug'], CompanyProfile::from_array( $data['profile'] ?? array() ), is_string( $data['updated_at'] ?? null ) ? $data['updated_at'] : null );
	}

	/**
	 * A slug suggestion from a name ("Kapadokya Balon Turları" → "kapadokya-balon-turlari").
	 *
	 * @param string $name Name.
	 */
	public static function slugify( string $name ): string {
		$ascii = strtr(
			mb_strtolower( str_replace( array( 'İ', 'I' ), array( 'i', 'ı' ), $name ) ),
			array(
				'ç' => 'c',
				'ğ' => 'g',
				'ı' => 'i',
				'ö' => 'o',
				'ş' => 's',
				'ü' => 'u',
				'â' => 'a',
				'î' => 'i',
				'û' => 'u',
			)
		);
		$slug  = trim( (string) preg_replace( '/[^a-z0-9]+/', '-', $ascii ), '-' );
		return substr( $slug, 0, 60 );
	}
}
