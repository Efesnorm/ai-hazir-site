<?php
/**
 * Settings → AI Hazır Güncelleme.
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\WordPress\Updates;

use AIHazirSite\WordPress\Admin\AdminMenu;
use AIHazirSite\Core\Features;
use AIHazirSite\Core\Telemetry\TelemetrySummary;
use AIHazirSite\Core\Updates\CanaryPolicy;

/**
 * Server address and channel, rollback, and the report panel consent with the exact data shown.
 */
final class UpdatesAdmin {

	public const CAPABILITY = 'manage_options';

	/**
	 * Registers hooks.
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_post_' . UpdateModule::SAVE, array( $this, 'on_save' ) );
		add_action( 'admin_post_' . UpdateModule::ROLLBACK, array( $this, 'on_rollback' ) );
		add_action( 'admin_post_' . UpdateModule::CONSENT, array( $this, 'on_consent' ) );
	}

	/**
	 * Submenu under Settings.
	 */
	public function add_menu(): void {
		AdminMenu::add( __( 'Güncelleme', 'ai-hazir-site' ), self::CAPABILITY, UpdateModule::PAGE, array( $this, 'render' ) );
	}

	/**
	 * Page URL.
	 *
	 * @param array<string, string> $args Extra query args.
	 */
	public static function url( array $args = array() ): string {
		return AdminMenu::url( UpdateModule::PAGE, $args );
	}

