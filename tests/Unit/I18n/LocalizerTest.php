<?php
/**
 * Localizer and translation writes.
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\Tests\Unit\I18n;

use AIHazirSite\Core\Catalog\CatalogService;
use AIHazirSite\Core\Catalog\CompanyProfile;
use AIHazirSite\Core\Catalog\Listing;
use AIHazirSite\Core\Catalog\ListingType;
use AIHazirSite\Core\I18n\LanguageSettings;
use AIHazirSite\Core\I18n\Localizer;
use AIHazirSite\Tests\Support\FixedClock;
use AIHazirSite\Tests\Support\MemoryListingRepository;
use AIHazirSite\Tests\Support\MemoryProfileRepository;
use AIHazirSite\Tests\Support\MemoryTranslationRepository;
use AIHazirSite\Tests\Unit\UnitTestCase;

/**
 * Only entered translations; missing ones fall back and are reported.
 *
 * @covers \AIHazirSite\Core\I18n\Localizer
 * @covers \AIHazirSite\Core\I18n\Localized
 * @covers \AIHazirSite\Core\Catalog\CatalogService
 */
final class LocalizerTest extends UnitTestCase {

	/**
	 * A listing in the default language.
	 */
	private static function listing(): Listing {
		return new Listing( 7, ListingType::OFFER, 'NYY kablo', 'Bakır iletkenli enerji kablosu.', 'Kablo', '1000', 'm', null, null, '', 'Marmara', 5, null, '2026-09-27T10:00:00Z' );
	}

	/**
	 * Translated fields replaced, untranslated non-empty ones listed, everything else untouched.
	 */
	public function test_listing(): void {
		$localizer = new Localizer( new LanguageSettings( 'tr', array( 'en', 'de' ) ) );
		$listing   = self::listing();
		$result    = $localizer->listing(
			$listing,
			array(
				'en' => array(
					'title'    => 'NYY cable',
					'category' => 'Cable',
				),
			),
			'en'
		);

		$this->assertSame( 'NYY cable', $result->record->title );
		$this->assertSame( 'Cable', $result->record->category );
		$this->assertSame( 'Bakır iletkenli enerji kablosu.', $result->record->description );
		$this->assertSame( array( 'description', 'region' ), $result->missing );
		$this->assertSame(
			array(
				'language'          => 'en',
				'missing'           => array( 'description', 'region' ),
				'fallback_language' => 'tr',
			),
			$result->marker()
		);
		$same = $listing->to_array();
		$got  = $result->record->to_array();
		foreach ( array_diff( Listing::FIELDS, Localizer::LISTING_FIELDS ) as $field ) {
			$this->assertSame( $same[ $field ], $got[ $field ], $field );
		}

		$this->assertSame( array( 'title', 'description', 'category', 'region' ), $localizer->listing( $listing, array(), 'de' )->missing );
		$default = $localizer->listing( $listing, array( 'en' => array( 'title' => 'x' ) ), 'tr' );
		$this->assertEquals( $listing, $default->record );
		$this->assertSame( array(), $default->missing );
		$this->assertNull( $default->marker()['fallback_language'] );
		$this->assertEquals( $listing, $localizer->listing( $listing, array( 'fr' => array( 'title' => 'x' ) ), 'fr' )->record, 'Unpublished language: unchanged.' );
	}

	/**
	 * Profile: only the sector is translatable; an empty sector is not "missing".
	 */
	public function test_profile(): void {
		$localizer = new Localizer( new LanguageSettings( 'tr', array( 'en' ) ) );
		$profile   = new CompanyProfile( 'Örnek Kablo A.Ş.', 'Kablo üretimi', 'TR', array( 'tr', 'en' ), 'satis@ornek.com.tr' );

		$result = $localizer->profile( $profile, array( 'en' => array( 'sector' => 'Cable manufacturing' ) ), 'en' );
		$this->assertSame( 'Cable manufacturing', $result->record->sector );
		$this->assertSame( 'Örnek Kablo A.Ş.', $result->record->name );
		$this->assertSame( array(), $result->missing );
		$this->assertSame( array( 'sector' ), $localizer->profile( $profile, array(), 'en' )->missing );
		$this->assertSame( array(), $localizer->profile( new CompanyProfile( 'Ad' ), array(), 'en' )->missing );
	}

