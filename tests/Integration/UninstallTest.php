<?php
/**
 * Behaviour of uninstall.php.
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\Tests\Integration;

use AIHazirSite\Core\Features;
use AIHazirSite\Core\Migrations\Migrator;
use AIHazirSite\Core\Uninstaller;
use WP_UnitTestCase;

/**
 * Uninstall integration tests.
 *
 * @covers \AIHazirSite\Core\Uninstaller
 */
final class UninstallTest extends WP_UnitTestCase {

	/**
	 * Seeds every plugin option.
	 */
	public function set_up(): void {
		parent::set_up();
		update_option( Features::OPTION, array( 'x' => false ) );
		update_option( Migrator::OPTION, 3 );
	}

	/**
	 * With the opt-in off, uninstall.php deletes nothing.
	 *
	 * The option is stored as '0', the way a settings form saves an unchecked box.
	 * (update_option() with `false` on a missing option stores nothing at all.)
	 */
	public function test_uninstall_file_keeps_data_when_opt_in_is_off(): void {
		update_option( Uninstaller::DELETE_OPTION, '0' );

		if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
			define( 'WP_UNINSTALL_PLUGIN', 'ai-hazir-site/ai-hazir-site.php' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- WordPress core constant.
		}
		require dirname( __DIR__, 2 ) . '/uninstall.php';

		$this->assertSame( array( 'x' => false ), get_option( Features::OPTION ) );
		$this->assertSame( 3, (int) get_option( Migrator::OPTION ) );
		$this->assertSame( '0', get_option( Uninstaller::DELETE_OPTION ) );
	}

	/**
	 * With the opt-in on, every plugin option is deleted.
	 */
	public function test_deletes_data_when_opt_in_is_on(): void {
		update_option( Uninstaller::DELETE_OPTION, true );

		$this->assertTrue( Uninstaller::run() );

		foreach ( Uninstaller::options() as $option ) {
			$this->assertFalse( get_option( $option ), $option );
		}
	}
}
