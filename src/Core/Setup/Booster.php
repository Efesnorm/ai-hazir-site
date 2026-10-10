<?php
/**
 * Booster AI: every suitable feature in one step (1.25.0).
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\Core\Setup;

use AIHazirSite\Core\Features;

/**
 * Decides what "Booster AI" turns on and in which order. It never turns anything off.
 * - NEVER: features that need their owner's separate, informed consent (telemetry sends data; the inquiry box collects
 *   personal data and has its own screen with the KVKK notice).
 * - OPTIONAL: features that change what the site is (portal mode) or need another plugin (multilingual); offered, but
 *   pre-ticked only when they fit the site.
 * - NETWORK: the portal network and its features; pre-ticked only on a site that is already in a network or a portal
 *   (a company site does not get them unasked).
 * - everything else is pre-ticked.
 * The order follows Features::REQUIRES and REQUIRES_ANY, so each feature comes after what it needs.
 */
final class Booster {

	public const NEVER    = array( Features::TELEMETRY, Features::INQUIRIES );
	public const OPTIONAL = array( Features::PORTAL_MODE, Features::MULTILINGUAL );
	public const NETWORK  = array( Features::PORTAL_NETWORK, Features::NETWORK_SUGGESTIONS, Features::NETWORK_BLOCK, Features::NETWORK_REFERRALS, Features::NETWORK_REPORT, Features::NETWORK_SHARE );

	/**
	 * Features Booster may turn on (all except NEVER), in requirement order.
	 *
	 * @return list<string>
	 */
	public static function candidates(): array {
		return self::order( array_values( array_diff( array_map( 'strval', array_keys( Features::defaults() ) ), self::NEVER ) ) );
	}

	/**
	 * The pre-ticked choice.
	 *
	 * @param bool $is_portal          The site already runs in portal mode.
	 * @param bool $multilingual_ready Polylang or WPML is active.
	 * @param bool $in_network         The site already has a network role (mother or member).
	 * @return list<string>
	 */
	public static function preselected( bool $is_portal, bool $multilingual_ready, bool $in_network = false ): array {
		return array_values(
			array_filter(
				self::candidates(),
				static fn( string $key ): bool => ( ! in_array( $key, self::OPTIONAL, true ) && ! in_array( $key, self::NETWORK, true ) )
					|| ( Features::PORTAL_MODE === $key && $is_portal )
					|| ( Features::MULTILINGUAL === $key && $multilingual_ready )
					|| ( in_array( $key, self::NETWORK, true ) && ( $in_network || $is_portal ) )
			)
		);
	}

	/**
	 * Features in requirement order (a feature after everything it needs; ties keep the given order).
	 *
	 * @param string[] $keys Feature keys.
	 * @return list<string>
	 *
	 * @phpstan-param list<string> $keys
	 */
	public static function order( array $keys ): array {
		$ordered = array();
		$placed  = array();
		$visit   = static function ( string $key ) use ( &$visit, &$ordered, &$placed, $keys ): void {
			if ( isset( $placed[ $key ] ) ) {
				return;
			}
			$placed[ $key ] = true;
			foreach ( array_merge( Features::REQUIRES[ $key ] ?? array(), Features::REQUIRES_ANY[ $key ] ?? array() ) as $needed ) {
				if ( in_array( $needed, $keys, true ) ) {
					$visit( $needed );
				}
			}
			$ordered[] = $key;
		};
		foreach ( $keys as $key ) {
			$visit( $key );
		}
		return $ordered;
	}

	/**
	 * What would happen to a choice: [turn on (ordered), already on, cannot (key → missing requirement keys)].
	 * A feature whose requirement is neither on nor chosen cannot be turned on.
	 *
	 * @param string[] $chosen Chosen keys (unknown and NEVER keys are dropped).
	 * @return array{0: list<string>, 1: list<string>, 2: array<string, list<string>>}
	 *
	 * @phpstan-param list<string> $chosen
	 */
	public static function plan( array $chosen ): array {
		$chosen = self::order( array_values( array_intersect( self::candidates(), $chosen ) ) );
		$on     = array_fill_keys( array_filter( array_map( 'strval', array_keys( Features::defaults() ) ), array( Features::class, 'is_enabled' ) ), true );
		$turn   = array();
		$have   = array();
		$cannot = array();
		foreach ( $chosen as $key ) {
			if ( isset( $on[ $key ] ) ) {
				$have[] = $key;
				continue;
			}
			$missing = array_values( array_filter( Features::REQUIRES[ $key ] ?? array(), static fn( string $r ): bool => ! isset( $on[ $r ] ) ) );
			$any     = Features::REQUIRES_ANY[ $key ] ?? array();
			if ( array() !== $any && array() === array_filter( $any, static fn( string $r ): bool => isset( $on[ $r ] ) ) ) {
				$missing = array_merge( $missing, $any );
			}
			if ( array() !== $missing ) {
				$cannot[ $key ] = $missing;
				continue;
			}
			$turn[]     = $key;
			$on[ $key ] = true;
		}
		return array( $turn, $have, $cannot );
	}
}
