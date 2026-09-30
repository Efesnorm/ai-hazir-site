<?php
/**
 * AI Hazır Site → Çeviriler.
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\WordPress\I18n;

use AIHazirSite\WordPress\Admin\AdminMenu;
use AIHazirSite\Core\Catalog\CompanyProfile;
use AIHazirSite\Core\Catalog\Listing;
use AIHazirSite\Core\Catalog\ListingType;
use AIHazirSite\Core\I18n\LanguageSettings;
use AIHazirSite\Core\I18n\Localizer;
use AIHazirSite\WordPress\Catalog\Admin\CatalogAdmin;
use AIHazirSite\WordPress\Catalog\Admin\FormState;
use AIHazirSite\WordPress\Catalog\CatalogModule;
use AIHazirSite\WordPress\Catalog\WpListingRepository;
use AIHazirSite\WordPress\Catalog\WpProfileRepository;

/**
 * Language setting, profile translation and per-listing translation forms. The catalog forms
 * themselves are not changed; everything is entered in the default language there.
 */
final class TranslationsAdmin {

	public const SLUG              = 'aihs-catalog-translations';
	public const SAVE_LANGUAGES    = 'aihs_save_languages';
	public const SAVE_PROFILE      = 'aihs_save_profile_translation';
	public const SAVE_LISTING      = 'aihs_save_listing_translation';
	public const LISTING_MAX_SHOWN = 200;

	/**
	 * Registers hooks.
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_menu' ), 20 );
		add_action( 'admin_post_' . self::SAVE_LANGUAGES, array( $this, 'on_save_languages' ) );
		add_action( 'admin_post_' . self::SAVE_PROFILE, array( $this, 'on_save_profile' ) );
		add_action( 'admin_post_' . self::SAVE_LISTING, array( $this, 'on_save_listing' ) );
	}

	/**
	 * Adds the submenu under AI Katalog.
	 */
	public function add_menu(): void {
		AdminMenu::add( __( 'Çeviriler', 'ai-hazir-site' ), CatalogAdmin::CAPABILITY, self::SLUG, array( $this, 'render' ) );
	}

	/**
	 * Page URL.
	 *
	 * @param array<string, string|int> $args Extra query args.
	 */
	public static function url( array $args = array() ): string {
		return AdminMenu::url( self::SLUG, $args );
	}

