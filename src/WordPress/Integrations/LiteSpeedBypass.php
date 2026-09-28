<?php
/**
 * LiteSpeed Cache: no cached pages for AI bots.
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\WordPress\Integrations;

use AIHazirSite\Core\Integrations\BotTokens;
use AIHazirSite\Core\Integrations\ManagedList;

/**
 * Adds the AI bot tokens to LiteSpeed Cache's "Do Not Cache User Agents" setting through its documented
 * API (https://docs.litespeedtech.com/lscache/lscwp/api/): read with the `litespeed_conf` filter, save
 * with the `litespeed_save_conf` action (LiteSpeed Cache 7.2+), which also rewrites its .htaccess rules.
 * The tokens we added are remembered, so turning off removes exactly those (1.8.0).
 */
final class LiteSpeedBypass {

	public const SETTING      = 'cache-exc_useragents';
	public const ADDED_OPTION = 'aihs_litespeed_added';

	/**
	 * Whether LiteSpeed Cache is active.
	 */
	public static function detected(): bool {
		return defined( 'LSCWP_V' );
	}

	/**
	 * Whether this LiteSpeed Cache version can save settings through its API.
	 */
	public static function supported(): bool {
		return self::detected() && false !== has_action( 'litespeed_save_conf' );
	}

	/**
	 * The current setting as a list.
	 *
	 * @return list<string>
	 */
	public static function current(): array {
		$value = apply_filters( 'litespeed_conf', self::SETTING ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- LiteSpeed Cache API.
		$lines = is_array( $value ) ? $value : ( is_string( $value ) && self::SETTING !== $value ? preg_split( '/\R/', $value ) : array() );
		return array_values( array_filter( array_map( 'trim', array_map( 'strval', (array) $lines ) ), static fn( string $line ): bool => '' !== $line ) );
	}

	/**
	 * Turns the integration on or off in LiteSpeed Cache.
	 *
	 * @param bool $on New state.
	 */
	public static function apply( bool $on ): void {
		if ( ! self::supported() ) {
			return;
		}
		if ( $on ) {
			$result = ManagedList::add( self::current(), BotTokens::all() );
			$added  = array_values( array_unique( array_merge( self::added(), $result['added'] ) ) );
			do_action( 'litespeed_save_conf', array( self::SETTING => $result['list'] ) ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- LiteSpeed Cache API.
			update_option( self::ADDED_OPTION, $added, false );
			return;
		}
		do_action( 'litespeed_save_conf', array( self::SETTING => ManagedList::remove( self::current(), self::added() ) ) ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- LiteSpeed Cache API.
		delete_option( self::ADDED_OPTION );
	}

	/**
	 * Tokens we added earlier.
	 *
	 * @return list<string>
	 */
	private static function added(): array {
		$added = get_option( self::ADDED_OPTION, array() );
		return is_array( $added ) ? array_values( array_map( 'strval', array_filter( $added, 'is_scalar' ) ) ) : array();
	}
}
