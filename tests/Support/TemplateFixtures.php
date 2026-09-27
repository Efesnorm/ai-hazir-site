<?php
/**
 * One listing per sector template.
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\Tests\Support;

use AIHazirSite\Core\Catalog\Listing;
use AIHazirSite\Core\Templates\TemplateRegistry;

/**
 * Listings as stored after template validation. "Today" is 2026-09-27; every listing was
 * last saved at 08:00 UTC that day.
 */
final class TemplateFixtures {

	public const TODAY   = '2026-09-27';
	public const NOW     = '2026-09-27T12:00:00Z';
	public const SAVED   = '2026-09-27T08:00:00Z';
	public const CATALOG = 'https://ornek.com/ai-katalog/';

	/**
	 * Shipped templates.
	 */
	public static function registry(): TemplateRegistry {
		return new TemplateRegistry( array( TemplateRegistry::data_dir() ) );
	}

	/**
	 * Listing per template id.
	 *
	 * @return array<string, Listing>
	 */
	public static function listings(): array {
		return array(
			'product'        => new Listing(
				21,
				'offer',
				'NYY 3x2,5 enerji kablosu',
				'Stoktan teslim.',
				'Kablo',
				'1500',
				'm',
				'42.50',
				'48.75',
				'TRY',
				'Türkiye',
				3,
				'2026-12-31',
				self::SAVED,
				array(
					'kesit'        => '2.5',
					'damar_sayisi' => '3',
					'iletken'      => 'Bakır',
					'izolasyon'    => 'PVC',
					'standart'     => 'TSE, IEC 60502-1',
					'renk'         => 'Siyah',
					'makara_boyu'  => '500',
					'ozel_not'     => 'Halojen içermez',
				),
				'product'
			),
			'export_product' => new Listing(
				22,
				'offer',
				'Bakır tel (ihracat)',
				'',
				'Hammadde',
				'20000',
				'kg',
				'9.5',
				null,
				'USD',
				'',
				30,
				'2026-12-31',
				self::SAVED,
				array(
					'gtip'           => '7408.11',
					'mense'          => 'TR',
					'teslim_sekli'   => 'FOB',
					'hedef_pazarlar' => 'DE, FR',
					'sertifikalar'   => 'ISO 9001, REACH',
					'moq'            => '1000',
				),
				'export_product'
			),
			// A price is stored here on purpose: the service template must never publish it.
			'service'        => new Listing(
				23,
				'offer',
				'Tahkim danışmanlığı',
				'Uluslararası ticari uyuşmazlıklar.',
				'',
				null,
				'',
				'1000',
				null,
				'TRY',
				'Türkiye',
				null,
				'2026-12-31',
				self::SAVED,
				array(
					'uzmanlik_alani' => 'Ticaret hukuku, Tahkim',
					'ofis_ulkesi'    => 'TR',
					'diller'         => 'tr, en',
				),
				'service'
			),
			'tour'           => new Listing(
				24,
				'offer',
				'Kapadokya balon turu',
				'',
				'Tur',
				null,
				'',
				'250',
				null,
				'EUR',
				'Nevşehir',
				null,
				'2026-10-14',
				self::SAVED,
				array(
					'baslangic_tarihi' => '2026-10-15',
					'sure_gun'         => '3',
					'kontenjan'        => '20',
					'kalan_yer'        => '6',
					'dahil_olanlar'    => 'Konaklama, balon uçuşu',
					'iptal_kosulu'     => '7 gün öncesine kadar ücretsiz',
					'bulusma_noktasi'  => 'Göreme otogarı',
				),
				'tour'
			),
		);
	}
}
