<?php
/**
 * Keeps page-cache plugins away from our live outputs.
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\WordPress\Platform;

/**
 * Our public outputs (/ai-katalog/, business pages, /llms.txt, the verification page, the A2A card)
 * change whenever the catalog changes, but page-cache plugins do not know that: a stored copy kept
 * serving the old page (found on a live site with WP Rocket, 1.6.1).
 *
 * DONOTCACHEPAGE is the shared convention page-cache plugins honor (WP Rocket, LiteSpeed Cache,
 * W3 Total Cache, WP Super Cache, WP Fastest Cache), so no plugin-specific purge calls are needed.
 */
final class PageCache {

	/**
	 * Marks the current response as not cacheable.
	 */
	public static function exclude(): void {
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- Shared page-cache convention.
		}
	}
}
