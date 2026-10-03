<?php
/**
 * Mutual consent rules of the portal network (1.20.0).
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\Core\Network;

/**
 * A site is in the network only when both sides say so: the mother lists it AND it names that mother. One-sided
 * entries never reach any output. Remote answers are data: texts are cleaned and shortened, never followed.
 *
 * @phpstan-type Member array{url: string, name: string, country: string, verified: bool}
 * @phpstan-type Document array{role: string, name: string, mother: string, members: list<Member>}
 */
final class NetworkCheck {

	public const VERIFIED      = 'verified';
	public const NOT_DECLARED  = 'not_declared';
	public const OTHER_MOTHER  = 'other_mother';
	public const NOT_LISTED    = 'not_listed';
	public const NOT_MOTHER    = 'not_mother';
	public const UNREACHABLE   = 'unreachable';
	public const GRACE_SECONDS = 86400;

	/**
	 * A remote /network answer, cleaned; null when it is not one.
	 *
	 * @param mixed $data Decoded JSON.
	 * @return array|null
	 *
	 * @phpstan-return Document|null
	 */
	public static function document( mixed $data ): ?array {
		if ( ! is_array( $data ) || ! in_array( $data['role'] ?? null, NetworkSettings::ROLES, true ) ) {
			return null;
		}
		$members = array();
		foreach ( is_array( $data['members'] ?? null ) ? array_slice( $data['members'], 0, NetworkSettings::MAX_MEMBERS + 1 ) : array() as $member ) {
			$url = is_array( $member ) && is_string( $member['url'] ?? null ) ? NetworkSettings::normalize_url( $member['url'] ) : null;
			if ( null === $url ) {
				continue;
			}
			$members[] = array(
				'url'      => $url,
				'name'     => self::text( $member['name'] ?? '', 100 ),
				'country'  => self::country( $member['country'] ?? '' ),
				'verified' => true === ( $member['verified'] ?? false ),
			);
		}
		return array(
			'role'    => (string) $data['role'],
			'name'    => self::text( $data['name'] ?? '', NetworkSettings::NAME_MAX ),
			'mother'  => is_string( $data['mother'] ?? null ) ? ( NetworkSettings::normalize_url( $data['mother'] ) ?? '' ) : '',
			'members' => $members,
		);
	}

	/**
	 * The mother's view of one member, from the member's own /network answer (null = unreachable).
	 *
	 * @param string     $mother The mother's (this site's) URL.
	 * @param array|null $remote The member's answer.
	 *
	 * @phpstan-param Document|null $remote
	 */
	public static function member_status( string $mother, ?array $remote ): string {
		if ( null === $remote ) {
			return self::UNREACHABLE;
		}
		if ( NetworkSettings::ROLE_MEMBER !== $remote['role'] || '' === $remote['mother'] ) {
			return self::NOT_DECLARED;
		}
		return $remote['mother'] === $mother ? self::VERIFIED : self::OTHER_MOTHER;
	}

	/**
	 * A member's view of itself, from its mother's /network answer (null = unreachable).
	 *
	 * @param string     $own_url   This site's URL.
	 * @param string     $mother The declared mother's URL.
	 * @param array|null $remote The mother's answer.
	 *
	 * @phpstan-param Document|null $remote
	 */
	public static function self_status( string $own_url, string $mother, ?array $remote ): string {
		if ( null === $remote ) {
			return self::UNREACHABLE;
		}
		if ( NetworkSettings::ROLE_MOTHER !== $remote['role'] || $remote['mother'] !== $mother ) {
			return self::NOT_MOTHER;
		}
		foreach ( $remote['members'] as $member ) {
			if ( $member['url'] === $own_url && $member['verified'] ) {
				return self::VERIFIED;
			}
		}
		return self::NOT_LISTED;
	}

	/**
	 * Status after a check: a failed request keeps an earlier verification for 24 hours.
	 *
	 * @param string   $status      New status.
	 * @param int|null $verified_at Time of the last verification, or null.
	 * @param int      $now         Now.
	 */
	public static function effective( string $status, ?int $verified_at, int $now ): string {
		if ( self::UNREACHABLE === $status && null !== $verified_at && $now - $verified_at < self::GRACE_SECONDS ) {
			return self::VERIFIED;
		}
		return $status;
	}

	/**
	 * Single-line text without control characters, shortened.
	 *
	 * @param mixed $value Value.
	 * @param int   $max   Max characters.
	 */
	public static function text( mixed $value, int $max ): string {
		$text = is_scalar( $value ) ? (string) $value : '';
		$text = (string) preg_replace( '/[\x00-\x1F\x7F]+/u', ' ', $text );
		$text = trim( (string) preg_replace( '/\s+/u', ' ', strip_tags( $text ) ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.strip_tags_strip_tags -- Platform-neutral core (no WordPress functions).
		return mb_substr( $text, 0, $max );
	}

	/**
	 * ISO 3166-1 alpha-2 code or ''.
	 *
	 * @param mixed $value Value.
	 */
	public static function country( mixed $value ): string {
		$code = strtoupper( is_string( $value ) ? trim( $value ) : '' );
		return 1 === preg_match( '/^[A-Z]{2}$/', $code ) ? $code : '';
	}
}
