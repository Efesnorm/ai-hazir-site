<?php
/**
 * NACE Rev. 2.1 sector field (1.22.0).
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\Tests\Unit\Catalog;

use AIHazirSite\Adapters\Rest\ListingsQuery;
use AIHazirSite\Adapters\Rest\RestResponder;
use AIHazirSite\Adapters\Rest\RestSchemas;
use AIHazirSite\Core\Catalog\CompanyProfile;
use AIHazirSite\Core\Catalog\Listing;
use AIHazirSite\Core\Catalog\Nace;
use AIHazirSite\Core\Catalog\ProfileValidator;
use AIHazirSite\Core\Catalog\Query\SectorFilter;
use AIHazirSite\Core\Portal\Business;
use PHPUnit\Framework\TestCase;

/**
 * Section list, validation, storage, REST output and the sector filter.
 *
 * @covers \AIHazirSite\Core\Catalog\Nace
 * @covers \AIHazirSite\Core\Catalog\Query\SectorFilter
 * @covers \AIHazirSite\Core\Catalog\CompanyProfile
 * @covers \AIHazirSite\Core\Catalog\ProfileValidator
 * @covers \AIHazirSite\Adapters\Rest\RestResponder
 * @covers \AIHazirSite\Adapters\Rest\ListingsQuery
 */
final class NaceTest extends TestCase {

	/**
	 * 22 sections, A to V, each with an English and a Turkish title.
	 */
	public function test_sections(): void {
		$this->assertSame( range( 'A', 'V' ), array_keys( Nace::SECTIONS ) );
		foreach ( Nace::SECTIONS as $titles ) {
			$this->assertCount( 2, $titles );
			$this->assertNotSame( '', $titles[0] );
			$this->assertNotSame( '', $titles[1] );
		}
		$this->assertSame( 'H', Nace::section( ' h ' ) );
		$this->assertSame( '', Nace::section( 'W' ) );
		$this->assertSame( '', Nace::section( 'HH' ) );
		$this->assertSame( '', Nace::section( array( 'H' ) ) );
		$this->assertSame( 'H – Ulaştırma ve depolama', Nace::label( 'H' ) );
		$this->assertSame( '', Nace::label( '' ) );
	}

	/**
	 * The profile takes an optional section; an unknown one is an error; an empty one changes nothing stored.
	 */
	public function test_profile_field(): void {
		$validator = new ProfileValidator();
		$this->assertSame(
			'I',
			$validator->validate(
				array(
					'name' => 'Ohrid Tur',
					'nace' => 'i',
				)
			)->profile()?->nace
		);
		$this->assertArrayHasKey(
			'nace',
			$validator->validate(
				array(
					'name' => 'Ohrid Tur',
					'nace' => 'Z',
				)
			)->errors
		);

		$plain = $validator->validate( array( 'name' => 'Ohrid Tur' ) )->profile();
		$this->assertNotNull( $plain );
		$this->assertSame( '', $plain->nace );
		$this->assertSame( CompanyProfile::FIELDS, array_keys( $plain->to_array() ), 'Without a section the stored profile is as before.' );

		$profile = new CompanyProfile( 'Ohrid Tur', nace: 'I' );
		$this->assertSame( 'I', CompanyProfile::from_array( $profile->to_array() )->nace );
		$this->assertSame( '', CompanyProfile::from_array( array( 'nace' => 'bogus' ) )->nace );
	}

	/**
	 * REST /profile and /businesses carry `nace` only when chosen; the schemas accept both shapes.
	 */
	public function test_rest_output(): void {
		$responder = new RestResponder( 'https://ornek.test/', 'https://ornek.test/ai-katalog/' );
		$this->assertArrayNotHasKey( 'nace', $responder->profile( new CompanyProfile( 'A' ), null ) );
		$this->assertSame( 'H', $responder->profile( new CompanyProfile( 'A', nace: 'H' ), null )['nace'] );

		$businesses = $responder->businesses(
			array(
				new Business( 1, 'nakliye', new CompanyProfile( 'Nakliye', nace: 'H' ) ),
				new Business( 2, 'otel', new CompanyProfile( 'Otel' ) ),
			),
			static fn(): string => 'https://ornek.test/isletme/'
		);
		$this->assertSame( 'H', $businesses['items'][0]['nace'] );
		$this->assertArrayNotHasKey( 'nace', $businesses['items'][1] );

		$profile = RestSchemas::profile();
		$this->assertNotContains( 'nace', $profile['required'] );
		$this->assertSame( '^[A-V]$', $profile['properties']['nace']['pattern'] );
		$this->assertNotContains( 'nace', RestSchemas::with_portal( 'businesses', array() )['properties']['items']['items']['required'] );
	}

	/**
	 * `sector` is a section letter or an error; the filter uses the business's section, else the site's.
	 */
	public function test_sector_filter(): void {
		[ $query ] = ListingsQuery::from_params( array( 'sector' => 'h' ) );
		$this->assertSame( 'H', $query?->sector );
		[ $none, $errors ] = ListingsQuery::from_params( array( 'sector' => 'nakliye' ) );
		$this->assertNull( $none );
		$this->assertArrayHasKey( 'sector', $errors );

		$listings = array(
			new Listing( 1, 'offer', 'Portalın kendi ilanı' ),
			new Listing( 2, 'offer', 'Nakliye firmasının ilanı' ),
			new Listing( 3, 'offer', 'Bölümsüz işletmenin ilanı' ),
		);
		$owned    = array(
			2 => 'H',
			3 => '',
		);
		$ids      = static fn( array $l ): array => array_map( static fn( Listing $x ): ?int => $x->id, $l );

		$this->assertSame( array( 2 ), $ids( SectorFilter::apply( $listings, 'H', 'N', $owned ) ) );
		$this->assertSame( array( 1 ), $ids( SectorFilter::apply( $listings, 'N', 'N', $owned ) ) );
		$this->assertSame( array(), $ids( SectorFilter::apply( $listings, 'I', '', $owned ) ) );
		$this->assertSame( array( 1, 2, 3 ), $ids( SectorFilter::apply( $listings, '', 'N', $owned ) ) );
	}
}
