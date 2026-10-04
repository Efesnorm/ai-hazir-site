<?php
/**
 * Tools → AI Hazır Entegrasyonlar.
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\WordPress\Integrations;

use AIHazirSite\WordPress\Admin\AdminMenu;
use AIHazirSite\Core\Features;
use AIHazirSite\Core\IndexNow\IndexNowService;
use AIHazirSite\WordPress\IndexNow\IndexNowModule;

/**
 * Work that involves other plugins is offered as options: the page shows what it found on the site
 * and each integration is turned on or off with one click (never automatically). The handler returns
 * the redirect URL so it can be tested without exiting (1.8.0).
 */
final class IntegrationsPage {

	public const SLUG       = 'aihs-integrations';
	public const CAPABILITY = 'manage_options';
	public const ACTION     = 'aihs_integration';

	public const BYPASS  = 'bot_cache_bypass';
	public const SITEMAP = 'catalog_sitemap';

	public const SERVER       = 'litespeed_server_bypass';
	public const INDEXNOW     = 'indexnow';
	public const INDEXNOW_NOW = 'indexnow_now';
	public const IMUNIFY      = 'security_imunify';
	public const WORDFENCE    = 'security_wordfence';

	/**
	 * Registers admin hooks.
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_page' ) );
		add_action( 'admin_post_' . self::ACTION, array( $this, 'on_save' ) );
	}

	/**
	 * Adds the page under Tools.
	 */
	public function add_page(): void {
		AdminMenu::add( __( 'Entegrasyonlar', 'ai-hazir-site' ), self::CAPABILITY, self::SLUG, array( $this, 'render' ) );
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
		echo '<div class="wrap">' . self::render_html( $message ) . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in render_html().
	}

	/**
	 * Page HTML (everything escaped here).
	 *
	 * @param string $message Result message key.
	 */
	public static function render_html( string $message = '' ): string {
		$notices = array(
			'on'              => __( 'Entegrasyon açıldı.', 'ai-hazir-site' ),
			'off'             => __( 'Entegrasyon kapatıldı.', 'ai-hazir-site' ),
			'sent'            => __( 'IndexNow bildirimi gönderildi; sonuç aşağıda.', 'ai-hazir-site' ),
			'not_sent'        => __( 'Bildirim gönderilmedi: bildirilecek adres yok ya da son bildirimden bu yana 1 saat geçmedi.', 'ai-hazir-site' ),
			'security_manual' => __( 'wp-config.php değiştirilmedi; aşağıdaki satırı elle ekleyebilirsiniz.', 'ai-hazir-site' ),
			'wordfence_added' => __( 'Ağ üyelerinin sunucuları Wordfence izin listesine eklendi.', 'ai-hazir-site' ),
			'wordfence_none'  => __( 'Eklenecek yeni adres yok (hepsi zaten listede ya da IPv4 değil).', 'ai-hazir-site' ),
		);
		$html    = '<h1>' . esc_html__( 'AI Hazır Entegrasyonlar', 'ai-hazir-site' ) . '</h1>'
			. ( isset( $notices[ $message ] ) ? '<div class="notice notice-success"><p>' . esc_html( $notices[ $message ] ) . '</p></div>' : '' )
			. '<p class="description">' . esc_html__( 'Sitenizdeki diğer eklentilerle yapılabilecek işler. Hiçbiri kendiliğinden yapılmaz; her birini buradan açıp kapatabilirsiniz. Kapatınca eklediğimiz her şey geri alınır.', 'ai-hazir-site' ) . '</p>';

		return $html . self::bypass_section() . self::server_section() . self::security_section() . self::sitemap_section() . self::indexnow_section();
	}

