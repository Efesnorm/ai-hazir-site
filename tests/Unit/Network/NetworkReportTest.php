<?php
/**
 * Network report totals, summary and matrix (1.24.0).
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\Tests\Unit\Network;

use AIHazirSite\Core\Inquiry\Inquiry;
use AIHazirSite\Core\Inquiry\InquiryContact;
use AIHazirSite\Core\Measurement\CsvExport;
use AIHazirSite\Core\Measurement\Hit;
use AIHazirSite\Core\Network\NetworkReport;
use AIHazirSite\Core\Network\NetworkStats;
use AIHazirSite\Tests\Support\MemoryHitRepository;
use AIHazirSite\Tests\Support\MemoryInquiryRepository;
use PHPUnit\Framework\TestCase;

/**
 * Totals only (no personal data, no paths), period limits, cleaning of a remote answer, summary, matrix and CSV.
 *
 * @covers \AIHazirSite\Core\Network\NetworkStats
 * @covers \AIHazirSite\Core\Network\NetworkReport
 * @covers \AIHazirSite\Core\Measurement\CsvExport
 */
final class NetworkReportTest extends TestCase {

	private const TODAY  = '2026-10-04';
	private const MOTHER = 'https://www.makedonya.tr/';
	private const KOSOVA = 'https://www.kosova.org.tr/';

	/**
	 * Counters inside and outside a 7-day period; two inquiries with contact data.
	 *
	 * @param int $days Period.
	 * @return array<string, mixed>
	 */
	private static function stats( int $days ): array {
		$hits = new MemoryHitRepository();
		$hits->increment( self::TODAY, Hit::KIND_BOT, 'gptbot', '/ai-katalog/', true );
		$hits->increment( self::TODAY, Hit::KIND_BOT, 'gptbot', '/', false );
		$hits->increment( '2026-09-28', Hit::KIND_BOT, 'claudebot', '/gizli-sayfa/', false );
		$hits->increment( '2026-09-01', Hit::KIND_BOT, 'gptbot', '/', true );
		$hits->increment( self::TODAY, Hit::KIND_REFERRAL, 'chatgpt', '/', false );
		$hits->increment( self::TODAY, Hit::KIND_NETWORK, 'kosova.org.tr', '/', false );
		$hits->increment( self::TODAY, Hit::KIND_MCP, 'aihs-search-listings', '/', false );
		$hits->increment( self::TODAY, Hit::KIND_TEST, 'test', '/', false );

		$inquiries = new MemoryInquiryRepository();
		foreach ( array( array( 'quote_request', '2026-10-03T10:00:00Z' ), array( 'offer', '2026-08-01T10:00:00Z' ) ) as [ $kind, $at ] ) {
			$inquiries->store_inquiry( new Inquiry( null, $kind, 'ai', 'mcp', null, 'Konu', 'Mesaj', InquiryContact::from_array( array( 'email' => 'kisi@ornek.com' ) ), 'new', 0, array(), 'hash', $at ) );
		}
		return NetworkStats::build( $hits, $inquiries, self::TODAY, $days, '1.24.0', array( 'catalog', 'measurement' ), 3, 87, '2026-10-01T09:00:00Z' );
	}

