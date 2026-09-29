<?php
/**
 * Removing our rewrite rules on deactivation.
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\WordPress\Platform;

/**
 * On deactivation the plugin is still loaded: its `init` hook has already added its rules for this request,
 * so a plain flush_rewrite_rules() stores them again (found on a live site, 1.12.1). As the Plugin Handbook
 * does for post types (unregister, then flush), the rule is forgotten first and the rules are flushed after.
 */
final class RewriteRules {

	/**
	 * Forgets our rule for this request and stores the rules without it.
	 *
	 * @param string $regex Rule added with add_rewrite_rule( $regex, …, 'top' ).
	 */
	public static function remove_and_flush( string $regex ): void {
		global $wp_rewrite;
		if ( $wp_rewrite instanceof \WP_Rewrite ) {
			unset( $wp_rewrite->extra_rules_top[ $regex ], $wp_rewrite->extra_rules[ $regex ] );
		}
		flush_rewrite_rules( false );
	}
}
