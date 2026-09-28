<?php
/**
 * Portal service and report.
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\Tests\Unit\Portal;

use AIHazirSite\Core\Catalog\CatalogService;
use AIHazirSite\Core\Catalog\ListingType;
use AIHazirSite\Core\Inquiry\Inquiry;
use AIHazirSite\Core\Inquiry\InquiryContact;
use AIHazirSite\Core\Portal\Business;
use AIHazirSite\Core\Portal\PortalReport;
use AIHazirSite\Core\Portal\PortalService;
use AIHazirSite\Tests\Support\FixedClock;
use AIHazirSite\Tests\Support\MemoryBusinessRepository;
use AIHazirSite\Tests\Support\MemoryListingRepository;
use AIHazirSite\Tests\Support\MemoryProfileRepository;
use AIHazirSite\Tests\Unit\UnitTestCase;

/**
 * Businesses, ownership rules and per-business figures.
 *
 * @covers \AIHazirSite\Core\Portal\PortalService
 * @covers \AIHazirSite\Core\Portal\PortalReport
 * @covers \AIHazirSite\Core\Portal\Business
 */
final class PortalServiceTest extends UnitTestCase {

	/**
	 * Storage.
	 *
	 * @var MemoryBusinessRepository
	 */
	private MemoryBusinessRepository $businesses;

	/**
	 * Listings.
	 *
	 * @var MemoryListingRepository
	 */
	private MemoryListingRepository $listings;

	/**
	 * Service under test.
	 *
	 * @var PortalService
	 */
	private PortalService $service;

	/**
	 * Fresh storage.
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->businesses = new MemoryBusinessRepository();
		$this->listings   = new MemoryListingRepository();
		$catalog          = new CatalogService( $this->listings, new MemoryProfileRepository(), new FixedClock( '2026-09-28' ) );
		$this->service    = new PortalService( $this->businesses, $this->businesses, $this->listings, $catalog );
	}

	/**
	 * A stored business.
	 *
	 * @param string $name Name.
	 * @param string $slug Slug ('' = from the name).
	 */
	private function business( string $name, string $slug = '' ): Business {
		$saved = $this->service->save_business(
			array(
				'name'    => $name,
				'country' => 'TR',
				'slug'    => $slug,
			)
		);
		$this->assertSame( array(), $saved['errors'] );
		$this->assertNotNull( $saved['business'] );
		return $saved['business'];
	}

	/**
	 * Slugs: generated from the name, validated, unique.
	 */
	public function test_businesses(): void {
		$balon = $this->business( 'Kapadokya Balon Turları' );
		$this->assertSame( 'kapadokya-balon-turlari', $balon->slug );
		$this->assertSame( 'istanbul-ic', Business::slugify( 'İstanbul İç' ) );

		$this->assertArrayHasKey(
			'slug',
			$this->service->save_business(
				array(
					'name' => 'Başka',
					'slug' => 'kapadokya-balon-turlari',
				)
			)['errors']
		);
		$this->assertArrayHasKey(
			'slug',
			$this->service->save_business(
				array(
					'name' => 'X',
					'slug' => 'Büyük Harf',
				)
			)['errors']
		);
		$this->assertArrayHasKey( 'name', $this->service->save_business( array( 'slug' => 'bos-ad' ) )['errors'] );
		$this->assertArrayHasKey( 'id', $this->service->save_business( array( 'name' => 'Y' ), 99 )['errors'] );

		$renamed = $this->service->save_business(
			array(
				'name' => 'Kapadokya Balon',
				'slug' => 'kapadokya-balon-turlari',
			),
			$balon->id
		);
		$this->assertSame( array(), $renamed['errors'], 'Keeping its own slug is allowed.' );
		$this->assertCount( 1, $this->businesses->businesses() );
	}

