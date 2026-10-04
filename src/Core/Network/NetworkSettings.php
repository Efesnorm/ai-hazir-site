<?php
/**
 * Portal network settings (1.20.0).
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\Core\Network;

/**
 * What this site declares about its network. Nothing is built in: any group of sites forms a network and picks its own
 * mother site.
 * - mother: this site manages the network name and the member list;
 * - member: this site only names its mother; the sibling list comes from the mother;
 * - none: not in a network.
 */
final class NetworkSettings {

	public const ROLE_NONE   = 'none';
	public const ROLE_MOTHER = 'mother';
	public const ROLE_MEMBER = 'member';
	public const ROLES       = array( self::ROLE_NONE, self::ROLE_MOTHER, self::ROLE_MEMBER );

	/**
	 * Members a mother may list (the first network has 11 portals).
	 */
	public const MAX_MEMBERS = 25;

	public const NAME_MAX = 100;

	/**
	 * Constructor.
	 *
	 * @param string   $role    One of self::ROLES.
	 * @param string   $name    Network name (mother only).
	 * @param string   $mother  Mother site URL (member only; for a mother, its own URL is used).
	 * @param string[] $members Member site URLs (mother only).
	 *
	 * @phpstan-param list<string> $members
	 */
	public function __construct(
		public readonly string $role = self::ROLE_NONE,
		public readonly string $name = '',
		public readonly string $mother = '',
		public readonly array $members = array()
	) {
	}

	/**
	 * Normalized site URL ("https://host[/path]/", lower-case host), or null when it is not an https site address.
	 *
	 * @param string $url Input.
	 */
	public static function normalize_url( string $url ): ?string {
		$url   = trim( $url );
		$parts = parse_url( $url ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- Platform-neutral core.
		if ( ! is_array( $parts ) || 'https' !== strtolower( (string) ( $parts['scheme'] ?? '' ) ) || '' === (string) ( $parts['host'] ?? '' ) ) {
			return null;
		}
		if ( isset( $parts['user'] ) || isset( $parts['pass'] ) || isset( $parts['query'] ) || isset( $parts['fragment'] ) ) {
			return null;
		}
		$host = strtolower( (string) $parts['host'] );
		if ( 1 !== preg_match( '/^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)+$/', $host ) ) {
			return null;
		}
		$port = isset( $parts['port'] ) ? ':' . (int) $parts['port'] : '';
		$path = trim( (string) ( $parts['path'] ?? '' ), '/' );
		return 'https://' . $host . $port . '/' . ( '' === $path ? '' : $path . '/' );
	}

	/**
	 * From form input: [settings, errors]. Invalid entries are reported and left out.
	 *
	 * @param array<string, mixed> $input Raw input (role, name, mother, members as text or list).
	 * @param string               $own_url  This site's URL (normalized by the caller's platform).
	 * @return array{0: self, 1: list<string>}
	 */
	public static function from_input( array $input, string $own_url ): array {
		$errors  = array();
		$role    = is_string( $input['role'] ?? null ) && in_array( $input['role'], self::ROLES, true ) ? $input['role'] : self::ROLE_NONE;
		$own_url = self::normalize_url( $own_url ) ?? $own_url;

		if ( self::ROLE_MOTHER === $role ) {
			$name = mb_substr( trim( (string) preg_replace( '/[\s\x00-\x1F\x7F]+/u', ' ', is_scalar( $input['name'] ?? null ) ? (string) $input['name'] : '' ) ), 0, self::NAME_MAX );
			if ( '' === $name ) {
				$errors[] = 'Ağ adı gerekli (yalnızca anne site için). Bu site bir anneye bağlanacaksa rolü "Üye" seçin.';
			}
			$lines   = is_array( $input['members'] ?? null ) ? $input['members'] : preg_split( '/[\r\n,]+/', is_scalar( $input['members'] ?? null ) ? (string) $input['members'] : '' );
			$members = array();
			foreach ( is_array( $lines ) ? $lines : array() as $line ) {
				if ( ! is_scalar( $line ) || '' === trim( (string) $line ) ) {
					continue;
				}
				$url = self::normalize_url( (string) $line );
				if ( null === $url ) {
					$errors[] = 'Geçersiz adres (yalnızca https site adresi): ' . mb_substr( trim( (string) $line ), 0, 200 );
					continue;
				}
				if ( $url !== $own_url && ! in_array( $url, $members, true ) ) {
					$members[] = $url;
				}
			}
			if ( count( $members ) > self::MAX_MEMBERS ) {
				$errors[] = sprintf( 'En çok %d üye olabilir; fazlası alınmadı.', self::MAX_MEMBERS );
				$members  = array_slice( $members, 0, self::MAX_MEMBERS );
			}
			return array( new self( $role, $name, $own_url, $members ), $errors );
		}

		if ( self::ROLE_MEMBER === $role ) {
			$mother = self::normalize_url( is_scalar( $input['mother'] ?? null ) ? (string) $input['mother'] : '' );
			if ( null === $mother ) {
				$errors[] = 'Anne site adresi gerekli (https).';
				return array( new self(), $errors );
			}
			if ( $mother === $own_url ) {
				$errors[] = 'Bu site kendi annesi olamaz; anne olacaksa rolü "anne site" seçin.';
				return array( new self(), $errors );
			}
			return array( new self( $role, '', $mother ), $errors );
		}

		return array( new self(), $errors );
	}

	/**
	 * Plain array for storage.
	 *
	 * @return array{role: string, name: string, mother: string, members: list<string>}
	 */
	public function to_array(): array {
		return array(
			'role'    => $this->role,
			'name'    => $this->name,
			'mother'  => $this->mother,
			'members' => $this->members,
		);
	}

	/**
	 * From storage (unknown data reads as "no network").
	 *
	 * @param mixed $data Stored data.
	 */
	public static function from_array( mixed $data ): self {
		if ( ! is_array( $data ) || ! in_array( $data['role'] ?? null, self::ROLES, true ) ) {
			return new self();
		}
		$members = array_values( array_filter( (array) ( $data['members'] ?? array() ), static fn( $m ): bool => is_string( $m ) && null !== self::normalize_url( $m ) ) );
		return new self( (string) $data['role'], (string) ( $data['name'] ?? '' ), (string) ( $data['mother'] ?? '' ), array_slice( $members, 0, self::MAX_MEMBERS ) );
	}
}
