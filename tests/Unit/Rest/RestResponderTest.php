<?php
/**
 * Tests for RestResponder and RestSchemas.
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\Tests\Unit\Rest;

use AIHazirSite\Adapters\Rest\ListingsQuery;
use AIHazirSite\Adapters\Rest\RestResponder;
use AIHazirSite\Adapters\Rest\RestSchemas;
use AIHazirSite\Core\Catalog\CompanyProfile;
use AIHazirSite\Core\Catalog\Listing;
use AIHazirSite\Tests\Support\SchemaFixtures as F;
use AIHazirSite\Tests\Support\TemplateFixtures as T;
use PHPUnit\Framework\TestCase;

/**
 * Bodies of the aihs/v1 endpoints.
 *
 * @covers \AIHazirSite\Adapters\Rest\RestResponder
 * @covers \AIHazirSite\Adapters\Rest\RestSchemas
 */
final class RestResponderTest extends TestCase {

	/**
	 * Responder with the shipped templates.
	 */
	private static function responder(): RestResponder {
		return new RestResponder( F::SITE_URL, T::CATALOG, T::registry() );
	}

	/**
	 * Every fixture listing (current, expired, stale-by-default and templated ones).
	 *
	 * @return list<Listing>
	 */
	private static function all(): array {
		return array_merge( array( F::offer(), F::supply(), F::demand(), F::expired(), F::stale(), F::unpriced() ), array_values( T::listings() ) );
	}

	/**
	 * Keys an object must have by its schema (required first, then optional ones present).
	 *
	 * @param array<string, mixed> $schema Object schema.
	 * @param array<string, mixed> $value  Value.
	 */
	private function assertKeysMatch( array $schema, array $value ): void {
		$this->assertSame( array(), array_values( array_diff( $schema['required'], array_keys( $value ) ) ), 'Missing keys.' );
		$this->assertSame( array(), array_values( array_diff( array_keys( $value ), array_keys( $schema['properties'] ) ) ), 'Unknown keys.' );
	}

	/**
	 * Profile body.
	 */
	public function test_profile(): void {
		$body = self::responder()->profile( F::profile(), '2026-09-22T08:00:00Z' );

		$this->assertKeysMatch( RestSchemas::profile(), $body );
		$this->assertSame( array( 'Örnek Kablo A.Ş.', 'general', '2026-09-22T08:00:00Z', null ), array( $body['name'], $body['template'], $body['updated_at'], $body['valid_until'] ) );
	}

	/**
	 * Only current listings, newest first, paged; updated_at is the latest change.
	 */
	public function test_listings_current_and_paged(): void {
		$first = self::responder()->listings( self::all(), new ListingsQuery( '', '', '', 1, 3 ), F::TODAY, T::NOW );

		$this->assertKeysMatch( RestSchemas::listings(), $first );
		$this->assertSame( array( 8, 3, 3, 1 ), array( $first['total'], $first['total_pages'], count( $first['items'] ), $first['page'] ) );
		$this->assertSame( array( 24, 23, 22 ), array_column( $first['items'], 'id' ), 'Same save time: higher id first.' );
		$this->assertSame( T::SAVED, $first['updated_at'] );

		$ids = array_column( self::responder()->listings( self::all(), new ListingsQuery( '', '', '', 1, 50 ), F::TODAY, T::NOW )['items'], 'id' );
		$this->assertNotContains( 14, $ids, 'Expired.' );
		$this->assertNotContains( 15, $ids, 'Expired by the 90-day default.' );
		$this->assertCount( 8, $ids );

		$last = self::responder()->listings( self::all(), new ListingsQuery( '', '', '', 3, 3 ), F::TODAY, T::NOW );
		$this->assertCount( 2, $last['items'] );
		$this->assertSame( array(), self::responder()->listings( self::all(), new ListingsQuery( '', '', '', 9, 3 ), F::TODAY, T::NOW )['items'] );

		$demand = self::responder()->listings( self::all(), new ListingsQuery( 'demand' ), F::TODAY, T::NOW );
		$this->assertSame( array( 13 ), array_column( $demand['items'], 'id' ) );
	}

