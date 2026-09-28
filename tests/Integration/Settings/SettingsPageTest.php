<?php
/**
 * Settings → AI Hazır Site (1.9.0).
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\Tests\Integration\Settings;

use AIHazirSite\Core\Features;
use AIHazirSite\WordPress\Integrations\LiteSpeedBypass;
use AIHazirSite\WordPress\Settings\SettingsModule;
use AIHazirSite\WordPress\Settings\SettingsPage;
use AIHazirSite\WordPress\Uninstaller;
use WP_UnitTestCase;

/**
 * Every feature on the screen; on/off with capability and nonce; requirements enforced; special flows kept.
 *
 * @covers \AIHazirSite\WordPress\Settings\SettingsPage
 * @covers \AIHazirSite\WordPress\Settings\SettingsModule
 */
final class SettingsPageTest extends WP_UnitTestCase {

	/**
	 * Administrator with a valid nonce for both actions; defaults.
	 */
	public function set_up(): void {
		parent::set_up();
		delete_option( Features::OPTION );
		delete_option( Uninstaller::DELETE_OPTION );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
	}

	/**
	 * Clears the request.
	 */
	public function tear_down(): void {
		unset( $_REQUEST['_wpnonce'] );
		parent::tear_down();
	}

	/**
	 * Toggles a feature through the handler.
	 *
	 * @param string $key Feature key.
	 * @param bool   $on  New state.
	 */
	private static function toggle( string $key, bool $on ): string {
		$_REQUEST['_wpnonce'] = wp_create_nonce( SettingsPage::TOGGLE );
		return SettingsPage::handle_toggle(
			array(
				'feature' => $key,
				'state'   => $on ? 'on' : 'off',
			)
		);
	}

	/**
	 * Every declared feature appears once, in exactly one group, with a name.
	 */
	public function test_every_feature_on_screen(): void {
		$grouped = array_merge( ...array_values( SettingsPage::groups() ) );
		sort( $grouped );
		$declared = array_keys( Features::defaults() );
		sort( $declared );
		$this->assertSame( $declared, $grouped );
		$this->assertSame( $declared, array_values( array_intersect( $declared, array_keys( SettingsPage::features() ) ) ) );

		$html = SettingsPage::render_html();
		foreach ( Features::defaults() as $key => $default ) {
			$this->assertStringContainsString( 'id="aihs-feature-' . $key . '"', $html );
		}
		$this->assertStringContainsString( 'Önce şunu açın: AI Katalog', $html, 'REST API row with the catalog off.' );
	}

	/**
	 * On and off; requirements block both ways, nothing is chained.
	 */
	public function test_toggle_with_requirements(): void {
		$this->assertStringContainsString( 'message=blocked', self::toggle( Features::REST_API, true ) );
		$this->assertFalse( Features::is_enabled( Features::REST_API ) );
		$this->assertFalse( Features::is_enabled( Features::CATALOG ), 'Not turned on by itself.' );

		$this->assertStringContainsString( 'message=on', self::toggle( Features::CATALOG, true ) );
		$this->assertStringContainsString( 'message=on', self::toggle( Features::REST_API, true ) );
		$this->assertTrue( Features::is_enabled( Features::REST_API ) );

		$this->assertStringContainsString( 'message=blocked', self::toggle( Features::CATALOG, false ) );
		$this->assertTrue( Features::is_enabled( Features::CATALOG ) );
		$this->assertStringContainsString( 'Önce şunu kapatın: REST API', SettingsPage::render_html() );

		self::toggle( Features::REST_API, false );
		$this->assertStringContainsString( 'message=off', self::toggle( Features::CATALOG, false ) );
		$this->assertFalse( Features::is_enabled( Features::CATALOG ) );

		$this->assertStringContainsString( 'message=off', self::toggle( Features::MEASUREMENT, false ) );
		$this->assertFalse( Features::is_enabled( Features::MEASUREMENT ) );
	}

	/**
	 * The quote box keeps its privacy-notice flow; the cache integration runs the Integrations work.
	 */
	public function test_special_flows(): void {
		self::toggle( Features::CATALOG, true );
		$this->assertStringContainsString( 'message=blocked', self::toggle( Features::INQUIRIES, true ) );
		$this->assertFalse( Features::is_enabled( Features::INQUIRIES ) );
		$this->assertStringContainsString( 'Teklif Kutusu ekranında açın', SettingsPage::render_html() );

		// LiteSpeed Cache 7.2+ API, simulated: turning on here writes our tokens there.
		if ( ! defined( 'LSCWP_V' ) ) {
			define( 'LSCWP_V', '7.2' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- LiteSpeed Cache's constant.
		}
		$saved = array();
		add_filter( 'litespeed_conf', static fn( $key ) => LiteSpeedBypass::SETTING === $key ? array() : $key ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- LiteSpeed Cache API.
		add_action(
			'litespeed_save_conf',
			static function ( $matrix ) use ( &$saved ): void { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- LiteSpeed Cache API.
				$saved = $matrix[ LiteSpeedBypass::SETTING ];
			}
		);
		self::toggle( Features::BOT_CACHE_BYPASS, true );
		$this->assertTrue( Features::is_enabled( Features::BOT_CACHE_BYPASS ) );
		$this->assertContains( 'GPTBot', $saved );
		remove_all_filters( 'litespeed_conf' );
		remove_all_actions( 'litespeed_save_conf' );
		delete_option( LiteSpeedBypass::ADDED_OPTION );
	}

	/**
	 * Delete-data choice, capability, nonce, unknown key, plugin row link.
	 */
	public function test_delete_data_and_guards(): void {
		$_REQUEST['_wpnonce'] = wp_create_nonce( SettingsPage::DELETE_DATA );
		SettingsPage::handle_delete_data( array( 'delete' => '1' ) );
		$this->assertTrue( Uninstaller::should_delete_data() );
		SettingsPage::handle_delete_data( array() );
		$this->assertFalse( Uninstaller::should_delete_data() );

		$links = SettingsModule::action_links( array( 'deactivate' => 'x' ) );
		$this->assertSame( array( 'aihs-settings', 'deactivate' ), array_keys( $links ) );
		$this->assertStringContainsString( 'options-general.php?page=aihs-settings', $links['aihs-settings'] );

		try {
			self::toggle( 'olmayan', true );
			$this->fail( 'Unknown key accepted.' );
		} catch ( \WPDieException $e ) {
			$this->assertStringContainsString( 'Bilinmeyen', $e->getMessage() );
		}

		$_REQUEST['_wpnonce'] = 'bozuk';
		try {
			SettingsPage::handle_toggle(
				array(
					'feature' => Features::CATALOG,
					'state'   => 'on',
				)
			);
			$this->fail( 'Bad nonce accepted.' );
		} catch ( \WPDieException $e ) {
			$this->assertFalse( Features::is_enabled( Features::CATALOG ) );
		}

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$this->expectException( \WPDieException::class );
		self::toggle( Features::CATALOG, true );
	}
}