	/**
	 * Security software (1.21.0): what is present, what it does to AI bots and the network, documented actions.
	 */
	private static function security_section(): string {
		if ( ! Features::is_enabled( Features::SECURITY_INTEGRATIONS ) ) {
			return '';
		}
		$found = SecuritySoftware::detect();
		$state = SecuritySoftware::state();
		$html  = '<h2 id="aihs-security">' . esc_html__( 'Güvenlik yazılımları', 'ai-hazir-site' ) . '</h2>'
			. '<p>' . esc_html__( 'Güvenlik katmanları AI botlarını ve portal ağımızın sunucudan sunucuya isteklerini yavaşlatabilir ya da engelleyebilir. Burada yalnızca yazılımların belgelediği yollar kullanılır; hiçbiri kendiliğinden yapılmaz.', 'ai-hazir-site' ) . '</p>';

		if ( ! $found['imunify'] && ! $found['wordfence'] && ! $found['cloudflare'] && array() === $found['others'] ) {
			$html .= '<p>' . esc_html__( 'Bilinen bir güvenlik yazılımı bulunamadı.', 'ai-hazir-site' ) . '</p>';
		}

		if ( $found['imunify'] ) {
			$preset = SecuritySoftware::imunify_preset();
			$html  .= '<h3>Imunify Security – AI Bot Management</h3>'
				. '<p>' . esc_html__( 'Dakikada izin verilen istek (Imunify belgesi): doğrulanmış arama motorları / doğrulanmış AI tarayıcıları / bilinmeyen otomatik istemciler / doğrulanamayan botlar. İnsan ziyaretçiler hiç sınırlanmaz.', 'ai-hazir-site' ) . '</p><table class="widefat striped" style="max-width:640px"><tbody>';
			foreach ( SecuritySoftware::PRESETS as $name => $limits ) {
				$html .= '<tr><th>' . esc_html( ucfirst( $name ) ) . '</th><td>' . esc_html( null === $limits ? __( 'sınır yok (yalnızca izler; kötü niyetli botlar da sınırsız)', 'ai-hazir-site' ) : implode( ' / ', $limits ) ) . '</td></tr>';
			}
			$html .= '</tbody></table><p>' . esc_html(
				'' === $preset
					? __( 'Ayar wp-config.php\'de sabitlenmemiş; geçerli hazır ayarı WordPress panosundaki Imunify Security kutusunun "Bot Protection" satırından görün.', 'ai-hazir-site' )
					: sprintf( /* translators: %s: preset. */ __( 'wp-config.php\'de sabitlenmiş hazır ayar: %s', 'ai-hazir-site' ), $preset )
			) . '</p>';
			$html .= '<p>' . esc_html__( '"Balanced\'ı sabitle", Imunify\'ın belgelediği IMUNIFY_AI_BOT_PROTECTION_PRESET sabitini wp-config.php\'ye "balanced" olarak yazar: kimse yanlışlıkla "Strict"e (AI botlarına dakikada 3) çekemez. "Monitor" ya da "kapalı" asla yazılmaz.', 'ai-hazir-site' ) . '</p>';
			if ( '' !== $state['imunify_error'] ) {
				$html .= '<div class="notice notice-warning inline"><p>' . esc_html( $state['imunify_error'] ) . ' ' . esc_html__( 'Elle eklemek için wp-config.php\'de "<?php" satırının hemen altına:', 'ai-hazir-site' ) . '</p><pre>' . esc_html( SecuritySoftware::imunify_line() ) . '</pre></div>';
			}
			$html .= self::toggle( self::IMUNIFY, $state['imunify_locked'] );
		}

		if ( $found['wordfence'] ) {
			$ips   = SecuritySoftware::network_ips();
			$html .= '<h3>Wordfence</h3>'
				. '<p>' . esc_html__( 'AI botlarının IP aralıkları izin listesine eklenmez: izin listesindeki bir adres bütün güvenlik kurallarını atlar. Wordfence\'in "Rate Limiting" ayarlarında tarayıcı sınırlarını "Unlimited" (varsayılan) bırakın.', 'ai-hazir-site' ) . '</p>';
			if ( array() === $ips ) {
				$html .= '<p><em>' . esc_html__( 'Portal ağından henüz bilinen bir sunucu adresi yok (ağ açık ve doğrulanmış bir kardeş bu siteyi en az bir kez okumuş olmalı).', 'ai-hazir-site' ) . '</em></p>';
			} else {
				$html .= '<p>' . esc_html__( 'Ağdaki sitelerin sunucuları:', 'ai-hazir-site' ) . ' ' . esc_html( implode( ', ', $ips ) ) . '</p>'
					. '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="' . esc_attr( self::ACTION ) . '"><input type="hidden" name="integration" value="' . esc_attr( self::WORDFENCE ) . '"><input type="hidden" name="state" value="on">'
					. wp_nonce_field( self::ACTION, '_wpnonce', true, false ) . get_submit_button( __( 'Ağ üyelerinin sunucularını Wordfence izin listesine ekle', 'ai-hazir-site' ), 'secondary', 'submit', false ) . '</form>';
			}
			// 1.24.0: the network report authenticates with WordPress application passwords, which Wordfence can switch off.
			if ( Features::is_enabled( Features::NETWORK_REPORT ) && ! wp_is_application_passwords_available() ) {
				$html .= '<div class="notice notice-warning inline" id="aihs-security-app-passwords"><p>' . esc_html__( 'WordPress uygulama parolaları kapalı; ağ raporu bu siteyi okuyamaz. Wordfence → Login Security → Settings → "Disable WordPress application passwords" seçeneğini kaldırın.', 'ai-hazir-site' ) . '</p></div>';
			}
			if ( array() !== $state['wordfence'] ) {
				$html .= '<p>' . esc_html__( 'Bizim eklediğimiz adresler (Wordfence\'in silme için herkese açık bir işlevi olmadığından gerekirse Wordfence → Firewall → Allowlisted IP addresses ekranından elle silin):', 'ai-hazir-site' ) . ' ' . esc_html( implode( ', ', $state['wordfence'] ) ) . '</p>';
			}
		}

		if ( $found['cloudflare'] ) {
			$html .= '<h3>Cloudflare</h3><p>' . esc_html__( 'Site Cloudflare arkasında. Cloudflare panelinde "AI botlarını engelle" ve AI Crawl Control ayarlarının istediğiniz AI tarayıcılarını engellemediğini kontrol edin; "Bot Fight Mode" sunucudan sunucuya isteklerimizi de engelleyebilir.', 'ai-hazir-site' ) . '</p>';
		}

		foreach ( $found['others'] as $name ) {
			$html .= '<h3>' . esc_html( $name ) . '</h3><p>' . esc_html__( 'Kötü bot / tarayıcı engelleme listelerinde GPTBot, ChatGPT-User, OAI-SearchBot, ClaudeBot, PerplexityBot gibi AI tarayıcılarının bulunmadığını; hız sınırlarının doğrulanmış tarayıcıları kısmadığını kontrol edin.', 'ai-hazir-site' ) . '</p>';
		}

		return $html . '<h3>' . esc_html__( 'Barındırma firmasına iletilecek metin', 'ai-hazir-site' ) . '</h3><p class="description">' . esc_html__( 'Sunucu düzeyindeki güvenlik (Imunify360, WAF, IP listeleri) yalnızca barındırma firmasında değiştirilebilir.', 'ai-hazir-site' ) . '</p>'
			. '<textarea class="large-text code" rows="10" readonly id="aihs-host-text">' . esc_textarea( SecuritySoftware::host_text() ) . '</textarea>';
	}

