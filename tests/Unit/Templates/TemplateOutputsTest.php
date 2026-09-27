<?php
/**
 * JSON-LD and llms.txt per sector template.
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\Tests\Unit\Templates;

use AIHazirSite\Adapters\Llms\LlmsTxtBuilder;
use AIHazirSite\Adapters\Schema\SchemaBuilder;
use AIHazirSite\Adapters\Schema\SchemaValidator;
use AIHazirSite\Core\Catalog\Listing;
use AIHazirSite\Core\Templates\Freshness;
use AIHazirSite\Tests\Support\FixedClock;
use AIHazirSite\Tests\Support\SchemaFixtures;
use AIHazirSite\Tests\Support\TemplateFixtures as T;
use AIHazirSite\Tests\Unit\Schema\SchemaBuilderTest;
use PHPUnit\Framework\TestCase;

/**
 * Snapshots live in tests/Snapshots/templates/; a changed output fails until reviewed on purpose.
 *
 * @covers \AIHazirSite\Adapters\Schema\SchemaBuilder
 * @covers \AIHazirSite\Adapters\Llms\LlmsTxtBuilder
 * @covers \AIHazirSite\Core\Templates\Freshness
 */
final class TemplateOutputsTest extends TestCase {

	/**
	 * Snapshot file path.
	 *
	 * @param string $name File name.
	 */
	public static function snapshot_path( string $name ): string {
		return dirname( __DIR__, 2 ) . '/Snapshots/templates/' . $name;
	}

	/**
	 * JSON-LD of one listing.
	 *
	 * @param Listing $listing Listing.
	 * @param string  $now     ISO 8601.
	 * @return array<string, mixed>
	 */
	public static function jsonld( Listing $listing, string $now = T::NOW ): array {
		return ( new SchemaBuilder( SchemaFixtures::SITE_URL, true, T::registry() ) )->catalog( SchemaFixtures::profile(), array( $listing ), T::TODAY, T::CATALOG, $now );
	}

	/**
	 * The llms.txt of one listing.
	 *
	 * @param Listing $listing Listing.
	 * @param string  $now     ISO 8601.
	 */
	public static function llms( Listing $listing, string $now = T::NOW ): string {
		return ( new LlmsTxtBuilder( SchemaFixtures::SITE_URL, T::CATALOG, '', array(), T::registry() ) )->build( SchemaFixtures::profile(), array( $listing ), T::TODAY, T::SAVED, $now );
	}

	/**
	 * One case per shipped sector template.
	 *
	 * @return array<string, array{string}>
	 */
	public static function templates(): array {
		return array(
			'product'        => array( 'product' ),
			'export_product' => array( 'export_product' ),
			'service'        => array( 'service' ),
			'tour'           => array( 'tour' ),
		);
	}

