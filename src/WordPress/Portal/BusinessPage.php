<?php
/**
 * /ai-katalog/isletme/{slug}/.
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\WordPress\Portal;

use AIHazirSite\WordPress\Schema\CatalogPage;

/**
 * One business's AI catalog page (its profile, current listings and JSON-LD). Its own path
 * lets A0 measurement count AI bot reads per business.
 */
final class BusinessPage {

	/**
	 * `template_redirect`: renders the page and stops; 404 for an unknown slug.
	 */
	public static function maybe_render(): void {
		$slug = get_query_var( Portal::QUERY_VAR );
		if ( ! is_string( $slug ) || '' === $slug || ! PortalModule::pages_enabled() ) {
			return;
		}
		$business = Portal::businesses()->business_by_slug( sanitize_title( $slug ) );
		if ( null === $business ) {
			global $wp_query;
			$wp_query->set_404();
			status_header( 404 );
			return;
		}
		status_header( 200 );
		header( 'Content-Type: text/html; charset=utf-8' );
		echo CatalogPage::render_html( CatalogPage::language(), $business ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in render_html().
		exit;
	}
}