	/**
	 * LiteSpeed server cache without the LiteSpeed Cache plugin (1.14.0).
	 */
	private static function server_section(): string {
		$on = Features::is_enabled( Features::LITESPEED_SERVER_BYPASS );
		if ( ! $on && ! LiteSpeedServerBypass::detected() ) {
			return '';
		}
		$html = '<h2>' . esc_html__( 'LiteSpeed sunucu önbelleği', 'ai-hazir-site' ) . '</h2>'
			. '<p>' . esc_html__( 'Sunucunuz LiteSpeed. Bazı barındırmalar LiteSpeed Cache eklentisi olmadan da sayfaları sunucuda önbelleğe alır; AI botlarının istekleri o zaman WordPress\'e hiç ulaşmaz (AI Ölçüm sayamaz, botlar eski kopyayı görür). Açınca .htaccess dosyasının WordPress bölümünün başına, AI botlarına sunucu önbelleğinden sayfa sunulmamasını söyleyen tek bir kural eklenir ve bot yanıtlarına "saklama" başlıkları konur. İnsan ziyaretçiler için önbellek aynen çalışır.', 'ai-hazir-site' ) . '</p>';
		if ( LiteSpeedBypass::detected() ) {
			return $html . '<p><em>' . esc_html__( 'LiteSpeed Cache eklentisi kurulu: yukarıdaki "AI botlarına önbellekten sayfa sunma" yeterli; bu satıra gerek yok.', 'ai-hazir-site' ) . '</em></p>'
				. ( $on ? self::toggle( self::SERVER, true ) : '' );
		}
		$html .= '<p class="description">' . esc_html__( 'Açmadan önce .htaccess dosyasının yedeğini alın. Kapatınca kural kaldırılır.', 'ai-hazir-site' ) . '</p>';

		$last = LiteSpeedServerBypass::last();
		if ( $on && null !== $last && ! $last['written'] ) {
			$html .= '<div class="notice notice-warning inline"><p>' . esc_html__( '.htaccess yazılamadı (dosya yazılamıyor ya da kalıcı bağlantılar "Düz" ayarında). Aşağıdaki satırları .htaccess dosyasında "# BEGIN WordPress" satırının hemen altına elle ekleyin:', 'ai-hazir-site' ) . '</p>'
				. '<pre>' . esc_html( implode( "\n", LiteSpeedServerBypass::lines() ) ) . '</pre></div>';
		} elseif ( $on ) {
			$html .= '<p>' . esc_html( LiteSpeedServerBypass::in_htaccess() ? __( '.htaccess: kural yerinde.', 'ai-hazir-site' ) : __( '.htaccess: kural bulunamadı (başka bir araç dosyayı değiştirmiş olabilir). Kapatıp yeniden açın.', 'ai-hazir-site' ) ) . '</p>';
		}
		return $html . self::toggle( self::SERVER, $on );
	}