	/**
	 * One listing: shape, effective valid_until, attributes with labels; expired → null.
	 */
	public function test_listing(): void {
		$item = self::responder()->listing( T::listings()['product'], F::TODAY, T::NOW );
		$this->assertNotNull( $item );
		$this->assertKeysMatch( RestSchemas::listing(), $item );
		$this->assertSame(
			array(
				'min'      => '42.50',
				'max'      => '48.75',
				'currency' => 'TRY',
			),
			$item['price']
		);
		$this->assertSame(
			array(
				'name'         => 'kesit',
				'label'        => 'Kesit',
				'value'        => '2.5',
				'unit'         => 'mm²',
				'verified'     => true,
				'confirmed_at' => null,
			),
			$item['attributes'][0]
		);
		$this->assertSame( 'ozel_not', $item['attributes'][7]['label'], 'Extras keep their key as label.' );
		$this->assertSame( T::CATALOG . '#ilan-21', $item['url'] );

		$unpriced = self::responder()->listing( F::unpriced(), F::TODAY, T::NOW );
		$this->assertNotNull( $unpriced );
		$this->assertArrayHasKey( 'price', $unpriced, 'Price allowed but not given: null.' );
		$this->assertSame( array( null, '2026-12-24' ), array( $unpriced['price'], $unpriced['valid_until'] ) );

		$this->assertNull( self::responder()->listing( F::expired(), F::TODAY, T::NOW ) );
	}

	/**
	 * The service template never exposes a price, even when one is stored.
	 */
	public function test_service_price_absent(): void {
		$item = self::responder()->listing( T::listings()['service'], F::TODAY, T::NOW );

		$this->assertNotNull( $item );
		$this->assertArrayNotHasKey( 'price', $item );
		$this->assertStringNotContainsString( '1000', (string) json_encode( $item ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
		$this->assertKeysMatch( RestSchemas::listing(), $item );
	}

	/**
	 * Remaining tour places: verified within 24 hours of the last save, not after.
	 */
	public function test_stale_value(): void {
		$tour  = T::listings()['tour'];
		$place = static fn( array $item ): array => array_values( array_filter( $item['attributes'], static fn( array $a ): bool => 'kalan_yer' === $a['name'] ) )[0];

		$fresh = $place( (array) self::responder()->listing( $tour, F::TODAY, '2026-09-28T08:00:00Z' ) );
		$this->assertSame( array( true, T::SAVED ), array( $fresh['verified'], $fresh['confirmed_at'] ) );

		$stale = $place( (array) self::responder()->listing( $tour, F::TODAY, '2026-09-28T08:00:01Z' ) );
		$this->assertSame( array( false, T::SAVED ), array( $stale['verified'], $stale['confirmed_at'] ) );
	}

	/**
	 * Templates in use: general, the profile's and the current listings'.
	 */
	public function test_templates(): void {
		$profile = new CompanyProfile( 'Tur A.Ş.', template: 'tour' );
		$used    = self::responder()->used_templates( $profile, array( T::listings()['service'], F::offer() ), F::TODAY );
		$body    = self::responder()->templates( $used );

		$this->assertKeysMatch( RestSchemas::templates(), $body );
		$this->assertSame( array( 'general', 'tour', 'service' ), array_column( $body['items'], 'id' ) );
		$this->assertFalse( $body['items'][2]['price_allowed'] );
		$this->assertSame( 24, $body['items'][1]['fields'][3]['fresh_hours'] );

		$off = ( new RestResponder( F::SITE_URL, T::CATALOG ) )->used_templates( $profile, array( T::listings()['service'] ), F::TODAY );
		$this->assertSame( array( 'general' ), array_map( static fn( $t ): string => $t->id, $off ), 'Templates off: general only.' );
	}

	/**
	 * Every schema is a titled draft-04 document.
	 */
	public function test_schemas(): void {
		foreach ( RestSchemas::NAMES as $name ) {
			$schema = RestSchemas::get( $name );
			$this->assertSame( array( RestSchemas::DRAFT, 'aihs-' . $name, 'object' ), array( $schema['$schema'], $schema['title'], $schema['type'] ), $name );
		}
		$this->assertSame( array(), RestSchemas::get( 'yok' ) );
	}
}
