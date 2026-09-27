<?php
/**
 * The virtual /ai-katalog/ page (extended by A3).
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\WordPress\Schema;

use AIHazirSite\Core\Catalog\ListingType;
use AIHazirSite\WordPress\Catalog\Admin\CatalogAdmin;
use AIHazirSite\WordPress\Catalog\WpProfileRepository;
use AIHazirSite\WordPress\Platform\WpClock;

/**
 * A minimal, JavaScript-free HTML page: the company, then its current listings by type,
 * with the DataFeed JSON-LD in the head. Expired listings are not shown.
 */
final class CatalogPage {

	/**
	 * `template_redirect`: renders the page for /ai-katalog/ and stops.
	 */
	public static function maybe_render(): void {
		if ( ! get_query_var( SchemaModule::QUERY_VAR ) ) {
			return;
		}
		status_header( 200 );
		header( 'Content-Type: text/html; charset=utf-8' );
		echo self::render_html(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in render_html().
		exit;
	}

	/**
	 * Full HTML document (everything escaped here).
	 */
	public static function render_html(): string {
		$profile = ( new WpProfileRepository() )->get();
		$today   = ( new WpClock() )->today();
		$labels  = CatalogAdmin::type_labels();
		$title   = sprintf(
			/* translators: %s: company name. */
			__( 'AI Katalog – %s', 'ai-hazir-site' ),
			'' === $profile->name ? get_bloginfo( 'name' ) : $profile->name
		);

		$sections = '';
		$by_type  = array_fill_keys( ListingType::ALL, array() );
		foreach ( SchemaModule::listings() as $listing ) {
			if ( null !== SchemaModule::builder()->entry( $listing, $today ) ) {
				$by_type[ $listing->type ][] = $listing;
			}
		}
		foreach ( $by_type as $type => $listings ) {
			if ( array() === $listings ) {
				continue;
			}
			$sections .= '<section><h2>' . esc_html( $labels[ $type ] ) . '</h2><ul>';
			foreach ( $listings as $listing ) {
				$sections .= '<li><strong>' . esc_html( $listing->title ) . '</strong>'
					. ( '' !== $listing->description ? ' – ' . esc_html( $listing->description ) : '' )
					. '</li>';
			}
			$sections .= '</ul></section>';
		}

		return '<!doctype html><html lang="' . esc_attr( get_bloginfo( 'language' ) ) . '"><head><meta charset="utf-8">'
			. '<title>' . esc_html( $title ) . '</title>'
			. '<meta name="viewport" content="width=device-width, initial-scale=1">'
			. '<link rel="canonical" href="' . esc_url( SchemaModule::catalog_url() ) . '">'
			. SchemaModule::script( SchemaModule::catalog_document() )
			. '</head><body><main><h1>' . esc_html( $title ) . '</h1>'
			. ( '' === $sections ? '<p>' . esc_html__( 'Şu anda yayında ilan yok.', 'ai-hazir-site' ) . '</p>' : $sections )
			. '</main></body></html>';
	}
}
