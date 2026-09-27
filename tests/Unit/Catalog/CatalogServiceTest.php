<?php
/**
 * Tests for CatalogService.
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\Tests\Unit\Catalog;

use AIHazirSite\Core\Catalog\CatalogService;
use AIHazirSite\Tests\Support\FixedClock;
use AIHazirSite\Tests\Support\MemoryListingRepository;
use AIHazirSite\Tests\Support\MemoryProfileRepository;
use PHPUnit\Framework\TestCase;

/**
 * Catalog write-path unit tests.
 *
 * @covers \AIHazirSite\Core\Catalog\CatalogService
 */
final class CatalogServiceTest extends TestCase {

	/**
	 * Listings.
	 *
	 * @var MemoryListingRepository
	 */
	private MemoryListingRepository $listings;

	/**
	 * Profile.
	 *
	 * @var MemoryProfileRepository
	 */
	private MemoryProfileRepository $profiles;

	/**
	 * Service under test.
	 *
	 * @var CatalogService
	 */
	private CatalogService $service;

	/**
	 * Fresh repositories.
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->listings = new MemoryListingRepository();
		$this->profiles = new MemoryProfileRepository();
		$this->service  = new CatalogService( $this->listings, $this->profiles, new FixedClock( '2026-09-27' ) );
	}

	/**
	 * Invalid input writes nothing; valid input is stored with an id.
	 */
	public function test_only_valid_listings_are_stored(): void {
		$bad = $this->service->save_listing(
			array(
				'type'  => 'offer',
				'title' => '',
			)
		);
		$this->assertFalse( $bad->is_valid() );
		$this->assertSame( array(), $this->listings->listings );

		$ok = $this->service->save_listing(
			array(
				'type'  => 'offer',
				'title' => 'Kablo',
			)
		);
		$this->assertTrue( $ok->is_valid() );
		$this->assertSame( 1, $ok->listing()?->id );
		$this->assertSame( '2026-09-27T12:00:00Z', $ok->listing()?->updated_at );
	}

	/**
	 * Updating keeps the id; an unknown id is an error.
	 */
	public function test_update(): void {
		$id = $this->service->save_listing(
			array(
				'type'  => 'demand',
				'title' => 'Bakır',
			)
		)->listing()?->id;
		$this->assertNotNull( $id );

		$updated = $this->service->save_listing(
			array(
				'type'  => 'demand',
				'title' => 'Bakır katot',
			),
			$id
		);
		$this->assertSame( $id, $updated->listing()?->id );
		$this->assertSame( 'Bakır katot', $this->listings->find( $id )?->title );
		$this->assertCount( 1, $this->listings->listings );

		$this->assertArrayHasKey(
			'id',
			$this->service->save_listing(
				array(
					'type'  => 'demand',
					'title' => 'x',
				),
				999
			)->errors
		);
	}

	/**
	 * Deleting checks existence and type.
	 */
	public function test_delete_respects_type(): void {
		$offer  = (int) $this->service->save_listing(
			array(
				'type'  => 'offer',
				'title' => 'A',
			)
		)->listing()?->id;
		$supply = (int) $this->service->save_listing(
			array(
				'type'  => 'supply',
				'title' => 'B',
			)
		)->listing()?->id;

		$this->assertFalse( $this->service->delete_listing( $supply, 'offer' ), 'An offer screen cannot delete a supply.' );
		$this->assertTrue( $this->service->delete_listing( $offer, 'offer' ) );
		$this->assertFalse( $this->service->delete_listing( $offer ) );
		$this->assertSame( array( $supply ), $this->listings->ids() );
	}

	/**
	 * Profile: invalid is not stored, valid is, warnings pass through.
	 */
	public function test_profile(): void {
		$this->assertFalse( $this->service->save_profile( array( 'name' => '' ) )->is_valid() );
		$this->assertNull( $this->profiles->profile );

		$result = $this->service->save_profile( array( 'name' => 'Örnek A.Ş.', 'contact_email' => 'x@hotmail.com' ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		$this->assertTrue( $result->is_valid() );
		$this->assertCount( 1, $result->warnings );
		$this->assertSame( 'Örnek A.Ş.', $this->profiles->get()->name );
	}

	/**
	 * Purge removes every listing and the profile.
	 */
	public function test_purge(): void {
		$this->service->save_listing(
			array(
				'type'  => 'offer',
				'title' => 'A',
			)
		);
		$this->service->save_listing(
			array(
				'type'  => 'demand',
				'title' => 'B',
			)
		);
		$this->service->save_profile( array( 'name' => 'Örnek' ) );

		$this->assertSame( 2, $this->service->purge() );
		$this->assertSame( array(), $this->listings->listings );
		$this->assertNull( $this->profiles->profile );
	}
}
