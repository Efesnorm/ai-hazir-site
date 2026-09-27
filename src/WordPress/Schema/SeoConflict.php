<?php
/**
 * Detects SEO plugins that already output an Organization node.
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\WordPress\Schema;

/**
 * Recognized plugins (all emit Organization in their JSON-LD graph by default) and the
 * constant each defines when active. Others can be reported through the
 * `aihs_schema_seo_conflict` filter (return the plugin name, or '' for none).
 */
final class SeoConflict {

	/**
	 * Constant → plugin name.
	 */
	public const KNOWN = array(
		'WPSEO_VERSION'             => 'Yoast SEO',
		'RANK_MATH_VERSION'         => 'Rank Math',
		'AIOSEO_VERSION'            => 'All in One SEO',
		'SEOPRESS_VERSION'          => 'SEOPress',
		'THE_SEO_FRAMEWORK_VERSION' => 'The SEO Framework',
	);

	/**
	 * Name of the active plugin that owns Organization, or null.
	 */
	public static function detect(): ?string {
		$found = '';
		foreach ( self::KNOWN as $constant => $name ) {
			if ( defined( $constant ) ) {
				$found = $name;
				break;
			}
		}

		/**
		 * Filters the detected SEO plugin that outputs Organization schema.
		 *
		 * @param string $found Plugin name, or '' when none.
		 */
		// Third-party callbacks may return null or false; normalize to a string ('' = no conflict).
		$found = (string) apply_filters( 'aihs_schema_seo_conflict', $found );

		return '' === $found ? null : $found;
	}
}
