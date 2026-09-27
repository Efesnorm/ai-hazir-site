<?php
/**
 * Tests for Availability.
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\Tests\Unit\Catalog\Query;

use AIHazirSite\Core\Catalog\Listing;
use AIHazirSite\Core\Catalog\Query\Availability;
use AIHazirSite\Core\Templates\Template;
use AIHazirSite\Tests\Support\SchemaFixtures as F;
use AIHazirSite\Tests\Support\TemplateFixtures as T;
use PHPUnit\Framework\TestCase;

/**
 * Yes / no / unknown with reasons.
 *
 * @covers \AIHazirSite\Core\Catalog\Query\Availability
 */
final class AvailabilityTest extends TestCase {

	/**
	 * Answer and reasons for a general listing.
	 *
	 * @param Listing|null $listing  Listing.
	 * @param string|null  $quantity Asked quantity.
	 * @param int|null     $days     Asked days.
	 * @return array{answer: string, reasons: list<string>, listing_id: int|null, available_quantity: string|null, unit: string, lead_time_days: int|null}
	 */
	private static function check( ?Listing $listing, ?string $quantity, ?int $days ): array {
		return Availability::check( $listing, Template::general(), $quantity, $days, T::NOW );
	}

	/**
	 * The acceptance question: "Stokta 3x2,5 kablo var mı, kaç günde gelir?"
	 */
	public function test_offer_in_stock(): void {
		$result = self::check( F::offer(), '500', 7 );

		$this->assertSame( Availability::YES, $result['answer'] );
		$this->assertSame( array( 'Stokta 1500 m var; istenen 500.', 'Teslim süresi 3 gün; istenen 7 gün içinde.' ), $result['reasons'] );
		$this->assertSame( array( 11, '1500', 'm', 3 ), array( $result['listing_id'], $result['available_quantity'], $result['unit'], $result['lead_time_days'] ) );

		$this->assertSame( Availability::YES, self::check( F::offer(), null, null )['answer'] );
		$this->assertStringContainsString( 'Teslim süresi 3 gün', self::check( F::offer(), null, null )['reasons'][0] );
	}

	/**
	 * Too much, too slow, unknown stock or lead time.
	 */
	public function test_no_and_unknown(): void {
		$this->assertSame( Availability::NO, self::check( F::offer(), '2000', null )['answer'] );
		$this->assertSame( Availability::NO, self::check( F::offer(), '10', 2 )['answer'], 'Any no wins.' );
		$this->assertSame( Availability::UNKNOWN, self::check( F::unpriced(), '1', null )['answer'] );
		$this->assertSame( Availability::UNKNOWN, self::check( F::unpriced(), null, 5 )['answer'] );
		$this->assertSame( Availability::NO, self::check( F::offer(), '1500.0001', null )['answer'], 'Decimal comparison without rounding.' );
		$this->assertSame( Availability::YES, self::check( F::offer(), '1500', null )['answer'] );
	}

	/**
	 * Supply is made to order; demand and missing listings are no.
	 */
	public function test_supply_demand_missing(): void {
		$supply = self::check( F::supply(), '100000', 30 );
		$this->assertSame( Availability::YES, $supply['answer'] );
		$this->assertSame( 'Siparişe göre üretilir; miktar sınırı yok.', $supply['reasons'][0] );

		$this->assertSame( Availability::NO, self::check( F::demand(), '1', null )['answer'] );
		$this->assertStringContainsString( 'alım ilanı', self::check( F::demand(), null, null )['reasons'][0] );
		$this->assertSame( array( Availability::NO, null ), array( self::check( null, '1', 1 )['answer'], self::check( null, '1', 1 )['listing_id'] ) );
	}

	/**
	 * Tour: remaining places are the stock while fresh; stale → unknown.
	 */
	public function test_tour_places(): void {
		$tour     = T::listings()['tour'];
		$template = T::registry()->get( 'tour' );

		$fresh = Availability::check( $tour, $template, '4', null, '2026-09-27T12:00:00Z' );
		$this->assertSame( array( Availability::YES, '6', 'Kalan yer' ), array( $fresh['answer'], $fresh['available_quantity'], $fresh['unit'] ) );
		$this->assertSame( Availability::NO, Availability::check( $tour, $template, '7', null, '2026-09-27T12:00:00Z' )['answer'] );

		$stale = Availability::check( $tour, $template, '4', null, '2026-09-28T12:00:00Z' );
		$this->assertSame( array( Availability::UNKNOWN, null ), array( $stale['answer'], $stale['available_quantity'] ) );
		$this->assertStringContainsString( 'doğrulanmadı', $stale['reasons'][0] );
	}
}
