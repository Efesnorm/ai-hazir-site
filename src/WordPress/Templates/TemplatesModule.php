<?php
/**
 * Sector templates (A4) – WordPress wiring.
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\WordPress\Templates;

use AIHazirSite\Core\Features;
use AIHazirSite\Core\Templates\TemplateRegistry;
use AIHazirSite\WordPress\Module;

/**
 * Loads the templates from data/templates plus any folder added with the
 * `aihs_template_dirs` filter, while `templates` is on. Off, everything behaves as in
 * 0.7.0: no template choice and every listing is "general".
 */
final class TemplatesModule implements Module {

	/**
	 * Loaded registry (per request).
	 *
	 * @var TemplateRegistry|null
	 */
	private static ?TemplateRegistry $registry = null;

	/**
	 * Registers hooks.
	 */
	public function register(): void {
		if ( Features::is_enabled( Features::TEMPLATES ) ) {
			add_action( 'admin_notices', array( self::class, 'admin_notice' ) );
		}
	}

	/**
	 * Lists template files that could not be loaded (they are skipped until fixed).
	 */
	public static function admin_notice(): void {
		$registry = self::registry();
		if ( null === $registry || array() === $registry->errors() || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$items = '';
		foreach ( $registry->errors() as $file => $error ) {
			$items .= '<li><code>' . esc_html( basename( $file ) ) . '</code>: ' . esc_html( $error ) . '</li>';
		}
		printf(
			'<div class="notice notice-error"><p>%s</p><ul>%s</ul></div>',
			esc_html__( 'AI Hazır Site: Bazı sektör şablonu dosyaları okunamadı; düzeltilene kadar kullanılmıyorlar.', 'ai-hazir-site' ),
			$items // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Each part escaped above.
		);
	}

	/**
	 * Nothing to clean up.
	 */
	public function deactivate(): void {
	}

	/**
	 * The registry, or null while templates are off.
	 */
	public static function registry(): ?TemplateRegistry {
		if ( ! Features::is_enabled( Features::TEMPLATES ) ) {
			return null;
		}
		if ( null === self::$registry ) {
			/**
			 * Filters the folders sector templates are read from (every *.json file is a template).
			 *
			 * @param string[] $dirs Folders; the plugin's data/templates first.
			 */
			$dirs           = (array) apply_filters( 'aihs_template_dirs', array( TemplateRegistry::data_dir() ) );
			self::$registry = new TemplateRegistry( array_values( array_filter( array_map( 'strval', $dirs ), 'is_dir' ) ) );
		}
		return self::$registry;
	}

	/**
	 * Forgets the loaded registry (after files or the filter changed; used by tests).
	 */
	public static function reset(): void {
		self::$registry = null;
	}

	/**
	 * Current time as ISO 8601 UTC (for freshness).
	 */
	public static function now(): string {
		return gmdate( 'Y-m-d\TH:i:s\Z' );
	}
}
