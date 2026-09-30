<?php
/**
 * Uninstall leaves no scheduled event (1.14.0).
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\Tests\Integration;

use AIHazirSite\Core\Features;
use AIHazirSite\WordPress\Catalog\CatalogModule;
use AIHazirSite\WordPress\IndexNow\IndexNowModule;
use AIHazirSite\WordPress\Uninstaller;
use WP_UnitTestCase;

/**
 * Found in the package test: deleting the listings during uninstall fired `deleted_post`, and IndexNow
 * scheduled a submission after deactivation had cleared it; the event outlived the plugin.
 *
 * @covers \AIHazirSite\WordPress\Uninstaller
 */
final class UninstallCronTest extends WP_UnitTestCase {

	/**
	 * IndexNow on with a listing; uninstall with data removal opted in.
	 */
	public function test_no_event_after_uninstall(): void {
		delete_option( Features::OPTION );
		foreach ( array( Features::CATALOG, Features::LLMS_TXT ) as $feature ) {
			Features::set( $feature, true );
		}
		IndexNowModule::enable( true );
		( new IndexNowModule() )->register();
		CatalogModule::service()->save_listing(
			array(
				'type'     => 'offer',
				'title'    => 'NYY kablo',
				'category' => 'Kablo',
			)
		);
		wp_clear_scheduled_hook( IndexNowModule::HOOK );

		update_option( Uninstaller::DELETE_OPTION, 1 );
		$this->assertTrue( Uninstaller::run() );

		foreach ( Uninstaller::cron_hooks() as $hook ) {
			$this->assertFalse( wp_next_scheduled( $hook ), $hook );
		}
		$this->assertContains( IndexNowModule::HOOK, Uninstaller::cron_hooks() );
	}
}
