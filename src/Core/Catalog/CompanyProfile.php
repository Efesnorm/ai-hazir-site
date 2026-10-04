<?php
/**
 * Company profile.
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\Core\Catalog;

use AIHazirSite\Core\Templates\Template;

/**
 * Company-level information only; there is deliberately no field for a person
 * (no personal name, personal e-mail or personal phone).
 */
final class CompanyProfile {

	/**
	 * Field names, in form order.
	 */
	public const FIELDS = array( 'name', 'sector', 'country', 'languages', 'contact_email', 'contact_phone', 'certifications', 'template' );

	/**
	 * Constructor.
	 *
	 * @param string   $name           Company name.
	 * @param string   $sector         Sector.
	 * @param string   $country        ISO 3166-1 alpha-2.
	 * @param string[] $languages      ISO 639-1 codes.
	 * @param string   $contact_email  Corporate e-mail.
	 * @param string   $contact_phone  Corporate phone.
	 * @param string[] $certifications Certificates, e.g. "ISO 9001".
	 * @param string   $template       Sector template for new listings (0.8.0).
	 * @param string   $nace           NACE Rev. 2.1 section letter, or '' (1.22.0).
	 *
	 * @phpstan-param list<string> $languages
	 * @phpstan-param list<string> $certifications
	 */
	public function __construct(
		public readonly string $name = '',
		public readonly string $sector = '',
		public readonly string $country = '',
		public readonly array $languages = array(),
		public readonly string $contact_email = '',
		public readonly string $contact_phone = '',
		public readonly array $certifications = array(),
		public readonly string $template = Template::GENERAL,
		public readonly string $nace = ''
	) {
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
		// 1.22.0: only when chosen, so profiles without a NACE section are stored exactly as before.
		if ( '' !== $this->nace ) {
			$data['nace'] = $this->nace;
		}
		return $data;
	}

	/**
	 * From stored data (no validation).
	 *
	 * @param mixed $data Stored data.
	 */
	public static function from_array( mixed $data ): self {
		$data   = is_array( $data ) ? $data : array();
		$string = static fn( string $k ): string => isset( $data[ $k ] ) && is_scalar( $data[ $k ] ) ? (string) $data[ $k ] : '';
		$list   = static fn( string $k ): array => array_values( array_map( 'strval', array_filter( is_array( $data[ $k ] ?? null ) ? $data[ $k ] : array(), 'is_scalar' ) ) );

		return new self( $string( 'name' ), $string( 'sector' ), $string( 'country' ), $list( 'languages' ), $string( 'contact_email' ), $string( 'contact_phone' ), $list( 'certifications' ), '' === $string( 'template' ) ? Template::GENERAL : $string( 'template' ), Nace::section( $data['nace'] ?? '' ) );
	}
}
