<?php
/**
 * Catalog data on uninstall.
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\Tests\Integration\Catalog;

use AIHazirSite\WordPress\Catalog\CatalogModule;
use AIHazirSite\WordPress\Catalog\PostType;
use AIHazirSite\WordPress\Catalog\WpProfileRepository;
use AIHazirSite\WordPress\Uninstaller;
use WP_UnitTestCase;

/**
 * Listings and the profile are removed only with the opt-in; other posts stay.
 *
 * @covers \AIHazirSite\WordPress\Uninstaller
 * @covers \AIHazirSite\Core\Catalog\CatalogService::purge
 */
final class CatalogUninstallTest extends WP_UnitTestCase {

	/**
	 * Seeds two listings, a profile and an ordinary post.
	 *
	 * @return int Ordinary post id.
	 */
	private function seed(): int {
		$service = CatalogModule::service();
		$service->save_listing(
			array(
				'type'  => 'offer',
				'title' => 'A',
			)
		);
		$service->save_listing(
			array(
				'type'  => 'demand',
				'title' => 'B',
			)
		);
		$service->save_profile( array( 'name' => 'Örnek A.Ş.' ) );
		return self::factory()->post->create();
	}

	/**
	 * Number of listing posts.
	 */
	private static function listing_count(): int {
		return count( get_posts( array( 'post_type' => PostType::NAME, 'post_status' => 'any', 'numberposts' => -1, 'fields' => 'ids' ) ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
	}

	/**
	 * Opt-in off: nothing is deleted.
	 */
	public function test_opt_in_off_keeps_catalog(): void {
		$this->seed();
		update_option( Uninstaller::DELETE_OPTION, '0' );

		$this->assertFalse( Uninstaller::run() );
		$this->assertSame( 2, self::listing_count() );
		$this->assertSame( 'Örnek A.Ş.', ( new WpProfileRepository() )->get()->name );
	}

	/**
	 * Opt-in on: listings and profile are removed; ordinary posts are not.
	 */
	public function test_opt_in_on_removes_catalog_only(): void {
		$post = $this->seed();
		update_option( Uninstaller::DELETE_OPTION, '1' );
		update_option( 'aihs_db_version', 0 );

		$this->assertTrue( Uninstaller::run() );
		$this->assertSame( 0, self::listing_count() );
		$this->assertFalse( get_option( WpProfileRepository::OPTION ) );
		$this->assertNotNull( get_post( $post ) );
	}
}
