<?php
/**
 * Settings → AI Hazır Site: every feature key on one screen.
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\WordPress\Settings;

use AIHazirSite\Core\Features;
use AIHazirSite\Core\Setup\SetupProfiles;
use AIHazirSite\WordPress\IndexNow\IndexNowModule;
use AIHazirSite\WordPress\Inquiry\Admin\InquiryAdmin;
use AIHazirSite\WordPress\Integrations\IntegrationsModule;
use AIHazirSite\WordPress\Uninstaller;
use AIHazirSite\WordPress\Updates\UpdateModule;

/**
 * Turns features on and off without WP-CLI (1.9.0). Requirements come from Features::REQUIRES and
 * REQUIRES_ANY: a feature is turned on only when they are on, and off only when nothing on needs it
 * (nothing is chained). Features with their own flow keep it: the quote box is turned on on its own
 * screen after the privacy notice; the cache and sitemap integrations run the same work as the
 * Integrations screen. Handlers return the redirect URL so they can be tested without exiting.
 */
final class SettingsPage {

	public const SLUG        = 'aihs-settings';
	public const CAPABILITY  = 'manage_options';
	public const TOGGLE      = 'aihs_settings_toggle';
	public const DELETE_DATA = 'aihs_settings_delete_data';
	public const PRESET      = 'aihs_settings_preset';

	/**
	 * Registers admin hooks.
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_page' ) );
		add_action( 'admin_post_' . self::TOGGLE, array( $this, 'on_toggle' ) );
		add_action( 'admin_post_' . self::PRESET, array( $this, 'on_preset' ) );
		add_action( 'admin_post_' . self::DELETE_DATA, array( $this, 'on_delete_data' ) );
	}

	/**
	 * Adds the page under Settings.
	 */
	public function add_page(): void {
		add_options_page( __( 'AI Hazır Site', 'ai-hazir-site' ), __( 'AI Hazır Site', 'ai-hazir-site' ), self::CAPABILITY, self::SLUG, array( $this, 'render' ) );
	}

	/**
	 * Page URL.
	 *
	 * @param array<string, string> $args Extra query args.
	 */
	public static function url( array $args = array() ): string {
		return add_query_arg( array_merge( array( 'page' => self::SLUG ), $args ), admin_url( 'options-general.php' ) );
	}

	/**
	 * Groups and the features in them.
	 *
	 * @return array<string, list<string>>
	 */
	public static function groups(): array {
		return array(
			__( 'Ölçüm ve uyum', 'ai-hazir-site' )     => array( Features::MEASUREMENT, Features::COMPLIANCE_SCAN, Features::COMPLIANCE_WIZARD, Features::COMPLIANCE_REPORT, Features::BOT_ACCESS ),
			__( 'AI Katalog', 'ai-hazir-site' )        => array( Features::CATALOG, Features::TEMPLATES, Features::SCHEMA_OUTPUT, Features::LLMS_TXT, Features::MULTILINGUAL ),
			__( 'AI kanalları', 'ai-hazir-site' )      => array( Features::REST_API, Features::ABILITIES, Features::MCP, Features::A2A, Features::DISCOVERY ),
			__( 'Etkileşim', 'ai-hazir-site' )         => array( Features::INQUIRIES, Features::MATCHING, Features::PORTAL_MODE ),
			__( 'Entegrasyonlar', 'ai-hazir-site' )    => array( Features::BOT_CACHE_BYPASS, Features::LITESPEED_SERVER_BYPASS, Features::CATALOG_SITEMAP, Features::INDEXNOW ),
			__( 'Merkezi hizmetler', 'ai-hazir-site' ) => array( Features::REMOTE_UPDATES, Features::TELEMETRY ),
		);
	}

