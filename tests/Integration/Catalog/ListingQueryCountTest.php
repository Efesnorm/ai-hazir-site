<?php
/**
 * Listing reads do not grow with the number of listings (1.14.1).
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\Tests\Integration\Catalog;

use AIHazirSite\Core\Catalog\Listing;
use AIHazirSite\Core\Features;
use AIHazirSite\WordPress\Catalog\CatalogModule;
use AIHazirSite\WordPress\Catalog\PostType;
use AIHazirSite\WordPress\Catalog\WpListingRepository;
use AIHazirSite\WordPress\Schema\SchemaModule;
use WP_UnitTestCase;

/**
 * Found in the system check: every listing cost a post query and a meta query (153 listings: 334 queries on
 * the home page).
 *
 * @covers \AIHazirSite\WordPress\Catalog\WpListingRepository
 */
final class ListingQueryCountTest extends WP_UnitTestCase {

	/**
	 * Catalog on.
	 */
	public function set_up(): void {
		parent::set_up();
		Features::set( Features::CATALOG, true );
	}

	/**
	 * 5 and 40 listings: the same number of queries.
	 */
	public function test_query_count_is_independent_of_listing_count(): void {
		$this->add( 5 );
		$few = $this->count_queries();
		$this->add( 35 );
		$many = $this->count_queries();

		$this->assertSame( $few, $many );
		$this->assertLessThanOrEqual( 12, $many );
	}

	/**
	 * The listings read in bulk are exactly the ones read one by one, in the same order.
	 */
	public function test_same_listings_and_order(): void {
		$this->add( 6 );
		$bulk = SchemaModule::listings();

		$ids = get_posts(
			array(
				'post_type'   => PostType::NAME,
				'numberposts' => -1,
				'fields'      => 'ids',
				'orderby'     => array(
					'modified' => 'DESC',
					'ID'       => 'DESC',
				),
			)
		);
		wp_cache_flush();
		$repository = new WpListingRepository();
		$single     = array_map( static fn( $id ): ?Listing => $repository->find( (int) $id ), $ids );

		$this->assertCount( 6, $bulk );
		$by_id = array();
		foreach ( $single as $listing ) {
			$by_id[ $listing->id ] = $listing;
		}
		foreach ( $bulk as $listing ) {
			$this->assertEquals( $by_id[ $listing->id ], $listing );
		}
		$offers = array_values( array_filter( $bulk, static fn( Listing $l ): bool => 'offer' === $l->type ) );
		$this->assertSame(
			array_values( array_filter( array_map( static fn( ?Listing $l ): ?int => null !== $l && 'offer' === $l->type ? $l->id : null, $single ) ) ),
			array_map( static fn( Listing $l ): ?int => $l->id, $offers )
		);
	}

	/**
	 * Queries for reading all listings with a cold cache.
	 */
	private function count_queries(): int {
		global $wpdb;
		wp_cache_flush();
		$before = $wpdb->num_queries;
		SchemaModule::listings();
		return $wpdb->num_queries - $before;
	}

	/**
	 * Adds listings of alternating types.
	 *
	 * @param int $count Count.
	 */
	private function add( int $count ): void {
		for ( $i = 0; $i < $count; $i++ ) {
			CatalogModule::service()->save_listing(
				array(
					'type'     => 0 === $i % 2 ? 'offer' : 'demand',
					'title'    => 'Kablo ' . $i,
					'category' => 'Kablo',
					'region'   => 'Bölge ' . $i,
				)
			);
		}
	}
}
