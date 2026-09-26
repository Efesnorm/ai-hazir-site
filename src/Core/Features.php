<?php
/**
 * Feature flags.
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\Core;

/**
 * Every user-facing feature is tied to a key here and ships disabled by default.
 *
 * Stored values live in the `aihs_features` option as `array<string, bool>`.
 * Keys that are not declared in {@see Features::defaults()} are always disabled,
 * even if the option contains them.
 */
final class Features {

	/**
	 * Option name that stores feature flag overrides.
	 */
	public const OPTION = 'aihs_features';

	/**
	 * Declared feature keys and their default state. All defaults must be false.
	 *
	 * @return array<string, bool>
	 */
	public static function defaults(): array {
		return array();
	}

	/**
	 * Whether a feature is enabled.
	 *
	 * @param string $key Feature key.
	 */
	public static function is_enabled( string $key ): bool {
		$defaults = self::defaults();
		if ( ! array_key_exists( $key, $defaults ) ) {
			return false;
		}

		$stored = get_option( self::OPTION, array() );
		if ( is_array( $stored ) && array_key_exists( $key, $stored ) ) {
			return (bool) $stored[ $key ];
		}

		return $defaults[ $key ];
	}
}
