<?php
/**
 * Tests for ScanStore and ScanToken.
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\Tests\Unit\Compliance;

use AIHazirSite\Core\Compliance\ScanStore;
use AIHazirSite\Core\Compliance\ScanToken;
use AIHazirSite\Core\Compliance\ScoreReport;
use AIHazirSite\Core\Compliance\Site;
use AIHazirSite\Tests\Support\MemorySettings;
use AIHazirSite\Tests\Support\StaticSecret;
use PHPUnit\Framework\TestCase;

/**
 * Scan history and scan marker unit tests.
 *
 * @covers \AIHazirSite\Core\Compliance\ScanStore
 * @covers \AIHazirSite\Core\Compliance\ScanToken
 */
final class ScanStoreTest extends TestCase {

	/**
	 * A report with one row.
	 *
	 * @param int $n Sequence number.
	 */
	private static function report( int $n ): ScoreReport {
		return new ScoreReport(
			sprintf( '2026-09-%02dT10:00:00Z', $n ),
			1,
			array(
				array(
					'id'       => 'llms_txt',
					'weight'   => 10,
					'ratio'    => 1.0,
					'points'   => 10.0,
					'gain'     => 0.0,
					'level'    => 'info',
					'findings' => array(),
					'fix'      => '',
				),
			)
		);
	}

	/**
	 * Newest first, at most 20, stored without autoload.
	 */
	public function test_keeps_last_20_newest_first(): void {
		$settings = new MemorySettings();
		$store    = new ScanStore( $settings );

		$this->assertNull( $store->latest() );
		for ( $i = 1; $i <= 21; $i++ ) {
			$store->add( self::report( $i ) );
		}

		$all = $store->all();
		$this->assertCount( ScanStore::KEEP, $all );
		$this->assertSame( '2026-09-21T10:00:00Z', $store->latest()?->scanned_at );
		$this->assertSame( '2026-09-20T10:00:00Z', $store->previous()?->scanned_at );
		$this->assertSame( '2026-09-02T10:00:00Z', $all[19]->scanned_at, 'The oldest (01) was dropped.' );
		$this->assertFalse( $settings->autoload[ ScanStore::OPTION ] );
	}

	/**
	 * Corrupt entries are skipped.
	 */
	public function test_skips_corrupt_entries(): void {
		$settings = new MemorySettings();
		$settings->set( ScanStore::OPTION, array( 'bozuk', self::report( 5 )->to_array() ) );

		$this->assertCount( 1, ( new ScanStore( $settings ) )->all() );
	}

	/**
	 * The scan marker is keyed, verifiable, and never empty-matching.
	 */
	public function test_scan_token(): void {
		$secret = new StaticSecret();
		$value  = ScanToken::value( $secret );

		$this->assertTrue( ScanToken::matches( $secret, $value ) );
		$this->assertFalse( ScanToken::matches( $secret, 'sahte' ) );
		$this->assertFalse( ScanToken::matches( $secret, '' ) );
		$this->assertSame( array( Site::SCAN_HEADER => $value ), ScanToken::headers( $secret ) );
	}
}