	/**
	 * Name, description and related screen (shown while the feature is on) of each feature.
	 *
	 * @return array<string, array{0: string, 1: string, 2: string}>
	 */
	public static function features(): array {
		$tools = static fn( string $slug ): string => admin_url( 'tools.php?page=' . $slug );
		$admin = static fn( string $slug ): string => admin_url( 'admin.php?page=' . $slug );
		return array(
			Features::MEASUREMENT             => array( __( 'AI ölçümü', 'ai-hazir-site' ), __( 'AI botlarının ve AI platformlarından gelen ziyaretlerin sayımı (kişisel veri saklanmaz).', 'ai-hazir-site' ), $tools( 'aihs-measurement' ) ),
			Features::COMPLIANCE_SCAN         => array( __( 'AI uyum taraması', 'ai-hazir-site' ), __( 'Sitenin AI uyum puanını ölçer.', 'ai-hazir-site' ), $tools( 'aihs-compliance' ) ),
			Features::COMPLIANCE_WIZARD       => array( __( 'AI uyum sihirbazı', 'ai-hazir-site' ), __( 'Eksikleri adım adım, geri alınabilir biçimde tamamlar.', 'ai-hazir-site' ), $tools( 'aihs-wizard' ) ),
			Features::COMPLIANCE_REPORT       => array( __( 'Uyum raporu ve rozet', 'ai-hazir-site' ), __( 'Önce/sonra raporu, AI Hazır rozeti ve doğrulama sayfası.', 'ai-hazir-site' ), $tools( 'aihs-report' ) ),
			Features::BOT_ACCESS              => array( __( 'AI bot erişimi', 'ai-hazir-site' ), __( 'robots.txt\'de AI botlarına izin ya da engel.', 'ai-hazir-site' ), $tools( 'aihs-bot-access' ) ),
			Features::CATALOG                 => array( __( 'AI Katalog', 'ai-hazir-site' ), __( 'Firma profili ve ilanlar: ne satıyorum, ne arıyorum, ne tedarik edebilirim.', 'ai-hazir-site' ), $admin( 'aihs-catalog' ) ),
			Features::TEMPLATES               => array( __( 'Sektör şablonları', 'ai-hazir-site' ), __( 'Ürün, hizmet, tur gibi sektöre özel ilan alanları.', 'ai-hazir-site' ), '' ),
			Features::SCHEMA_OUTPUT           => array( __( 'Schema.org yapılandırılmış veri', 'ai-hazir-site' ), __( 'Firma ve ilanlar makine okunur (JSON-LD); /ai-katalog/ sayfası.', 'ai-hazir-site' ), '' ),
			Features::LLMS_TXT                => array( __( 'llms.txt', 'ai-hazir-site' ), __( 'AI\'ların okuduğu /llms.txt özeti ve /ai-katalog/ sayfası.', 'ai-hazir-site' ), home_url( '/llms.txt' ) ),
			Features::MULTILINGUAL            => array( __( 'Çoklu dil', 'ai-hazir-site' ), __( 'Çeviriler ve dile göre çıktılar (Polylang, WPML).', 'ai-hazir-site' ), $admin( 'aihs-catalog-translations' ) ),
			Features::REST_API                => array( __( 'REST API', 'ai-hazir-site' ), __( 'Katalog JSON olarak: /wp-json/aihs/v1/.', 'ai-hazir-site' ), '' ),
			Features::ABILITIES               => array( __( 'Yetenekler (Abilities)', 'ai-hazir-site' ), __( 'Katalog sorguları WordPress Abilities API ile.', 'ai-hazir-site' ), '' ),
			Features::MCP                     => array( __( 'MCP sunucusu', 'ai-hazir-site' ), __( 'AI asistanları kataloğu doğrudan sorgular: /wp-json/aihs/mcp.', 'ai-hazir-site' ), '' ),
			Features::A2A                     => array( __( 'A2A agent', 'ai-hazir-site' ), __( 'A2A kartviziti ve agent: müsaitlik sorusu, teklif isteği.', 'ai-hazir-site' ), home_url( '/.well-known/agent-card.json' ) ),
			Features::DISCOVERY               => array( __( 'Sayfalardan keşif', 'ai-hazir-site' ), __( 'Sayfalarda llms.txt ve API\'ye standart bağlantılar.', 'ai-hazir-site' ), admin_url( 'admin.php?page=aihs-catalog-profile' ) ),
			Features::INQUIRIES               => array( __( 'Teklif kutusu', 'ai-hazir-site' ), __( 'AI agentların ve ziyaretçilerin talep bırakması (kişisel veri: KVKK uyarısıyla açılır).', 'ai-hazir-site' ), InquiryAdmin::url() ),
			Features::MATCHING                => array( __( 'Eşleştirme', 'ai-hazir-site' ), __( 'Aranan ilanlarınıza uyan satılan ve tedarik edilebilen ilanlar.', 'ai-hazir-site' ), $admin( 'aihs-matching' ) ),
			Features::PORTAL_MODE             => array( __( 'Portal modu', 'ai-hazir-site' ), __( 'Birçok işletmenin ilanları tek sitede, işletme yetkilileriyle.', 'ai-hazir-site' ), $admin( 'aihs-portal' ) ),
			Features::BOT_CACHE_BYPASS        => array( __( 'AI botlarına önbellekten sayfa sunma', 'ai-hazir-site' ), __( 'WP Rocket ve LiteSpeed Cache AI botlarına kayıtlı kopya sunmaz; botlar ölçülür ve güncel içerik görür.', 'ai-hazir-site' ), $tools( 'aihs-integrations' ) ),
			Features::CATALOG_SITEMAP         => array( __( 'AI Katalog site haritasında', 'ai-hazir-site' ), __( '/ai-katalog/ sayfası sitenin site haritasına eklenir.', 'ai-hazir-site' ), $tools( 'aihs-integrations' ) ),
			Features::LITESPEED_SERVER_BYPASS => array( __( 'LiteSpeed sunucu önbelleği', 'ai-hazir-site' ), __( 'LiteSpeed sunucusunun kendi önbelleği AI botlarına kayıtlı kopya sunmaz (.htaccess kuralı).', 'ai-hazir-site' ), $tools( 'aihs-integrations' ) ),
			Features::INDEXNOW                => array( __( 'IndexNow bildirimi', 'ai-hazir-site' ), __( 'Katalog değişince Bing ve diğer IndexNow arama motorlarına haber verilir (yalnızca herkese açık adresler).', 'ai-hazir-site' ), $tools( 'aihs-integrations' ) ),
			Features::REMOTE_UPDATES          => array( __( 'Merkezi güncelleme', 'ai-hazir-site' ), __( 'Güncellemeler kendi sunucumuzdan; sunucu adresi girilmedikçe hiçbir istek atılmaz.', 'ai-hazir-site' ), admin_url( 'options-general.php?page=' . UpdateModule::PAGE ) ),
			Features::TELEMETRY               => array( __( 'Rapor paneline özet', 'ai-hazir-site' ), __( 'Haftalık toplam sayılar; gönderim için ayrıca açık onay gerekir.', 'ai-hazir-site' ), admin_url( 'options-general.php?page=' . UpdateModule::PAGE ) ),
		);
	}

