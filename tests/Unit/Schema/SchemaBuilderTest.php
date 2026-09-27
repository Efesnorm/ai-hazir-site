<?php
/**
 * Snapshot and rule tests for the Schema.org output.
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\Tests\Unit\Schema;

use AIHazirSite\Adapters\Schema\SchemaBuilder;
use AIHazirSite\Adapters\Schema\SchemaValidator;
use AIHazirSite\Core\Catalog\Listing;
use AIHazirSite\Tests\Support\SchemaFixtures as F;
use PHPUnit\Framework\TestCase;

/**
 * Snapshots live in tests/Snapshots/schema/; a changed output fails until reviewed on purpose.
 *
 * @covers \AIHazirSite\Adapters\Schema\SchemaBuilder
 * @covers \AIHazirSite\Adapters\Schema\SchemaValidator
 */
final class SchemaBuilderTest extends TestCase {

	/**
	 * Pretty JSON used for snapshots.
	 *
	 * @param array<mixed> $data Data.
	 */
	public static function json( array $data ): string {
		return (string) json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . "\n"; // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
	}

	/**
	 * Snapshot content.
	 *
	 * @param string $name Name.
	 */
	private static function snapshot( string $name ): string {
		return (string) file_get_contents( dirname( __DIR__, 2 ) . '/Snapshots/schema/' . $name . '.json' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
	}

	/**
	 * Builder over the fixture site.
	 */
	private static function builder(): SchemaBuilder {
		return new SchemaBuilder( F::SITE_URL );
	}

	/**
	 * One listing per type.
	 *
	 * @return array<string, array{string, Listing}>
	 */
	public static function types(): array {
		return array(
			'offer'  => array( 'offer', F::offer() ),
			'supply' => array( 'supply', F::supply() ),
			'demand' => array( 'demand', F::demand() ),
		);
	}

	/**
	 * Each listing type matches its snapshot and validates without errors or warnings.
	 *
	 * @dataProvider types
	 *
	 * @param string  $name    Snapshot name.
	 * @param Listing $listing Listing.
	 */
	public function test_listing_type_snapshot( string $name, Listing $listing ): void {
		$entry = self::builder()->entry( $listing, F::TODAY, F::profile() );
		$this->assertNotNull( $entry );
		$this->assertSame( self::snapshot( $name ), self::json( $entry ) );

		$result = ( new SchemaValidator() )->validate( array( '@context' => SchemaBuilder::CONTEXT ) + $entry );
		$this->assertSame( array(), $result['errors'] );
		$this->assertSame( array(), $result['warnings'] );
	}

	/**
	 * Home page graph matches its snapshot and validates.
	 */
	public function test_home_snapshot(): void {
		$home = self::builder()->home( F::profile(), '2026-09-22T08:00:00Z' );

		$this->assertSame( self::snapshot( 'home' ), self::json( $home ) );
		$this->assertSame( array( 'errors' => array(), 'warnings' => array() ), ( new SchemaValidator() )->validate( $home ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
	}

	/**
	 * Expired listings (explicitly or by the 90-day default) are not in the output.
	 */
	public function test_expired_listings_are_left_out(): void {
		$feed = self::builder()->catalog( F::profile(), array( F::offer(), F::expired(), F::stale(), F::demand(), F::unpriced() ), F::TODAY, F::SITE_URL . 'ai-katalog/' );
		$json = self::json( $feed );

		$this->assertCount( 3, $feed['dataFeedElement'] );
		$this->assertStringNotContainsString( 'Eski kampanya', $json );
		$this->assertStringNotContainsString( 'Güncellenmeyen ilan', $json );
		$this->assertNull( self::builder()->entry( F::expired(), F::TODAY ) );
		$this->assertSame( '2026-09-25T12:00:00Z', $feed['dateModified'], 'Latest change among published listings.' );
	}

	/**
	 * Both dateModified and validThrough are always present, with a 90-day default validity.
	 */
	public function test_dates_always_present(): void {
		$entry = self::builder()->entry( F::unpriced(), F::TODAY );
		$this->assertNotNull( $entry );

		$this->assertSame( '2026-09-25T12:00:00Z', $entry['dateModified'] );
		$this->assertSame( '2026-12-24', $entry['item']['offers']['validThrough'], 'Updated 2026-09-25 + 90 days.' );
		$this->assertArrayNotHasKey( 'price', $entry['item']['offers'] );

		$result = ( new SchemaValidator() )->validate( array( '@context' => SchemaBuilder::CONTEXT ) + $entry );
		$this->assertSame( array(), $result['errors'] );
		$this->assertCount( 1, $result['warnings'], 'No price: Google warning, not an error.' );
	}

	/**
	 * With another plugin owning Organization, references carry the name instead of our @id.
	 */
	public function test_named_organization_reference_on_conflict(): void {
		$feed = ( new SchemaBuilder( F::SITE_URL, false ) )->catalog( F::profile(), array( F::offer() ), F::TODAY, F::SITE_URL . 'ai-katalog/' );

		$this->assertSame( 'Örnek Kablo A.Ş.', $feed['publisher']['name'] );
		$this->assertSame( 'Örnek Kablo A.Ş.', $feed['dataFeedElement'][0]['item']['offers']['seller']['name'] );
		$this->assertStringNotContainsString( '#organization', self::json( $feed ) );
	}

	/**
	 * The validator catches broken output.
	 */
	public function test_validator_errors(): void {
		$bad = array(
			'@context'        => 'http://example.com',
			'@type'           => 'DataFeed',
			'name'            => 'x',
			'dateModified'    => 'dün',
			'dataFeedElement' => array(
				array(
					'@type' => 'DataFeedItem',
					'item'  => array(
						'@type'  => 'Product',
						'offers' => array(
							'@type'         => 'Offer',
							'price'         => 'on bin',
							'priceCurrency' => 'lira',
							'availability'  => 'https://schema.org/Belki',
						),
					),
				),
				array( '@type' => 'Recipe' ),
			),
		);

		$errors = implode( "\n", ( new SchemaValidator() )->validate( $bad )['errors'] );
		foreach ( array( '@context', 'dateModified geçerli bir tarih değil', 'dateModified zorunlu', 'name zorunlu', 'validThrough zorunlu', 'price sayı olmalı', 'priceCurrency ISO 4217', 'availability geçersiz', 'beklenmeyen tür "Recipe"' ) as $expected ) {
			$this->assertStringContainsString( $expected, $errors );
		}
	}
}