	/**
	 * Renders the page.
	 */
	public function render(): void {
		if ( ! current_user_can( CatalogAdmin::CAPABILITY ) ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only view selection.
		$id = isset( $_GET['listing'] ) ? absint( wp_unslash( $_GET['listing'] ) ) : 0;
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display only.
		$message = isset( $_GET['message'] ) ? sanitize_key( wp_unslash( $_GET['message'] ) ) : '';
		echo self::page( $id, FormState::take(), $message ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in page().
	}

	/**
	 * Page HTML (everything escaped here).
	 *
	 * @param int                                                                                       $id      Listing to translate (0 = overview).
	 * @param array{errors: array<string, string>, input: array<string, mixed>, warnings: list<string>} $state   Form state after a failed save.
	 * @param string                                                                                    $message Message key.
	 */
	public static function page( int $id, array $state, string $message = '' ): string {
		$settings = Multilingual::settings();
		$html     = '<div class="wrap"><h1>' . esc_html__( 'Çeviriler', 'ai-hazir-site' ) . '</h1>';
		$html    .= '<p>' . esc_html__( 'İlan ve firma profili metinlerini diğer dillerde girin. Otomatik çeviri yapılmaz; yalnızca girdiğiniz çeviriler yayınlanır. Çevirisi olmayan alan varsayılan dilde gösterilir ve AI çıktılarında işaretlenir.', 'ai-hazir-site' ) . '</p>';

		if ( 'saved' === $message ) {
			$html .= '<div class="notice notice-success"><p>' . esc_html__( 'Kaydedildi.', 'ai-hazir-site' ) . '</p></div>';
		}
		if ( array() !== $state['errors'] ) {
			$html .= '<div class="notice notice-error"><ul>';
			foreach ( $state['errors'] as $field => $error ) {
				$html .= '<li>' . esc_html( $field . ': ' . $error ) . '</li>';
			}
			$html .= '</ul></div>';
		}

		if ( $id > 0 ) {
			$listing = ( new WpListingRepository() )->find( $id );
			if ( null === $listing ) {
				return $html . '<p>' . esc_html__( 'İlan bulunamadı.', 'ai-hazir-site' ) . '</p></div>';
			}
			return $html . self::listing_form( $listing, $settings, $state['input'] ) . '</div>';
		}

		$html .= self::languages_form( $settings );
		if ( ! $settings->is_multilingual() ) {
			return $html . '<p id="aihs-translations-single">' . esc_html__( 'Şu anda tek dil tanımlı; çeviri girmek için yukarıdan en az bir dil daha ekleyin.', 'ai-hazir-site' ) . '</p></div>';
		}
		return $html . self::profile_form( ( new WpProfileRepository() )->get(), $settings ) . self::listing_table( $settings ) . '</div>';
	}

	/**
	 * Language setting (read only while a multilingual plugin is in charge).
	 *
	 * @param LanguageSettings $settings Settings.
	 */
	private static function languages_form( LanguageSettings $settings ): string {
		$html   = '<h2>' . esc_html__( 'Diller', 'ai-hazir-site' ) . '</h2>';
		$plugin = LanguageSource::plugin();
		if ( null !== $plugin ) {
			/* translators: 1: plugin name, 2: languages. */
			return $html . '<p id="aihs-languages-plugin">' . esc_html( sprintf( __( 'Diller %1$s eklentisinden okunuyor: %2$s (ilki varsayılan).', 'ai-hazir-site' ), LanguageSource::NAMES[ $plugin ] ?? $plugin, implode( ', ', $settings->languages ) ) ) . '</p>';
		}
		return $html . '<form id="aihs-languages-form" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">'
			. '<input type="hidden" name="action" value="' . esc_attr( self::SAVE_LANGUAGES ) . '">' . wp_nonce_field( self::SAVE_LANGUAGES, '_wpnonce', true, false )
			. '<table class="form-table"><tbody>'
			. '<tr><th><label for="aihs-default-language">' . esc_html__( 'Varsayılan dil', 'ai-hazir-site' ) . '</label></th><td><input id="aihs-default-language" name="default_language" class="small-text" maxlength="5" value="' . esc_attr( $settings->default ) . '"><p class="description">' . esc_html__( 'İlanları girdiğiniz dil (ISO 639-1, ör. tr).', 'ai-hazir-site' ) . '</p></td></tr>'
			. '<tr><th><label for="aihs-languages">' . esc_html__( 'Diğer diller', 'ai-hazir-site' ) . '</label></th><td><input id="aihs-languages" name="languages" class="regular-text" value="' . esc_attr( implode( ', ', $settings->translated() ) ) . '"><p class="description">' . esc_html__( 'Virgülle ayırın, ör. en, de, ar.', 'ai-hazir-site' ) . '</p></td></tr>'
			. '</tbody></table>' . get_submit_button( __( 'Dilleri kaydet', 'ai-hazir-site' ) ) . '</form>';
	}

	/**
	 * Profile translation form.
	 *
	 * @param CompanyProfile   $profile  Profile.
	 * @param LanguageSettings $settings Settings.
	 */
	private static function profile_form( CompanyProfile $profile, LanguageSettings $settings ): string {
		$stored = ( new WpProfileRepository() )->profile_translations();
		$html   = '<h2>' . esc_html__( 'Firma profili', 'ai-hazir-site' ) . '</h2><form id="aihs-profile-translation-form" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">'
			. '<input type="hidden" name="action" value="' . esc_attr( self::SAVE_PROFILE ) . '">' . wp_nonce_field( self::SAVE_PROFILE, '_wpnonce', true, false )
			. '<table class="form-table"><tbody>';
		foreach ( $settings->translated() as $language ) {
			$html .= '<tr><th><label for="aihs-sector-' . esc_attr( $language ) . '">' . esc_html__( 'Sektör', 'ai-hazir-site' ) . ' (' . esc_html( $language ) . ')</label></th><td><input id="aihs-sector-' . esc_attr( $language ) . '" lang="' . esc_attr( $language ) . '" name="t[' . esc_attr( $language ) . '][sector]" class="regular-text" value="' . esc_attr( $stored[ $language ]['sector'] ?? '' ) . '"><p class="description" lang="' . esc_attr( $settings->default ) . '">' . esc_html( $profile->sector ) . '</p></td></tr>';
		}
		return $html . '</tbody></table>' . get_submit_button( __( 'Profil çevirisini kaydet', 'ai-hazir-site' ) ) . '</form>';
	}

	/**
	 * Listings with their translation status per language.
	 *
	 * @param LanguageSettings $settings Settings.
	 */
	private static function listing_table( LanguageSettings $settings ): string {
		$repository = new WpListingRepository();
		$html       = '<h2>' . esc_html__( 'İlanlar', 'ai-hazir-site' ) . '</h2><table id="aihs-translation-status" class="widefat striped"><thead><tr><th>' . esc_html__( 'İlan', 'ai-hazir-site' ) . '</th>';
		foreach ( $settings->translated() as $language ) {
			$html .= '<th>' . esc_html( $language ) . '</th>';
		}
		$html .= '<th></th></tr></thead><tbody>';
		foreach ( ListingType::ALL as $type ) {
			foreach ( $repository->all( $type, self::LISTING_MAX_SHOWN ) as $listing ) {
				$localizer = new Localizer( $settings );
				$stored    = $repository->translations( (int) $listing->id );
				$html     .= '<tr data-listing="' . esc_attr( (string) $listing->id ) . '"><td>' . esc_html( $listing->title ) . '</td>';
				foreach ( $settings->translated() as $language ) {
					$missing = $localizer->listing( $listing, $stored, $language )->missing;
					$html   .= '<td data-language="' . esc_attr( $language ) . '" data-missing="' . esc_attr( (string) count( $missing ) ) . '">' . esc_html( array() === $missing ? __( 'tamam', 'ai-hazir-site' ) : sprintf( /* translators: %d: number of fields. */ _n( '%d alan eksik', '%d alan eksik', count( $missing ), 'ai-hazir-site' ), count( $missing ) ) ) . '</td>';
				}
				$html .= '<td><a href="' . esc_url( self::url( array( 'listing' => (int) $listing->id ) ) ) . '">' . esc_html__( 'Çevir', 'ai-hazir-site' ) . '</a></td></tr>';
			}
		}
		return $html . '</tbody></table>';
	}

	/**
	 * Translation form of one listing (every translated language).
	 *
	 * @param Listing              $listing  Listing.
	 * @param LanguageSettings     $settings Settings.
	 * @param array<string, mixed> $input    Values of a failed save.
	 */
	private static function listing_form( Listing $listing, LanguageSettings $settings, array $input ): string {
		$stored   = ( new WpListingRepository() )->translations( (int) $listing->id );
		$values   = is_array( $input['t'] ?? null ) ? $input['t'] : $stored;
		$labels   = array(
			'title'       => __( 'Başlık', 'ai-hazir-site' ),
			'description' => __( 'Açıklama', 'ai-hazir-site' ),
			'category'    => __( 'Kategori', 'ai-hazir-site' ),
			'region'      => __( 'Bölge', 'ai-hazir-site' ),
		);
		$html     = '<h2>' . esc_html( $listing->title ) . '</h2><p><a href="' . esc_url( self::url() ) . '">' . esc_html__( '← Tüm çeviriler', 'ai-hazir-site' ) . '</a></p>'
			. '<form id="aihs-listing-translation-form" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">'
			. '<input type="hidden" name="action" value="' . esc_attr( self::SAVE_LISTING ) . '"><input type="hidden" name="id" value="' . esc_attr( (string) $listing->id ) . '">' . wp_nonce_field( self::SAVE_LISTING, '_wpnonce', true, false );
		$original = $listing->to_array();
		foreach ( $settings->translated() as $language ) {
			$html .= '<h3>' . esc_html( $language ) . '</h3><table class="form-table" data-language="' . esc_attr( $language ) . '"><tbody>';
			foreach ( Localizer::LISTING_FIELDS as $field ) {
				$name  = 't[' . $language . '][' . $field . ']';
				$id    = 'aihs-t-' . $language . '-' . $field;
				$value = is_array( $values[ $language ] ?? null ) && is_scalar( $values[ $language ][ $field ] ?? null ) ? (string) $values[ $language ][ $field ] : '';
				$input = 'description' === $field
					? '<textarea id="' . esc_attr( $id ) . '" lang="' . esc_attr( $language ) . '" name="' . esc_attr( $name ) . '" rows="4" class="large-text">' . esc_textarea( $value ) . '</textarea>'
					: '<input id="' . esc_attr( $id ) . '" lang="' . esc_attr( $language ) . '" name="' . esc_attr( $name ) . '" class="regular-text" value="' . esc_attr( $value ) . '">';
				$html .= '<tr><th><label for="' . esc_attr( $id ) . '">' . esc_html( $labels[ $field ] ) . '</label></th><td>' . $input . '<p class="description" lang="' . esc_attr( $settings->default ) . '">' . esc_html( is_scalar( $original[ $field ] ?? null ) ? (string) $original[ $field ] : '' ) . '</p></td></tr>';
			}
			$html .= '</tbody></table>';
		}
		return $html . get_submit_button( __( 'Çevirileri kaydet', 'ai-hazir-site' ) ) . '</form>';
	}

	/**
	 * `admin_post_aihs_save_languages`.
	 */
	public function on_save_languages(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified in handle_save_languages().
		self::redirect( self::handle_save_languages( wp_unslash( $_POST ) ) );
	}

	/**
	 * `admin_post_aihs_save_profile_translation`.
	 */
	public function on_save_profile(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified in handle_save_profile().
		self::redirect( self::handle_save_profile( wp_unslash( $_POST ) ) );
	}

	/**
	 * `admin_post_aihs_save_listing_translation`.
	 */
	public function on_save_listing(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified in handle_save_listing().
		self::redirect( self::handle_save_listing( wp_unslash( $_POST ) ) );
	}

	/**
	 * Saves the language setting; returns where to redirect.
	 *
	 * @param array<mixed> $post Unslashed $_POST.
	 */
	public static function handle_save_languages( array $post ): string {
		CatalogAdmin::authorize( self::SAVE_LANGUAGES );
		$invalid = LanguageSource::save( sanitize_text_field( self::text( $post, 'default_language' ) ), sanitize_text_field( self::text( $post, 'languages' ) ) );
		if ( array() !== $invalid ) {
			FormState::put( array( 'languages' => __( 'Anlaşılmayan dil kodları (yok sayıldı):', 'ai-hazir-site' ) . ' ' . implode( ', ', $invalid ) ) );
			return self::url();
		}
		return self::url( array( 'message' => 'saved' ) );
	}

	/**
	 * Saves the profile translations; returns where to redirect.
	 *
	 * @param array<mixed> $post Unslashed $_POST.
	 */
	public static function handle_save_profile( array $post ): string {
		CatalogAdmin::authorize( self::SAVE_PROFILE );
		$settings = Multilingual::settings();
		$errors   = array();
		foreach ( $settings->translated() as $language ) {
			$input = self::fields( $post, $language, Localizer::PROFILE_FIELDS );
			foreach ( CatalogModule::service()->save_profile_translation( $language, $input, $settings ) as $field => $error ) {
				$errors[ $language . '.' . $field ] = $error;
			}
		}
		FormState::put( $errors );
		return self::url( array() === $errors ? array( 'message' => 'saved' ) : array() );
	}

	/**
	 * Saves the translations of one listing; returns where to redirect.
	 *
	 * @param array<mixed> $post Unslashed $_POST.
	 */
	public static function handle_save_listing( array $post ): string {
		CatalogAdmin::authorize( self::SAVE_LISTING );
		$id       = absint( self::text( $post, 'id' ) );
		$settings = Multilingual::settings();
		$errors   = array();
		$values   = array();
		foreach ( $settings->translated() as $language ) {
			$input               = self::fields( $post, $language, Localizer::LISTING_FIELDS );
			$values[ $language ] = $input;
			foreach ( CatalogModule::service()->save_listing_translation( $id, $language, $input, $settings ) as $field => $error ) {
				$errors[ $language . '.' . $field ] = $error;
			}
		}
		if ( array() === $errors ) {
			return self::url( array( 'message' => 'saved' ) );
		}
		FormState::put( $errors, array( 't' => $values ) );
		return self::url( array( 'listing' => $id ) );
	}

	/**
	 * Sanitized translation fields of one language from a submitted form.
	 *
	 * @param array<mixed> $post     Unslashed $_POST.
	 * @param string       $language Language.
	 * @param string[]     $fields   Fields.
	 * @return array<string, string>
	 */
	private static function fields( array $post, string $language, array $fields ): array {
		$submitted = is_array( $post['t'] ?? null ) && is_array( $post['t'][ $language ] ?? null ) ? $post['t'][ $language ] : array();
		$input     = array();
		foreach ( $fields as $field ) {
			$value           = is_scalar( $submitted[ $field ] ?? null ) ? (string) $submitted[ $field ] : '';
			$input[ $field ] = 'description' === $field ? sanitize_textarea_field( $value ) : sanitize_text_field( $value );
		}
		return $input;
	}

	/**
	 * String value from an array.
	 *
	 * @param array<mixed> $data Data.
	 * @param string       $key  Key.
	 */
	private static function text( array $data, string $key ): string {
		return isset( $data[ $key ] ) && is_scalar( $data[ $key ] ) ? (string) $data[ $key ] : '';
	}

	/**
	 * Redirects and stops.
	 *
	 * @param string $url URL.
	 */
	private static function redirect( string $url ): void {
		wp_safe_redirect( $url );
		exit;
	}
}
