<?php
/**
 * Announces our AI resources on the site's own pages (1.7.0) – WordPress wiring.
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\WordPress\Discovery;

use AIHazirSite\Adapters\Discovery\DiscoveryLinks;
use AIHazirSite\Core\Features;
use AIHazirSite\WordPress\Catalog\Admin\CatalogAdmin;
use AIHazirSite\WordPress\Llms\LlmsModule;
use AIHazirSite\WordPress\Module;
use AIHazirSite\WordPress\Rest\RestModule;
use AIHazirSite\WordPress\Schema\SchemaModule;

/**
 * While `discovery` is on, every front-end page carries <link> elements (and the response a Link
 * header) to /llms.txt and the REST API, for whichever of them is on. The site owner may also show a
 * small visible line at the bottom of the pages (agents read visible text), off by default.
 * Settings: AI Hazır Site → Firma Profili.
 */
final class DiscoveryModule implements Module {

	public const VISIBLE_OPTION = 'aihs_discovery_visible';
	public const SAVE           = 'aihs_save_discovery';

	/**
	 * Registers hooks.
	 */
	public function register(): void {
		add_action( 'admin_post_' . self::SAVE, array( self::class, 'on_save' ) );
		if ( ! Features::is_enabled( Features::DISCOVERY ) ) {
			return;
		}
		add_action( 'wp_head', array( self::class, 'print_links' ), 2 );
		add_action( 'template_redirect', array( self::class, 'send_header' ) );
		add_action( 'wp_footer', array( self::class, 'print_visible' ) );
	}

	/**
	 * Nothing to clean up.
	 */
	public function deactivate(): void {
	}

	/**
	 * Links for the resources that are on.
	 *
	 * @return list<array{rel: string, href: string, type: string, title: string}>
	 */
	public static function links(): array {
		return DiscoveryLinks::links(
			Features::is_enabled( Features::LLMS_TXT ) && null === LlmsModule::physical_file() ? home_url( '/' . LlmsModule::FILE ) : '',
			Features::is_enabled( Features::REST_API ) ? RestModule::url() : ''
		);
	}

	/**
	 * `wp_head`: the <link> elements.
	 */
	public static function print_links(): void {
		echo DiscoveryLinks::html( self::links() ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in DiscoveryLinks::html().
	}

	/**
	 * `template_redirect`: the Link header on front-end responses.
	 */
	public static function send_header(): void {
		$value = DiscoveryLinks::header( self::links() );
		if ( '' === $value || headers_sent() || is_admin() || is_feed() || is_robots() ) {
			return;
		}
		header( 'Link: ' . $value, false );
	}

	/**
	 * `wp_footer`: the visible line, when the site owner turned it on.
	 */
	public static function print_visible(): void {
		echo self::visible_html(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in visible_html().
	}

	/**
	 * The visible line ('' when off or nothing to show).
	 */
	public static function visible_html(): string {
		if ( ! get_option( self::VISIBLE_OPTION ) ) {
			return '';
		}
		$items = array();
		if ( SchemaModule::catalog_enabled() ) {
			$items[] = '<a href="' . esc_url( SchemaModule::catalog_url() ) . '">' . esc_html__( 'AI Katalog', 'ai-hazir-site' ) . '</a>';
		}
		foreach ( self::links() as $link ) {
			if ( DiscoveryLinks::LLMS_REL === $link['rel'] ) {
				$items[] = '<a href="' . esc_url( $link['href'] ) . '">llms.txt</a>';
			}
		}
		if ( array() === $items ) {
			return '';
		}
		return '<p class="aihs-discovery" style="text-align:center;font-size:12px;margin:8px 0">'
			. esc_html__( 'AI asistanları için:', 'ai-hazir-site' ) . ' ' . implode( ' · ', $items ) . '</p>';
	}

	/**
	 * Settings section under the company profile form.
	 */
	public static function settings_html(): string {
		return '<h2>' . esc_html__( 'AI keşif', 'ai-hazir-site' ) . '</h2>'
			. '<p class="description">' . esc_html__( 'AI agentlar çoğu zaman yalnızca sitenin sayfalarını okur; llms.txt ve API adreslerine kendiliğinden bakmaz. Bu ayarlar sayfalarınızdan bu kaynaklara standart bağlantılar verir.', 'ai-hazir-site' ) . '</p>'
			. '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" id="aihs-discovery-form">'
			. '<input type="hidden" name="action" value="' . esc_attr( self::SAVE ) . '">'
			. wp_nonce_field( self::SAVE, '_wpnonce', true, false )
			. '<p><label><input type="checkbox" name="announce" value="1"' . checked( Features::is_enabled( Features::DISCOVERY ), true, false ) . '> '
			. esc_html__( 'Sayfalarda AI kaynaklarını duyur (sayfa başında görünmeyen bağlantılar: llms.txt, API)', 'ai-hazir-site' ) . '</label></p>'
			. '<p><label><input type="checkbox" name="visible" value="1"' . checked( (bool) get_option( self::VISIBLE_OPTION ), true, false ) . '> '
			. esc_html__( 'Sayfa altında küçük, görünür bir satır göster ("AI asistanları için: AI Katalog · llms.txt")', 'ai-hazir-site' ) . '</label></p>'
			. get_submit_button( __( 'Keşif ayarlarını kaydet', 'ai-hazir-site' ), 'secondary' )
			. '</form>';
	}

	/**
	 * `admin_post_aihs_save_discovery`.
	 */
	public static function on_save(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified in handle_save().
		wp_safe_redirect( self::handle_save( wp_unslash( $_POST ) ) );
		exit;
	}

	/**
	 * Saves both settings; returns where to redirect.
	 *
	 * @param array<mixed> $post Unslashed $_POST.
	 */
	public static function handle_save( array $post ): string {
		CatalogAdmin::authorize( self::SAVE );
		Features::set( Features::DISCOVERY, ! empty( $post['announce'] ) );
		update_option( self::VISIBLE_OPTION, empty( $post['visible'] ) ? 0 : 1, false );
		return CatalogAdmin::profile_url( array( 'message' => 'saved' ) );
	}
}
