<?php
/**
 * Matching engine acceptance (unit).
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\Tests\Unit\Matching;

use AIHazirSite\Core\Catalog\Listing;
use AIHazirSite\Core\Catalog\ListingType;
use AIHazirSite\Core\Matching\Candidate;
use AIHazirSite\Core\Matching\HardFilter;
use AIHazirSite\Core\Matching\Matcher;
use AIHazirSite\Core\Matching\MatchWeights;
use AIHazirSite\Core\Matching\PartnerListings;
use AIHazirSite\Tests\Support\FakeHttpClient;
use AIHazirSite\Tests\Unit\UnitTestCase;

/**
 * Cable distributor (needs) ↔ cable factory (offers/supply).
 *
 * @covers \AIHazirSite\Core\Matching\Matcher
 * @covers \AIHazirSite\Core\Matching\HardFilter
 * @covers \AIHazirSite\Core\Matching\MatchWeights
 * @covers \AIHazirSite\Core\Matching\PartnerListings
 * @covers \AIHazirSite\Core\Matching\Candidate
 */
final class MatcherTest extends UnitTestCase {

	/**
	 * The distributor's need: 2000 m NYY 3x2,5, IEC 60502-1, Marmara, within 10 days, budget 40–45 TRY.
	 */
	private static function need(): Listing {
		return new Listing(
			1,
			ListingType::DEMAND,
			'NYY 3x2,5 kablo aranıyor',
			'',
			'Kablo',
			'2000',
			'm',
			'40',
			'45',
			'TRY',
			'Marmara',
			10,
			null,
			null,
			array(
				'kesit'        => '2,5',
				'damar_sayisi' => '3',
				'standart'     => 'IEC 60502-1',
			),
			'product'
		);
	}

	/**
	 * A factory listing.
	 *
	 * @param int                   $id         Id.
	 * @param string                $type       Type.
	 * @param array<string, string> $attributes Attributes.
	 * @param array<string, mixed>  $more       Overrides: quantity, unit, price_min, price_max, currency, region, lead, category, template.
	 */
	private static function offer( int $id, string $type, array $attributes, array $more = array() ): Candidate {
		$m = array_merge(
			array(
				'quantity'  => '5000',
				'unit'      => 'm',
				'price_min' => '42',
				'price_max' => '44',
				'currency'  => 'TRY',
				'region'    => 'Marmara',
				'lead'      => 5,
				'category'  => 'Kablo',
				'template'  => 'product',
			),
			$more
		);
		return new Candidate( new Listing( $id, $type, 'Kablo ' . $id, '', $m['category'], $m['quantity'], $m['unit'], $m['price_min'], $m['price_max'], $m['currency'], $m['region'], $m['lead'], null, null, $attributes, $m['template'] ) );
	}

	/**
	 * The test data set: expected order and exclusions.
	 *
	 * @return list<Candidate>
	 */
	private static function candidates(): array {
		$full = array(
			'kesit'        => '2.5',
			'damar_sayisi' => '3',
			'standart'     => 'IEC 60502-1, TS 212',
		);
		return array(
			self::offer( 10, ListingType::OFFER, $full ), // Perfect: 100.
			self::offer( 11, ListingType::SUPPLY, $full, array( 'quantity' => '1000' ) ), // Half the quantity: 87.5.
			self::offer(
				12,
				ListingType::OFFER,
				array_merge( $full, array( 'kesit' => '4' ) ),
				array(
					'price_min' => '50',
					'price_max' => '55',
				)
			), // One of two attributes off, price 5 over: 12.5 + 25 + 25 + 25×(1−5/45).
			self::offer(
				13,
				ListingType::OFFER,
				$full,
				array(
					'lead'      => null,
					'quantity'  => null,
					'price_min' => null,
					'price_max' => null,
				)
			), // Unknowns: 25 + 12.5 + 12.5 + 12.5.
			self::offer( 20, ListingType::OFFER, $full, array( 'category' => 'Aydınlatma' ) ), // Excluded: category.
			self::offer( 21, ListingType::OFFER, array( 'kesit' => '2,5' ) ), // Excluded: standard missing.
			self::offer( 22, ListingType::OFFER, $full, array( 'region' => 'Ege' ) ), // Excluded: region.
			self::offer( 23, ListingType::OFFER, $full, array( 'lead' => 30 ) ), // Excluded: lead time.
			self::offer( 24, ListingType::DEMAND, $full ), // Excluded: another need.
			self::offer( 25, ListingType::OFFER, $full, array( 'template' => 'tour' ) ), // Excluded: template.
		);
	}

	/**
	 * Expected matches in the expected order; the scores follow the documented formula.
	 */
	public function test_expected_matches_in_order(): void {
		$result = ( new Matcher() )->match( self::need(), self::candidates() );
		$ids    = array_map( static fn( array $m ): int => (int) $m['candidate']->listing->id, $result['matches'] );
		$this->assertSame( array( 10, 11, 12, 13 ), $ids );

		$scores = array_column( $result['matches'], 'score' );
		$this->assertEqualsWithDelta( 100.0, $scores[0], 0.01 );
		$this->assertEqualsWithDelta( 87.5, $scores[1], 0.01 );
		$this->assertEqualsWithDelta( 25 * 1 / 2 + 25 + 25 + 25 * ( 1 - 5 / 45 ), $scores[2], 0.02, 'kesit differs (1 of 2 attributes), price 5 over the 45 budget.' );
		$this->assertEqualsWithDelta( 62.5, $scores[3], 0.01 );
	}