	/**
	 * Cleaning keeps only translated languages, translatable fields and non-empty strings.
	 */
	public function test_clean(): void {
		$localizer = new Localizer( new LanguageSettings( 'tr', array( 'en' ) ) );
		$this->assertSame(
			array( 'en' => array( 'title' => 'Cable' ) ),
			$localizer->clean(
				array(
					'tr' => array( 'title' => 'Kablo' ),
					'en' => array(
						'title'  => ' Cable ',
						'region' => '',
						'price'  => '5',
						'unit'   => array( 'x' ),
					),
					'fr' => array( 'title' => 'Câble' ),
				),
				Localizer::LISTING_FIELDS
			)
		);
		$this->assertSame( array(), $localizer->clean( 'bozuk', Localizer::LISTING_FIELDS ) );
	}

	/**
	 * Writes go through the service: validated, merged per language, empty removes.
	 */
	public function test_service_writes(): void {
		$listings     = new MemoryListingRepository();
		$profiles     = new MemoryProfileRepository();
		$translations = new MemoryTranslationRepository();
		$service      = new CatalogService( $listings, $profiles, new FixedClock( '2026-09-27' ), null, $translations, $translations );
		$languages    = new LanguageSettings( 'tr', array( 'en', 'de' ) );
		$id           = (int) $listings->store_listing( self::listing() )->id;

		$this->assertSame( array(), $service->save_listing_translation( $id, 'en', array( 'title' => 'NYY cable' ), $languages ) );
		$this->assertSame( array(), $service->save_listing_translation( $id, 'de', array( 'title' => 'NYY-Kabel' ), $languages ) );
		$this->assertSame( array(), $service->save_listing_translation( $id, 'en', array( 'description' => 'Copper power cable.' ), $languages ) );
		$this->assertSame(
			array(
				'en' => array( 'description' => 'Copper power cable.' ),
				'de' => array( 'title' => 'NYY-Kabel' ),
			),
			$translations->translations( $id ),
			'A language is replaced as a whole; other languages kept.'
		);

		$this->assertArrayHasKey( 'language', $service->save_listing_translation( $id, 'tr', array( 'title' => 'x' ), $languages ) );
		$this->assertArrayHasKey( 'language', $service->save_listing_translation( $id, 'fr', array( 'title' => 'x' ), $languages ) );
		$this->assertArrayHasKey( 'id', $service->save_listing_translation( 999, 'en', array( 'title' => 'x' ), $languages ) );
		$this->assertArrayHasKey( 'title', $service->save_listing_translation( $id, 'en', array( 'title' => str_repeat( 'ç', 201 ) ), $languages ) );
		$this->assertArrayHasKey( 'title', $service->save_listing_translation( $id, 'en', array( 'title' => array( 'x' ) ), $languages ) );
		$this->assertSame( 'Copper power cable.', $translations->translations( $id )['en']['description'], 'Rejected input changed nothing.' );

		$this->assertSame( array(), $service->save_profile_translation( 'en', array( 'sector' => 'Cable manufacturing' ), $languages ) );
		$this->assertSame( array( 'en' => array( 'sector' => 'Cable manufacturing' ) ), $translations->profile_translations() );
		$this->assertSame( array(), $service->save_profile_translation( 'en', array( 'sector' => '' ), $languages ) );
		$this->assertSame( array(), $translations->profile_translations(), 'Empty removes.' );

		$without = new CatalogService( $listings, $profiles, new FixedClock( '2026-09-27' ) );
		$this->assertNotSame( array(), $without->save_listing_translation( $id, 'en', array( 'title' => 'x' ), $languages ) );
		$this->assertNotSame( array(), $without->save_profile_translation( 'en', array( 'sector' => 'x' ), $languages ) );

		$service->save_profile_translation( 'en', array( 'sector' => 'Cable' ), $languages );
		$service->purge();
		$this->assertSame( array(), $translations->profile_translations() );
	}
}