	/**
	 * The totals of a period; nothing personal or page-level in the answer.
	 */
	public function test_build(): void {
		$week = self::stats( 7 );
		$this->assertSame( '2026-09-28', $week['since'] );
		$this->assertSame(
			array(
				'total'    => 3,
				'verified' => 1,
				'by_bot'   => array(
					'gptbot'    => 2,
					'claudebot' => 1,
				),
			),
			$week['bots']
		);
		$this->assertSame( array( 'chatgpt' => 1 ), $week['referrals']['by_source'] );
		$this->assertSame( array( 'kosova.org.tr' => 1 ), $week['network']['by_sibling'] );
		$this->assertSame( 1, $week['mcp']['total'] );
		$this->assertSame( array( 'quote_request' => 1 ), $week['inquiries']['by_kind'] );
		$this->assertSame( 3, $week['listings'] );
		$this->assertSame( 87, $week['compliance']['score'] ?? null );

		$json = (string) json_encode( $week ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
		$this->assertStringNotContainsString( 'kisi@ornek.com', $json );
		$this->assertStringNotContainsString( 'Mesaj', $json );
		$this->assertStringNotContainsString( '/gizli-sayfa/', $json );
		$this->assertStringNotContainsString( 'test', $json, 'Our own test traffic is not counted.' );

		$quarter = self::stats( 90 );
		$this->assertSame( 4, $quarter['bots']['total'] );
		$this->assertSame( 2, $quarter['inquiries']['total'] );
		$this->assertSame( 28, NetworkStats::period( 30 ) );
		$this->assertSame( 90, NetworkStats::period( '90' ) );
	}

	/**
	 * A remote answer is cleaned: negative or text counts, odd keys, long texts, a score above 100.
	 */
	public function test_from_array(): void {
		$this->assertSame( self::stats( 28 ), NetworkStats::from_array( json_decode( (string) json_encode( self::stats( 28 ) ), true ) ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
		$this->assertNull( NetworkStats::from_array( 'bozuk' ) );

		$dirty = NetworkStats::from_array(
			array(
				'version'    => '<script>1.0</script>' . str_repeat( 'x', 50 ),
				'days'       => 5,
				'since'      => 'dün',
				'features'   => array( 'catalog', '<b>', 7 ),
				'bots'       => array(
					'total'  => -5,
					'by_bot' => array(
						'gptbot'              => '12',
						'<img src=x>'         => 3,
						str_repeat( 'a', 99 ) => 1,
					),
				),
				'compliance' => array( 'score' => 900 ),
			)
		);
		$this->assertNotNull( $dirty );
		$this->assertStringNotContainsString( '<', $dirty['version'] );
		$this->assertLessThanOrEqual( 20, mb_strlen( $dirty['version'] ) );
		$this->assertSame( 28, $dirty['days'] );
		$this->assertSame( '', $dirty['since'] );
		$this->assertSame( array( 'catalog' ), $dirty['features'] );
		$this->assertSame( 0, $dirty['bots']['total'] );
		$this->assertSame( array( 'gptbot' => 12 ), $dirty['bots']['by_bot'] );
		$this->assertSame( 100, $dirty['compliance']['score'] ?? null );
	}

	/**
	 * Summary rows with a network total; the referral matrix; the CSV.
	 */
	public function test_summary_matrix_csv(): void {
		$mother                          = self::stats( 28 );
		$kosova                          = self::stats( 28 );
		$kosova['network']['by_sibling'] = array( 'makedonya.tr' => 4 );
		$kosova['network']['total']      = 4;
		$sites                           = array(
			array(
				'url'        => self::MOTHER,
				'name'       => 'Makedonya',
				'status'     => NetworkReport::SELF,
				'fetched_at' => 1790000000,
				'stats'      => $mother,
			),
			array(
				'url'        => self::KOSOVA,
				'name'       => '=Kosova',
				'status'     => NetworkReport::OK,
				'fetched_at' => 1790000000,
				'stats'      => $kosova,
			),
			array(
				'url'        => 'https://www.yunanistan.org.tr/',
				'name'       => '',
				'status'     => NetworkReport::NO_KEY,
				'fetched_at' => 0,
				'stats'      => null,
			),
		);

		$rows = NetworkReport::summary( $sites );
		$this->assertCount( 4, $rows );
		$this->assertSame( 'yunanistan.org.tr', $rows[2]['site'] );
		$this->assertSame( 0, $rows[2]['bots'] );
		$this->assertSame( '', $rows[3]['site'] );
		$this->assertSame( 6, $rows[3]['bots'] );
		$this->assertSame( 5, $rows[3]['network'] );

		[ $hosts, $cells ] = NetworkReport::matrix( $sites );
		$this->assertSame( array( 'makedonya.tr', 'kosova.org.tr', 'yunanistan.org.tr' ), $hosts );
		$this->assertSame( 4, $cells['kosova.org.tr']['makedonya.tr'] );
		$this->assertSame( 1, $cells['makedonya.tr']['kosova.org.tr'] );
		$this->assertSame( 0, $cells['yunanistan.org.tr']['kosova.org.tr'] );

		$csv = NetworkReport::csv(
			$sites,
			array(
				'site'   => 'Site',
				'bots'   => 'AI bot',
				'status' => 'Durum',
			),
			array( NetworkReport::NO_KEY => 'anahtar yok' ),
			'Ağ toplamı'
		);
		$this->assertStringStartsWith( CsvExport::BOM . "Site,\"AI bot\",Durum\n", $csv );
		$this->assertStringContainsString( "'=Kosova,3,ok", $csv, 'Formula injection escaped.' );
		$this->assertStringContainsString( 'yunanistan.org.tr,0,"anahtar yok"', $csv );
		$this->assertStringContainsString( '"Ağ toplamı",6,', $csv );
	}
}
