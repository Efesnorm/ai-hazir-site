<?php
/**
 * Tests for Scanner, CheckResult and ScoreReport.
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\Tests\Unit\Compliance;

use AIHazirSite\Core\Compliance\Check;
use AIHazirSite\Core\Compliance\CheckResult;
use AIHazirSite\Core\Compliance\Scanner;
use AIHazirSite\Core\Compliance\ScoreReport;
use AIHazirSite\Core\Compliance\Site;
use AIHazirSite\Tests\Support\FakePageFetcher;
use AIHazirSite\Tests\Support\FixedClock;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Scoring unit tests with scripted checks.
 *
 * @covers \AIHazirSite\Core\Compliance\Scanner
 * @covers \AIHazirSite\Core\Compliance\ScoreReport
 * @covers \AIHazirSite\Core\Compliance\CheckResult
 */
final class ScannerTest extends TestCase {

	/**
	 * A scripted check.
	 *
	 * @param string                      $id     Id.
	 * @param int                         $weight Weight.
	 * @param float|null|RuntimeException $ratio Ratio, null = unmeasured, exception = throws.
	 */
	public static function check( string $id, int $weight, float|null|RuntimeException $ratio ): Check {
		return new class( $id, $weight, $ratio ) implements Check {
			/**
			 * Constructor.
			 *
			 * @param string                      $id     Id.
			 * @param int                         $weight Weight.
			 * @param float|null|RuntimeException $ratio  Outcome.
			 */
			public function __construct( private string $id, private int $weight, private float|null|RuntimeException $ratio ) {
			}

			/**
			 * Id.
			 */
			public function id(): string {
				return $this->id;
			}

			/**
			 * Weight.
			 */
			public function weight(): int {
				return $this->weight;
			}

			/**
			 * Scripted outcome.
			 *
			 * @param Site $site Site.
			 * @throws RuntimeException When scripted to.
			 */
			public function run( Site $site ): CheckResult {
				if ( $this->ratio instanceof RuntimeException ) {
					throw $this->ratio;
				}
				return null === $this->ratio ? CheckResult::unmeasured( 'erişilemedi' ) : CheckResult::measured( $this->ratio, array( 'bulgu' ), 'düzelt' );
			}
		};
	}

	/**
	 * Report for scripted checks.
	 *
	 * @param Check[] $checks Checks.
	 */
	private function scan( array $checks ): ScoreReport {
		return ( new Scanner( $checks, new FixedClock( '2026-09-27' ) ) )->scan( new Site( 'https://ornek.com/', new FakePageFetcher() ) );
	}

	/**
	 * Hand-computed example: unmeasured checks are left out of the denominator.
	 *
	 * Measured weights 20+20+20+15+10+5 = 90; points 20+10+0+15+2.5+5 = 52.5;
	 * score = round(100 × 52.5 / 90) = round(58.33) = 58.
	 */
	public function test_score_matches_hand_calculation(): void {
		$report = $this->scan(
			array(
				self::check( 'a', 20, 1.0 ),
				self::check( 'b', 20, 0.5 ),
				self::check( 'c', 20, 0.0 ),
				self::check( 'd', 15, 1.0 ),
				self::check( 'e', 10, null ),
				self::check( 'f', 10, 0.25 ),
				self::check( 'g', 5, 1.0 ),
			)
		);

		$this->assertSame( 58, $report->score() );
		$this->assertSame( 90, $report->measured_weight() );
		$this->assertSame( 100, $report->total_weight() );
		$this->assertSame( 2.5, $report->result( 'f' )['points'] );
		$this->assertSame( 7.5, $report->result( 'f' )['gain'] );
		$this->assertNull( $report->result( 'e' )['ratio'] );
		$this->assertSame( 0.0, $report->result( 'e' )['gain'], 'Unmeasured checks cost nothing.' );
	}

	/**
	 * Biggest gain first, then unmeasured, then complete checks.
	 */
	public function test_rows_sorted_by_gain(): void {
		$report = $this->scan(
			array(
				self::check( 'tam', 20, 1.0 ),
				self::check( 'yarim', 20, 0.5 ),
				self::check( 'olculemedi', 15, null ),
				self::check( 'sifir', 20, 0.0 ),
				self::check( 'ceyrek', 10, 0.25 ),
			)
		);

		$this->assertSame( array( 'sifir', 'yarim', 'ceyrek', 'olculemedi', 'tam' ), array_column( $report->by_gain(), 'id' ) );
	}

	/**
	 * A throwing check is reported as unmeasured and does not stop the scan.
	 */
	public function test_throwing_check_is_unmeasured(): void {
		$report = $this->scan( array( self::check( 'hata', 50, new RuntimeException( 'boom' ) ), self::check( 'ok', 50, 1.0 ) ) );

		$this->assertNull( $report->result( 'hata' )['ratio'] );
		$this->assertStringContainsString( 'boom', $report->result( 'hata' )['findings'][0] );
		$this->assertSame( 100, $report->score() );
	}

	/**
	 * Nothing measured → no score.
	 */
	public function test_nothing_measured_has_no_score(): void {
		$this->assertNull( $this->scan( array( self::check( 'x', 10, null ) ) )->score() );
	}

	/**
	 * Score version and time are written to the report; storage round-trip keeps everything.
	 */
	public function test_report_metadata_and_round_trip(): void {
		$report = $this->scan( array( self::check( 'a', 60, 0.5 ), self::check( 'b', 40, null ) ) );

		$this->assertSame( Scanner::SCORE_VERSION, $report->score_version );
		$this->assertSame( 1, Scanner::SCORE_VERSION );
		$this->assertSame( '2026-09-27T12:00:00Z', $report->scanned_at );

		$copy = ScoreReport::from_array( json_decode( (string) json_encode( $report->to_array() ), true ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
		$this->assertNotNull( $copy );
		$this->assertEquals( $report, $copy );
		$this->assertNull( ScoreReport::from_array( 'bozuk' ) );
	}

	/**
	 * Levels follow the ratio; a complete check has no fix text.
	 */
	public function test_levels(): void {
		$this->assertSame( CheckResult::INFO, CheckResult::measured( 1.0, array(), 'x' )->level );
		$this->assertSame( '', CheckResult::measured( 1.0, array(), 'x' )->fix );
		$this->assertSame( CheckResult::WARNING, CheckResult::measured( 0.5 )->level );
		$this->assertSame( CheckResult::ERROR, CheckResult::measured( 0.49 )->level );
		$this->assertSame( 1.0, CheckResult::measured( 3.0 )->ratio );
	}

	/**
	 * Duplicate ids and non-positive weights are rejected.
	 */
	public function test_invalid_check_sets(): void {
		$this->expectException( InvalidArgumentException::class );
		new Scanner( array( self::check( 'a', 10, 1.0 ), self::check( 'a', 10, 1.0 ) ), new FixedClock() );
	}

	/**
	 * Zero weight is rejected.
	 */
	public function test_zero_weight_rejected(): void {
		$this->expectException( InvalidArgumentException::class );
		new Scanner( array( self::check( 'a', 0, 1.0 ) ), new FixedClock() );
	}
}