	/**
	 * A business user reaches only its own listings; deleting a business with listings is refused.
	 */
	public function test_ownership(): void {
		$a = (int) $this->business( 'A Turizm' )->id;
		$b = (int) $this->business( 'B Turizm' )->id;

		$created = $this->service->save_business_listing(
			$a,
			array(
				'type'  => ListingType::OFFER,
				'title' => 'Balon turu',
			)
		);
		$this->assertTrue( $created->is_valid() );
		$id = (int) $created->listing()?->id;
		$this->assertSame( $a, $this->businesses->business_of( $id ) );
		$this->assertTrue( $this->service->owns( $a, $id ) );
		$this->assertFalse( $this->service->owns( $b, $id ) );

		$foreign = $this->service->save_business_listing(
			$b,
			array(
				'type'  => ListingType::OFFER,
				'title' => 'Ele geçirme',
			),
			$id
		);
		$this->assertArrayHasKey( 'id', $foreign->errors );
		$this->assertSame( 'Balon turu', $this->listings->find( $id )?->title, 'Unchanged.' );
		$this->assertFalse( $this->service->delete_business_listing( $b, $id ) );
		$this->assertNotNull( $this->listings->find( $id ) );

		$this->assertNotSame( '', $this->service->delete_business( $a ), 'Has listings.' );
		$this->assertTrue(
			$this->service->save_business_listing(
				$a,
				array(
					'type'  => ListingType::OFFER,
					'title' => 'Balon turu (gün doğumu)',
				),
				$id
			)->is_valid()
		);
		$this->assertTrue( $this->service->delete_business_listing( $a, $id ) );
		$this->assertNull( $this->listings->find( $id ) );

		$this->assertArrayHasKey( 'business', $this->service->save_business_listing( 99, array( 'title' => 'x' ) )->errors );

		// The portal admin moves listings between businesses.
		$own = (int) $this->listings->store_listing( new \AIHazirSite\Core\Catalog\Listing( null, ListingType::OFFER, 'Portal ilanı' ) )->id;
		$this->assertTrue( $this->service->assign_listing( $own, $b ) );
		$this->assertSame( $b, $this->businesses->business_of( $own ) );
		$this->assertFalse( $this->service->assign_listing( $own, 99 ) );
		$this->assertFalse( $this->service->assign_listing( 999, $b ) );
		$this->assertTrue( $this->service->assign_listing( $own, null ) );
		$this->assertNull( $this->businesses->business_of( $own ) );

		$this->assertSame( '', $this->service->delete_business( $a ) );
		$this->assertNull( $this->businesses->business( $a ) );
		$this->assertNotSame( '', $this->service->delete_business( $a ) );
	}

	/**
	 * Rows add up to the totals; unknown businesses and missing listings count for the portal.
	 */
	public function test_report(): void {
		$a       = $this->business( 'A Turizm' );
		$b       = $this->business( 'B Turizm' );
		$inquiry = static fn( ?int $listing ): Inquiry => new Inquiry( null, 'quote_request', 'ai', 'mcp', $listing, 'Konu', 'Mesaj', new InquiryContact( 'Ad', '', 'a@b.example', '' ), 'new', 0, array(), str_repeat( 'a', 64 ), '2026-09-27T10:00:00Z' );
		$path_of = static fn( Business $x ): string => '/ai-katalog/isletme/' . $x->slug . '/';
		$report  = PortalReport::build(
			array( $a, $b ),
			array(
				1 => $a->id,
				2 => $a->id,
				3 => $b->id,
				4 => null,
				5 => 99,
			),
			array(
				'/ai-katalog/isletme/a-turizm/' => 7,
				'/ai-katalog/isletme/b-turizm/' => 2,
				'/ai-katalog/'                  => 50,
			),
			array( $inquiry( 1 ), $inquiry( 2 ), $inquiry( 3 ), $inquiry( 4 ), $inquiry( null ), $inquiry( 77 ) ),
			$path_of
		);

		$this->assertSame( array( 7, 2, 2 ), array( $report['rows'][ $a->id ]['page_hits'], $report['rows'][ $a->id ]['listings'], $report['rows'][ $a->id ]['inquiries'] ) );
		$this->assertSame( array( 2, 1, 1 ), array( $report['rows'][ $b->id ]['page_hits'], $report['rows'][ $b->id ]['listings'], $report['rows'][ $b->id ]['inquiries'] ) );
		$this->assertSame( array( 0, 2, 3 ), array( $report['rows'][0]['page_hits'], $report['rows'][0]['listings'], $report['rows'][0]['inquiries'] ) );
		foreach ( array( 'page_hits', 'listings', 'inquiries' ) as $column ) {
			$this->assertSame( $report['totals'][ $column ], array_sum( array_column( $report['rows'], $column ) ), $column );
		}
		$this->assertSame( 9, $report['totals']['page_hits'] );
		$this->assertSame( 6, $report['totals']['inquiries'] );
	}
}
