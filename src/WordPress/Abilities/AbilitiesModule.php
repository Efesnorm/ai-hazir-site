<?php
/**
 * Catalog abilities (A6) – WordPress Abilities API wiring.
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\WordPress\Abilities;

use AIHazirSite\Adapters\Abilities\AbilitySchemas;
use AIHazirSite\Adapters\Rest\RestResponder;
use AIHazirSite\Core\Catalog\CompanyProfile;
use AIHazirSite\Core\Catalog\Query\Availability;
use AIHazirSite\Core\Catalog\Query\ListingSearch;
use AIHazirSite\Core\Features;
use AIHazirSite\Core\RateLimit\FixedWindowLimiter;
use AIHazirSite\Core\Templates\Template;
use AIHazirSite\WordPress\Catalog\CatalogReader;
use AIHazirSite\WordPress\Catalog\WpProfileRepository;
use AIHazirSite\WordPress\I18n\Multilingual;
use AIHazirSite\WordPress\Module;
use AIHazirSite\WordPress\Platform\WpCache;
use AIHazirSite\WordPress\Platform\WpClock;
use AIHazirSite\WordPress\Platform\WpSecret;
use AIHazirSite\WordPress\Templates\TemplatesModule;
use WP_Error;

/**
 * Four read-only abilities in the `aihs-catalog` category while `abilities` is on. They are thin:
 * the core query service answers, the REST body producer shapes the answer (same as REST).
 * Public read (the permission callback allows everyone); each call is counted against a per-client
 * limit (60 a minute) inside the execute callback, because WordPress hides an error returned by a
 * permission callback behind a generic "no permission" answer.
 * Not exposed in the REST abilities endpoints (the catalog has its own REST API, A5).
 */
final class AbilitiesModule implements Module {

	public const CATEGORY      = 'aihs-catalog';
	public const DEFAULT_LIMIT = 60;

	/**
	 * Registers hooks.
	 */
	public function register(): void {
		if ( ! Features::is_enabled( Features::ABILITIES ) || ! function_exists( 'wp_register_ability' ) ) {
			return;
		}
		add_action( 'wp_abilities_api_categories_init', array( self::class, 'register_category' ) );
		add_action( 'wp_abilities_api_init', array( self::class, 'register_abilities' ) );
	}

	/**
	 * Nothing to clean up.
	 */
	public function deactivate(): void {
	}

	/**
	 * `wp_abilities_api_categories_init`.
	 */
	public static function register_category(): void {
		wp_register_ability_category(
			self::CATEGORY,
			array(
				'label'       => __( 'AI Katalog', 'ai-hazir-site' ),
				'description' => __( 'Firmanın sattığı, aradığı ve tedarik edebildiği ürün ve hizmetleri okuma.', 'ai-hazir-site' ),
			)
		);
	}

	/**
	 * `wp_abilities_api_init`.
	 */
	public static function register_abilities(): void {
		$texts = array(
			'aihs/get-profile'        => array( __( 'Firma profilini getir', 'ai-hazir-site' ), __( 'Firmanın adı, sektörü, ülkesi, dilleri, sertifikaları ve kurumsal iletişim bilgileri.', 'ai-hazir-site' ), array( self::class, 'get_profile' ) ),
			'aihs/search-listings'    => array( __( 'İlan ara', 'ai-hazir-site' ), __( 'Firmanın geçerli ilanlarını arar: tür (offer satılan, demand aranan, supply tedarik edilebilen), kategori, bölge, anahtar kelime ve şablon alanlarıyla. Stok miktarı, fiyat (varsa), teslim süresi ve geçerlilik tarihi döner.', 'ai-hazir-site' ), array( self::class, 'search_listings' ) ),
			'aihs/get-listing'        => array( __( 'İlanı getir', 'ai-hazir-site' ), __( 'Tek bir geçerli ilanın tüm ayrıntıları. Süresi dolmuş ilan dönmez.', 'ai-hazir-site' ), array( self::class, 'get_listing' ) ),
			'aihs/check-availability' => array( __( 'Müsaitlik sor', 'ai-hazir-site' ), __( 'Bir ilan için "bu miktar var mı, istenen günde gelir mi?" sorusuna yes / no / unknown ve gerekçeleriyle cevap verir. Yalnızca ilandaki bilgiye dayanır; kesin teklif için firmayla iletişime geçin.', 'ai-hazir-site' ), array( self::class, 'check_availability' ) ),
		);

		foreach ( AbilitySchemas::all( Multilingual::active() ? Multilingual::settings()->languages : array() ) as $name => $schemas ) {
			wp_register_ability(
				$name,
				array(
					'label'               => $texts[ $name ][0],
					'description'         => $texts[ $name ][1],
					'category'            => self::CATEGORY,
					'input_schema'        => $schemas['input'],
					'output_schema'       => $schemas['output'],
					'execute_callback'    => $texts[ $name ][2],
					'permission_callback' => array( self::class, 'permission' ),
					'meta'                => array(
						'annotations'  => array(
							'readonly'    => true,
							'destructive' => false,
							'idempotent'  => true,
						),
						'show_in_rest' => false,
					),
				)
			);
		}
	}

	/**
	 * Public read.
	 */
	public static function permission(): bool {
		return true;
	}