	/**
	 * IndexNow: state, requirement, key file, last result, "Şimdi bildir".
	 */
	private static function indexnow_section(): string {
		$on   = Features::is_enabled( Features::INDEXNOW );
		$html = '<h2>' . esc_html__( 'Değişiklikleri IndexNow ile bildir', 'ai-hazir-site' ) . '</h2>'
			. '<p>' . esc_html__( 'İlan ya da profil değişince /ai-katalog/ adresi Bing, Yandex ve diğer IndexNow arama motorlarına bildirilir (ChatGPT arama Bing dizinini kullanır; Google IndexNow kullanmaz). Yalnızca herkese açık adresler, site adı ve anahtar gönderilir. Değişiklikten 10 dakika sonra tek bildirim gider; iki bildirim arası en az 1 saattir.', 'ai-hazir-site' ) . '</p>';

		$missing = Features::missing_requirements( Features::INDEXNOW );
		if ( ! $on && array() !== $missing['any'] ) {
			return $html . '<p><em>' . esc_html__( 'Önce Schema.org yapılandırılmış veriyi ya da llms.txt\'yi açın (AI Hazır Site → Ayarlar).', 'ai-hazir-site' ) . '</em></p>';
		}
		if ( $on ) {
			$key_url = IndexNowModule::key_url();
			$last    = IndexNowModule::service()->last();
			$html   .= '<p>' . esc_html__( 'Anahtar dosyası:', 'ai-hazir-site' ) . ' <a href="' . esc_url( $key_url ) . '">' . esc_html( $key_url ) . '</a></p>'
				. '<p>' . esc_html__( 'Son bildirim:', 'ai-hazir-site' ) . ' ' . esc_html(
					null === $last
						? __( 'henüz yok', 'ai-hazir-site' )
						/* translators: 1: date, 2: number of addresses, 3: HTTP status, 4: meaning. */
						: sprintf( __( '%1$s, %2$d adres, yanıt %3$d: %4$s', 'ai-hazir-site' ), wp_date( 'd.m.Y H:i', $last['at'] ), $last['count'], $last['status'], IndexNowService::meaning( $last['status'] ) )
				) . '</p>'
				. '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">'
				. '<input type="hidden" name="action" value="' . esc_attr( self::ACTION ) . '">'
				. '<input type="hidden" name="integration" value="' . esc_attr( self::INDEXNOW_NOW ) . '">'
				. wp_nonce_field( self::ACTION, '_wpnonce', true, false )
				. get_submit_button( __( 'Şimdi bildir', 'ai-hazir-site' ), 'secondary', 'submit', false )
				. '</form>';
		}
		return $html . self::toggle( self::INDEXNOW, $on );
	}

