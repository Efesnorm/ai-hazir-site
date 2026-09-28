<?php
/**
 * WP Rocket: no cached pages for AI bots.
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\WordPress\Integrations;

use AIHazirSite\Core\Integrations\BotTokens;
use AIHazirSite\Core\Integrations\ManagedList;

/**
 * Adds the AI bot tokens to WP Rocket's "Never Cache User Agent(s)" through its documented
 * `rocket_cache_reject_ua` filter (https://docs.wp-rocket.me/article/1389-never-cache-user-agents).
 * WP Rocket writes that list into its .htaccess rules and advanced-cache config, so both are
 * regenerated after a change, as WP Rocket's own helper plugins do (flush_rocket_htaccess(),
 * rocket_generate_config_file()). The owner's own entries are never touched (1.8.0).
 */
final class WpRocketBypass {

	/**
	 * Whether WP Rocket is active.
	 */
	public static function detected(): bool {
		return defined( 'WP_ROCKET_VERSION' );
	}

	/**
	 * Adds the filter (every request while the integration is on, so WP Rocket's own regenerations keep it).
	 */
	public static function hook(): void {
		add_filter( 'rocket_cache_reject_ua', array( self::class, 'reject_ua' ) );
	}

	/**
	 * `rocket_cache_reject_ua`: WP Rocket's list plus the AI bot tokens.
	 *
	 * @param mixed $user_agents WP Rocket's list.
	 * @return list<string>
	 */
	public static function reject_ua( mixed $user_agents ): array {
		$current = array_values( array_map( 'strval', array_filter( is_array( $user_agents ) ? $user_agents : array(), 'is_scalar' ) ) );
		return ManagedList::add( $current, BotTokens::all() )['list'];
	}

	/**
	 * Turns the integration on or off in WP Rocket (the feature flag is set by the caller).
	 *
	 * @param bool $on New state.
	 */
	public static function apply( bool $on ): void {
		if ( $on ) {
			self::hook();
		} else {
			remove_filter( 'rocket_cache_reject_ua', array( self::class, 'reject_ua' ) );
		}
		if ( function_exists( 'flush_rocket_htaccess' ) ) {
			flush_rocket_htaccess();
		}
		if ( function_exists( 'rocket_generate_config_file' ) ) {
			rocket_generate_config_file();
		}
	}
}
