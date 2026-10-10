<?php
/**
 * "Ağda yayınla" rules (1.26.0).
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\Tests\Unit\Network;

use AIHazirSite\Adapters\Llms\LlmsTxtBuilder;
use AIHazirSite\Adapters\Rest\RestSchemas;
use AIHazirSite\Core\Catalog\CompanyProfile;
use AIHazirSite\Core\Catalog\Query\ListingSearch;
use AIHazirSite\Core\Network\NetworkShare;
use AIHazirSite\Core\Network\SiblingCatalog;
use Opis\JsonSchema\CompliantValidator;
use Opis\JsonSchema\Helper;
use PHPUnit\Framework\TestCase;

/**
 * Share input, the receiving side's choice, the REST schema and the llms.txt section.
 *
 * @covers \AIHazirSite\Core\Network\NetworkShare
 * @covers \AIHazirSite\Core\Network\SiblingCatalog
 * @covers \AIHazirSite\Adapters\Rest\RestSchemas
 * @covers \AIHazirSite\Adapters\Llms\LlmsTxtBuilder
 */
final class NetworkShareTest extends TestCase {

	private const MOTHER = 'https://www.makedonya.tr/';
	private const KOSOVA = 'https://www.kosova.org.tr/';
	private const YUNAN  = 'https://www.yunanistan.org.tr/';
	private const NOW    = 1790000000;

	/**
	 * The mother's catalog as a sibling reads it: one listing for the whole network, one for Kosova, one not shared.
	 *
	 * @return array<string, mixed>
	 */
	private static function catalog(): array {
		$listing = static fn( int $id, string $title, mixed $share ): array => array(
			'id'            => $id,
			'type'          => 'offer',
			'title'         => $title,
			'category'      => 'Tur',
			'region'        => 'Balkanlar',
			'url'           => self::MOTHER . 'ai-katalog/#ilan-' . $id,
			'updated_at'    => '2026-10-0' . $id . 'T10:00:00Z',
			'network_share' => $share,
		);
		return SiblingCatalog::compact(
			array(
				'url'     => self::MOTHER,
				'name'    => 'Makedonya Türkiye Consulting',
				'country' => 'MK',
			),
			null,
			array(
				$listing(
					1,
					'Balkan turu – son 5 kişi, 399 €',
					array(
						'all'   => true,
						'sites' => array(),
					)
				),
				$listing(
					2,
					'Prizren günübirlik',
					array(
						'all'   => false,
						'sites' => array( self::KOSOVA ),
					)
				),
				$listing( 3, 'Üsküp ofis kiralama', null ),
			),
			array(),
			self::NOW
		);
	}

	/**
	 * Form input: whole network, chosen verified sites only, or not shared; stored values; hosts.
	 */
	public function test_share_values(): void {
		$verified = array( self::KOSOVA, self::YUNAN );
		$this->assertSame(
			array(
				'all'   => true,
				'sites' => array(),
			),
			NetworkShare::from_input( '1', array( self::KOSOVA ), $verified )
		);
		$this->assertSame(
			array(
				'all'   => false,
				'sites' => array( self::KOSOVA ),
			),
			NetworkShare::from_input( false, array( self::KOSOVA, 'https://kotu.example/', self::KOSOVA ), $verified )
		);
		$this->assertNull( NetworkShare::from_input( false, array( 'https://kotu.example/' ), $verified ) );
		$this->assertNull( NetworkShare::from_input( false, 'bozuk', $verified ) );

		$this->assertNull( NetworkShare::from_stored( '' ) );
		$this->assertNull( NetworkShare::from_stored( array( 'sites' => array( 'http://duz.example/' ) ) ) );
		$this->assertSame( array( '*' ), NetworkShare::hosts( array( 'all' => true ) ) );
		$this->assertSame( array( 'kosova.org.tr' ), NetworkShare::hosts( array( 'sites' => array( self::KOSOVA, 'https://kosova.org.tr/' ) ) ) );
		$this->assertTrue( NetworkShare::reaches( array( 'kosova.org.tr' ), self::KOSOVA ) );
		$this->assertFalse( NetworkShare::reaches( array( 'kosova.org.tr' ), self::YUNAN ) );
		$this->assertTrue( NetworkShare::reaches( array( '*' ), self::YUNAN ) );
	}

