<?php
/**
 * Listings and profile stored in WordPress.
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\Tests\Integration\Catalog;

use AIHazirSite\Core\Catalog\CatalogService;
use AIHazirSite\Core\Catalog\ListingType;
use AIHazirSite\WordPress\Catalog\CatalogModule;
use AIHazirSite\WordPress\Catalog\PostType;
use AIHazirSite\WordPress\Catalog\WpListingRepository;
use AIHazirSite\WordPress\Catalog\WpProfileRepository;
use WP_UnitTestCase;

/**
 * Catalog storage integration tests.
 *
 * @covers \AIHazirSite\WordPress\Catalog\PostType
 * @covers \AIHazirSite\WordPress\Catalog\WpListingRepository
 * @covers \AIHazirSite\WordPress\Catalog\WpProfileRepository
 */
final class ListingCrudTest extends WP_UnitTestCase {

	/**
	 * Service with the WordPress repositories.
	 *
	 * @var CatalogService
	 */
	private CatalogService $service;

	/**
	 * Fresh service.
	 */
	public function set_up(): void {
		parent::set_up();
		$this->service = CatalogModule::service();
	}

	/**
	 * Input for a listing of a type.
	 *
	 * @param string $type  Type.
	 * @param string $title Title.
	 * @return array<string, mixed>
	 */
	private static function input( string $type, string $title ): array {
		return array(
			'type'           => $type,
			'title'          => $title,
			'description'    => 'Açıklama "tırnaklı" ve \\ ters bölü',
			'category'       => 'Kablo',
			'quantity'       => '1500',
			'unit'           => 'm',
			'price_min'      => '42.50',
			'price_max'      => '48.75',
			'currency'       => 'TRY',
			'region'         => 'Marmara',
			'lead_time_days' => '7',
			'valid_until'    => gmdate( 'Y-m-d', time() + 30 * DAY_IN_SECONDS ),
			'attributes'     => "kesit: 3x2,5\nstandart: TS EN 60228",
		);
	}

	/**
	 * The post type is private: no front end, archive, search, REST or core edit screen.
	 */
	public function test_post_type_is_private(): void {
		$object = get_post_type_object( PostType::NAME );

		$this->assertNotNull( $object );
		$this->assertFalse( $object->public );
		$this->assertFalse( $object->publicly_queryable );
		$this->assertTrue( $object->exclude_from_search );
		$this->assertFalse( $object->show_ui );
		$this->assertFalse( $object->show_in_rest );
		$this->assertFalse( $object->has_archive );
		$this->assertFalse( $object->rewrite );
		$this->assertFalse( is_post_type_viewable( $object ) );
		$this->assertTrue( is_protected_meta( PostType::META['type'], 'post' ) );
	}

	/**
	 * Each type: add, edit, delete; the other types' records stay exactly as they were.
	 */
	public function test_crud_per_type_leaves_other_types_untouched(): void {
		$repository = new WpListingRepository();
		$ids        = array();
		foreach ( ListingType::ALL as $type ) {
			$result = $this->service->save_listing( self::input( $type, 'İlk ' . $type ) );
			$this->assertTrue( $result->is_valid(), print_r( $result->errors, true ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_print_r
			$ids[ $type ] = (int) $result->listing()?->id;
		}

		foreach ( ListingType::ALL as $type ) {
			$others = array();
			foreach ( array_diff( ListingType::ALL, array( $type ) ) as $other ) {
				$others[ $other ] = $repository->find( $ids[ $other ] )?->to_array();
			}

			// Add a second one of this type.
			$second = $this->service->save_listing( self::input( $type, 'İkinci ' . $type ) )->listing();
			$this->assertNotNull( $second );
			$this->assertCount( 2, $repository->all( $type ) );

			// Edit.
			$edited = $this->service->save_listing( array_merge( self::input( $type, 'Düzenlendi ' . $type ), array( 'price_max' => '99' ) ), $second->id );
			$this->assertTrue( $edited->is_valid() );
			$this->assertSame( 'Düzenlendi ' . $type, $repository->find( (int) $second->id )?->title );
			$this->assertSame( '99', $repository->find( (int) $second->id )?->price_max );

			// Delete.
			$this->assertTrue( $this->service->delete_listing( (int) $second->id, $type ) );
			$this->assertNull( $repository->find( (int) $second->id ) );
			$this->assertNull( get_post( (int) $second->id ), 'Deleted permanently.' );

			foreach ( $others as $other => $before ) {
				$this->assertSame( $before, $repository->find( $ids[ $other ] )?->to_array(), "{$other} untouched while editing {$type}" );
				$this->assertCount( 1, $repository->all( $other ) );
			}
		}
	}

	/**
	 * All fields survive the round trip, including quotes and backslashes.
	 */
	public function test_round_trip(): void {
		$listing = $this->service->save_listing( self::input( ListingType::SUPPLY, 'Kablo "NYY" O\'Brien \\ test' ) )->listing();
		$this->assertNotNull( $listing );

		$stored = ( new WpListingRepository() )->find( (int) $listing->id );
		$this->assertNotNull( $stored );
		$this->assertSame( 'Kablo "NYY" O\'Brien \\ test', $stored->title );
		$this->assertSame( 'Açıklama "tırnaklı" ve \\ ters bölü', $stored->description );
		$this->assertSame( '42.50', $stored->price_min );
		$this->assertSame( 7, $stored->lead_time_days );
		$this->assertSame(
			array(
				'kesit'    => '3x2,5',
				'standart' => 'TS EN 60228',
			),
			$stored->attributes
		);
		$this->assertMatchesRegularExpression( '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', (string) $stored->updated_at );
		$this->assertSame( 'supply', get_post_meta( (int) $listing->id, '_aihs_type', true ) );
	}

	/**
	 * Other post types are never read or deleted through the repository.
	 */
	public function test_other_post_types_are_ignored(): void {
		$post_id = self::factory()->post->create();

		$this->assertNull( ( new WpListingRepository() )->find( $post_id ) );
		$this->assertFalse( $this->service->delete_listing( $post_id ) );
		$this->assertNotNull( get_post( $post_id ) );
	}

	/**
	 * The profile is stored in aihs_profile.
	 */
	public function test_profile_storage(): void {
		$this->assertTrue( $this->service->save_profile( array( 'name' => 'Örnek A.Ş.', 'country' => 'TR' ) )->is_valid() ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound

		$this->assertSame( 'Örnek A.Ş.', ( new WpProfileRepository() )->get()->name );
		$this->assertSame( 'TR', get_option( WpProfileRepository::OPTION )['country'] );
	}
}