	/**
	 * Nothing that fails a hard filter is listed; every exclusion has a reason.
	 */
	public function test_hard_filters(): void {
		$result = ( new Matcher() )->match( self::need(), self::candidates() );
		$listed = array_map( static fn( array $m ): string => $m['candidate']->key(), $result['matches'] );
		foreach ( self::candidates() as $candidate ) {
			$reason = HardFilter::reason( self::need(), $candidate->listing );
			$this->assertSame( null === $reason, in_array( $candidate->key(), $listed, true ), $candidate->key() );
		}
		$this->assertSame( array( '#20', '#21', '#22', '#23', '#24', '#25' ), array_keys( $result['excluded'] ) );
		$this->assertStringContainsString( 'IEC 60502-1', $result['excluded']['#21'] );
		$this->assertStringContainsString( '30 gün > 10 gün', $result['excluded']['#23'] );

		// A need without a region or lead time does not filter on them.
		$open = new Listing( 2, ListingType::DEMAND, 'Kablo', '', 'Kablo' );
		$this->assertCount( 8, ( new Matcher() )->match( $open, self::candidates() )['matches'], 'Only the other need and the tour template are left out.' );
	}

	/**
	 * Each explanation adds up to its score; weights are normalised and change the order.
	 */
	public function test_explanation_matches_score(): void {
		foreach ( array(
			new MatchWeights(),
			new MatchWeights(
				array(
					'price'    => 3,
					'quantity' => 0,
				)
			),
			new MatchWeights(
				array(
					'attributes' => -5,
					'quantity'   => 'x',
				)
			),
		) as $weights ) {
			$this->assertEqualsWithDelta( 1.0, array_sum( $weights->weights ), 0.000001 );
			foreach ( ( new Matcher( $weights ) )->match( self::need(), self::candidates() )['matches'] as $match ) {
				$this->assertEqualsWithDelta( $match['score'], array_sum( array_column( $match['breakdown'], 'points' ) ), 0.011 );
				$this->assertSame( MatchWeights::CRITERIA, array_column( $match['breakdown'], 'criterion' ) );
				foreach ( $match['breakdown'] as $row ) {
					$this->assertEqualsWithDelta( 100 * $row['weight'] * $row['value'], $row['points'], 0.011 );
				}
			}
		}
		$this->assertSame(
			array( 0.25, 0.25, 0.25, 0.25 ),
			array_values(
				( new MatchWeights(
					array(
						'attributes' => 0,
						'quantity'   => 0,
						'lead_time'  => 0,
						'price'      => 0,
					)
				) )->weights
			)
		);

		// Quantity weight 0: the half-quantity supply ties with the perfect offer (then ordered by key).
		$ids = array_map( static fn( array $m ): int => (int) $m['candidate']->listing->id, ( new Matcher( new MatchWeights( array( 'quantity' => 0 ) ) ) )->match( self::need(), self::candidates() )['matches'] );
		$this->assertSame( array( 10, 11 ), array_slice( $ids, 0, 2 ) );
	}

	/**
	 * Partner sites: REST items become candidates; an unreachable partner gives null and local matching goes on.
	 */
	public function test_partner_unreachable(): void {
		$base    = 'https://fabrika.example/wp-json/aihs/v1/';
		$item    = array(
			'id'             => 7,
			'type'           => 'offer',
			'title'          => 'NYY 3x2,5',
			'category'       => 'Kablo',
			'url'            => 'https://fabrika.example/ai-katalog/#ilan-7',
			'quantity'       => array(
				'value' => '3000',
				'unit'  => 'm',
			),
			'price'          => array(
				'min'      => '43',
				'max'      => null,
				'currency' => 'TRY',
			),
			'region'         => 'Marmara',
			'lead_time_days' => 7,
			'template'       => 'product',
			'attributes'     => array(
				array(
					'name'  => 'kesit',
					'value' => '2,5',
				),
				array(
					'name'  => 'damar_sayisi',
					'value' => '3',
				),
				array(
					'name'  => 'standart',
					'value' => 'IEC 60502-1',
				),
				'bozuk',
			),
		);
		$http    = new FakeHttpClient(
			array(
				$base . 'listings?type=offer&per_page=50' => (string) json_encode( array( 'items' => array( $item, array( 'id' => 'x' ) ) ) ), // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Unit test without WordPress.
			)
		);
		$partner = ( new PartnerListings( $http ) )->candidates( $base );
		$this->assertNotNull( $partner );
		$this->assertCount( 1, $partner, 'Invalid items are skipped.' );
		$this->assertSame( $base, $partner[0]->source );
		$this->assertSame( 'https://fabrika.example/ai-katalog/#ilan-7', $partner[0]->url );

		$this->assertNull( ( new PartnerListings( new FakeHttpClient() ) )->candidates( 'https://kapali.example/wp-json/aihs/v1/' ) );
		$this->assertNull( ( new PartnerListings( $http ) )->candidates( 'http://guvensiz.example/' ) );

		$local  = array_slice( self::candidates(), 0, 2 );
		$result = ( new Matcher() )->match( self::need(), array_merge( $local, $partner ) );
		$this->assertCount( 3, $result['matches'] );
		$this->assertSame( $base . '#7', $result['matches'][1]['candidate']->key(), 'Partner candidate scored like any other.' );
		$this->assertEqualsWithDelta( 100.0, $result['matches'][1]['score'], 0.01 );
	}
}
