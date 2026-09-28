<?php
/**
 * Settings screen (1.9.0) – WordPress wiring.
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\WordPress\Settings;

use AIHazirSite\WordPress\Module;

/**
 * Settings → AI Hazır Site, and the standard "Ayarlar" link on the Plugins screen
 * (`plugin_action_links_{plugin file}`). Not behind a feature key: the screen changes nothing by
 * itself, it is how keys are changed (approved exception, see CHANGELOG 1.9.0).
 */
final class SettingsModule implements Module {

	/**
	 * Registers hooks.
	 */
	public function register(): void {
		if ( ! is_admin() ) {
			return;
		}
		( new SettingsPage() )->register();
		if ( defined( 'AIHS_FILE' ) ) {
			add_filter( 'plugin_action_links_' . plugin_basename( (string) AIHS_FILE ), array( self::class, 'action_links' ) );
		}
	}

	/**
	 * Nothing to clean up.
	 */
	public function deactivate(): void {
	}

	/**
	 * "Ayarlar" first in the plugin's row.
	 *
	 * @param mixed $links Existing links.
	 * @return array<int|string, mixed>
	 */
	public static function action_links( mixed $links ): array {
		$settings = '<a href="' . esc_url( SettingsPage::url() ) . '">' . esc_html__( 'Ayarlar', 'ai-hazir-site' ) . '</a>';
		return array_merge( array( 'aihs-settings' => $settings ), is_array( $links ) ? $links : array() );
	}
}
