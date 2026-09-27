<?php
/**
 * Catalog data for Schema.org tests.
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\Tests\Support;

use AIHazirSite\Core\Catalog\CompanyProfile;
use AIHazirSite\Core\Catalog\Listing;

/**
 * A fixed profile and one listing per situation. "Today" is 2026-09-27.
 */
final class SchemaFixtures {

	public const TODAY    = '2026-09-27';
	public const SITE_URL = 'https://ornek.com/';

	/**
	 * Company profile.
	 */
	public static function profile(): CompanyProfile {
		return new CompanyProfile( 'Örnek Kablo A.Ş.', 'Enerji ve telekom kabloları', 'TR', array( 'tr', 'en' ), 'satis@ornek.com.tr', '+90 212 555 12 34', array( 'ISO 9001' ) );
	}

	/**
	 * Offer with a price range, stock, attributes and lead time.
	 */
	public static function offer(): Listing {
		return new Listing(
			11,
			'offer',
			'NYY 3x2,5 enerji kablosu',
			'TSE belgeli, stoktan teslim.',
			'Kablo',
			'1500',
			'm',
			'42.50',
			'48.75',
			'TRY',
			'Türkiye',
			3,
			'2026-12-31',
			'2026-09-20T09:30:00Z',
			array(
				'kesit'    => '3x2,5 mm²',
				'standart' => 'TS EN 60228',
			)
		);
	}

	/**
	 * Suppliable item with one price and lead time.
	 */
	public static function supply(): Listing {
		return new Listing( 12, 'supply', 'Özel kesim kablo demeti', 'Çizime göre üretim.', 'Kablo demeti', null, 'adet', '120', '120', 'EUR', 'Avrupa', 21, '2027-03-31', '2026-09-21T10:00:00Z' );
	}

	/**
	 * Demand with a quantity and budget.
	 */
	public static function demand(): Listing {
		return new Listing( 13, 'demand', 'Bakır katot', 'LME A sınıfı.', 'Hammadde', '20', 'ton', '8000', '9000', 'USD', 'Marmara', 30, '2026-10-31', '2026-09-22T08:00:00Z' );
	}

	/**
	 * Expired offer (valid until yesterday).
	 */
	public static function expired(): Listing {
		return new Listing( 14, 'offer', 'Eski kampanya', '', '', null, '', '10', null, 'TRY', '', null, '2026-09-26', '2026-09-01T00:00:00Z' );
	}

	/**
	 * Offer without a date, updated 100 days ago (the 90-day default has passed).
	 */
	public static function stale(): Listing {
		return new Listing( 15, 'offer', 'Güncellenmeyen ilan', '', '', null, '', '5', null, 'TRY', '', null, null, '2026-06-19T00:00:00Z' );
	}

	/**
	 * Offer without price or date, updated recently.
	 */
	public static function unpriced(): Listing {
		return new Listing( 16, 'offer', 'Fiyat sorunuz', '', '', null, '', null, null, '', '', null, null, '2026-09-25T12:00:00Z' );
	}
}
