<?php
/**
 * The virtual /ai-katalog/ page (extended by A3).
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\WordPress\Schema;

use AIHazirSite\Adapters\Llms\LlmsTxtBuilder;
use AIHazirSite\Core\Catalog\CompanyProfile;
use AIHazirSite\Core\Catalog\ListingType;
use AIHazirSite\Core\Catalog\ListingValidity;
use AIHazirSite\Core\Features;
use AIHazirSite\WordPress\Catalog\Admin\CatalogAdmin;
use AIHazirSite\WordPress\Catalog\WpProfileRepository;
use AIHazirSite\WordPress\Llms\LlmsModule;
use AIHazirSite\WordPress\Platform\WpClock;
use AIHazirSite\WordPress\Templates\TemplatesModule;

/**
 * A minimal, JavaScript-free HTML page: the company, then its current listings by type
 * (each with an "ilan-{id}" anchor that llms.txt links to), with the DataFeed JSON-LD in
 * the head while `schema_output` is on. Expired listings are not shown (ListingValidity).
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

		$details  = new LlmsTxtBuilder( home_url( '/' ), SchemaModule::catalog_url(), '', LlmsModule::labels(), TemplatesModule::registry() );
		$now      = TemplatesModule::now();
		$sections = '';
		$by_type  = array_fill_keys( ListingType::ALL, array() );
		foreach ( SchemaModule::listings() as $listing ) {
			if ( ListingValidity::is_current( $listing, $today ) ) {
				$by_type[ $listing->type ][] = $listing;
			}
		}
		foreach ( $by_type as $type => $listings ) {
			if ( array() === $listings ) {
				continue;
			}
			$sections .= '<section><h2>' . esc_html( $labels[ $type ] ) . '</h2><ul>';
			foreach ( $listings as $listing ) {
				$lines = array();
				foreach ( $details->details( $listing, $today, $now ) as $label => $value ) {
					$lines[] = esc_html( $label . ': ' . $value );
				}
				$sections .= '<li' . ( null === $listing->id ? '' : ' id="ilan-' . (int) $listing->id . '"' ) . '><strong>' . esc_html( $listing->title ) . '</strong>'
					. ( '' !== $listing->description ? ' – ' . esc_html( $listing->description ) : '' )
					. '<br><small>' . implode( '; ', $lines ) . '</small></li>';
			}
			$sections .= '</ul></section>';
		}

		return '<!doctype html><html lang="' . esc_attr( get_bloginfo( 'language' ) ) . '"><head><meta charset="utf-8">'
			. '<title>' . esc_html( $title ) . '</title>'
			. '<meta name="viewport" content="width=device-width, initial-scale=1">'
			. '<link rel="canonical" href="' . esc_url( SchemaModule::catalog_url() ) . '">'
			. ( Features::is_enabled( Features::SCHEMA_OUTPUT ) ? SchemaModule::script( SchemaModule::catalog_document() ) : '' )
			. '</head><body><main><h1>' . esc_html( $title ) . '</h1>'
			. self::company( $profile )
			. ( '' === $sections ? '<p>' . esc_html__( 'Şu anda yayında ilan yok.', 'ai-hazir-site' ) . '</p>' : $sections )
			. '</main></body></html>';
	}

	/**
	 * Company summary and contact (empty fields left out).
	 *
	 * @param CompanyProfile $profile Profile.
	 */
	private static function company( CompanyProfile $profile ): string {
		$labels = LlmsModule::labels();
		$items  = array_filter(
			array(
				__( 'Sektör', 'ai-hazir-site' ) => $profile->sector,
				$labels['country']              => $profile->country,
				$labels['languages']            => implode( ', ', $profile->languages ),
				$labels['certifications']       => implode( ', ', $profile->certifications ),
				$labels['email']                => $profile->contact_email,
				$labels['phone']                => $profile->contact_phone,
			),
			static fn( string $value ): bool => '' !== $value
		);
		if ( array() === $items ) {
			return '';
		}
		$html = '';
		foreach ( $items as $label => $value ) {
			$html .= '<li>' . esc_html( $label . ': ' . $value ) . '</li>';
		}
		return '<ul>' . $html . '</ul>';
	}
}
