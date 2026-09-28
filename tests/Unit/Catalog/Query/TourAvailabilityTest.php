<?php
/**
 * Tour availability from remaining places only (1.6.1).
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\Tests\Unit\Catalog\Query;

use AIHazirSite\Core\Catalog\Listing;
use AIHazirSite\Core\Catalog\Query\Availability;
use AIHazirSite\Tests\Support\TemplateFixtures as T;
use PHPUnit\Framework\TestCase;

/**
 * On a live site a tour with a total ("Miktar: 199") and a capacity ("Kontenjan: 35") but no remaining
 * places was answered from the total, worded as stock. The template's own field decides now.
 *
 * @covers \AIHazirSite\Core\Catalog\Query\Availability
 */
final class TourAvailabilityTest extends TestCase {

	/**
	 * The fixture tour with its attributes and quantity replaced.
	 *
	 * @param array<string, string> $attributes Attributes.
	 */
	private static function tour( array $attributes ): Listing {
		$data               = T::listings()['tour']->to_array();
		$data['attributes'] = $attributes;
		$data['quantity']   = '199';
		return Listing::from_array( $data );
	}

	/**
	 * No remaining places: unknown, even with a general quantity; the reason names the field.
	 */
	public function test_missing_remaining_places_is_unknown(): void {
		$result = Availability::check( self::tour( array( 'kontenjan' => '35' ) ), T::registry()->get( 'tour' ), '4', null, '2026-09-27T12:00:00Z' );

		$this->assertSame( array( Availability::UNKNOWN, null ), array( $result['answer'], $result['available_quantity'] ) );
		$this->assertSame( array( 'Kalan yer belirtilmemiş; firmaya sorun.' ), $result['reasons'] );
	}

	/**
	 * Remaining places: worded as places, not stock.
	 */
	public function test_reasons_use_the_field_name(): void {
		$template = T::registry()->get( 'tour' );
		$fresh    = T::listings()['tour'];

		$this->assertSame( array( 'Kalan yer: 6; istenen 4.' ), Availability::check( $fresh, $template, '4', null, '2026-09-27T12:00:00Z' )['reasons'] );
		$this->assertSame( array( 'Kalan yer: 6; istenen 7 karşılanamıyor.' ), Availability::check( $fresh, $template, '7', null, '2026-09-27T12:00:00Z' )['reasons'] );
		$this->assertStringStartsWith( 'Kalan yer son olarak 6 bildirildi, ancak doğrulanmadı', Availability::check( $fresh, $template, '4', null, '2026-09-28T12:00:00Z' )['reasons'][0] );
	}
}
