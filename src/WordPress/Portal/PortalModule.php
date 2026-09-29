<?php
/**
 * Portal mode (A9).
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\WordPress\Portal;

use AIHazirSite\Core\Features;
use AIHazirSite\WordPress\Module;
use AIHazirSite\WordPress\Platform\RewriteRules;
use AIHazirSite\WordPress\Schema\SchemaModule;

/**
 * While `portal_mode` is on: the business user capability, business catalog pages
 * (/ai-katalog/isletme/{slug}/) and the admin screens. The page rule is removed when the
 * feature or the catalog page is off (flushed only when that changes).
 */
final class PortalModule implements Module {

	/**
	 * Registers hooks.
	 */
	public function register(): void {
		add_action( 'init', array( self::class, 'rewrite' ), 20 );
		if ( ! Portal::active() ) {
			return;
		}
		add_filter( 'user_has_cap', array( Portal::class, 'grant' ), 10, 4 );
		add_filter( 'query_vars', array( self::class, 'query_vars' ) );
		add_action( 'template_redirect', array( BusinessPage::class, 'maybe_render' ) );
		if ( is_admin() ) {
			if ( Features::is_enabled( Features::CATALOG ) ) {
				( new PortalAdmin() )->register();
			}
			( new BusinessAdmin() )->register();
		}
	}

	/**
	 * Removes the page rule on deactivation.
	 */
	public function deactivate(): void {
		RewriteRules::remove_and_flush( Portal::REWRITE );
	}

	/**
	 * Whether business pages are served (portal on and the AI catalog page published).
	 */
	public static function pages_enabled(): bool {
		return Portal::active() && SchemaModule::catalog_enabled();
	}

	/**
	 * Adds or drops the page rule; flushes only when its presence changes.
	 */
	public static function rewrite(): void {
		$enabled = self::pages_enabled();
		if ( $enabled ) {
			add_rewrite_rule( Portal::REWRITE, 'index.php?' . Portal::QUERY_VAR . '=$matches[1]', 'top' );
		}
		$rules   = get_option( 'rewrite_rules' );
		$present = is_array( $rules ) && isset( $rules[ Portal::REWRITE ] );
		if ( $present !== $enabled ) {
			flush_rewrite_rules( false );
		}
	}

	/**
	 * Registers the query var.
	 *
	 * @param string[] $vars Query vars.
	 * @return string[]
	 */
	public static function query_vars( array $vars ): array {
		$vars[] = Portal::QUERY_VAR;
		return $vars;
	}
}
