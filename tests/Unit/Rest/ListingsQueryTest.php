<?php
/**
 * Tests for ListingsQuery.
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\Tests\Unit\Rest;

use AIHazirSite\Adapters\Rest\ListingsQuery;
use AIHazirSite\Tests\Support\SchemaFixtures as F;
use PHPUnit\Framework\TestCase;

/**
 * Parameter validation and filtering.
 *
 * @covers \AIHazirSite\Adapters\Rest\ListingsQuery
 */
final class ListingsQueryTest extends TestCase {

	/**
	 * Defaults and valid values.
	 */
	public function test_valid(): void {
		[ $query, $errors ] = ListingsQuery::from_params( array() );
		$this->assertSame( array(), $errors );
		$this->assertSame( array( '', 1, 20 ), array( $query?->type, $query?->page, $query?->per_page ) );

		[ $query ] = ListingsQuery::from_params(
			array(
				'type'     => 'demand',
				'category' => ' hammadde ',
				'region'   => 'marmara',
				'page'     => '2',
				'per_page' => '50',
			)
		);
		$this->assertSame( array( 'demand', 'hammadde', 'marmara', 2, 50 ), array( $query?->type, $query?->category, $query?->region, $query?->page, $query?->per_page ) );
	}

	/**
	 * Invalid values are reported, not corrected.
	 */
	public function test_invalid(): void {
		[ $query, $errors ] = ListingsQuery::from_params(
			array(
				'type'     => 'rent',
				'page'     => '0',
				'per_page' => '51',
			)
		);
		$this->assertNull( $query );
		$this->assertSame( array( 'type', 'page', 'per_page' ), array_keys( $errors ) );
		$this->assertArrayHasKey( 'page', ListingsQuery::from_params( array( 'page' => '1.5' ) )[1] );
	}

	/**
	 * Filters: exact, case-insensitive (Turkish letters included), combined.
	 */
	public function test_matches(): void {
		$this->assertTrue( ( new ListingsQuery() )->matches( F::offer() ) );
		$this->assertTrue( ( new ListingsQuery( 'offer', 'KABLO', 'TÜRKİYE' ) )->matches( F::offer() ) );
		$this->assertFalse( ( new ListingsQuery( 'demand' ) )->matches( F::offer() ) );
		$this->assertFalse( ( new ListingsQuery( '', 'Kablo demeti' ) )->matches( F::offer() ), 'Exact, not partial.' );
		$this->assertFalse( ( new ListingsQuery( 'offer', 'Kablo', 'Avrupa' ) )->matches( F::offer() ) );
	}
}