	/**
	 * JSON-LD snapshot per template; valid by our validator.
	 *
	 * @dataProvider templates
	 * @param string $id Template id.
	 */
	public function test_jsonld_snapshot( string $id ): void {
		$document = self::jsonld( T::listings()[ $id ] );

		$this->assertSame( array(), ( new SchemaValidator() )->validate( $document )['errors'] );
		$this->assertSame( (string) file_get_contents( self::snapshot_path( $id . '.jsonld.json' ) ), SchemaBuilderTest::json( $document ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
	}

	/**
	 * The llms.txt snapshot per template.
	 *
	 * @dataProvider templates
	 * @param string $id Template id.
	 */
	public function test_llms_snapshot( string $id ): void {
		$this->assertSame( (string) file_get_contents( self::snapshot_path( $id . '.llms.txt' ) ), self::llms( T::listings()[ $id ] ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
	}

	/**
	 * Item types and mapped properties.
	 */
	public function test_item_types_and_mappings(): void {
		$product = self::jsonld( T::listings()['product'] )['dataFeedElement'][0]['item'];
		$this->assertSame( array( 'Product', 'Bakır', 'Siyah' ), array( $product['@type'], $product['material'], $product['color'] ) );

		$export = self::jsonld( T::listings()['export_product'] )['dataFeedElement'][0]['item'];
		$this->assertSame(
			array(
				'@type' => 'Country',
				'name'  => 'TR',
			),
			$export['countryOfOrigin']
		);
		$this->assertSame( array( 'DE', 'FR' ), $export['offers']['eligibleRegion'] );
		$this->assertSame( 1000, $export['offers']['eligibleQuantity']['minValue'] );

		$service = self::jsonld( T::listings()['service'] )['dataFeedElement'][0]['item'];
		$this->assertSame( array( 'Service', array( 'Ticaret hukuku', 'Tahkim' ) ), array( $service['@type'], $service['serviceType'] ) );
		$this->assertArrayNotHasKey( 'additionalProperty', $service, 'Service has no additionalProperty; it goes on the Offer.' );

		$tour = self::jsonld( T::listings()['tour'] )['dataFeedElement'][0]['item'];
		$this->assertSame( array( 'TouristTrip', '2026-10-15', 6 ), array( $tour['@type'], $tour['departureTime'], $tour['offers']['inventoryLevel']['value'] ) );
	}

	/**
	 * The service template publishes no price in any output, even when one is stored.
	 */
	public function test_service_price_never_published(): void {
		$listing = T::listings()['service'];
		$this->assertSame( '1000', $listing->price_min );

		$json = SchemaBuilderTest::json( self::jsonld( $listing ) );
		foreach ( array( '"price', 'priceCurrency', 'priceSpecification', '1000' ) as $needle ) {
			$this->assertStringNotContainsString( $needle, $json );
		}
		$text = self::llms( $listing );
		$this->assertStringNotContainsString( 'Fiyat', $text );
		$this->assertStringNotContainsString( '1000', $text );
	}

	/**
	 * Remaining places are stated for 24 hours after the last save, then marked "not verified".
	 */
	public function test_stale_tour_availability(): void {
		$listing = T::listings()['tour'];
		$field   = T::registry()->get( 'tour' )->field( 'kalan_yer' );
		$this->assertNotNull( $field );
		$iso = static fn( FixedClock $clock ): string => gmdate( 'Y-m-d\TH:i:s\Z', $clock->now() );

		// Saved 08:00; the fake clock says noon of the same day (4 hours later).
		$today = $iso( new FixedClock( '2026-09-27' ) );
		$fresh = self::jsonld( $listing, $today )['dataFeedElement'][0]['item']['offers'];
		$this->assertSame( 6, $fresh['inventoryLevel']['value'] );
		$this->assertStringContainsString( 'Kalan yer: 6;', self::llms( $listing, $today ) );

		// Noon of the next day: 28 hours after the last save.
		$tomorrow = $iso( new FixedClock( '2026-09-28' ) );
		$stale    = self::jsonld( $listing, $tomorrow )['dataFeedElement'][0]['item']['offers'];
		$this->assertArrayNotHasKey( 'inventoryLevel', $stale );
		$marked = array_values( array_filter( $stale['additionalProperty'], static fn( array $p ): bool => 'Kalan yer' === $p['name'] ) );
		$this->assertSame( 'Doğrulanmadı: son güncelleme 2026-09-27T08:00:00Z', $marked[0]['description'] );
		$this->assertStringContainsString( 'Kalan yer: 6 (doğrulanmadı, son güncelleme 2026-09-27 08:00 UTC);', self::llms( $listing, $tomorrow ) );

		// The limit itself: 24 hours are still fresh, one second more is not.
		$this->assertFalse( Freshness::is_stale( $field, $listing, '2026-09-28T08:00:00Z' ) );
		$this->assertTrue( Freshness::is_stale( $field, $listing, '2026-09-28T08:00:01Z' ) );
		$this->assertFalse( Freshness::is_stale( $field, new Listing( null, 'offer', 'Kaydedilmemiş', template: 'tour' ), '2030-01-01T00:00:00Z' ) );
	}

	/**
	 * A demanded service: no additionalProperty on Demand or Service, so extras appear only in llms.txt.
	 */
	public function test_demanded_service(): void {
		$offer  = T::listings()['service'];
		$demand = new Listing( 25, 'demand', 'Aranan: tahkim avukatı', '', '', null, '', null, null, '', '', null, '2026-12-31', T::SAVED, $offer->attributes, 'service' );

		$document = self::jsonld( $demand );
		$json     = SchemaBuilderTest::json( $document );
		$this->assertSame( array(), ( new SchemaValidator() )->validate( $document )['errors'] );
		$this->assertStringNotContainsString( 'additionalProperty', $json );
		$this->assertStringContainsString( 'Ofis ülkesi: TR', self::llms( $demand ) );
	}
}
