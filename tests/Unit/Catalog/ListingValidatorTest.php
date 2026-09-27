<?php
/**
 * Tests for ListingValidator.
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\Tests\Unit\Catalog;

use AIHazirSite\Core\Catalog\Listing;
use AIHazirSite\Core\Catalog\ListingValidator;
use PHPUnit\Framework\TestCase;

/**
 * Listing validation unit tests.
 *
 * @covers \AIHazirSite\Core\Catalog\ListingValidator
 * @covers \AIHazirSite\Core\Catalog\Listing
 */
final class ListingValidatorTest extends TestCase {

	private const TODAY = '2026-09-27';

	/**
	 * A complete valid input.
	 *
	 * @param array<string, mixed> $overrides Changes.
	 * @return array<string, mixed>
	 */
	private static function input( array $overrides = array() ): array {
		return array_merge(
			array(
				'type'           => 'offer',
				'title'          => '  NYY 3x2,5 enerji kablosu ',
				'description'    => "TSE belgeli.\nStokta.",
				'category'       => 'Kablo',
				'quantity'       => '1500',
				'unit'           => 'm',
				'price_min'      => '42,50',
				'price_max'      => '48.75',
				'currency'       => 'try',
				'region'         => 'Türkiye',
				'lead_time_days' => '7',
				'valid_until'    => '2026-12-31',
				'attributes'     => "kesit: 3x2,5 mm²\nStandart: TS EN 60228\n",
			),
			$overrides
		);
	}

	/**
	 * Valid input becomes a normalized listing.
	 */
	public function test_valid_input(): void {
		$result = ( new ListingValidator() )->validate( self::input(), self::TODAY );

		$this->assertTrue( $result->is_valid(), print_r( $result->errors, true ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_print_r
		$listing = $result->listing();
		$this->assertInstanceOf( Listing::class, $listing );
		$this->assertNull( $listing->id );
		$this->assertSame( 'NYY 3x2,5 enerji kablosu', $listing->title );
		$this->assertSame( '42.50', $listing->price_min, 'Comma accepted as decimal separator.' );
		$this->assertSame( 'TRY', $listing->currency );
		$this->assertSame( 7, $listing->lead_time_days );
		$this->assertSame(
			array(
				'kesit'    => '3x2,5 mm²',
				'standart' => 'TS EN 60228',
			),
			$listing->attributes
		);
	}

	/**
	 * Invalid inputs and the field that reports them.
	 *
	 * @return array<string, array{array<string, mixed>, string, string}>
	 */
	public static function invalid(): array {
		return array(
			'empty title'            => array( array( 'title' => '   ' ), 'title', 'Başlık boş olamaz.' ),
			'long title'             => array( array( 'title' => str_repeat( 'a', 201 ) ), 'title', 'en fazla 200' ),
			'min above max'          => array( array( 'price_min' => '50', 'price_max' => '49.99' ), 'price_max', 'En düşük fiyat en yüksek fiyattan büyük olamaz.' ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
			'past date'              => array( array( 'valid_until' => '2026-09-26' ), 'valid_until', 'Geçerlilik tarihi geçmişte olamaz.' ),
			'bad date'               => array( array( 'valid_until' => '2026-02-30' ), 'valid_until', 'YYYY-AA-GG' ),
			'bad type'               => array( array( 'type' => 'sale' ), 'type', 'Geçersiz ilan türü.' ),
			'negative quantity'      => array( array( 'quantity' => '-5' ), 'quantity', 'Miktar' ),
			'text price'             => array( array( 'price_min' => 'on bin' ), 'price_min', 'En düşük fiyat' ),
			'bad currency'           => array( array( 'currency' => 'lira' ), 'currency', 'ISO 4217' ),
			'price without currency' => array( array( 'currency' => '' ), 'currency', 'para birimi zorunludur' ),
			'bad lead time'          => array( array( 'lead_time_days' => '2.5' ), 'lead_time_days', 'tam sayı' ),
			'bad attribute key'      => array( array( 'attributes' => 'Kesit Alanı: 3' ), 'attributes', 'geçersiz' ),
		);
	}

	/**
	 * Invalid input is rejected with a clear message on the right field.
	 *
	 * @dataProvider invalid
	 *
	 * @param array<string, mixed> $overrides Changes.
	 * @param string               $field     Field with the error.
	 * @param string               $message   Part of the message.
	 */
	public function test_invalid_input( array $overrides, string $field, string $message ): void {
		$result = ( new ListingValidator() )->validate( self::input( $overrides ), self::TODAY );

		$this->assertFalse( $result->is_valid() );
		$this->assertNull( $result->listing() );
		$this->assertArrayHasKey( $field, $result->errors );
		$this->assertStringContainsString( $message, $result->errors[ $field ] );
	}

	/**
	 * Editing an expired listing without touching its date is allowed; changing the date to the past is not.
	 */
	public function test_expired_listing_can_be_edited(): void {
		$existing = new Listing( 5, 'offer', 'Eski', valid_until: '2026-01-31', updated_at: '2026-01-01T00:00:00Z' );

		$kept = ( new ListingValidator() )->validate( self::input( array( 'valid_until' => '2026-01-31' ) ), self::TODAY, $existing );
		$this->assertTrue( $kept->is_valid() );
		$this->assertSame( 5, $kept->listing()?->id );

		$moved = ( new ListingValidator() )->validate( self::input( array( 'valid_until' => '2026-02-01' ) ), self::TODAY, $existing );
		$this->assertArrayHasKey( 'valid_until', $moved->errors );
	}

	/**
	 * The type of an existing listing cannot change.
	 */
	public function test_type_is_fixed_on_edit(): void {
		$existing = new Listing( 5, 'demand', 'Aranan' );

		$this->assertSame( 'İlanın türü değiştirilemez.', ( new ListingValidator() )->validate( self::input(), self::TODAY, $existing )->errors['type'] );
	}

	/**
	 * Decimal comparison without floating point.
	 */
	public function test_decimal_compare(): void {
		$this->assertSame( 0, ListingValidator::compare( '42.5', '042.50' ) );
		$this->assertSame( -1, ListingValidator::compare( '9.9999', '10' ) );
		$this->assertSame( 1, ListingValidator::compare( '100000000000.0001', '100000000000' ) );
	}

	/**
	 * Expiry follows the validity date; storage fields survive a round trip.
	 */
	public function test_expiry_and_round_trip(): void {
		$listing = ( new ListingValidator() )->validate( self::input(), self::TODAY )->listing();
		$this->assertNotNull( $listing );

		$this->assertFalse( $listing->is_expired( '2026-12-31' ) );
		$this->assertTrue( $listing->is_expired( '2027-01-01' ) );

		$stored = $listing->stored( 9, '2026-09-27T10:00:00Z' );
		$this->assertEquals( $stored, Listing::from_array( $stored->to_array() ) );
	}
}
