<?php
/**
 * "Ağda yayınla": where a listing is shared in the portal network (1.26.0).
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\Core\Network;

/**
 * A listing lives on one site; sharing only tells verified sibling portals that they may show it (with a link to the
 * original). Nothing is copied. A share is either the whole network or chosen sites (only verified ones are kept).
 *
 * @phpstan-type Share array{all: bool, sites: list<string>}
 */
final class NetworkShare {

	/**
	 * A share from form or stored input, or null for "not shared". Sites not in $verified are dropped.
	 *
	 * @param mixed    $all      "Whole network" choice.
	 * @param mixed    $sites    Chosen site URLs.
	 * @param string[] $verified Verified sibling URLs.
	 * @return array<string, mixed>|null
	 *
	 * @phpstan-param list<string> $verified
	 * @phpstan-return Share|null
	 */
	public static function from_input( mixed $all, mixed $sites, array $verified ): ?array {
		if ( true === $all || '1' === $all || 1 === $all ) {
			return array(
				'all'   => true,
				'sites' => array(),
			);
		}
		$chosen = array();
		foreach ( is_array( $sites ) ? $sites : array() as $site ) {
			if ( is_string( $site ) && in_array( $site, $verified, true ) && ! in_array( $site, $chosen, true ) ) {
				$chosen[] = $site;
			}
		}
		return array() === $chosen ? null : array(
			'all'   => false,
			'sites' => $chosen,
		);
	}

	/**
	 * Stored value → share (null when not shared or damaged). Stored sites are kept as they are (a sibling that is
	 * temporarily unverified gets the listing back when it is verified again).
	 *
	 * @param mixed $stored Stored value.
	 * @return array<string, mixed>|null
	 *
	 * @phpstan-return Share|null
	 */
	public static function from_stored( mixed $stored ): ?array {
		if ( ! is_array( $stored ) ) {
			return null;
		}
		if ( true === ( $stored['all'] ?? null ) ) {
			return array(
				'all'   => true,
				'sites' => array(),
			);
		}
		$sites = array_values( array_filter( is_array( $stored['sites'] ?? null ) ? $stored['sites'] : array(), static fn( $s ): bool => is_string( $s ) && str_starts_with( $s, 'https://' ) ) );
		return array() === $sites ? null : array(
			'all'   => false,
			'sites' => $sites,
		);
	}

	/**
	 * Hosts a sibling reads from a share in another site's answer: ['*'] for the whole network, else hosts.
	 *
	 * @param mixed $share `network_share` of a listing in a sibling's REST answer.
	 * @return list<string>
	 */
	public static function hosts( mixed $share ): array {
		if ( ! is_array( $share ) ) {
			return array();
		}
		if ( true === ( $share['all'] ?? null ) ) {
			return array( '*' );
		}
		$hosts = array();
		foreach ( is_array( $share['sites'] ?? null ) ? array_slice( $share['sites'], 0, 50 ) : array() as $site ) {
			$host = is_string( $site ) ? NetworkLink::host( $site ) : '';
			if ( '' !== $host && ! in_array( $host, $hosts, true ) ) {
				$hosts[] = $host;
			}
		}
		return $hosts;
	}

	/**
	 * Whether a share (as hosts) reaches a site.
	 *
	 * @param string[] $hosts Hosts from hosts().
	 * @param string   $url   The site's URL or host.
	 *
	 * @phpstan-param list<string> $hosts
	 */
	public static function reaches( array $hosts, string $url ): bool {
		return in_array( '*', $hosts, true ) || in_array( NetworkLink::host( $url ), $hosts, true );
	}
}
