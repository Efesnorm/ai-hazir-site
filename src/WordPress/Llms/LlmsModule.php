<?php
/**
 * The llms.txt file (A3) – WordPress wiring.
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\WordPress\Llms;

use AIHazirSite\Adapters\Llms\LlmsCache;
use AIHazirSite\Adapters\Llms\LlmsTxtBuilder;
use AIHazirSite\Core\Catalog\ListingType;
use AIHazirSite\Core\Features;
use AIHazirSite\WordPress\Catalog\WpProfileRepository;
use AIHazirSite\WordPress\Module;
use AIHazirSite\WordPress\Platform\WpClock;
use AIHazirSite\WordPress\Platform\WpSettings;
use AIHazirSite\WordPress\Rest\RestModule;
use AIHazirSite\WordPress\Schema\SchemaModule;
use AIHazirSite\WordPress\Templates\TemplatesModule;

/**
 * Serves /llms.txt while `llms_txt` is on. The request path is matched directly on
 * `parse_request` (WP::parse_request only matches rewrite rules with pretty permalinks,
 * but the action always fires), so it works with any permalink setting. Priority 20 runs
 * after the A0 counter (priority 0), which therefore still counts the request.
 * A physical llms.txt is never touched; the web server serves it and admins are warned.
 */
final class LlmsModule implements Module {

	public const FILE = 'llms.txt';

	/**
	 * Response headers.
	 */
	public const HEADERS = array(
		'Content-Type: text/plain; charset=utf-8',
		'X-Robots-Tag: noindex',
	);

	/**
	 * Registers hooks.
	 */
	public function register(): void {
		if ( ! Features::is_enabled( Features::LLMS_TXT ) ) {
			return;
		}
		add_action( 'parse_request', array( self::class, 'maybe_serve' ), 20 );
		add_action( 'admin_notices', array( self::class, 'admin_notice' ) );
	}

	/**
	 * Nothing to clean up (no rewrite rule, no scheduled event).
	 */
	public function deactivate(): void {
	}

