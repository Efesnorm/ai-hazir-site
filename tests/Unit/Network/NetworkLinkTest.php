<?php
/**
 * "Komşu ülkelerde" links, block selection and network referrals (1.23.0).
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\Tests\Unit\Network;

use AIHazirSite\Core\Catalog\Query\ListingSearch;
use AIHazirSite\Core\Features;
use AIHazirSite\Core\Measurement\CsvExport;
use AIHazirSite\Core\Measurement\Hit;
use AIHazirSite\Core\Measurement\Report;
use AIHazirSite\Core\Measurement\Request;
use AIHazirSite\Core\Measurement\Tracker;
use AIHazirSite\Core\Network\NetworkLink;
use AIHazirSite\Core\Network\SiblingCatalog;
use AIHazirSite\Tests\Support\FixedClock;
use AIHazirSite\Tests\Support\MemoryHitRepository;
use AIHazirSite\Tests\Support\MemorySettings;
use PHPUnit\Framework\TestCase;

/**
 * UTM tagging, block picking, referral classification, the report section and CSV — no WordPress.
 *
 * @covers \AIHazirSite\Core\Network\NetworkLink
 * @covers \AIHazirSite\Core\Network\SiblingCatalog
 * @covers \AIHazirSite\Core\Measurement\Tracker
 * @covers \AIHazirSite\Core\Measurement\Report
 * @covers \AIHazirSite\Core\Measurement\CsvExport
 */
final class NetworkLinkTest extends TestCase {

	private const KOSOVA = 'https://www.kosova.org.tr/';
	private const YUNAN  = 'https://yunanistan.org.tr/';
	private const NOW    = 1790000000;
	private const CHROME = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36';
	private const GPTBOT = 'Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko; compatible; GPTBot/1.4; +https://openai.com/gptbot)';

	/**
	 * Measurement on (its default) with in-memory settings.
	 */
	protected function setUp(): void {
		parent::setUp();
		Features::use_settings( new MemorySettings() );
	}

	/**
	 * Campaign parameters go before the fragment; an existing query is kept.
	 */
	public function test_tag(): void {
		$this->assertSame(
			'https://www.kosova.org.tr/ai-katalog/?utm_source=makedonya.org.tr&utm_medium=portal-agi&utm_campaign=komsu-ulkeler#ilan-7',
			NetworkLink::tag( self::KOSOVA . 'ai-katalog/#ilan-7', 'makedonya.org.tr' )
		);
		$this->assertSame(
			'https://www.kosova.org.tr/?lang=sq&utm_source=makedonya.org.tr&utm_medium=portal-agi&utm_campaign=komsu-ulkeler',
			NetworkLink::tag( self::KOSOVA . '?lang=sq', 'makedonya.org.tr' )
		);
		$this->assertSame( 'kosova.org.tr', NetworkLink::host( self::KOSOVA ) );
		$this->assertSame( 'kosova.org.tr', NetworkLink::host( 'WWW.Kosova.org.tr.' ) );
	}

	/**
	 * Referer or (utm_medium = portal-agi and utm_source) of a verified sibling; anything else is not a network referral.
	 */
	public function test_referral(): void {
		$siblings = array( self::KOSOVA, self::YUNAN );
		$this->assertSame( 'kosova.org.tr', NetworkLink::referral( 'https://kosova.org.tr/turlar/', '', '', $siblings ) );
		$this->assertSame( 'yunanistan.org.tr', NetworkLink::referral( '', 'yunanistan.org.tr', 'portal-agi', $siblings ) );
		$this->assertSame( 'yunanistan.org.tr', NetworkLink::referral( '', 'www.yunanistan.org.tr', 'Portal-Agi', $siblings ) );
		$this->assertNull( NetworkLink::referral( '', 'yunanistan.org.tr', 'email', $siblings ), 'Another campaign medium.' );
		$this->assertNull( NetworkLink::referral( '', 'sahte.example', 'portal-agi', $siblings ), 'Not a verified sibling.' );
		$this->assertNull( NetworkLink::referral( 'https://arnavutluk.org.tr/', '', '', $siblings ), 'Not verified.' );
		$this->assertNull( NetworkLink::referral( 'kosova.org.tr', '', '', $siblings ), 'A Referer is a URL.' );
		$this->assertNull( NetworkLink::referral( 'https://kosova.org.tr/', '', '', array() ) );
	}