	/**
	 * AI bots and page caches.
	 */
	private static function bypass_section(): string {
		$found = array();
		if ( WpRocketBypass::detected() ) {
			$found[] = 'WP Rocket';
		}
		if ( LiteSpeedBypass::detected() ) {
			$found[] = LiteSpeedBypass::supported() ? 'LiteSpeed Cache' : __( 'LiteSpeed Cache (7.2 veya üstü gerekir; elle ekleyin)', 'ai-hazir-site' );
		}
		$manual = array();
		if ( defined( 'W3TC' ) ) {
			$manual[] = 'W3 Total Cache → Page Cache → Rejected User Agents';
		}
		if ( defined( 'WPCACHEHOME' ) ) {
			$manual[] = 'WP Super Cache → Advanced → Rejected User Agents';
		}

		$html = '<h2>' . esc_html__( 'AI botlarına önbellekten sayfa sunma', 'ai-hazir-site' ) . '</h2>'
			. '<p>' . esc_html__( 'Sayfa önbelleği AI botlarına kayıtlı kopyayı sunar: botlar eski içeriği görür ve AI Ölçüm bu ziyaretleri sayamaz. Açınca önbellek eklentinizin "bu tarayıcılara önbellek sunma" listesine AI botları eklenir. İnsan ziyaretçiler için hiçbir şey değişmez.', 'ai-hazir-site' ) . '</p>'
			. '<p class="description">' . esc_html__( 'Bedeli: botlar önbelleksiz sayfa alır, sunucu yükü biraz artar. Barındırma firmanızın hız sınırı varsa botlar ona daha sık takılabilir.', 'ai-hazir-site' ) . '</p>'
			. '<p>' . esc_html__( 'Bulunan önbellek eklentileri:', 'ai-hazir-site' ) . ' <strong>' . esc_html( array() === $found ? __( 'yok', 'ai-hazir-site' ) : implode( ', ', $found ) ) . '</strong></p>';
		if ( array() !== $manual ) {
			$html .= '<p>' . esc_html__( 'Bu eklentilerde elle ekleyin (AI bot adları, her satıra bir tane):', 'ai-hazir-site' ) . ' ' . esc_html( implode( '; ', $manual ) ) . '</p>';
		}
		$usable = WpRocketBypass::detected() || LiteSpeedBypass::supported();
		return $html . ( $usable || Features::is_enabled( Features::BOT_CACHE_BYPASS ) ? self::toggle( self::BYPASS, Features::is_enabled( Features::BOT_CACHE_BYPASS ) ) : '' );
	}

	/**
	 * The catalog in the sitemap.
	 */
	private static function sitemap_section(): string {
		$system = CatalogSitemap::system();
		$html   = '<h2>' . esc_html__( 'AI Katalog\'u site haritasına ekle', 'ai-hazir-site' ) . '</h2>'
			. '<p>' . esc_html__( 'Arama motorlarından okuyan AI agentlar ilanlarınızı ancak /ai-katalog/ sayfası dizine girince görür. Açınca sayfa, sitenizin kullandığı site haritasına son değişiklik tarihiyle eklenir.', 'ai-hazir-site' ) . '</p>'
			. '<p>' . esc_html__( 'Kullanılan site haritası:', 'ai-hazir-site' ) . ' <strong>' . esc_html( '' === $system ? __( 'bulunamadı', 'ai-hazir-site' ) : $system ) . '</strong></p>';
		if ( Features::is_enabled( Features::CATALOG_SITEMAP ) ) {
			$html .= '<p>' . esc_html__( 'Site haritamız:', 'ai-hazir-site' ) . ' <a href="' . esc_url( CatalogSitemap::url() ) . '">' . esc_html( CatalogSitemap::url() ) . '</a></p>';
		}
		return $html . self::toggle( self::SITEMAP, Features::is_enabled( Features::CATALOG_SITEMAP ) );
	}

