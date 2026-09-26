<?php
/**
 * Tests for CsvExport.
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\Tests\Unit\Measurement;

use AIHazirSite\Modules\Measurement\Admin\CsvExport;
use AIHazirSite\Modules\Measurement\Report;
use AIHazirSite\Tests\Unit\UnitTestCase;
use Brain\Monkey\Functions;

/**
 * CSV unit tests.
 *
 * @covers \AIHazirSite\Modules\Measurement\Admin\CsvExport
 */
final class CsvExportTest extends UnitTestCase {

	/**
	 * Header, BOM, quoting and formula neutralization.
	 */
	public function test_csv_format(): void {
		Functions\stubTranslationFunctions();

		$csv = CsvExport::to_csv(
			array(
				array(
					'section'    => Report::SECTION_BOTS,
					'source'     => 'GPTBot',
					'path'       => '',
					'verified'   => 2,
					'unverified' => 1,
					'total'      => 3,
				),
				array(
					'section'    => Report::SECTION_PAGES,
					'source'     => '',
					'path'       => '/a,"b"',
					'verified'   => 0,
					'unverified' => 1,
					'total'      => 1,
				),
				array(
					'section'    => Report::SECTION_PAGES,
					'source'     => '',
					'path'       => '=HYPERLINK("x")',
					'verified'   => 0,
					'unverified' => 1,
					'total'      => 1,
				),
			)
		);

		$this->assertSame(
			CsvExport::BOM
			. "Bölüm,Kaynak,Sayfa,Doğrulanmış,Doğrulanmamış,Toplam\n"
			. "Bot,GPTBot,,2,1,3\n"
			. "Sayfa,,\"/a,\"\"b\"\"\",0,1,1\n"
			. "Sayfa,,\"'=HYPERLINK(\"\"x\"\")\",0,1,1\n",
			$csv
		);
	}
}
