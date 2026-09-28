<?php
/**
 * The AI catalog in the site's XML sitemap.
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\WordPress\Integrations;

use AIHazirSite\Adapters\Sitemap\SitemapXml;
use AIHazirSite\WordPress\Platform\PageCache;
use AIHazirSite\WordPress\Portal\Portal;
use AIHazirSite\WordPress\Schema\SchemaModule;

/**
 * Search engines find /ai-katalog/ (and portal business pages) through the sitemap the site already
 * uses (1.8.0):
 * - our own small sitemap, /ai-katalog-sitemap.xml (sitemaps.org protocol), listed in the Rank Math
 *   (`rank_math/sitemap/index`) and Yoast SEO (`wpseo_sitemap_index`) sitemap indexes;
 * - WordPress core sitemaps (when no SEO plugin replaced them): a provider registered with
 *   wp_register_sitemap_provider().
 * Only the active sitemap system shows the entry; the others' hooks never run.
 */
final class CatalogSitemap {

	public const FILE     = 'ai-katalog-sitemap.xml';
	public const PROVIDER = 'aihs';

	/**
	 * Registers the hooks (while the integration is on).
	 */
	public static function hook(): void {
		add_action( 'parse_request', array( self::class, 'maybe_serve' ), 20 );
		add_filter( 'rank_math/sitemap/index', array( self::class, 'index' ), 11 );
		add_filter( 'wpseo_sitemap_index', array( self::class, 'index' ) );
		add_action( 'init', array( self::class, 'register_provider' ), 20 );
	}

	/**
	 * Which sitemap system the site uses (for the settings screen).
	 */
	public static function system(): string {
		if ( defined( 'RANK_MATH_VERSION' ) ) {
			return 'Rank Math';
		}
		if ( defined( 'WPSEO_VERSION' ) ) {
			return 'Yoast SEO';
		}
		return function_exists( 'wp_sitemaps_get_server' ) && wp_sitemaps_get_server()->sitemaps_enabled() ? 'WordPress' : '';
	}

	/**
	 * Our pages: the catalog, and in portal mode each business page.
	 *
	 * @return list<array{loc: string, lastmod: string}>
	 */
	public static function urls(): array {
		if ( ! SchemaModule::catalog_enabled() ) {
			return array();
		}
		$lastmod = (string) SchemaModule::last_modified( SchemaModule::listings() );
		$urls    = array(
			array(
				'loc'     => SchemaModule::catalog_url(),
				'lastmod' => $lastmod,
			),
		);
		if ( Portal::active() ) {
			foreach ( Portal::businesses()->businesses() as $business ) {
				$urls[] = array(
					'loc'     => Portal::page_url( $business ),
					'lastmod' => $lastmod,
				);
			}
		}
		return $urls;
	}

	/**
	 * Address of our sitemap.
	 */
	public static function url(): string {
		return home_url( '/' . self::FILE );
	}

	/**
	 * `rank_math/sitemap/index`, `wpseo_sitemap_index`: our sitemap in their index.
	 *
	 * @param mixed $xml Index entries so far.
	 */
	public static function index( mixed $xml ): string {
		$urls = self::urls();
		if ( array() === $urls ) {
			return (string) $xml;
		}
		return (string) $xml . SitemapXml::index_entry( self::url(), $urls[0]['lastmod'] );
	}

	/**
	 * `parse_request`: serves /ai-katalog-sitemap.xml and stops.
	 */
	public static function maybe_serve(): void {
		$uri  = isset( $_SERVER['REQUEST_URI'] ) && is_string( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Only compared with a fixed path.
		$path = wp_parse_url( $uri, PHP_URL_PATH );
		$home = wp_parse_url( home_url( '/' ), PHP_URL_PATH );
		$urls = self::urls();
		if ( trailingslashit( is_string( $home ) ? $home : '/' ) . self::FILE !== $path || array() === $urls ) {
			return;
		}
		PageCache::exclude();
		status_header( 200 );
		header( 'Content-Type: application/xml; charset=utf-8' );
		header( 'X-Robots-Tag: noindex' );
		echo SitemapXml::urlset( $urls ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- XML escaped in SitemapXml.
		exit;
	}

	/**
	 * `init`: the WordPress core sitemap provider (wp-sitemap-aihs-1.xml).
	 */
	public static function register_provider(): void {
		if ( function_exists( 'wp_register_sitemap_provider' ) ) {
			wp_register_sitemap_provider( self::PROVIDER, new CatalogSitemapProvider() );
		}
	}

	/**
	 * Clears the SEO plugins' stored sitemaps so a change shows at once (their public cache methods).
	 */
	public static function flush_caches(): void {
		foreach ( array( 'RankMath\Sitemap\Cache::invalidate_storage', 'WPSEO_Sitemaps_Cache::clear' ) as $clear ) {
			if ( is_callable( $clear ) ) {
				call_user_func( $clear );
			}
		}
	}
}