	/**
	 * State and the on/off button.
	 *
	 * @param string $id Integration id.
	 * @param bool   $on Current state.
	 */
	private static function toggle( string $id, bool $on ): string {
		return '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">'
			. '<input type="hidden" name="action" value="' . esc_attr( self::ACTION ) . '">'
			. '<input type="hidden" name="integration" value="' . esc_attr( $id ) . '">'
			. '<input type="hidden" name="state" value="' . ( $on ? 'off' : 'on' ) . '">'
			. wp_nonce_field( self::ACTION, '_wpnonce', true, false )
			. '<p>' . esc_html__( 'Durum:', 'ai-hazir-site' ) . ' <strong>' . esc_html( $on ? __( 'Açık', 'ai-hazir-site' ) : __( 'Kapalı', 'ai-hazir-site' ) ) . '</strong> '
			. get_submit_button( $on ? __( 'Kapat', 'ai-hazir-site' ) : __( 'Aç', 'ai-hazir-site' ), $on ? 'secondary' : 'primary', 'submit', false )
			. '</p></form>';
	}

	/**
	 * `admin_post_aihs_integration`.
	 */
	public function on_save(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified in handle().
		wp_safe_redirect( self::handle( wp_unslash( $_POST ) ) );
		exit;
	}

	/**
	 * Turns one integration on or off; returns where to redirect.
	 *
	 * @param array<mixed> $post Unslashed $_POST.
	 */
	public static function handle( array $post ): string {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'Bu işlem için yetkiniz yok.', 'ai-hazir-site' ), 403 );
		}
		check_admin_referer( self::ACTION );

		$id = isset( $post['integration'] ) && is_string( $post['integration'] ) ? sanitize_key( $post['integration'] ) : '';
		$on = isset( $post['state'] ) && 'on' === $post['state'];
		if ( self::BYPASS === $id ) {
			IntegrationsModule::set_bypass( $on );
		} elseif ( self::SITEMAP === $id ) {
			IntegrationsModule::set_sitemap( $on );
		} elseif ( self::SERVER === $id ) {
			IntegrationsModule::set_server_bypass( $on );
		} elseif ( self::INDEXNOW === $id ) {
			$missing = Features::missing_requirements( Features::INDEXNOW );
			if ( ! $on || array() === $missing['any'] ) {
				IndexNowModule::enable( $on );
			}
		} elseif ( self::IMUNIFY === $id && Features::is_enabled( Features::SECURITY_INTEGRATIONS ) ) {
			$error = SecuritySoftware::set_imunify_lock( $on );
			return AdminMenu::url( self::SLUG, array( 'message' => '' !== $error ? 'security_manual' : ( $on ? 'on' : 'off' ) ) ) . '#aihs-security';
		} elseif ( self::WORDFENCE === $id && Features::is_enabled( Features::SECURITY_INTEGRATIONS ) ) {
			$added = SecuritySoftware::allow_network_in_wordfence();
			return AdminMenu::url( self::SLUG, array( 'message' => array() === $added ? 'wordfence_none' : 'wordfence_added' ) ) . '#aihs-security';
		} elseif ( self::INDEXNOW_NOW === $id ) {
			$result = IndexNowModule::submit();
			return AdminMenu::url( self::SLUG, array( 'message' => null === $result ? 'not_sent' : 'sent' ) );
		} else {
			wp_die( esc_html__( 'Bilinmeyen entegrasyon.', 'ai-hazir-site' ), 400 );
		}
		return AdminMenu::url( self::SLUG, array( 'message' => $on ? 'on' : 'off' ) );
	}
}
