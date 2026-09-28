<?php
/**
 * WordPress core sitemap provider for our pages.
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\WordPress\Integrations;

use WP_Sitemaps_Provider;

/**
 * The file wp-sitemap-aihs-1.xml in the core sitemap index (WordPress 5.5+ sitemaps API,
 * https://developer.wordpress.org/reference/classes/wp_sitemaps_provider/). One page is enough:
 * the catalog plus, in portal mode, the business pages (1.8.0).
 */
final class CatalogSitemapProvider extends WP_Sitemaps_Provider {

	/**
	 * Provider name and object type.
	 */
	public function __construct() {
		$this->name        = CatalogSitemap::PROVIDER;
		$this->object_type = CatalogSitemap::PROVIDER;
	}

	/**
	 * URL list of a page.
	 *
	 * @param int    $page_num       Page number (only 1 has entries).
	 * @param string $object_subtype Unused.
	 * @return array<int, array<string, string>>
	 */
	public function get_url_list( $page_num, $object_subtype = '' ) {
		if ( 1 !== (int) $page_num ) {
			return array();
		}
		return array_map( static fn( array $url ): array => array_filter( $url, static fn( string $value ): bool => '' !== $value ), CatalogSitemap::urls() );
	}

	/**
	 * Number of pages.
	 *
	 * @param string $object_subtype Unused.
	 */
	public function get_max_num_pages( $object_subtype = '' ) {
		return array() === CatalogSitemap::urls() ? 0 : 1;
	}
}
