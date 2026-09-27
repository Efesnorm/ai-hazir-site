<?php
/**
 * Listings: paging, filters, expired listings, prices.
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\Tests\Integration\Rest;

use AIHazirSite\WordPress\Catalog\CatalogModule;

/**
 * GET /listings and /listings/{id}.
 *
 * @covers \AIHazirSite\WordPress\Rest\RestModule
 * @covers \AIHazirSite\Adapters\Rest\ListingsQuery
 */
final class RestListingsTest extends RestTestCase {

	/**
	 * 55 listings: at most 50 per page, correct totals and headers, pages without overlap.
	 */
	public function test_paging(): void {
		$service = CatalogModule::service();
		for ( $i = 1; $i <= 55; $i++ ) {
			$service->save_listing(
				array(
					'type'  => 0 === $i % 5 ? 'demand' : 'offer',
					'title' => 'İlan ' . $i,
				)
			);
		}

		$first = self::get( '/listings', array( 'per_page' => 50 ) );
		$this->assertCount( 50, $first->get_data()['items'] );
		$this->assertSame( array( '55', '2' ), array( $first->get_headers()['X-WP-Total'], $first->get_headers()['X-WP-TotalPages'] ) );

		$second = self::get(
			'/listings',
			array(
				'per_page' => 50,
				'page'     => 2,
			)
		);
		$this->assertCount( 5, $second->get_data()['items'] );
		$this->assertSame( array(), array_intersect( array_column( $first->get_data()['items'], 'id' ), array_column( $second->get_data()['items'], 'id' ) ) );

		$default = self::get( '/listings' )->get_data();
		$this->assertSame( array( 20, 3 ), array( count( $default['items'] ), $default['total_pages'] ) );

		foreach ( array( array( 'per_page' => 51 ), array( 'per_page' => 0 ), array( 'page' => 0 ), array( 'type' => 'kiralik' ) ) as $params ) {
			$this->assertSame( 400, self::get( '/listings', $params )->get_status(), (string) wp_json_encode( $params ) );
		}
	}

	/**
	 * Filters alone and together; case-insensitive.
	 */
	public function test_filters(): void {
		self::catalog();
		$titles = static fn( array $params ): array => array_column( self::get( '/listings', $params )->get_data()['items'], 'title' );

		$this->assertSame( array( 'Bakır katot' ), $titles( array( 'type' => 'demand' ) ) );
		$this->assertSame( array( 'NYY kablo' ), $titles( array( 'category' => 'KABLO' ) ) );
		$this->assertSame( array( 'NYY kablo' ), $titles( array( 'region' => 'türkiye' ) ) );
		$this->assertSame(
			array( 'Bakır katot' ),
			$titles(
				array(
					'type'     => 'demand',
					'category' => 'hammadde',
					'region'   => 'Marmara',
				)
			)
		);
		$this->assertSame(
			array(),
			$titles(
				array(
					'type'     => 'offer',
					'category' => 'Hammadde',
				)
			)
		);
	}

	/**
	 * Expired listings are nowhere; the service price is nowhere; the tour shows its remaining places.
	 */
	public function test_expired_and_service_price(): void {
		$ids = self::catalog();

		$this->assertNotContains( 'Eski kampanya', array_column( self::get( '/listings', array( 'per_page' => 50 ) )->get_data()['items'], 'title' ) );
		$this->assertSame( 404, self::get( '/listings/' . $ids['expired'] )->get_status() );
		$this->assertSame( 404, self::get( '/listings/999999' )->get_status() );

		$service = self::get( '/listings/' . $ids['service'] )->get_data();
		$this->assertArrayNotHasKey( 'price', $service );
		$this->assertStringNotContainsString( '1000', (string) wp_json_encode( self::get( '/listings', array( 'per_page' => 50 ) )->get_data() ) );

		$cable = self::get( '/listings/' . $ids['cable'] )->get_data();
		$this->assertSame(
			array(
				'min'      => '42.50',
				'max'      => null,
				'currency' => 'TRY',
			),
			$cable['price']
		);
		$this->assertSame( array( 'Kesit', '2.5', 'mm²' ), array( $cable['attributes'][0]['label'], $cable['attributes'][0]['value'], $cable['attributes'][0]['unit'] ) );

		$tour = self::get( '/listings/' . $ids['tour'] )->get_data();
		$this->assertTrue( array_column( $tour['attributes'], 'verified', 'name' )['kalan_yer'] );
		$this->assertMatchesRegularExpression( '/^\d{4}-\d{2}-\d{2}$/', $tour['valid_until'] );
		$this->assertMatchesRegularExpression( '/^\d{4}-\d{2}-\d{2}T/', (string) $tour['updated_at'] );
	}
}
