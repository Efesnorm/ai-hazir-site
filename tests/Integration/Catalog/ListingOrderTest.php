<?php
/**
 * Stable listing order.
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\Tests\Integration\Catalog;

use AIHazirSite\Core\Catalog\ListingType;
use AIHazirSite\WordPress\Catalog\CatalogModule;
use AIHazirSite\WordPress\Catalog\WpListingRepository;
use WP_UnitTestCase;

/**
 * Listings updated in the same second come out newest id first, every time.
 *
 * @covers \AIHazirSite\WordPress\Catalog\WpListingRepository
 */
final class ListingOrderTest extends WP_UnitTestCase {

	/**
	 * Same modified time: id breaks the tie; a later update still comes first.
	 */
	public function test_same_second_is_ordered_by_id(): void {
		$ids = array();
		foreach ( array( 'Birinci', 'İkinci', 'Üçüncü' ) as $title ) {
			$ids[] = (int) CatalogModule::service()->save_listing(
				array(
					'type'     => ListingType::OFFER,
					'title'    => $title,
					'category' => 'Kablo',
				)
			)->listing()?->id;
		}
		foreach ( $ids as $id ) {
			self::modified( $id, '2026-01-01 10:00:00' );
		}

		$order = static fn(): array => array_map( static fn( $l ): int => (int) $l->id, ( new WpListingRepository() )->all( ListingType::OFFER ) );
		$this->assertSame( array_reverse( $ids ), $order() );

		self::modified( $ids[0], '2026-01-01 10:00:01' );
		$this->assertSame( array( $ids[0], $ids[2], $ids[1] ), $order() );
	}

	/**
	 * Sets a post's modified time directly.
	 *
	 * @param int    $id   Post id.
	 * @param string $time GMT time.
	 */
	private static function modified( int $id, string $time ): void {
		global $wpdb;
		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Test fixture.
			$wpdb->posts,
			array(
				'post_modified'     => $time,
				'post_modified_gmt' => $time,
			),
			array( 'ID' => $id )
		);
		clean_post_cache( $id );
	}
}
