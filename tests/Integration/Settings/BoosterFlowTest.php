<?php
/**
 * Booster AI end to end (1.25.0).
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\Tests\Integration\Settings;

use AIHazirSite\Core\Features;
use AIHazirSite\Core\Setup\Booster;
use AIHazirSite\WordPress\Settings\BoosterModule;
use AIHazirSite\WordPress\Settings\SettingsPage;
use AIHazirSite\WordPress\Updates\UpdateModule;
use WP_UnitTestCase;
use WPDieException;

/**
 * The button turns on the pre-ticked features with their own work, sets up automatic updates, schedules the first
 * run and can go back; nonce and capability are required.
 *
 * @covers \AIHazirSite\WordPress\Settings\BoosterModule
 * @covers \AIHazirSite\WordPress\Settings\SettingsPage
 */
final class BoosterFlowTest extends WP_UnitTestCase {

	/**
	 * Fresh features, settings and update options; outgoing requests fail fast.
	 */
	public function set_up(): void {
		parent::set_up();
		foreach ( array( Features::OPTION, BoosterModule::SNAPSHOT, UpdateModule::SETTINGS ) as $option ) {
			delete_option( $option );
		}
		delete_site_option( 'auto_update_plugins' );
		wp_clear_scheduled_hook( BoosterModule::HOOK );
		add_filter( 'pre_http_request', static fn(): \WP_Error => new \WP_Error( 'http_request_failed', 'test' ) );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
	}

	/**
	 * Clears request globals.
	 */
	public function tear_down(): void {
		unset( $_REQUEST['_wpnonce'] );
		parent::tear_down();
	}

	/**
	 * The posted choice of the box (pre-ticked boxes, as the browser would send them).
	 *
	 * @return array<string, mixed>
	 */
	private static function posted(): array {
		$_REQUEST['_wpnonce'] = wp_create_nonce( BoosterModule::RUN );
		return array( 'features' => BoosterModule::preselected() );
	}

	/**
	 * One click: features, updates, first run scheduled, result shown once; back to before.
	 */
	public function test_run_and_revert(): void {
		$html = SettingsPage::render_html();
		$this->assertStringContainsString( 'id="aihs-booster"', $html );
		$this->assertStringContainsString( 'name="features[]" value="mcp" checked=\'checked\'', $html );
		$this->assertStringNotContainsString( 'name="features[]" value="portal_mode" checked', $html, 'A company site is not turned into a portal.' );
		$this->assertStringNotContainsString( 'name="features[]" value="inquiries"', $html );
		$this->assertStringNotContainsString( 'name="features[]" value="telemetry"', $html );

		$redirect = BoosterModule::handle_run( self::posted() );
		$this->assertStringContainsString( 'message=booster', $redirect );

		foreach ( Booster::preselected( false, false ) as $key ) {
			$this->assertTrue( Features::is_enabled( $key ), $key );
		}
		$this->assertFalse( Features::is_enabled( Features::PORTAL_MODE ) );
		$this->assertFalse( Features::is_enabled( Features::INQUIRIES ) );
		$this->assertFalse( Features::is_enabled( Features::TELEMETRY ) );

		$this->assertSame( BoosterModule::MANIFEST, UpdateModule::server() );
		$this->assertTrue( BoosterModule::auto_update_on() );
		$this->assertNotFalse( wp_next_scheduled( BoosterModule::HOOK ) );

		$result = SettingsPage::render_html( 'booster' );
		$this->assertStringContainsString( 'id="aihs-booster-result"', $result );
		$this->assertStringContainsString( 'Otomatik güncelleme açık', $result );
		$this->assertStringContainsString( 'Portal ağı açık; bu sitenin rolünü', $result );
		$this->assertStringNotContainsString( 'id="aihs-booster-result"', SettingsPage::render_html(), 'Shown once.' );
		$this->assertStringContainsString( 'Booster&#039;dan önceki duruma dön', SettingsPage::render_html() );

		// The first run never throws, even with every request failing.
		BoosterModule::first_run();

		// Back to before: only measurement (and its test filter) on, updates as they were.
		$_REQUEST['_wpnonce'] = wp_create_nonce( BoosterModule::REVERT );
		BoosterModule::handle_revert();
		foreach ( Booster::candidates() as $key ) {
			$this->assertSame( Features::defaults()[ $key ], Features::is_enabled( $key ), $key );
		}
		$this->assertSame( '', UpdateModule::server() );
		$this->assertFalse( BoosterModule::auto_update_on() );
		$this->assertFalse( get_option( BoosterModule::SNAPSHOT ) );
	}

	/**
	 * An update server the owner set is kept; a second run keeps the first snapshot.
	 */
	public function test_keeps_owner_choices(): void {
		update_option( UpdateModule::SETTINGS, array( 'server' => 'https://guncelleme.ornek.com/ai-hazir-site.json' ) );
		BoosterModule::handle_run( self::posted() );
		$this->assertSame( 'https://guncelleme.ornek.com/ai-hazir-site.json', UpdateModule::server() );

		$first = get_option( BoosterModule::SNAPSHOT );
		BoosterModule::handle_run( self::posted() );
		$this->assertSame( $first, get_option( BoosterModule::SNAPSHOT ) );
		$this->assertFalse( $first['features'][ Features::CATALOG ] ?? true );
	}

	/**
	 * Nonce and capability.
	 */
	public function test_guards(): void {
		try {
			BoosterModule::handle_run( array( 'features' => array( Features::CATALOG ) ) );
			$this->fail( 'Nonce required.' );
		} catch ( WPDieException $e ) {
			$this->assertFalse( Features::is_enabled( Features::CATALOG ) );
		}

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$this->expectException( WPDieException::class );
		BoosterModule::handle_run( self::posted() );
	}
}