	/**
	 * `parse_request`: serves the text for /llms.txt and stops.
	 */
	public static function maybe_serve(): void {
		$uri  = isset( $_SERVER['REQUEST_URI'] ) && is_string( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Only compared with a fixed path.
		$text = self::response( $uri );
		if ( null === $text ) {
			return;
		}
		status_header( 200 );
		foreach ( self::HEADERS as $header ) {
			header( $header );
		}
		echo $text; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Plain text (text/plain), not HTML.
		exit;
	}

	/**
	 * The text to serve for a request URI, or null when this request is not ours.
	 *
	 * @param string $uri Request URI (path and query).
	 */
	public static function response( string $uri ): ?string {
		$path = wp_parse_url( $uri, PHP_URL_PATH );
		if ( ! is_string( $path ) || self::path() !== $path || null !== self::physical_file() ) {
			return null;
		}
		return self::text();
	}

	/**
	 * Path of /llms.txt under the site home (e.g. "/llms.txt" or "/blog/llms.txt").
	 */
	public static function path(): string {
		$home = wp_parse_url( home_url( '/' ), PHP_URL_PATH );
		return trailingslashit( is_string( $home ) ? $home : '/' ) . self::FILE;
	}

	/**
	 * The llms.txt text (from the cache unless the data changed).
	 */
	public static function text(): string {
		$profile  = ( new WpProfileRepository() )->get();
		$listings = SchemaModule::listings();
		$today    = ( new WpClock() )->today();
		$modified = (string) SchemaModule::last_modified( $listings );
		$registry = TemplatesModule::registry();
		$api_url  = Features::is_enabled( Features::REST_API ) ? RestModule::url() : '';
		$builder  = new LlmsTxtBuilder( home_url( '/' ), SchemaModule::catalog_url(), (string) get_bloginfo( 'name' ), self::labels(), $registry, $api_url );
		$now      = TemplatesModule::now();

		// Short-lived values can turn stale within a day: then the text depends on the hour too.
		$hourly = false;
		foreach ( $listings as $listing ) {
			$hourly = $hourly || ( null !== $registry && $registry->get( $listing->template )->has_freshness() );
		}

		$input = array(
			'profile'  => $profile->to_array(),
			'listings' => array_map( static fn( $l ): array => $l->to_array(), $listings ),
			'today'    => $today,
			'hour'     => $hourly ? substr( $now, 0, 13 ) : '',
			'template' => null === $registry ? array() : array_map( static fn( $t ): array => array( $t->id, $t->version ), array_values( $registry->all() ) ),
			'modified' => $modified,
			'site'     => array( home_url( '/' ), SchemaModule::catalog_url(), get_bloginfo( 'name' ), $api_url ),
			'labels'   => self::labels(),
		);

		return self::cache()->text( $input, static fn(): string => $builder->build( $profile, $listings, $today, $modified, $now ) );
	}

	/**
	 * Text cache.
	 */
	public static function cache(): LlmsCache {
		return new LlmsCache( new WpSettings() );
	}

	/**
	 * Path of a physical llms.txt in the site root, or null. When it exists the web server
	 * serves it directly and WordPress never runs for /llms.txt.
	 */
	public static function physical_file(): ?string {
		if ( ! function_exists( 'get_home_path' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		$path = trailingslashit( get_home_path() ) . self::FILE;
		return file_exists( $path ) ? $path : null;
	}

	/**
	 * Warning when a physical llms.txt hides ours, with the text it would publish.
	 */
	public static function admin_notice(): void {
		if ( ! current_user_can( 'manage_options' ) || null === self::physical_file() ) {
			return;
		}
		printf(
			'<div class="notice notice-warning"><p>%s</p><details><summary>%s</summary><pre style="white-space:pre-wrap">%s</pre></details></div>',
			esc_html__( 'AI Hazır Site: Sitenin kök dizininde bir llms.txt dosyası var; sunucu onu gösterdiği için AI Hazır Site kendi llms.txt çıktısını yayınlamıyor ve dosyaya dokunmuyor. Kendi içeriğimizi kullanmak için dosyayı kaldırın ya da aşağıdaki metni dosyaya ekleyin.', 'ai-hazir-site' ),
			esc_html__( 'Eklenecek metin', 'ai-hazir-site' ),
			esc_html( self::text() )
		);
	}

	/**
	 * Translated texts for the builder.
	 *
	 * @return array<string, string>
	 */
	public static function labels(): array {
		return array(
			ListingType::OFFER  => __( 'Satılanlar', 'ai-hazir-site' ),
			ListingType::DEMAND => __( 'Arananlar', 'ai-hazir-site' ),
			ListingType::SUPPLY => __( 'Tedarik edilebilenler', 'ai-hazir-site' ),
			/* translators: %s: company name (and sector). */
			'summary'           => __( '%s: sattığı, aradığı ve tedarik edebildiği ürünlerin güncel özeti.', 'ai-hazir-site' ),
			'country'           => __( 'Ülke', 'ai-hazir-site' ),
			'languages'         => __( 'Diller', 'ai-hazir-site' ),
			'certifications'    => __( 'Sertifikalar', 'ai-hazir-site' ),
			/* translators: 1: value, 2: time of the last confirmation. */
			'unverified'        => __( '%1$s (doğrulanmadı, son güncelleme %2$s)', 'ai-hazir-site' ),
			'updated'           => __( 'Son güncelleme', 'ai-hazir-site' ),
			'category'          => __( 'Kategori', 'ai-hazir-site' ),
			'quantity'          => __( 'Miktar', 'ai-hazir-site' ),
			'price'             => __( 'Fiyat', 'ai-hazir-site' ),
			'budget'            => __( 'Bütçe', 'ai-hazir-site' ),
			'region'            => __( 'Bölge', 'ai-hazir-site' ),
			'lead_time'         => __( 'Teslim süresi', 'ai-hazir-site' ),
			/* translators: %d: number of days. */
			'days'              => __( '%d gün', 'ai-hazir-site' ),
			'valid_until'       => __( 'Geçerlilik', 'ai-hazir-site' ),
			'attributes'        => __( 'Özellikler', 'ai-hazir-site' ),
			'contact'           => __( 'İletişim', 'ai-hazir-site' ),
			'website'           => __( 'Web sitesi', 'ai-hazir-site' ),
			'email'             => __( 'E-posta', 'ai-hazir-site' ),
			'phone'             => __( 'Telefon', 'ai-hazir-site' ),
			'catalog'           => __( 'AI Katalog', 'ai-hazir-site' ),
			'catalog_note'      => __( 'Tüm geçerli ilanların sade HTML listesi', 'ai-hazir-site' ),
			'api'               => __( 'AI Katalog API (JSON)', 'ai-hazir-site' ),
			/* translators: %s: JSON schema URL. */
			'api_note'          => __( 'Geçerli ilanlar, sayfalı; şema: %s', 'ai-hazir-site' ),
			'api_templates'     => __( 'Şablon alan tanımları (JSON)', 'ai-hazir-site' ),
		);
	}
}