	/**
	 * Renders the page.
	 */
	public function render(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'Bu sayfaya erişim yetkiniz yok.', 'ai-hazir-site' ), 403 );
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display-only message key.
		$message = isset( $_GET['message'] ) && is_string( $_GET['message'] ) ? sanitize_key( wp_unslash( $_GET['message'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only preview; applying needs the nonce.
		$profile = isset( $_GET['profil'] ) && is_string( $_GET['profil'] ) ? sanitize_key( wp_unslash( $_GET['profil'] ) ) : '';
		echo '<div class="wrap">' . self::render_html( $message, $profile ) . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in render_html().
	}

	/**
	 * Page HTML (everything escaped here).
	 *
	 * @param string $message Result message key.
	 * @param string $profile Previewed setup profile ('' = none).
	 */
	public static function render_html( string $message = '', string $profile = '' ): string {
		$notices = array(
			'preset'  => array( 'success', __( 'Önerilen kurulum uygulandı. Aşağıda her özelliğin durumu görünür.', 'ai-hazir-site' ) ),
			'on'      => array( 'success', __( 'Özellik açıldı.', 'ai-hazir-site' ) ),
			'off'     => array( 'success', __( 'Özellik kapatıldı.', 'ai-hazir-site' ) ),
			'blocked' => array( 'error', __( 'İşlem yapılmadı: önce satırda yazan özellikleri açın ya da kapatın.', 'ai-hazir-site' ) ),
			'saved'   => array( 'success', __( 'Ayar kaydedildi.', 'ai-hazir-site' ) ),
		);
		$html    = '<h1>' . esc_html__( 'AI Hazır Site', 'ai-hazir-site' ) . '</h1>';
		if ( isset( $notices[ $message ] ) ) {
			$html .= '<div class="notice notice-' . esc_attr( $notices[ $message ][0] ) . '"><p>' . esc_html( $notices[ $message ][1] ) . '</p></div>';
		}
		$html .= '<p class="description">' . esc_html__( 'Her özellik ayrı açılır ve kapanır; varsayılan olarak yalnızca AI ölçümü açıktır. Bir özelliğin önkoşulu kapalıysa ya da açık başka bir özellik ona bağlıysa satırında yazar.', 'ai-hazir-site' ) . '</p>'
			. self::preset_section( $profile );

		$features = self::features();
		foreach ( self::groups() as $group => $keys ) {
			$html .= '<h2>' . esc_html( $group ) . '</h2><table class="widefat striped" role="presentation"><tbody>';
			foreach ( $keys as $key ) {
				$html .= self::row( $key, $features[ $key ] );
			}
			$html .= '</tbody></table>';
		}
		return $html . self::delete_data_section();
	}

	/**
	 * One feature row.
	 *
	 * @param string                                 $key  Feature key.
	 * @param array{0: string, 1: string, 2: string} $info Name, description, related screen.
	 */
	private static function row( string $key, array $info ): string {
		$on    = Features::is_enabled( $key );
		$names = self::names();
		$note  = '';
		if ( $on ) {
			$blocking = Features::enabled_dependents( $key );
			if ( array() !== $blocking ) {
				/* translators: %s: feature names. */
				$note = sprintf( __( 'Önce şunu kapatın: %s', 'ai-hazir-site' ), implode( ', ', array_map( static fn( string $k ): string => $names[ $k ], $blocking ) ) );
			}
		} else {
			$missing = Features::missing_requirements( $key );
			$parts   = array();
			if ( array() !== $missing['all'] ) {
				$parts[] = implode( ', ', array_map( static fn( string $k ): string => $names[ $k ], $missing['all'] ) );
			}
			if ( array() !== $missing['any'] ) {
				/* translators: %s: feature names. */
				$parts[] = sprintf( __( 'şunlardan biri: %s', 'ai-hazir-site' ), implode( ' / ', array_map( static fn( string $k ): string => $names[ $k ], $missing['any'] ) ) );
			}
			if ( array() !== $parts ) {
				/* translators: %s: feature names. */
				$note = sprintf( __( 'Önce şunu açın: %s', 'ai-hazir-site' ), implode( '; ', $parts ) );
			}
		}

		if ( '' !== $note ) {
			$action = '<em>' . esc_html( $note ) . '</em>';
		} elseif ( Features::INQUIRIES === $key && ! $on ) {
			$action = '<a class="button" href="' . esc_url( $info[2] ) . '">' . esc_html__( 'Teklif Kutusu ekranında açın', 'ai-hazir-site' ) . '</a>';
		} else {
			$action = self::toggle( $key, $on );
		}

		return '<tr id="aihs-feature-' . esc_attr( $key ) . '"><td style="width:45%"><strong>' . esc_html( $info[0] ) . '</strong><br><span class="description">' . esc_html( $info[1] ) . '</span></td>'
			. '<td style="width:10%">' . ( $on ? '<strong>' . esc_html__( 'Açık', 'ai-hazir-site' ) . '</strong>' : esc_html__( 'Kapalı', 'ai-hazir-site' ) ) . '</td>'
			. '<td>' . $action . ( $on && '' !== $info[2] ? ' <a href="' . esc_url( $info[2] ) . '">' . esc_html__( 'Ekrana git', 'ai-hazir-site' ) . '</a>' : '' ) . '</td></tr>';
	}

	/**
	 * Feature names by key.
	 *
	 * @return array<string, string>
	 */
	private static function names(): array {
		return array_map( static fn( array $info ): string => $info[0], self::features() );
	}

	/**
	 * The on/off form of a feature.
	 *
	 * @param string $key Feature key.
	 * @param bool   $on  Current state.
	 */
	private static function toggle( string $key, bool $on ): string {
		return '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline">'
			. '<input type="hidden" name="action" value="' . esc_attr( self::TOGGLE ) . '">'
			. '<input type="hidden" name="feature" value="' . esc_attr( $key ) . '">'
			. '<input type="hidden" name="state" value="' . ( $on ? 'off' : 'on' ) . '">'
			. wp_nonce_field( self::TOGGLE, '_wpnonce', true, false )
			. get_submit_button( $on ? __( 'Kapat', 'ai-hazir-site' ) : __( 'Aç', 'ai-hazir-site' ), $on ? 'secondary small' : 'primary small', 'submit', false )
			. '</form>';
	}

	/**
	 * "Delete all data on uninstall".
	 */
	private static function delete_data_section(): string {
		$on = Uninstaller::should_delete_data();
		return '<h2>' . esc_html__( 'Eklentiyi silme', 'ai-hazir-site' ) . '</h2>'
			. '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">'
			. '<input type="hidden" name="action" value="' . esc_attr( self::DELETE_DATA ) . '">'
			. wp_nonce_field( self::DELETE_DATA, '_wpnonce', true, false )
			. '<p><label><input type="checkbox" name="delete" value="1"' . checked( $on, true, false ) . '> '
			. esc_html__( 'Eklentiyi silerken tüm verilerini de sil (tablolar, ayarlar, ilanlar, talepler). Geri alınamaz.', 'ai-hazir-site' ) . '</label></p>'
			. '<p class="description">' . esc_html__( 'İşaretli değilse eklenti silinse bile verileriniz korunur.', 'ai-hazir-site' ) . '</p>'
			. get_submit_button( __( 'Kaydet', 'ai-hazir-site' ), 'secondary', 'submit', false )
			. '</form>';
	}

	/**
	 * `admin_post_aihs_settings_toggle`.
	 */
	public function on_toggle(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified in handle_toggle().
		wp_safe_redirect( self::handle_toggle( wp_unslash( $_POST ) ) );
		exit;
	}

	/**
	 * `admin_post_aihs_settings_delete_data`.
	 */
	public function on_delete_data(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified in handle_delete_data().
		wp_safe_redirect( self::handle_delete_data( wp_unslash( $_POST ) ) );
		exit;
	}

	/**
	 * Turns one feature on or off; returns where to redirect.
	 *
	 * @param array<mixed> $post Unslashed $_POST.
	 */
	public static function handle_toggle( array $post ): string {
		self::authorize( self::TOGGLE );
		$key = isset( $post['feature'] ) && is_string( $post['feature'] ) ? sanitize_key( $post['feature'] ) : '';
		if ( ! array_key_exists( $key, Features::defaults() ) ) {
			wp_die( esc_html__( 'Bilinmeyen özellik.', 'ai-hazir-site' ), 400 );
		}
		$on      = isset( $post['state'] ) && 'on' === $post['state'];
		$missing = Features::missing_requirements( $key );
		if ( ( $on && ( array() !== $missing['all'] || array() !== $missing['any'] || Features::INQUIRIES === $key ) )
			|| ( ! $on && array() !== Features::enabled_dependents( $key ) ) ) {
			return self::url( array( 'message' => 'blocked' ) ) . '#aihs-feature-' . $key;
		}

		self::set_feature( $key, $on );
		return self::url( array( 'message' => $on ? 'on' : 'off' ) ) . '#aihs-feature-' . $key;
	}

	/**
	 * Turns a feature on or off with its own work (integrations, IndexNow key).
	 *
	 * @param string $key Feature key.
	 * @param bool   $on  New state.
	 */
	private static function set_feature( string $key, bool $on ): void {
		if ( Features::BOT_CACHE_BYPASS === $key ) {
			IntegrationsModule::set_bypass( $on );
		} elseif ( Features::CATALOG_SITEMAP === $key ) {
			IntegrationsModule::set_sitemap( $on );
		} elseif ( Features::LITESPEED_SERVER_BYPASS === $key ) {
			IntegrationsModule::set_server_bypass( $on );
		} elseif ( Features::INDEXNOW === $key ) {
			IndexNowModule::enable( $on );
		} else {
			Features::set( $key, $on );
		}
	}

	/**
	 * Profile names (1.11.0).
	 *
	 * @return array<string, string>
	 */
	public static function profiles(): array {
		return array(
			SetupProfiles::PRODUCT => __( 'Ürün satıcısı, üretici ya da dağıtıcı', 'ai-hazir-site' ),
			SetupProfiles::EXPORT  => __( 'İhracatçı (çoklu dil ile)', 'ai-hazir-site' ),
			SetupProfiles::TOUR    => __( 'Tur operatörü', 'ai-hazir-site' ),
			SetupProfiles::PORTAL  => __( 'Portal (birçok işletme)', 'ai-hazir-site' ),
			SetupProfiles::SERVICE => __( 'Hizmet, yalnızca okuma (ör. hukuk bürosu: A2A ve teklif kutusu yok)', 'ai-hazir-site' ),
		);
	}

	/**
	 * "Önerilen kurulum": choose a site type, preview what would be turned on, apply.
	 *
	 * @param string $profile Previewed profile ('' = none yet).
	 */
	private static function preset_section( string $profile ): string {
		$names   = self::names();
		$options = '<option value="">' . esc_html__( '— Site türü seçin —', 'ai-hazir-site' ) . '</option>';
		foreach ( self::profiles() as $id => $label ) {
			$options .= '<option value="' . esc_attr( $id ) . '"' . selected( $profile, $id, false ) . '>' . esc_html( $label ) . '</option>';
		}
		$html = '<h2>' . esc_html__( 'Önerilen kurulum', 'ai-hazir-site' ) . '</h2>'
			. '<p class="description">' . esc_html__( 'Site türünüze uygun özellikleri tek seferde açar. Hiçbir özelliği kapatmaz. Önce neyin açılacağını gösterir.', 'ai-hazir-site' ) . '</p>'
			. '<form method="get" action="' . esc_url( admin_url( 'options-general.php' ) ) . '"><input type="hidden" name="page" value="' . esc_attr( self::SLUG ) . '">'
			. '<select name="profil">' . $options . '</select> '
			. get_submit_button( __( 'Önizle', 'ai-hazir-site' ), 'secondary', 'submit', false ) . '</form>';

		if ( ! isset( self::profiles()[ $profile ] ) ) {
			return $html;
		}
		$plan    = SetupProfiles::plan( $profile );
		$already = array_values( array_diff( SetupProfiles::all()[ $profile ], $plan ) );
		$list    = static fn( array $keys ): string => array() === $keys ? '–' : implode( ', ', array_map( static fn( string $k ): string => $names[ $k ], $keys ) );
		$html   .= '<div id="aihs-preset-preview" class="notice notice-info inline"><p><strong>' . esc_html( self::profiles()[ $profile ] ) . '</strong></p>'
			. '<p>' . esc_html__( 'Açılacaklar:', 'ai-hazir-site' ) . ' ' . esc_html( $list( $plan ) ) . '</p>'
			. '<p>' . esc_html__( 'Zaten açık:', 'ai-hazir-site' ) . ' ' . esc_html( $list( $already ) ) . '</p>'
			. '<p>' . esc_html__( 'Ayrıca elle: teklif kutusu (KVKK uyarısıyla kendi ekranından), aranan ilanınız varsa eşleştirme, firma profilinde sektör şablonu.', 'ai-hazir-site' ) . '</p>';
		if ( array() !== $plan ) {
			$html .= '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">'
				. '<input type="hidden" name="action" value="' . esc_attr( self::PRESET ) . '">'
				. '<input type="hidden" name="profil" value="' . esc_attr( $profile ) . '">'
				. wp_nonce_field( self::PRESET, '_wpnonce', true, false )
				. get_submit_button( __( 'Uygula', 'ai-hazir-site' ), 'primary', 'submit', false ) . '</form>';
		}
		return $html . '</div>';
	}

	/**
	 * `admin_post_aihs_settings_preset`.
	 */
	public function on_preset(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified in handle_preset().
		wp_safe_redirect( self::handle_preset( wp_unslash( $_POST ) ) );
		exit;
	}

	/**
	 * Turns on a profile's features in requirement order (never turns anything off); returns where to redirect.
	 *
	 * @param array<mixed> $post Unslashed $_POST.
	 */
	public static function handle_preset( array $post ): string {
		self::authorize( self::PRESET );
		$profile = isset( $post['profil'] ) && is_string( $post['profil'] ) ? sanitize_key( $post['profil'] ) : '';
		if ( ! isset( self::profiles()[ $profile ] ) ) {
			wp_die( esc_html__( 'Bilinmeyen site türü.', 'ai-hazir-site' ), 400 );
		}
		foreach ( SetupProfiles::plan( $profile ) as $key ) {
			$missing = Features::missing_requirements( $key );
			if ( array() === $missing['all'] && array() === $missing['any'] ) {
				self::set_feature( $key, true );
			}
		}
		return self::url( array( 'message' => 'preset' ) );
	}

	/**
	 * Saves the delete-data choice; returns where to redirect.
	 *
	 * @param array<mixed> $post Unslashed $_POST.
	 */
	public static function handle_delete_data( array $post ): string {
		self::authorize( self::DELETE_DATA );
		update_option( Uninstaller::DELETE_OPTION, empty( $post['delete'] ) ? 0 : 1, false );
		return self::url( array( 'message' => 'saved' ) );
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
}
