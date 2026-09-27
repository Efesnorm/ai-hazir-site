<?php
/**
 * Tests for CatalogQuery and ListingSearch.
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\Tests\Unit\Catalog\Query;

use AIHazirSite\Core\Catalog\Listing;
use AIHazirSite\Core\Catalog\Query\CatalogQuery;
use AIHazirSite\Core\Catalog\Query\ListingSearch;
use AIHazirSite\Tests\Support\FixedClock;
use AIHazirSite\Tests\Support\MemoryListingRepository;
use AIHazirSite\Tests\Support\SchemaFixtures as F;
use AIHazirSite\Tests\Support\TemplateFixtures as T;
use PHPUnit\Framework\TestCase;

/**
 * The single read path for current listings.
 *
 * @covers \AIHazirSite\Core\Catalog\Query\CatalogQuery
 * @covers \AIHazirSite\Core\Catalog\Query\ListingSearch
 */
final class CatalogQueryTest extends TestCase {

	/**
	 * Query over every fixture listing, stored in memory (storing stamps the save time
	 * 2026-09-27T12:00:00Z, so the "stale" fixture becomes current again, as a real save would).
	 */
	private static function query(): CatalogQuery {
		$repository = new MemoryListingRepository();
		foreach ( array_merge( array( F::offer(), F::supply(), F::demand(), F::expired(), F::stale(), F::unpriced() ), array_values( T::listings() ) ) as $listing ) {
			$repository->store_listing( $listing );
		}
		return new CatalogQuery( $repository, new FixedClock( F::TODAY ) );
	}

	/**
	 * Titles of a search.
	 *
	 * @param ListingSearch $search Criteria.
	 * @return list<string>
	 */
	private static function titles( ListingSearch $search ): array {
		return array_map( static fn( Listing $l ): string => $l->title, self::query()->search( $search )['items'] );
	}

	/**
	 * Keyword: title, description, category, region or attribute value; Turkish-aware, case-insensitive.
	 */
	public function test_keyword(): void {
		$this->assertSame( array( 'NYY 3x2,5 enerji kablosu', 'NYY 3x2,5 enerji kablosu' ), self::titles( new ListingSearch( keyword: '3X2,5' ) ), 'The general and the templated cable.' );
		$this->assertSame( array( 'Bakır katot' ), self::titles( new ListingSearch( keyword: 'BAKIR KATOT' ) ) );
		$this->assertSame( array( 'NYY 3x2,5 enerji kablosu' ), self::titles( new ListingSearch( keyword: 'ts en 60228' ) ), 'Attribute value of a general listing.' );
		$this->assertSame( array( 'Kapadokya balon turu' ), self::titles( new ListingSearch( keyword: 'göreme' ) ), 'Attribute value.' );
		$this->assertSame( array(), self::titles( new ListingSearch( keyword: 'Eski kampanya' ) ), 'Expired listings are never found.' );
	}

	/**
	 * Attribute equality, combined with type; paging and totals.
	 */
	public function test_attributes_and_paging(): void {
		$this->assertSame( array( 'NYY 3x2,5 enerji kablosu' ), self::titles( new ListingSearch( 'offer', attributes: array( 'iletken' => 'BAKIR' ) ) ) );
		$this->assertSame( array(), self::titles( new ListingSearch( 'demand', attributes: array( 'iletken' => 'Bakır' ) ) ) );
		$this->assertSame( array(), self::titles( new ListingSearch( attributes: array( 'yok' => 'x' ) ) ) );

		$page = self::query()->search( new ListingSearch( page: 2, per_page: 3 ) );
		$this->assertSame( array( 9, 3 ), array( $page['total'], count( $page['items'] ) ) );
		$this->assertSame( '2026-09-27T12:00:00Z', $page['updated_at'] );
	}

	/**
	 * Find: current listings only.
	 */
	public function test_find(): void {
		$this->assertSame( 'NYY 3x2,5 enerji kablosu', self::query()->find( 11 )?->title );
		$this->assertNull( self::query()->find( 14 ), 'Expired.' );
		$this->assertNull( self::query()->find( 999 ) );
		$this->assertCount( 10, self::query()->all() );
	}
}
