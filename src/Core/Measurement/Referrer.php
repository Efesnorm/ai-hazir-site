<?php
/**
 * AI platform referrer definition.
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\Core\Measurement;

/**
 * One entry of data/ai-referrers.json.
 */
final class Referrer {

	/**
	 * Constructor.
	 *
	 * @param string $id     Stable identifier stored in the database.
	 * @param string $name   Display name.
	 * @param string $domain Lower-case domain; subdomains match too.
	 */
	public function __construct(
		public readonly string $id,
		public readonly string $name,
		public readonly string $domain
	) {
	}

	/**
	 * Builds a referrer from a decoded JSON record, or null when the record is invalid.
	 *
	 * @param mixed $data Decoded JSON record.
	 */
	public static function from_array( mixed $data ): ?self {
		if ( ! is_array( $data ) ) {
			return null;
		}
		foreach ( array( 'id', 'name', 'domain' ) as $key ) {
			if ( ! isset( $data[ $key ] ) || ! is_string( $data[ $key ] ) || '' === $data[ $key ] ) {
				return null;
			}
		}
		if ( ! preg_match( '/^[a-z0-9-]{1,64}$/', $data['id'] ) ) {
			return null;
		}

		return new self( $data['id'], $data['name'], strtolower( $data['domain'] ) );
	}

	/**
	 * Whether a host is this domain or one of its subdomains.
	 *
	 * @param string $host Host name.
	 */
	public function matches_host( string $host ): bool {
		$host = strtolower( rtrim( $host, '.' ) );
		return $host === $this->domain || str_ends_with( $host, '.' . $this->domain );
	}
}