	/**
	 * A sibling keeps the share only on shared listings; each site gets only what is shared with it.
	 */
	public function test_shared_with_site(): void {
		$catalog = self::catalog();
		$this->assertSame( array( '*' ), $catalog['items'][0]['share'] );
		$this->assertArrayNotHasKey( 'share', $catalog['items'][2], 'Unshared entries are unchanged.' );
		$this->assertSame( $catalog, SiblingCatalog::from_array( $catalog ) );

		$titles = static fn( array $items ): array => array_column( $items, 'title' );
		$this->assertSame( array( 'Prizren günübirlik', 'Balkan turu – son 5 kişi, 399 €' ), $titles( SiblingCatalog::shared( array( $catalog ), self::KOSOVA, new ListingSearch(), '', self::NOW ) ) );
		$this->assertSame( array( 'Balkan turu – son 5 kişi, 399 €' ), $titles( SiblingCatalog::shared( array( $catalog ), self::YUNAN, new ListingSearch(), '', self::NOW ) ) );
		$this->assertSame( array( 'Prizren günübirlik' ), $titles( SiblingCatalog::shared( array( $catalog ), self::KOSOVA, new ListingSearch( keyword: 'PRİZREN' ), '', self::NOW ) ) );
		$this->assertSame( array(), SiblingCatalog::shared( array( $catalog ), self::KOSOVA, new ListingSearch(), '', self::NOW + SiblingCatalog::MAX_AGE + 1 ), 'Stale copies are not shown.' );
	}

	/**
	 * Listings bodies with `network_share` and `network_listings` validate; without the extension they do not.
	 */
	public function test_schema(): void {
		$plain = static function ( array $schema ): array {
			unset( $schema['$schema'] );
			return $schema;
		};
		$body  = array(
			'items'            => array(),
			'page'             => 1,
			'per_page'         => 20,
			'total'            => 0,
			'total_pages'      => 0,
			'updated_at'       => null,
			'valid_until'      => null,
			'network_listings' => SiblingCatalog::shared( array( self::catalog() ), self::KOSOVA, new ListingSearch(), '', self::NOW ),
		);
		$this->assertTrue( ( new CompliantValidator() )->validate( Helper::toJSON( $body ), Helper::toJSON( $plain( RestSchemas::with_share( 'listings', RestSchemas::listings() ) ) ) )->isValid() );
		$this->assertFalse( ( new CompliantValidator() )->validate( Helper::toJSON( $body ), Helper::toJSON( $plain( RestSchemas::listings() ) ) )->isValid() );
		$this->assertArrayHasKey( 'network_share', RestSchemas::with_share( 'listing', RestSchemas::listing() )['properties'] );
		$this->assertSame( RestSchemas::profile(), RestSchemas::with_share( 'profile', RestSchemas::profile() ), 'Other documents unchanged.' );
	}

	/**
	 * Llms.txt lists shared listings with their source, linking to the original.
	 */
	public function test_llms_section(): void {
		$shared  = SiblingCatalog::shared( array( self::catalog() ), self::KOSOVA, new ListingSearch(), '', self::NOW );
		$builder = new LlmsTxtBuilder( self::KOSOVA, self::KOSOVA . 'ai-katalog/', 'Kosova', array(), null, '', array(), array(), $shared );
		$text    = $builder->build( new CompanyProfile( 'Kosova Portalı' ), array(), '2026-10-04', '2026-10-04T00:00:00Z' );
		$this->assertStringContainsString( "## Ağdaki ilanlar\n\n- [Prizren günübirlik](" . self::MOTHER . 'ai-katalog/#ilan-2): Kaynak: Makedonya Türkiye Consulting (MK); Bölge: Balkanlar', $text );
		$this->assertLessThan( strpos( $text, '## Optional' ), strpos( $text, '## Ağdaki ilanlar' ) );
		$this->assertStringNotContainsString( 'Ağdaki ilanlar', ( new LlmsTxtBuilder( self::KOSOVA, self::KOSOVA . 'ai-katalog/', 'Kosova' ) )->build( new CompanyProfile( 'Kosova Portalı' ), array(), '2026-10-04', '2026-10-04T00:00:00Z' ) );
	}
}
