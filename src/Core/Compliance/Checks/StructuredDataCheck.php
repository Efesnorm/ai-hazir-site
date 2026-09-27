<?php
/**
 * Structured data (JSON-LD) check.
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\Core\Compliance\Checks;

use AIHazirSite\Core\Compliance\Check;
use AIHazirSite\Core\Compliance\CheckResult;
use AIHazirSite\Core\Compliance\Html;
use AIHazirSite\Core\Compliance\Site;

/**
 * Sample pages carry parseable JSON-LD with the properties Google requires:
 * Product: name + one of offers/review/aggregateRating; Offer: price;
 * LocalBusiness: name + address. Organization has no required property.
 * Sources: developers.google.com/search/docs/appearance/structured-data/{product-snippet,local-business,organization}.
 */
final class StructuredDataCheck implements Check {

	/**
	 * Id.
	 */
	public function id(): string {
		return 'structured_data';
	}

	/**
	 * Weight.
	 */
	public function weight(): int {
		return 20;
	}

	/**
	 * Per page: 1 = valid JSON-LD with required properties, 0.5 = partly valid, 0 = none.
	 *
	 * @param Site $site Site.
	 */
	public function run( Site $site ): CheckResult {
		$scores   = array();
		$findings = array();

		foreach ( $site->page_responses() as $page ) {
			if ( ! $page['response']->ok() ) {
				continue;
			}
			$json_ld = ( new Html( $page['response']->body ) )->json_ld();
			if ( array() === $json_ld['items'] ) {
				$scores[]   = 0.0;
				$findings[] = $page['url'] . ': ' . ( $json_ld['blocks'] > 0 ? 'JSON-LD ayrıştırılamadı.' : 'JSON-LD yok.' );
				continue;
			}

			$missing = array();
			foreach ( $json_ld['items'] as $item ) {
				array_push( $missing, ...self::missing_required( $item ) );
			}
			if ( $json_ld['invalid'] > 0 ) {
				$missing[] = $json_ld['invalid'] . ' blok ayrıştırılamadı';
			}
			$scores[] = array() === $missing ? 1.0 : 0.5;
			if ( array() !== $missing ) {
				$findings[] = $page['url'] . ': ' . implode( '; ', $missing ) . '.';
			}
		}

		if ( array() === $scores ) {
			return CheckResult::unmeasured( 'Örnek sayfalara erişilemedi.' );
		}

		$valid = count( array_filter( $scores, static fn( float $s ): bool => $s >= 1.0 ) );
		array_unshift( $findings, sprintf( '%d/%d sayfada eksiksiz yapılandırılmış veri var.', $valid, count( $scores ) ) );

		return CheckResult::measured(
			array_sum( $scores ) / count( $scores ),
			$findings,
			'Ana sayfaya Organization, ürün sayfalarına Product (name ve offers/price) JSON-LD ekleyin; Google Zengin Sonuç Testi ile doğrulayın.'
		);
	}

	/**
	 * Missing required properties of one JSON-LD item.
	 *
	 * @param array<string, mixed> $item JSON-LD object.
	 * @return list<string>
	 */
	private static function missing_required( array $item ): array {
		$types   = array_map( 'strval', (array) ( $item['@type'] ?? array() ) );
		$missing = array();

		if ( array() === $types ) {
			return array( '@type eksik' );
		}
		if ( in_array( 'Product', $types, true ) ) {
			if ( empty( $item['name'] ) ) {
				$missing[] = 'Product: name eksik';
			}
			if ( empty( $item['offers'] ) && empty( $item['review'] ) && empty( $item['aggregateRating'] ) ) {
				$missing[] = 'Product: offers, review veya aggregateRating eksik';
			}
			foreach ( self::list_of( $item['offers'] ?? array() ) as $offer ) {
				$is_aggregate = in_array( 'AggregateOffer', array_map( 'strval', (array) ( $offer['@type'] ?? array() ) ), true );
				if ( $is_aggregate && ( ! isset( $offer['lowPrice'] ) || empty( $offer['priceCurrency'] ) ) ) {
					$missing[] = 'AggregateOffer: lowPrice veya priceCurrency eksik';
				} elseif ( ! $is_aggregate && ! isset( $offer['price'] ) && ! isset( $offer['priceSpecification']['price'] ) ) {
					$missing[] = 'Offer: price eksik';
				}
			}
		}
		if ( in_array( 'LocalBusiness', $types, true ) ) {
			foreach ( array( 'name', 'address' ) as $property ) {
				if ( empty( $item[ $property ] ) ) {
					$missing[] = 'LocalBusiness: ' . $property . ' eksik';
				}
			}
		}
		return $missing;
	}

	/**
	 * A JSON-LD value as a list of objects.
	 *
	 * @param mixed $value Value.
	 * @return list<array<mixed>>
	 */
	private static function list_of( mixed $value ): array {
		if ( ! is_array( $value ) ) {
			return array();
		}
		if ( ! array_is_list( $value ) ) {
			return array( $value );
		}
		return array_values( array_filter( $value, 'is_array' ) );
	}
}
