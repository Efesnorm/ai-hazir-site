<?php
/**
 * "Önerilen kurulum" on the settings screen (1.11.0).
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\Tests\Integration\Settings;

use AIHazirSite\Core\Features;
use AIHazirSite\Core\IndexNow\IndexNowService;
use AIHazirSite\Core\Setup\SetupProfiles;
use AIHazirSite\WordPress\IndexNow\IndexNowModule;
use AIHazirSite\WordPress\Settings\SettingsPage;
use WP_UnitTestCase;

/**
 * Preview, apply (only on, requirement order, the features' own work), guards.
 *
 * @covers \AIHazirSite\WordPress\Settings\SettingsPage
 */
final class SetupPresetTest extends WP_UnitTestCase {

	/**
	 * Defaults and an administrator.
	 */
	public function set_up(): void {
		parent::set_up();
		delete_option( Features::OPTION );
		delete_option( IndexNowService::OPTION );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
	}

	/**
	 * Clean-up.
	 */
	public function tear_down(): void {
		unset( $_REQUEST['_wpnonce'] );
		wp_clear_scheduled_hook( IndexNowModule::HOOK );
		parent::tear_down();
	}

	/**
	 * Applies a profile through the handler.
	 *
	 * @param string $profile Profile.
	 */
	private static function apply( string $profile ): string {
		$_REQUEST['_wpnonce'] = wp_create_nonce( SettingsPage::PRESET );
		return SettingsPage::handle_preset( array( 'profil' => $profile ) );
	}

	/**
	 * The preview lists what would be turned on and what is already on; no form when nothing is left.
	 */
	public function test_preview(): void {
		$html = SettingsPage::render_html( '', SetupProfiles::SERVICE );
		$this->assertStringContainsString( 'id="aihs-preset-preview"', $html );
		$this->assertStringContainsString( 'Açılacaklar: AI uyum taraması', $html );
		$this->assertStringContainsString( 'Zaten açık: AI ölçümü', $html );
		$this->assertStringContainsString( 'name="action" value="aihs_settings_preset"', $html );
		$this->assertStringNotContainsString( 'id="aihs-preset-preview"', SettingsPage::render_html() );

		self::apply( SetupProfiles::SERVICE );
		$this->assertStringNotContainsString( 'value="aihs_settings_preset"', SettingsPage::render_html( '', SetupProfiles::SERVICE ), 'Nothing left to turn on.' );
	}

	/**
	 * Apply: every feature of the profile on, the quote box and anything else untouched, the IndexNow key made.
	 */
	public function test_apply_turns_on_only(): void {
		Features::set( Features::MATCHING, false );
		Features::set( Features::COMPLIANCE_WIZARD, true );

		$this->assertStringContainsString( 'message=preset', self::apply( SetupProfiles::PRODUCT ) );
		foreach ( SetupProfiles::all()[ SetupProfiles::PRODUCT ] as $key ) {
			$this->assertTrue( Features::is_enabled( $key ), $key );
		}
		$this->assertFalse( Features::is_enabled( Features::INQUIRIES ) );
		$this->assertFalse( Features::is_enabled( Features::MATCHING ) );
		$this->assertTrue( Features::is_enabled( Features::COMPLIANCE_WIZARD ), 'Nothing is turned off.' );
		$this->assertNotSame( '', IndexNowModule::service()->key(), 'IndexNow turned on with its own work.' );

		self::apply( SetupProfiles::SERVICE );
		$this->assertTrue( Features::is_enabled( Features::A2A ), 'A read-only profile does not turn A2A off.' );
	}

	/**
	 * Unknown profile, bad nonce, no capability.
	 */
	public function test_guards(): void {
		try {
			self::apply( 'olmayan' );
			$this->fail( 'Unknown profile accepted.' );
		} catch ( \WPDieException $e ) {
			$this->assertStringContainsString( 'Bilinmeyen', $e->getMessage() );
		}

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$this->expectException( \WPDieException::class );
		self::apply( SetupProfiles::PRODUCT );
	}
}