	/**
	 * Tracker order: bot and AI platform first; network referrals only with the sibling list; the source is ours.
	 */
	public function test_tracker(): void {
		$hits    = new MemoryHitRepository();
		$asked   = 0;
		$network = static function () use ( &$asked ): array {
			++$asked;
			return array( self::KOSOVA );
		};
		$tracker = new Tracker( $hits, new FixedClock( '2026-10-04' ), null, null, $network );

		$this->assertTrue( $tracker->handle( new Request( self::CHROME, '/turlar/?utm_source=kosova.org.tr', '', '', 'kosova.org.tr', 'portal-agi' ) ) );
		$this->assertTrue( $tracker->handle( new Request( self::CHROME, '/', '', 'https://www.kosova.org.tr/ai-katalog/' ) ) );
		$this->assertTrue( $tracker->handle( new Request( self::CHROME, '/', '', 'https://chatgpt.com/' ) ), 'AI platform.' );
		$this->assertTrue( $tracker->handle( new Request( self::GPTBOT, '/', '', 'https://www.kosova.org.tr/' ) ), 'Bot.' );
		$this->assertFalse( $tracker->handle( new Request( self::CHROME, '/', '', '', 'sahte.example', 'portal-agi' ) ) );
		$this->assertFalse( $tracker->handle( new Request( self::CHROME, '/' ) ) );

		$kinds = array_count_values( array_column( $hits->rows, 'kind' ) );
		$this->assertSame( 2, $kinds[ Hit::KIND_NETWORK ] );
		$this->assertSame( 1, $kinds[ Hit::KIND_REFERRAL ] );
		$this->assertSame( 1, $kinds[ Hit::KIND_BOT ] );
		$this->assertSame( array( 'kosova.org.tr' ), array_values( array_unique( array_column( array_filter( $hits->rows, static fn( array $r ): bool => Hit::KIND_NETWORK === $r['kind'] ), 'source_id' ) ) ) );
		$this->assertSame( 3, $asked, 'Sibling list read only for human visits with a Referer or utm_source.' );

		$without = new MemoryHitRepository();
		$this->assertFalse( ( new Tracker( $without, new FixedClock( '2026-10-04' ) ) )->handle( new Request( self::CHROME, '/', '', 'https://www.kosova.org.tr/' ) ), 'Off without the sibling list.' );

		$report = new Report( $hits, new FixedClock( '2026-10-04' ), 7 );
		$rows   = $report->rows();
		$this->assertSame( array( array( 'kosova.org.tr', 2 ) ), array_map( static fn( array $r ): array => array( $r['source'], $r['total'] ), Report::section( $rows, Report::SECTION_NETWORK ) ) );
		$this->assertSame( array( '/', '/turlar/' ), array_column( Report::section( $rows, Report::SECTION_NET_PAGES ), 'path' ) );
		$this->assertStringContainsString( '"Ağ yönlendirmesi",kosova.org.tr,,0,2,2', CsvExport::to_csv( $rows ) );
	}

	/**
	 * Block picking: filters, sibling in turn, count limits, one sibling only, stale catalogs skipped.
	 */
	public function test_pick(): void {
		$catalog  = static function ( string $url, string $name, array $titles, int $retrieved = self::NOW ): array {
			$listings = array();
			foreach ( $titles as $i => $title ) {
				$listings[] = array(
					'type'       => 'offer',
					'title'      => $title,
					'category'   => 'Tur',
					'region'     => '',
					'url'        => $url . 'ai-katalog/#ilan-' . $i,
					'updated_at' => '2026-10-0' . ( 9 - $i ) . 'T10:00:00Z',
				);
			}
			$compact                 = SiblingCatalog::compact(
				array(
					'url'     => $url,
					'name'    => $name,
					'country' => 'XK',
				),
				null,
				$listings,
				array(),
				self::NOW
			);
			$compact['retrieved_at'] = $retrieved;
			return $compact;
		};
		$catalogs = array(
			$catalog( self::KOSOVA, 'Kosova', array( 'K1', 'K2', 'K3', 'K4' ) ),
			$catalog( self::YUNAN, 'Yunanistan', array( 'Y1' ) ),
		);
		$titles   = static fn( array $items ): array => array_column( $items, 'title' );

		$this->assertSame( array( 'K1', 'Y1', 'K2', 'K3', 'K4' ), $titles( SiblingCatalog::pick( $catalogs, new ListingSearch(), '', '', 6, self::NOW ) ) );
		$this->assertSame( array( 'K1', 'Y1' ), $titles( SiblingCatalog::pick( $catalogs, new ListingSearch(), '', '', 2, self::NOW ) ) );
		$this->assertSame( array( 'K1' ), $titles( SiblingCatalog::pick( $catalogs, new ListingSearch(), '', '', 0, self::NOW ) ), 'At least one.' );
		$this->assertSame( array( 'Y1' ), $titles( SiblingCatalog::pick( $catalogs, new ListingSearch(), '', self::YUNAN, 6, self::NOW ) ) );
		$this->assertSame( array(), SiblingCatalog::pick( $catalogs, new ListingSearch( type: 'demand' ), '', '', 6, self::NOW ) );
		$this->assertSame( array(), SiblingCatalog::pick( $catalogs, new ListingSearch(), '', '', 6, self::NOW + SiblingCatalog::MAX_AGE + 1 ) );

		$many = array( $catalog( self::KOSOVA, 'Kosova', array_map( static fn( int $i ): string => 'T' . $i, range( 1, 20 ) ) ) );
		$this->assertCount( SiblingCatalog::BLOCK_MAX, SiblingCatalog::pick( $many, new ListingSearch(), '', '', 99, self::NOW ) );
	}
}
