<?php
/**
 * The SEO plugin notice: own screens only, dismissible per user (1.15.0).
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\Tests\Integration\Admin;

use AIHazirSite\WordPress\Admin\AdminNotices;
use AIHazirSite\WordPress\Schema\SchemaModule;
use AIHazirSite\WordPress\Uninstaller;
use WP_UnitTestCase;
use WPDieException;

/**
 * Pilot feedback: the Rank Math notice showed on every admin screen and could not be closed.
 *
 * @covers \AIHazirSite\WordPress\Admin\AdminNotices
 * @covers \AIHazirSite\WordPress\Schema\SchemaModule::admin_notice
 */
final class AdminNoticesTest extends WP_UnitTestCase {

	/**
	 * Administrator; Rank Math "active" (through SeoConflict's filter).
	 */
	public function set_up(): void {
		parent::set_up();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		add_filter( 'aihs_schema_seo_conflict', static fn(): string => 'Rank Math' );
	}

	/**
	 * Restores the admin page global.
	 */
	public function tear_down(): void {
		unset( $GLOBALS['plugin_page'] );
		parent::tear_down();
	}

	/**
	 * Only on the plugin's own screens.
	 */
	public function test_only_on_own_screens(): void {
		$GLOBALS['plugin_page'] = 'baska-eklenti'; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Test.
		$this->assertStringNotContainsString( 'aihs-seo-conflict', self::notices() );

		unset( $GLOBALS['plugin_page'] );
		$this->assertStringNotContainsString( 'aihs-seo-conflict', self::notices(), 'Dashboard and other core screens.' );

		$GLOBALS['plugin_page'] = 'aihs-settings'; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Test.
		$html                   = self::notices();
		$this->assertStringContainsString( 'aihs-seo-conflict', $html );
		$this->assertStringContainsString( 'notice-info', $html );
		$this->assertStringContainsString( 'Bir daha gösterme', $html );
	}

	/**
	 * Dismissed for this user only; unknown ids refused; uninstall removes the choice.
	 */
	public function test_dismiss_per_user(): void {
		$GLOBALS['plugin_page'] = 'aihs-measurement'; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Test.
		$first                  = get_current_user_id();

		$this->assertTrue( AdminNotices::dismiss( AdminNotices::SEO_CONFLICT ) );
		$this->assertStringNotContainsString( 'aihs-seo-conflict', self::notices() );
		$this->assertFalse( AdminNotices::dismiss( 'uydurma' ) );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$this->assertStringContainsString( 'aihs-seo-conflict', self::notices(), 'Another administrator still sees it.' );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$this->assertFalse( AdminNotices::dismiss( AdminNotices::SEO_CONFLICT ), 'Needs manage_options.' );

		update_option( Uninstaller::DELETE_OPTION, 1 );
		Uninstaller::run();
		$this->assertFalse( AdminNotices::dismissed( AdminNotices::SEO_CONFLICT, $first ) );
	}

	/**
	 * The admin-post handler refuses a request without a valid nonce.
	 */
	public function test_handler_needs_nonce(): void {
		$_GET['notice'] = AdminNotices::SEO_CONFLICT;
		try {
			AdminNotices::on_dismiss();
			$this->fail( 'Expected wp_die.' );
		} catch ( WPDieException $e ) {
			$this->assertFalse( AdminNotices::dismissed( AdminNotices::SEO_CONFLICT, get_current_user_id() ) );
		} finally {
			unset( $_GET['notice'] );
		}
		$this->assertStringContainsString( '_wpnonce=', AdminNotices::dismiss_url( AdminNotices::SEO_CONFLICT ) );
	}

	/**
	 * Admin notices HTML.
	 */
	private static function notices(): string {
		ob_start();
		SchemaModule::admin_notice();
		return (string) ob_get_clean();
	}
}
