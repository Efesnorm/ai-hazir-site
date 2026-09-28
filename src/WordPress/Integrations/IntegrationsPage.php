<?php
/**
 * Tools → AI Hazır Entegrasyonlar.
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\WordPress\Integrations;

use AIHazirSite\Core\Features;

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
		add_management_page( __( 'AI Hazır Entegrasyonlar', 'ai-hazir-site' ), __( 'AI Hazır Entegrasyonlar', 'ai-hazir-site' ), self::CAPABILITY, self::SLUG, array( $this, 'render' ) );
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
			'on'  => __( 'Entegrasyon açıldı.', 'ai-hazir-site' ),
			'off' => __( 'Entegrasyon kapatıldı.', 'ai-hazir-site' ),
		);
		$html    = '<h1>' . esc_html__( 'AI Hazır Entegrasyonlar', 'ai-hazir-site' ) . '</h1>'
			. ( isset( $notices[ $message ] ) ? '<div class="notice notice-success"><p>' . esc_html( $notices[ $message ] ) . '</p></div>' : '' )
			. '<p class="description">' . esc_html__( 'Sitenizdeki diğer eklentilerle yapılabilecek işler. Hiçbiri kendiliğinden yapılmaz; her birini buradan açıp kapatabilirsiniz. Kapatınca eklediğimiz her şey geri alınır.', 'ai-hazir-site' ) . '</p>';

		return $html . self::bypass_section() . self::sitemap_section();
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
		} else {
			wp_die( esc_html__( 'Bilinmeyen entegrasyon.', 'ai-hazir-site' ), 400 );
		}
		return add_query_arg(
			array(
				'page'    => self::SLUG,
				'message' => $on ? 'on' : 'off',
			),
			admin_url( 'tools.php' )
		);
	}
}
