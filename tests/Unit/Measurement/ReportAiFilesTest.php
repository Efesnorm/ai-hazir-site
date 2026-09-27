<?php
/**
 * The "AI files" report section.
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\Tests\Unit\Measurement;

use AIHazirSite\Core\Measurement\CsvExport;
use AIHazirSite\Core\Measurement\Hit;
use AIHazirSite\Core\Measurement\Report;
use AIHazirSite\Tests\Support\FixedClock;
use AIHazirSite\Tests\Support\MemoryHitRepository;
use PHPUnit\Framework\TestCase;

/**
 * Bot visits to llms.txt and the AI catalog are reported on their own.
 *
 * @covers \AIHazirSite\Core\Measurement\Report
 * @covers \AIHazirSite\Core\Measurement\CsvExport
 */
final class ReportAiFilesTest extends TestCase {

	/**
	 * AI files appear in their own section even outside the top 10 pages; referrals do not count.
	 */
	public function test_ai_files_section(): void {
		$hits = new MemoryHitRepository();
		for ( $i = 1; $i <= 12; $i++ ) {
			for ( $n = 0; $n < 3; $n++ ) {
				$hits->increment( '2026-09-27', Hit::KIND_BOT, 'gptbot', '/sayfa-' . $i . '/', false );
			}
		}
		$hits->increment( '2026-09-27', Hit::KIND_BOT, 'gptbot', '/llms.txt', true );
		$hits->increment( '2026-09-26', Hit::KIND_BOT, 'claudebot', '/llms.txt', false );
		$hits->increment( '2026-09-26', Hit::KIND_BOT, 'claudebot', '/ai-katalog/', false );
		$hits->increment( '2026-09-27', Hit::KIND_REFERRAL, 'chatgpt', '/llms.txt', false );
		$hits->increment( '2026-09-01', Hit::KIND_BOT, 'gptbot', '/llms.txt', false );

		$rows = ( new Report( $hits, new FixedClock( '2026-09-27' ), 7 ) )->rows();

		$this->assertNotContains( '/llms.txt', array_column( Report::section( $rows, Report::SECTION_PAGES ), 'path' ) );
		$this->assertSame(
			array( array( '/llms.txt', 1, 1, 2 ), array( '/ai-katalog/', 0, 1, 1 ) ),
			array_map( static fn( array $r ): array => array( $r['path'], $r['verified'], $r['unverified'], $r['total'] ), Report::section( $rows, Report::SECTION_AI_FILES ) )
		);
		$this->assertStringEndsWith( "\"AI dosyası\",,/llms.txt,1,1,2\n\"AI dosyası\",,/ai-katalog/,0,1,1\n", CsvExport::to_csv( $rows ) );
	}

	/**
	 * No visits to AI files: no rows (existing reports are unchanged).
	 */
	public function test_no_rows_without_visits(): void {
		$hits = new MemoryHitRepository();
		$hits->increment( '2026-09-27', Hit::KIND_BOT, 'gptbot', '/urunler/', false );

		$this->assertSame( array(), Report::section( ( new Report( $hits, new FixedClock( '2026-09-27' ), 7 ) )->rows(), Report::SECTION_AI_FILES ) );
	}
}
