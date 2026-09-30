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
use AIHazirSite\Core\Portal\Business;
use AIHazirSite\WordPress\Catalog\Admin\CatalogAdmin;
use AIHazirSite\WordPress\Catalog\CatalogReader;
use AIHazirSite\WordPress\Catalog\WpProfileRepository;
use AIHazirSite\WordPress\I18n\Multilingual;
use AIHazirSite\WordPress\Llms\LlmsModule;
use AIHazirSite\WordPress\Platform\PageCache;
use AIHazirSite\WordPress\Platform\WpClock;
use AIHazirSite\WordPress\Portal\Portal;
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
		PageCache::exclude();
		status_header( 200 );
		header( 'Content-Type: text/html; charset=utf-8' );
		echo self::render_html( self::language() ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in render_html().
		exit;
	}

	/**
	 * Page language from ?lang= (else the default), or null while multilingual output is inactive.
	 * Each language has its own address, so the Accept-Language header does not choose here.
	 */
	public static function language(): ?string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Public read-only page.
		$requested = isset( $_GET['lang'] ) && is_string( $_GET['lang'] ) ? sanitize_text_field( wp_unslash( $_GET['lang'] ) ) : null;
		return Multilingual::language( $requested );
	}

	/**
	 * Full HTML document (everything escaped here). With a language (1.1.0): translated values,
	 * default-language fallbacks marked with the HTML lang attribute, hreflang alternates.
	 *
	 * @param string|null   $language Page language, or null for single-language output.
	 * @param Business|null $business One business's page (1.2.0 portal mode), or null for the whole catalog.
	 */
	public static function render_html( ?string $language = null, ?Business $business = null ): string {
		$profile  = ( new WpProfileRepository() )->get();
		$all      = SchemaModule::listings();
		$markers  = array();
		$sector   = false;
		$fallback = Multilingual::settings()->default;
		if ( null !== $language ) {
			$localized         = Multilingual::profile( $profile, $language );
			$profile           = $localized->record instanceof CompanyProfile ? $localized->record : $profile;
			$sector            = in_array( 'sector', $localized->missing, true );
			[ $all, $markers ] = CatalogReader::localized( $all, $language );
		}
		$owners = Portal::active() ? Portal::owners( SchemaModule::listings() ) : array();
		if ( null !== $business ) {
			$profile = $business->profile;
			$sector  = false;
			$all     = array_values( array_filter( $all, static fn( $l ): bool => null !== $l->id && ( $owners[ $l->id ]->id ?? null ) === $business->id ) );
		}
		$mark   = static fn( string $escaped, bool $missing ): string => $missing ? '<span lang="' . esc_attr( $fallback ) . '">' . $escaped . '</span>' : $escaped;
		$today  = ( new WpClock() )->today();
		$labels = CatalogAdmin::type_labels();
		$title  = sprintf(
			/* translators: %s: company name. */
			__( 'AI Katalog – %s', 'ai-hazir-site' ),
			'' === $profile->name ? get_bloginfo( 'name' ) : $profile->name
		);

		$llms     = LlmsModule::labels();
		$details  = new LlmsTxtBuilder( home_url( '/' ), SchemaModule::catalog_url(), '', LlmsModule::labels(), TemplatesModule::registry() );
		$now      = TemplatesModule::now();
		$sections = '';
		$by_type  = array_fill_keys( ListingType::ALL, array() );
		foreach ( $all as $listing ) {
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
				$missing = $markers[ (int) $listing->id ]['missing'] ?? array();
				$lines   = array();
				foreach ( $details->details( $listing, $today, $now ) as $label => $value ) {
					$field   = array_search(
						$label,
						array(
							'category' => $llms['category'],
							'region'   => $llms['region'],
						),
						true
					);
					$lines[] = false !== $field && in_array( $field, $missing, true ) ? esc_html( $label . ': ' ) . $mark( esc_html( $value ), true ) : esc_html( $label . ': ' . $value );
				}
				$sections .= '<li' . ( null === $listing->id ? '' : ' id="ilan-' . (int) $listing->id . '"' ) . '><strong>' . $mark( esc_html( $listing->title ), in_array( 'title', $missing, true ) ) . '</strong>'
					. ( '' !== $listing->description ? ' – ' . $mark( esc_html( $listing->description ), in_array( 'description', $missing, true ) ) : '' )
					. '<br><small>' . implode( '; ', $lines ) . '</small>'
					. ( null === $business && isset( $owners[ (int) $listing->id ] ) ? '<br><small>' . esc_html__( 'İşletme', 'ai-hazir-site' ) . ': <a href="' . esc_url( Portal::page_url( $owners[ (int) $listing->id ] ) ) . '">' . esc_html( $owners[ (int) $listing->id ]->profile->name ) . '</a></small>' : '' )
					. '</li>';
			}
			$sections .= '</ul></section>';
		}

		return '<!doctype html><html lang="' . esc_attr( $language ?? get_bloginfo( 'language' ) ) . '"><head><meta charset="utf-8">'
			. '<title>' . esc_html( $title ) . '</title>'
			. '<meta name="viewport" content="width=device-width, initial-scale=1">'
			. '<link rel="canonical" href="' . esc_url( null === $business ? SchemaModule::catalog_url( $language ) : Portal::page_url( $business ) ) . '">'
			. ( null === $language || null !== $business ? '' : self::alternates() )
			. ( null === $business ? self::markdown_alternate( $language ) : '' )
			. ( Features::is_enabled( Features::SCHEMA_OUTPUT ) ? SchemaModule::script( SchemaModule::catalog_document( $language, $business ) ) : '' )
			. '</head><body><main><h1>' . esc_html( $title ) . '</h1>'
			. self::company( $profile, $sector ? $fallback : null )
			. ( null === $business && Portal::active() ? self::directory() : '' )
			. ( '' === $sections ? '<p>' . esc_html__( 'Şu anda yayında ilan yok.', 'ai-hazir-site' ) . '</p>' : $sections )
			. '</main></body></html>';
	}

	/**
	 * Portal: the business directory (name, sector, link to the business page).
	 */
	private static function directory(): string {
		$businesses = Portal::businesses()->businesses();
		if ( array() === $businesses ) {
			return '';
		}
		$html = '<section id="aihs-businesses"><h2>' . esc_html__( 'İşletmeler', 'ai-hazir-site' ) . '</h2><ul>';
		foreach ( $businesses as $business ) {
			$html .= '<li><a href="' . esc_url( Portal::page_url( $business ) ) . '">' . esc_html( $business->profile->name ) . '</a>' . ( '' === $business->profile->sector ? '' : ' – ' . esc_html( $business->profile->sector ) ) . '</li>';
		}
		return $html . '</ul></section>';
	}

	/**
	 * The Markdown version of this page (1.18.0): our llms.txt carries the same catalog, so it is linked the way the
	 * llms.txt proposal recommends, rel="alternate" type="text/markdown". Only while llms.txt is served by us.
	 *
	 * @param string|null $language Page language, or null.
	 */
	private static function markdown_alternate( ?string $language ): string {
		if ( ! Features::is_enabled( Features::LLMS_TXT ) || null !== LlmsModule::physical_file() ) {
			return '';
		}
		$url = home_url( '/' . LlmsModule::FILE );
		return '<link rel="alternate" type="text/markdown" href="' . esc_url( null === $language ? $url : add_query_arg( 'lang', $language, $url ) ) . '">';
	}

	/**
	 * Links (hreflang) to every language version, plus x-default (Google's localized-versions guide).
	 */
	private static function alternates(): string {
		$html = '';
		foreach ( Multilingual::settings()->languages as $code ) {
			$html .= '<link rel="alternate" hreflang="' . esc_attr( $code ) . '" href="' . esc_url( SchemaModule::catalog_url( $code ) ) . '">';
		}
		return $html . '<link rel="alternate" hreflang="x-default" href="' . esc_url( SchemaModule::catalog_url() ) . '">';
	}

	/**
	 * Company summary and contact (empty fields left out).
	 *
	 * @param CompanyProfile $profile         Profile.
	 * @param string|null    $sector_fallback Language of an untranslated sector (1.1.0), or null.
	 */
	private static function company( CompanyProfile $profile, ?string $sector_fallback = null ): string {
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
			$html .= null !== $sector_fallback && __( 'Sektör', 'ai-hazir-site' ) === $label
				? '<li>' . esc_html( $label . ': ' ) . '<span lang="' . esc_attr( $sector_fallback ) . '">' . esc_html( $value ) . '</span></li>'
				: '<li>' . esc_html( $label . ': ' . $value ) . '</li>';
		}
		return '<ul>' . $html . '</ul>';
	}
}