	/**
	 * A 429 error when the client is over the limit, else null.
	 */
	public static function limited(): ?WP_Error {
		$ip    = isset( $_SERVER['REMOTE_ADDR'] ) && is_string( $_SERVER['REMOTE_ADDR'] ) ? (string) filter_var( wp_unslash( $_SERVER['REMOTE_ADDR'] ), FILTER_VALIDATE_IP ) : '';
		$limit = (int) apply_filters( 'aihs_abilities_rate_limit', self::DEFAULT_LIMIT );
		$retry = ( new FixedWindowLimiter( new WpCache(), new WpClock(), new WpSecret(), max( 1, $limit ), 60, 'abilities' ) )->hit( $ip );
		if ( null === $retry ) {
			return null;
		}
		return new WP_Error(
			'aihs_rate_limited',
			/* translators: %d: seconds. */
			sprintf( __( 'Çok fazla istek. %d saniye sonra tekrar deneyin.', 'ai-hazir-site' ), $retry ),
			array(
				'status'      => 429,
				'retry_after' => $retry,
			)
		);
	}

	/**
	 * `aihs/get-profile`.
	 *
	 * @param mixed $input Validated input (1.1.0: optional lang).
	 * @return array<string, mixed>|WP_Error
	 */
	public static function get_profile( mixed $input = null ): array|WP_Error {
		$limited = self::limited();
		if ( null !== $limited ) {
			return $limited;
		}
		$profiles = new WpProfileRepository();
		$language = self::language( $input );
		if ( null === $language ) {
			return CatalogReader::responder()->profile( $profiles->get(), $profiles->updated_at() );
		}
		$localized = Multilingual::profile( $profiles->get(), $language );
		$record    = $localized->record instanceof CompanyProfile ? $localized->record : $profiles->get();
		return RestResponder::translated( CatalogReader::responder()->profile( $record, $profiles->updated_at() ), $language, array( 'profile' => $localized->marker() ) );
	}

	/**
	 * Answer language from the input, or null while multilingual output is inactive. Agents choose
	 * explicitly; without `lang` the default language is used.
	 *
	 * @param mixed $input Validated input.
	 */
	private static function language( mixed $input ): ?string {
		$requested = is_array( $input ) && is_string( $input['lang'] ?? null ) ? $input['lang'] : null;
		return Multilingual::language( $requested );
	}

	/**
	 * `aihs/search-listings`.
	 *
	 * @param mixed $input Validated input.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function search_listings( mixed $input = null ): array|WP_Error {
		$limited = self::limited();
		if ( null !== $limited ) {
			return $limited;
		}
		$input      = is_array( $input ) ? $input : array();
		$text       = static fn( string $k ): string => isset( $input[ $k ] ) && is_scalar( $input[ $k ] ) ? (string) $input[ $k ] : '';
		$attributes = array();
		foreach ( is_array( $input['attributes'] ?? null ) ? $input['attributes'] : array() as $key => $value ) {
			$attributes[ (string) $key ] = is_scalar( $value ) ? (string) $value : '';
		}
		$search   = new ListingSearch(
			$text( 'type' ),
			$text( 'category' ),
			$text( 'region' ),
			$text( 'keyword' ),
			$attributes,
			max( 1, (int) ( $input['page'] ?? 1 ) ),
			min( ListingSearch::MAX_PER_PAGE, max( 1, (int) ( $input['per_page'] ?? ListingSearch::DEFAULT_PER_PAGE ) ) )
		);
		$language = self::language( $input );
		if ( null === $language ) {
			return CatalogReader::responder()->listings( CatalogReader::query()->all(), $search, ( new WpClock() )->today(), TemplatesModule::now() );
		}
		[ $listings, $markers ] = CatalogReader::localized( CatalogReader::query()->all(), $language );
		return RestResponder::translated( CatalogReader::responder()->listings( $listings, $search, ( new WpClock() )->today(), TemplatesModule::now() ), $language, $markers );
	}

	/**
	 * `aihs/get-listing`.
	 *
	 * @param mixed $input Validated input.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function get_listing( mixed $input = null ): array|WP_Error {
		$limited = self::limited();
		if ( null !== $limited ) {
			return $limited;
		}
		$id       = is_array( $input ) ? absint( $input['id'] ?? 0 ) : 0;
		$listing  = CatalogReader::query()->find( $id );
		$language = self::language( $input );
		$markers  = array();
		if ( null !== $listing && null !== $language ) {
			[ $records, $markers ] = CatalogReader::localized( array( $listing ), $language );
			$listing               = $records[0] ?? $listing;
		}
		$body = null === $listing ? null : CatalogReader::responder()->listing( $listing, ( new WpClock() )->today(), TemplatesModule::now() );
		if ( null !== $body && null !== $language ) {
			$body = RestResponder::translated( $body, $language, $markers );
		}
		return $body ?? new WP_Error( 'aihs_listing_not_found', __( 'İlan bulunamadı veya süresi doldu.', 'ai-hazir-site' ), array( 'status' => 404 ) );
	}

	/**
	 * `aihs/check-availability`.
	 *
	 * @param mixed $input Validated input.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function check_availability( mixed $input = null ): array|WP_Error {
		$limited = self::limited();
		if ( null !== $limited ) {
			return $limited;
		}
		$input    = is_array( $input ) ? $input : array();
		$listing  = CatalogReader::query()->find( absint( $input['id'] ?? 0 ) );
		$quantity = isset( $input['quantity'] ) && is_numeric( $input['quantity'] ) ? self::decimal( (float) $input['quantity'] ) : null;
		$days     = isset( $input['within_days'] ) && is_numeric( $input['within_days'] ) ? (int) $input['within_days'] : null;
		$registry = TemplatesModule::registry();
		$template = null === $registry || null === $listing ? Template::general() : $registry->get( $listing->template );

		return Availability::check( $listing, $template, $quantity, $days, TemplatesModule::now() );
	}

	/**
	 * A JSON number as a decimal string without exponent ("1500", "2.5").
	 *
	 * @param float $number Number.
	 */
	private static function decimal( float $number ): string {
		$text = rtrim( rtrim( sprintf( '%.4F', max( 0.0, $number ) ), '0' ), '.' );
		return '' === $text ? '0' : $text;
	}
}