	/**
	 * Renders the page.
	 */
	public function render(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display only.
		$message = isset( $_GET['message'] ) ? sanitize_key( wp_unslash( $_GET['message'] ) ) : '';
		echo self::page( $message ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in page().
	}

	/**
	 * Page HTML (everything escaped here).
	 *
	 * @param string $message Message key.
	 */
	public static function page( string $message = '' ): string {
		$messages = array(
			'saved'           => __( 'Kaydedildi.', 'ai-hazir-site' ),
			'rolled_back'     => __( 'Önceki sürüme dönüldü.', 'ai-hazir-site' ),
			'rollback_failed' => __( 'Geri alma yapılamadı.', 'ai-hazir-site' ),
			'consented'       => __( 'Onayınız kaydedildi.', 'ai-hazir-site' ),
			'revoked'         => __( 'Onayınız geri alındı; artık özet gönderilmez.', 'ai-hazir-site' ),
		);
		$settings = UpdateModule::settings();
		$html     = '<div class="wrap"><h1>' . esc_html__( 'AI Hazır Güncelleme', 'ai-hazir-site' ) . '</h1>';
		if ( isset( $messages[ $message ] ) ) {
			$error = get_transient( 'aihs_rollback_error_' . get_current_user_id() );
			$html .= '<div class="notice ' . ( 'rollback_failed' === $message ? 'notice-error' : 'notice-success' ) . '"><p>' . esc_html( $messages[ $message ] . ( 'rollback_failed' === $message && is_string( $error ) ? ' ' . $error : '' ) ) . '</p></div>';
		}

		if ( Features::is_enabled( Features::REMOTE_UPDATES ) ) {
			$constant = defined( 'AIHS_UPDATE_SERVER' );
			$html    .= '<h2>' . esc_html__( 'Güncellemeler', 'ai-hazir-site' ) . '</h2><form id="aihs-update-form" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="' . esc_attr( UpdateModule::SAVE ) . '">' . wp_nonce_field( UpdateModule::SAVE, '_wpnonce', true, false )
				. '<table class="form-table"><tbody><tr><th><label for="aihs-update-server">' . esc_html__( 'Güncelleme sunucusu (https)', 'ai-hazir-site' ) . '</label></th><td><input id="aihs-update-server" name="server" type="url" class="regular-text" value="' . esc_attr( $constant ? UpdateModule::server() : $settings['server'] ) . '"' . ( $constant ? ' readonly' : '' ) . '>'
				. '<p class="description">' . esc_html( $constant ? __( 'AIHS_UPDATE_SERVER sabitiyle belirlendi.', 'ai-hazir-site' ) : __( 'Boş bırakılırsa hiçbir güncelleme isteği atılmaz.', 'ai-hazir-site' ) ) . '</p></td></tr>'
				. '<tr><th>' . esc_html__( 'Kanal', 'ai-hazir-site' ) . '</th><td><label><input type="radio" name="channel" value="' . esc_attr( CanaryPolicy::PILOT ) . '"' . checked( $settings['channel'], CanaryPolicy::PILOT, false ) . '> ' . esc_html__( 'Pilot (yeni sürümü hemen alır)', 'ai-hazir-site' ) . '</label><br><label><input type="radio" name="channel" value="' . esc_attr( CanaryPolicy::GENERAL ) . '"' . checked( $settings['channel'], CanaryPolicy::GENERAL, false ) . '> '
				/* translators: %d: hours. */
				. esc_html( sprintf( __( 'Genel (yeni sürümü %d saat sonra alır)', 'ai-hazir-site' ), CanaryPolicy::DELAY_HOURS ) ) . '</label></td></tr>'
				. '<tr><th><label for="aihs-telemetry-url">' . esc_html__( 'Rapor paneli adresi (https)', 'ai-hazir-site' ) . '</label></th><td><input id="aihs-telemetry-url" name="telemetry_url" type="url" class="regular-text" value="' . esc_attr( $settings['telemetry_url'] ) . '"></td></tr>'
				. '</tbody></table>' . get_submit_button( __( 'Kaydet', 'ai-hazir-site' ) ) . '</form>';

			/* translators: %s: version. */
			$html .= '<h2>' . esc_html__( 'Önceki sürüme dön', 'ai-hazir-site' ) . '</h2><p>' . esc_html( sprintf( __( 'Kurulu sürüm: %s. Geri alma önce veritabanını önceki sürümün şemasına indirir, sonra önceki paketi kurar. Önce yedek alın.', 'ai-hazir-site' ), AIHS_VERSION ) ) . '</p>'
				. '<form id="aihs-rollback-form" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="' . esc_attr( UpdateModule::ROLLBACK ) . '">' . wp_nonce_field( UpdateModule::ROLLBACK, '_wpnonce', true, false ) . get_submit_button( __( 'Önceki sürüme dön', 'ai-hazir-site' ), 'secondary' ) . '</form>';
		}

		if ( Features::is_enabled( Features::TELEMETRY ) ) {
			$consent = UpdateModule::telemetry()->consent();
			$html   .= '<h2>' . esc_html__( 'Rapor paneline haftalık özet', 'ai-hazir-site' ) . '</h2>'
				. '<p id="aihs-telemetry-notice">' . esc_html__( 'Onay verirseniz sitenin haftalık özeti rapor paneli adresine gönderilir. Yalnızca aşağıdaki toplamlar gider; ilan metni, adres, e-posta, IP veya kişisel veri gitmez. Site kimliği rastgele üretilir. Onayı istediğiniz an geri alabilirsiniz.', 'ai-hazir-site' ) . '</p>'
				. '<p>' . esc_html__( 'Gönderilecek alanlar', 'ai-hazir-site' ) . ': <code>' . esc_html( implode( ', ', array_merge( array( 'site_id', 'sent_at' ), TelemetrySummary::FIELDS ) ) ) . '</code></p>'
				. '<pre id="aihs-telemetry-preview">' . esc_html( (string) wp_json_encode( UpdateModule::telemetry()->payload( UpdateModule::summary() ), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) ) . '</pre>'
				. '<form id="aihs-consent-form" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="' . esc_attr( UpdateModule::CONSENT ) . '">' . wp_nonce_field( UpdateModule::CONSENT, '_wpnonce', true, false )
				. ( null === $consent
					? '<input type="hidden" name="consent" value="give">' . get_submit_button( __( 'Onay veriyorum', 'ai-hazir-site' ) )
					/* translators: %s: date. */
					: '<p>' . esc_html( sprintf( __( 'Onay tarihi: %s', 'ai-hazir-site' ), $consent['given_at'] ) ) . '</p><input type="hidden" name="consent" value="revoke">' . get_submit_button( __( 'Onayı geri al', 'ai-hazir-site' ), 'secondary' ) )
				. '</form>';
		}
		return $html . '</div>';
	}

	/**
	 * `admin_post_aihs_save_update_settings`.
	 */
	public function on_save(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified in handle_save().
		self::redirect( self::handle_save( wp_unslash( $_POST ) ) );
	}

	/**
	 * `admin_post_aihs_rollback`.
	 */
	public function on_rollback(): void {
		self::redirect( self::handle_rollback() );
	}

	/**
	 * `admin_post_aihs_telemetry_consent_action`.
	 */
	public function on_consent(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified in handle_consent().
		self::redirect( self::handle_consent( wp_unslash( $_POST ) ) );
	}

	/**
	 * Saves the settings; returns where to redirect.
	 *
	 * @param array<mixed> $post Unslashed $_POST.
	 */
	public static function handle_save( array $post ): string {
		self::authorize( UpdateModule::SAVE );
		$url     = static fn( string $k ): string => is_string( $post[ $k ] ?? null ) && str_starts_with( trim( $post[ $k ] ), 'https://' ) ? esc_url_raw( trim( $post[ $k ] ), array( 'https' ) ) : '';
		$channel = is_string( $post['channel'] ?? null ) && in_array( $post['channel'], CanaryPolicy::CHANNELS, true ) ? $post['channel'] : CanaryPolicy::GENERAL;
		update_option(
			UpdateModule::SETTINGS,
			array(
				'server'        => $url( 'server' ),
				'channel'       => $channel,
				'telemetry_url' => $url( 'telemetry_url' ),
			),
			false
		);
		delete_site_transient( UpdateModule::MANIFEST_CACHE );
		delete_site_transient( 'update_plugins' );
		UpdateModule::schedule();
		return self::url( array( 'message' => 'saved' ) );
	}

	/**
	 * Rolls back; returns where to redirect.
	 */
	public static function handle_rollback(): string {
		self::authorize( UpdateModule::ROLLBACK );
		$result = UpdateModule::rollback();
		if ( ! $result['ok'] ) {
			set_transient( 'aihs_rollback_error_' . get_current_user_id(), $result['error'], 300 );
		}
		return self::url( array( 'message' => $result['ok'] ? 'rolled_back' : 'rollback_failed' ) );
	}

	/**
	 * Gives or revokes consent; returns where to redirect.
	 *
	 * @param array<mixed> $post Unslashed $_POST.
	 */
	public static function handle_consent( array $post ): string {
		self::authorize( UpdateModule::CONSENT );
		if ( 'give' === ( $post['consent'] ?? '' ) ) {
			UpdateModule::telemetry()->give_consent();
			$message = 'consented';
		} else {
			UpdateModule::telemetry()->revoke();
			$message = 'revoked';
		}
		UpdateModule::schedule();
		return self::url( array( 'message' => $message ) );
	}

	/**
	 * Capability and nonce check; dies on failure.
	 *
	 * @param string $action Nonce action.
	 */
	private static function authorize( string $action ): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'Bu işlem için yetkiniz yok.', 'ai-hazir-site' ), 403 );
		}
		check_admin_referer( $action );
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
