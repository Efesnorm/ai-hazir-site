<?php
/**
 * Schema.org output (A2) – WordPress wiring.
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\WordPress\Schema;

use AIHazirSite\Adapters\Schema\SchemaBuilder;
use AIHazirSite\Adapters\Schema\SchemaCache;
use AIHazirSite\Core\Catalog\Listing;
use AIHazirSite\Core\Catalog\ListingType;
use AIHazirSite\Core\Features;
use AIHazirSite\WordPress\Catalog\WpListingRepository;
use AIHazirSite\WordPress\Catalog\WpProfileRepository;
use AIHazirSite\WordPress\Module;
use AIHazirSite\WordPress\Platform\WpClock;
use AIHazirSite\WordPress\Platform\WpSettings;
use AIHazirSite\WordPress\Templates\TemplatesModule;

/**
 * Publishes Organization + WebPage on the home page and a DataFeed on /ai-katalog/ while
 * `schema_output` is on. Invalid output is never published (SchemaCache serves the last valid one).
 * The /ai-katalog/ page itself is served while `schema_output` or `llms_txt` is on
 * (llms.txt links to it); its JSON-LD only with `schema_output`.
 */
final class SchemaModule implements Module {

	public const QUERY_VAR = 'aihs_catalog';
	public const SLUG      = 'ai-katalog';
	public const REWRITE   = '^ai-katalog/?$';

	/**
	 * Registers hooks.
	 */
	public function register(): void {
		add_action( 'init', array( self::class, 'rewrite' ), 20 );
		if ( self::catalog_enabled() ) {
			add_filter( 'query_vars', array( self::class, 'query_vars' ) );
			add_action( 'template_redirect', array( CatalogPage::class, 'maybe_render' ) );
		}
		if ( ! Features::is_enabled( Features::SCHEMA_OUTPUT ) ) {
			return;
		}
		add_action( 'wp_head', array( self::class, 'print_home' ), 20 );
		add_action( 'admin_notices', array( self::class, 'admin_notice' ) );
	}

	/**
	 * Removes the rewrite rule on deactivation.
	 */
	public function deactivate(): void {
		flush_rewrite_rules( false );
	}

	/**
	 * Whether the /ai-katalog/ page is served.
	 */
	public static function catalog_enabled(): bool {
		return Features::is_enabled( Features::SCHEMA_OUTPUT ) || Features::is_enabled( Features::LLMS_TXT );
	}

	/**
	 * Adds (page on) or drops (page off) the /ai-katalog/ rule; flushes only when it changes.
	 */
	public static function rewrite(): void {
		$enabled = self::catalog_enabled();
		if ( $enabled ) {
			add_rewrite_rule( self::REWRITE, 'index.php?' . self::QUERY_VAR . '=1', 'top' );
		}
		$rules = get_option( 'rewrite_rules' );
		$has   = is_array( $rules ) && isset( $rules[ self::REWRITE ] );
		if ( $enabled !== $has ) {
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
		$vars[] = self::QUERY_VAR;
		return $vars;
	}

	/**
	 * Catalog page URL.
	 */
	public static function catalog_url(): string {
		return home_url( '/' . self::SLUG . '/' );
	}

	/**
	 * Builder for this site.
	 */
	public static function builder(): SchemaBuilder {
		return new SchemaBuilder( home_url( '/' ), null === SeoConflict::detect(), TemplatesModule::registry() );
	}

	/**
	 * Last-valid-output cache.
	 */
	public static function cache(): SchemaCache {
		return new SchemaCache( new WpSettings() );
	}

	/**
	 * All listings of every type.
	 *
	 * @return list<\AIHazirSite\Core\Catalog\Listing>
	 */
	public static function listings(): array {
		$repository = new WpListingRepository();
		$all        = array();
		foreach ( ListingType::ALL as $type ) {
			array_push( $all, ...$repository->all( $type ) );
		}
		return $all;
	}

	/**
	 * Home page JSON-LD (validated), or null when nothing may be published.
	 *
	 * @return array<string, mixed>|null
	 */
	public static function home_document(): ?array {
		if ( null !== SeoConflict::detect() ) {
			return null;
		}
		$modified = self::last_modified( self::listings() ) ?? gmdate( 'Y-m-d\TH:i:s\Z' );

		return self::cache()->publish( 'home', self::builder()->home( ( new WpProfileRepository() )->get(), $modified ), gmdate( 'Y-m-d\TH:i:s\Z' ) );
	}

	/**
	 * Latest change of the profile or of any listing (ISO 8601), or null when unknown.
	 *
	 * @param Listing[] $listings Listings.
	 *
	 * @phpstan-param list<Listing> $listings
	 */
	public static function last_modified( array $listings ): ?string {
		$dates = array_filter( array_merge( array( ( new WpProfileRepository() )->updated_at() ), array_map( static fn( $l ): ?string => $l->updated_at, $listings ) ) );
		rsort( $dates );
		return isset( $dates[0] ) ? (string) $dates[0] : null;
	}

	/**
	 * Catalog page JSON-LD (validated), or null.
	 *
	 * @return array<string, mixed>|null
	 */
	public static function catalog_document(): ?array {
		$document = self::builder()->catalog( ( new WpProfileRepository() )->get(), self::listings(), ( new WpClock() )->today(), self::catalog_url(), TemplatesModule::now() );
		return self::cache()->publish( 'catalog', $document, gmdate( 'Y-m-d\TH:i:s\Z' ) );
	}

	/**
	 * `wp_head` on the front page.
	 */
	public static function print_home(): void {
		if ( ! is_front_page() ) {
			return;
		}
		echo self::script( self::home_document() ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON encoded with JSON_HEX_TAG in script().
	}

	/**
	 * A JSON-LD script tag ('' for null). JSON_HEX_TAG keeps "</script>" in data harmless.
	 *
	 * @param array<string, mixed>|null $document Document.
	 */
	public static function script( ?array $document ): string {
		if ( null === $document ) {
			return '';
		}
		$json = wp_json_encode( $document, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP );
		return false === $json ? '' : "<script type=\"application/ld+json\">{$json}</script>\n";
	}

	/**
	 * Admin notice for validation errors and SEO plugin conflicts.
	 */
	public static function admin_notice(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		foreach ( self::cache()->errors() as $page => $record ) {
			printf(
				'<div class="notice notice-error"><p>%s</p><ul>%s</ul></div>',
				esc_html(
					sprintf(
						/* translators: 1: page key, 2: time. */
						__( 'AI Hazır Site: "%1$s" sayfasının Schema.org çıktısında hata bulundu (%2$s). Hatalı çıktı yayınlanmadı; son geçerli çıktı sunuluyor.', 'ai-hazir-site' ),
						$page,
						$record['at']
					)
				),
				implode( '', array_map( static fn( string $e ): string => '<li>' . esc_html( $e ) . '</li>', $record['errors'] ) ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Each item escaped.
			);
		}

		$conflict = SeoConflict::detect();
		if ( null !== $conflict ) {
			printf(
				'<div class="notice notice-warning"><p>%s</p></div>',
				esc_html(
					sprintf(
						/* translators: %s: SEO plugin name. */
						__( 'AI Hazır Site: %s zaten Organization şeması üretiyor; çakışmayı önlemek için AI Hazır Site kendi Organization çıktısını eklemiyor. AI katalog ilanları yayınlanmaya devam ediyor.', 'ai-hazir-site' ),
						$conflict
					)
				)
			);
		}
	}
}
