<?php
/**
 * Tools → AI Bot Erişimi admin page.
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\WordPress\Access\Admin;

use AIHazirSite\WordPress\Admin\AdminMenu;
use AIHazirSite\Core\Access\BotPolicy;
use AIHazirSite\Core\Access\Presets;
use AIHazirSite\Core\Compliance\Robots;
use AIHazirSite\Core\Measurement\Registry;
use AIHazirSite\WordPress\Access\AccessModule;

/**
 * Bot list with category, chosen setting and actual state, presets, preview and warnings.
 * The handler returns the redirect URL so it can be tested without exiting.
 */
final class AccessPage {

	public const SLUG       = 'aihs-bot-access';
	public const CAPABILITY = 'manage_options';
	public const ACTION     = 'aihs_save_bot_policy';

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
		AdminMenu::add( __( 'AI Bot Erişimi', 'ai-hazir-site' ), self::CAPABILITY, self::SLUG, array( $this, 'render' ) );
	}

	/**
	 * Labels.
	 *
	 * @return array{modes: array<string, string>, categories: array<string, string>, presets: array<string, string>}
	 */
	public static function labels(): array {
		return array(
			'modes'      => array(
				BotPolicy::DEFAULT  => __( 'Site kuralları (varsayılan)', 'ai-hazir-site' ),
				BotPolicy::ALLOW    => __( 'İzin ver', 'ai-hazir-site' ),
				BotPolicy::DISALLOW => __( 'Engelle', 'ai-hazir-site' ),
			),
			'categories' => array(
				'training'   => __( 'Model eğitimi', 'ai-hazir-site' ),
				'search'     => __( 'AI arama', 'ai-hazir-site' ),
				'user_agent' => __( 'Kullanıcı adına ziyaret', 'ai-hazir-site' ),
			),
			'presets'    => array(
				Presets::ALLOW_ALL       => __( 'Hepsine izin ver', 'ai-hazir-site' ),
				Presets::SEARCH_AND_USER => __( 'Sadece arama ve kullanıcı agentları', 'ai-hazir-site' ),
				Presets::BLOCK_TRAINING  => __( 'Eğitim botlarını engelle', 'ai-hazir-site' ),
			),
		);
	}

	/**
	 * Renders the page.
	 */
	public function render(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'Bu sayfaya erişim yetkiniz yok.', 'ai-hazir-site' ), 403 );
		}
		echo '<div class="wrap">' . self::render_content() . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in render_content().
	}

	/**
	 * Page body (everything escaped here).
	 */
	public static function render_content(): string {
		$labels   = self::labels();
		$policy   = AccessModule::store()->get();
		$bots     = Registry::bots();
		$physical = AccessModule::physical_file();
		$served   = null === $physical ? AccessModule::virtual_robots() : (string) file_get_contents( $physical ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local file, read only.
		$robots   = new Robots( $served );
		$block    = AccessModule::rules()->block( $policy, $bots );

		$html = '<h1>' . esc_html__( 'AI Bot Erişimi', 'ai-hazir-site' ) . '</h1>';

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display-only flag set by our own redirect.
		if ( isset( $_GET['updated'] ) ) {
			$html .= '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Ayarlar kaydedildi.', 'ai-hazir-site' ) . '</p></div>';
		}

		if ( null !== $physical ) {
			$html .= '<div class="notice notice-warning inline" id="aihs-physical"><p>'
				. esc_html__( 'Sitenizin kökünde fiziksel bir robots.txt dosyası var. Web sunucusu bu dosyayı doğrudan verdiği için buradaki ayarlar otomatik uygulanamaz ve eklenti dosyaya dokunmaz. Aşağıdaki metni dosyanın sonuna kendiniz ekleyin:', 'ai-hazir-site' )
				. '</p><textarea readonly class="large-text code" rows="8" id="aihs-copy">' . esc_textarea( '' === $block ? '' : $block ) . '</textarea></div>';
		}

		if ( '0' === (string) get_option( 'blog_public' ) ) {
			$html .= '<div class="notice notice-warning inline" id="aihs-not-public"><p>' . esc_html__( '"Arama motorlarının bu siteyi dizine eklemesini engelle" ayarı açık. "İzin ver" seçtiğiniz AI botları bu genel kısıtlamayı aşar.', 'ai-hazir-site' ) . '</p></div>';
		}

		$html .= '<p class="description">' . esc_html__( 'robots.txt bir ricadır; kurallara uymayan botları durdurmaz. Önbellek eklentisi kullanıyorsanız değişiklikten sonra önbelleği temizleyin.', 'ai-hazir-site' ) . '</p>';

		$html .= '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" id="aihs-access-form">'
			. '<input type="hidden" name="action" value="' . esc_attr( self::ACTION ) . '">'
			. wp_nonce_field( self::ACTION, '_wpnonce', true, false )
			. '<h2>' . esc_html__( 'Hazır ayarlar', 'ai-hazir-site' ) . '</h2><p>';
		foreach ( $labels['presets'] as $preset => $label ) {
			$html .= '<button type="submit" class="button" name="preset" value="' . esc_attr( $preset ) . '">' . esc_html( $label ) . '</button> ';
		}
		$html .= '</p>';

		$html .= '<h2>' . esc_html__( 'Botlar', 'ai-hazir-site' ) . '</h2><table class="widefat striped" id="aihs-bots"><thead><tr>'
			. '<th>' . esc_html__( 'Bot', 'ai-hazir-site' ) . '</th>'
			. '<th>' . esc_html__( 'İşletmeci', 'ai-hazir-site' ) . '</th>'
			. '<th>' . esc_html__( 'Kategori', 'ai-hazir-site' ) . '</th>'
			. '<th>' . esc_html__( 'Ayar', 'ai-hazir-site' ) . '</th>'
			. '<th>' . esc_html__( 'Şu anki durum', 'ai-hazir-site' ) . '</th>'
			. '</tr></thead><tbody>';

		foreach ( $bots as $bot ) {
			$allowed = $robots->allows( $bot->name, '/' );
			$html   .= '<tr data-bot="' . esc_attr( $bot->id ) . '">'
				. '<td><strong>' . esc_html( $bot->name ) . '</strong></td>'
				. '<td>' . esc_html( $bot->operator ) . '</td>'
				. '<td>' . esc_html( $labels['categories'][ $bot->category ] ?? $bot->category ) . '</td>'
				. '<td><select name="modes[' . esc_attr( $bot->id ) . ']" aria-label="' . esc_attr( $bot->name ) . '">';
			foreach ( $labels['modes'] as $mode => $mode_label ) {
				$html .= '<option value="' . esc_attr( $mode ) . '"' . selected( $policy->mode( $bot->id ), $mode, false ) . '>' . esc_html( $mode_label ) . '</option>';
			}
			$html .= '</select></td>'
				. '<td class="aihs-state-' . ( $allowed ? 'allowed' : 'blocked' ) . '">' . esc_html( $allowed ? __( 'İzinli', 'ai-hazir-site' ) : __( 'Engelli', 'ai-hazir-site' ) ) . '</td>'
				. '</tr>';
		}
		$html .= '</tbody></table>' . get_submit_button( __( 'Kaydet', 'ai-hazir-site' ) ) . '</form>';

		$html .= '<h2>' . esc_html__( 'robots.txt önizlemesi', 'ai-hazir-site' ) . '</h2>'
			. '<p class="description">' . esc_html( null === $physical ? __( 'Sitenin şu anda verdiği robots.txt (diğer eklentilerin satırları dahil).', 'ai-hazir-site' ) : __( 'Fiziksel robots.txt dosyasının şu anki içeriği.', 'ai-hazir-site' ) ) . '</p>'
			. '<textarea readonly class="large-text code" rows="14" id="aihs-preview">' . esc_textarea( $served ) . '</textarea>';

		return $html;
	}

	/**
	 * `admin_post_aihs_save_bot_policy`.
	 */
	public function on_save(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified in handle_save().
		wp_safe_redirect( $this->handle_save( wp_unslash( $_POST ) ) );
		exit;
	}

	/**
	 * Saves a preset or per-bot choices; returns where to redirect.
	 *
	 * @param array<mixed> $post Unslashed $_POST.
	 */
	public function handle_save( array $post ): string {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'Bu işlem için yetkiniz yok.', 'ai-hazir-site' ), 403 );
		}
		check_admin_referer( self::ACTION );

		$bots   = Registry::bots();
		$preset = isset( $post['preset'] ) && is_string( $post['preset'] ) ? sanitize_key( $post['preset'] ) : '';
		if ( in_array( $preset, Presets::ALL, true ) ) {
			$policy = Presets::policy( $preset, $bots );
		} else {
			$modes = array();
			foreach ( is_array( $post['modes'] ?? null ) ? $post['modes'] : array() as $bot_id => $mode ) {
				if ( is_string( $mode ) ) {
					$modes[ sanitize_key( (string) $bot_id ) ] = sanitize_key( $mode );
				}
			}
			$policy = new BotPolicy( $modes );
		}

		AccessModule::store()->save( $policy, $bots );
		return AdminMenu::url( self::SLUG, array( 'updated' => 1 ) );
	}
}
