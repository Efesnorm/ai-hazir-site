<?php
/**
 * Opt-in uninstall removes the portal's user links and the plugin's transients.
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\Tests\Integration\Portal;

use AIHazirSite\Core\Features;
use AIHazirSite\Core\Migrations\Migrator;
use AIHazirSite\WordPress\Platform\WpSettings;
use AIHazirSite\WordPress\Plugin;
use AIHazirSite\WordPress\Portal\Portal;
use AIHazirSite\WordPress\Uninstaller;
use WP_UnitTestCase;

/**
 * No business link on users and no aihs_* transient left after uninstall.
 *
 * @covers \AIHazirSite\WordPress\Uninstaller
 */
final class PortalUninstallTest extends WP_UnitTestCase {

	/**
	 * Real DDL (the rollback drops tables).
	 */
	public function set_up(): void {
		parent::set_up();
		remove_filter( 'query', array( $this, '_create_temporary_tables' ) );
		remove_filter( 'query', array( $this, '_drop_temporary_tables' ) );
	}

	/**
	 * Schema back to the latest version for the other tests.
	 */
	public function tear_down(): void {
		( new Migrator( Plugin::migrations(), new WpSettings() ) )->migrate();
		parent::tear_down();
	}

	/**
	 * Kept without the opt-in; removed with it.
	 */
	public function test_user_links_and_transients(): void {
		global $wpdb;
		Features::set( Features::PORTAL_MODE, true );
		$business = Portal::service()->save_business( array( 'name' => 'A Turizm' ) )['business'];
		$user     = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$other    = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		update_user_meta( $user, Portal::USER_META, (int) $business?->id );
		update_user_meta( $other, 'baska_eklenti', 'kalsin' );
		set_transient( 'aihs_form_1', array( 'errors' => array() ), 60 );
		set_site_transient( 'aihs_ornek', 'x', 60 );
		set_transient( 'baska_eklenti_onbellek', 'kalsin', 60 );

		update_option( Uninstaller::DELETE_OPTION, false );
		$this->assertFalse( Uninstaller::run() );
		$this->assertSame( (string) $business?->id, (string) get_user_meta( $user, Portal::USER_META, true ), 'Kept without the opt-in.' );

		update_option( Uninstaller::DELETE_OPTION, true );
		$this->assertTrue( Uninstaller::run() );
		$this->assertSame( '', get_user_meta( $user, Portal::USER_META, true ) );
		$this->assertFalse( get_transient( 'aihs_form_1' ) );
		$this->assertFalse( get_site_transient( 'aihs_ornek' ) );
		$left = $wpdb->get_col( $wpdb->prepare( 'SELECT option_name FROM %i WHERE option_name LIKE %s', $wpdb->options, '%transient%aihs\_%' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$this->assertSame( array(), $left, 'Timeout rows go too.' );

		$this->assertSame( 'kalsin', get_user_meta( $other, 'baska_eklenti', true ), 'Other plugins\' data untouched.' );
		$this->assertSame( 'kalsin', get_transient( 'baska_eklenti_onbellek' ) );
	}
}
