<?php
/**
 * One top-level menu for every screen (1.15.0).
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\Tests\Integration\Admin;

use AIHazirSite\Core\Features;
use AIHazirSite\WordPress\Admin\AdminMenu;
use AIHazirSite\WordPress\Catalog\CatalogModule;
use AIHazirSite\WordPress\Compliance\ComplianceModule;
use AIHazirSite\WordPress\Measurement\MeasurementModule;
use AIHazirSite\WordPress\Settings\SettingsModule;
use WP_UnitTestCase;

/**
 * Pilot feedback: the screens were spread over Settings, Tools and an "AI Katalog" menu.
 *
 * @covers \AIHazirSite\WordPress\Admin\AdminMenu
 * @covers \AIHazirSite\WordPress\Settings\SettingsPage
 */
final class AdminMenuTest extends WP_UnitTestCase {

	/**
	 * Administrator on an admin screen; menus from scratch.
	 */
	public function set_up(): void {
		parent::set_up();
		global $submenu, $menu;
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		set_current_screen( 'dashboard' );
		delete_option( Features::OPTION );
		remove_all_actions( 'admin_menu' );
		$submenu = array(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Test isolation.
		$menu    = array(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Test isolation.
	}

	/**
	 * Screens of the features that are on, under one menu, in the set order; none under Tools or Settings.
	 */
	public function test_screens_under_one_menu_in_order(): void {
		global $submenu, $menu;
		foreach ( array( Features::CATALOG, Features::COMPLIANCE_SCAN ) as $feature ) {
			Features::set( $feature, true );
		}
		foreach ( array( new CatalogModule(), new ComplianceModule(), new MeasurementModule(), new SettingsModule() ) as $module ) {
			$module->register();
		}
		do_action( 'admin_menu' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core hook.

		$this->assertContains( AdminMenu::PARENT, array_column( $menu, 2 ) );
		$this->assertNotContains( 'aihs-catalog', array_column( $menu, 2 ), 'The old AI Katalog top-level menu is gone.' );
		$this->assertSame(
			array( 'aihs-settings', 'aihs-catalog', 'aihs-catalog-demand', 'aihs-catalog-supply', 'aihs-catalog-profile', 'aihs-measurement', 'aihs-compliance' ),
			array_column( $submenu[ AdminMenu::PARENT ], 2 )
		);
		$this->assertArrayNotHasKey( 'tools.php', $submenu );
		$this->assertArrayNotHasKey( 'options-general.php', $submenu );
		$this->assertSame( admin_url( 'admin.php?page=aihs-measurement' ), menu_page_url( 'aihs-measurement', false ) );
	}

	/**
	 * A feature that is off adds no screen.
	 */
	public function test_feature_off_adds_no_screen(): void {
		global $submenu;
		foreach ( array( new CatalogModule(), new ComplianceModule(), new SettingsModule() ) as $module ) {
			$module->register();
		}
		do_action( 'admin_menu' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core hook.

		$this->assertSame( array( 'aihs-settings' ), array_column( $submenu[ AdminMenu::PARENT ], 2 ) );
	}

	/**
	 * Old addresses map to the new one with their arguments; other plugins' pages are left alone.
	 */
	public function test_legacy_addresses(): void {
		$this->assertSame(
			admin_url( 'admin.php?page=aihs-measurement&days=30' ),
			AdminMenu::legacy_target(
				'tools.php',
				array(
					'page' => 'aihs-measurement',
					'days' => '30',
				)
			)
		);
		$this->assertSame( admin_url( 'admin.php?page=aihs-settings' ), AdminMenu::legacy_target( 'options-general.php', array( 'page' => 'aihs-settings' ) ) );
		$this->assertSame( admin_url( 'admin.php?page=aihs-updates' ), AdminMenu::legacy_target( 'options-general.php', array( 'page' => 'aihs-updates' ) ) );
		$this->assertNull( AdminMenu::legacy_target( 'tools.php', array( 'page' => 'baska-eklenti' ) ) );
		$this->assertNull( AdminMenu::legacy_target( 'tools.php', array() ) );
		$this->assertNull( AdminMenu::legacy_target( 'admin.php', array( 'page' => 'aihs-measurement' ) ) );
		$this->assertNull( AdminMenu::legacy_target( 'options-general.php', array( 'page' => 'aihs-measurement' ) ) );

		// WordPress refuses an unknown tools.php?page=… before admin_init; the redirect runs just before that.
		AdminMenu::register();
		$this->assertNotFalse( has_action( 'admin_page_access_denied', array( AdminMenu::class, 'redirect_legacy' ) ) );
		$this->assertNotFalse( has_action( 'admin_init', array( AdminMenu::class, 'redirect_legacy' ) ) );
	}

	/**
	 * Screen links point to the new address.
	 */
	public function test_links_use_new_address(): void {
		$this->assertSame( admin_url( 'admin.php?page=aihs-settings' ), \AIHazirSite\WordPress\Settings\SettingsPage::url() );
		$this->assertSame( admin_url( 'admin.php?page=aihs-measurement' ), \AIHazirSite\WordPress\Measurement\Admin\ReportPage::url() );
		$this->assertSame( admin_url( 'admin.php?page=aihs-wizard' ), \AIHazirSite\WordPress\Compliance\Wizard\WizardPage::url() );
		$this->assertSame( admin_url( 'admin.php?page=aihs-updates' ), \AIHazirSite\WordPress\Updates\UpdatesAdmin::url() );
		$this->assertSame( admin_url( 'admin.php?page=aihs-inquiries' ), \AIHazirSite\WordPress\Inquiry\Admin\InquiryAdmin::url() );
	}
}
