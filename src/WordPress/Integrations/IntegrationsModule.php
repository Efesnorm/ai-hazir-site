<?php
/**
 * Integrations with other plugins (1.8.0) – WordPress wiring.
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\WordPress\Integrations;

use AIHazirSite\Core\Features;
use AIHazirSite\WordPress\Module;

/**
 * Tools → AI Hazır Entegrasyonlar, and the hooks of the integrations that are on:
 * - `bot_cache_bypass`: AI bots get no cached pages (WP Rocket, LiteSpeed Cache);
 * - `catalog_sitemap`: the AI catalog in the site's sitemap (WordPress core, Rank Math, Yoast SEO).
 * Both are off by default and changed only on the page. Deactivation reverts what we wrote into other
 * plugins' settings and turns the cache integration off.
 */
final class IntegrationsModule implements Module {

	/**
	 * Registers hooks.
	 */
	public function register(): void {
		if ( is_admin() ) {
			( new IntegrationsPage() )->register();
		}
		if ( Features::is_enabled( Features::BOT_CACHE_BYPASS ) ) {
			WpRocketBypass::hook();
		}
		if ( Features::is_enabled( Features::CATALOG_SITEMAP ) ) {
			CatalogSitemap::hook();
		}
		if ( Features::is_enabled( Features::LITESPEED_SERVER_BYPASS ) ) {
			LiteSpeedServerBypass::hook();
		}
	}

	/**
	 * Reverts our entries in other plugins and in .htaccess (they must not stay without us). The feature keys stay on
	 * (1.19.1): activate() writes the entries again, so a deactivate/activate cycle does not silently turn them off.
	 */
	public function deactivate(): void {
		if ( Features::is_enabled( Features::BOT_CACHE_BYPASS ) ) {
			self::apply_bypass( false );
		}
		if ( Features::is_enabled( Features::LITESPEED_SERVER_BYPASS ) ) {
			LiteSpeedServerBypass::apply( false );
		}
		if ( Features::is_enabled( Features::CATALOG_SITEMAP ) ) {
			CatalogSitemap::flush_caches();
		}
	}

	/**
	 * Plugin activation (1.19.1): writes again the entries of the integrations that are on (deactivation removed them).
	 */
	public static function activate(): void {
		if ( Features::is_enabled( Features::BOT_CACHE_BYPASS ) ) {
			self::apply_bypass( true );
		}
		if ( Features::is_enabled( Features::LITESPEED_SERVER_BYPASS ) ) {
			LiteSpeedServerBypass::apply( true );
		}
	}

	/**
	 * Turns the cache integration on or off in every supported page-cache plugin.
	 *
	 * @param bool $on New state.
	 */
	public static function set_bypass( bool $on ): void {
		Features::set( Features::BOT_CACHE_BYPASS, $on );
		self::apply_bypass( $on );
	}

	/**
	 * Writes or removes our entries in the page-cache plugins (the feature key is not touched).
	 *
	 * @param bool $on New state.
	 */
	private static function apply_bypass( bool $on ): void {
		if ( WpRocketBypass::detected() ) {
			WpRocketBypass::apply( $on );
		}
		LiteSpeedBypass::apply( $on );
	}

	/**
	 * Turns the LiteSpeed server cache rule on or off (1.14.0).
	 *
	 * @param bool $on New state.
	 * @return bool Whether .htaccess was written.
	 */
	public static function set_server_bypass( bool $on ): bool {
		Features::set( Features::LITESPEED_SERVER_BYPASS, $on );
		return LiteSpeedServerBypass::apply( $on );
	}

	/**
	 * Turns the sitemap integration on or off.
	 *
	 * @param bool $on New state.
	 */
	public static function set_sitemap( bool $on ): void {
		Features::set( Features::CATALOG_SITEMAP, $on );
		CatalogSitemap::flush_caches();
	}
}
