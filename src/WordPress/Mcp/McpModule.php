<?php
/**
 * MCP server over the catalog abilities (A6).
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\WordPress\Mcp;

use AIHazirSite\WordPress\Abilities\AbilitiesModule;
use AIHazirSite\Core\Features;
use AIHazirSite\WordPress\Inquiry\InquiryChannels;
use AIHazirSite\WordPress\Module;
use WP\MCP\Core\McpAdapter;

/**
 * While `mcp` and `abilities` are on, publishes the four read-only catalog abilities as the MCP
 * server `aihs-catalog` at /wp-json/aihs/mcp (MCP Adapter 0.6.1). Public read, as approved: the
 * adapter's HttpTransport needs a logged-in user for its sessions, so the server uses our
 * sessionless StatelessHttpTransport; each tool call is limited per client by the abilities.
 * The adapter's own default server (login required, only explicitly public abilities) is left
 * as the adapter creates it.
 */
final class McpModule implements Module {

	public const SERVER_ID = 'aihs-catalog';
	public const NAMESPACE = 'aihs';
	public const ROUTE     = 'mcp';

	/**
	 * Registers hooks.
	 */
	public function register(): void {
		if ( ! self::enabled() || ! class_exists( McpAdapter::class ) ) {
			return;
		}
		add_action( 'mcp_adapter_init', array( self::class, 'create_server' ) );
		if ( did_action( 'plugins_loaded' ) ) {
			McpAdapter::instance();
			return;
		}
		add_action( 'plugins_loaded', array( self::class, 'start' ) );
	}

	/**
	 * Nothing to clean up.
	 */
	public function deactivate(): void {
	}

	/**
	 * Whether the server is published (needs the abilities it serves).
	 */
	public static function enabled(): bool {
		return Features::is_enabled( Features::MCP ) && Features::is_enabled( Features::ABILITIES );
	}

	/**
	 * `plugins_loaded`: starts the adapter (a singleton shared with other plugins).
	 */
	public static function start(): void {
		McpAdapter::instance();
	}

	/**
	 * `mcp_adapter_init`: creates our server.
	 *
	 * @param McpAdapter $adapter Adapter.
	 */
	public static function create_server( McpAdapter $adapter ): void {
		$adapter->create_server(
			self::SERVER_ID,
			self::NAMESPACE,
			self::ROUTE,
			__( 'AI Hazır Site – AI Katalog', 'ai-hazir-site' ),
			InquiryChannels::abilities_enabled()
				? __( 'Firmanın profili ve geçerli ilanları: arama, ilan ayrıntısı ve müsaitlik sorusu; firmaya talep bırakma (otomatik onay ve otomatik yanıt yok).', 'ai-hazir-site' )
				: __( 'Firmanın profili ve geçerli ilanları: arama, ilan ayrıntısı ve müsaitlik sorusu. Salt okuma.', 'ai-hazir-site' ),
			defined( 'AIHS_VERSION' ) ? (string) AIHS_VERSION : '0',
			array( StatelessHttpTransport::class ),
			null,
			McpObservability::class,
			self::tools(),
			array(),
			array(),
			array( self::class, 'allow' )
		);
	}

	/**
	 * Tools: the four catalog abilities, plus this site's inquiry ability while the inquiry box is on.
	 *
	 * @return list<string>
	 */
	public static function tools(): array {
		$tools = array_keys( AbilitiesModule::schemas() );
		if ( InquiryChannels::abilities_enabled() ) {
			$tools[] = InquiryChannels::ability_name();
		}
		return $tools;
	}

	/**
	 * Transport permission: public (approved access model; the one writing tool is rate limited and spam checked).
	 */
	public static function allow(): bool {
		return true;
	}

	/**
	 * Endpoint path (for the measurement).
	 */
	public static function path(): string {
		return (string) wp_parse_url( rest_url( self::NAMESPACE . '/' . self::ROUTE ), PHP_URL_PATH );
	}

	/**
	 * Endpoint URL.
	 */
	public static function url(): string {
		return rest_url( self::NAMESPACE . '/' . self::ROUTE );
	}
}
