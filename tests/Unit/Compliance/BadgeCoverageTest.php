<?php
/**
 * The badge needs enough of the scan measured (1.12.1).
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\Tests\Unit\Compliance;

use AIHazirSite\Core\Compliance\Badge;
use AIHazirSite\Core\Compliance\ScoreReport;
use PHPUnit\Framework\TestCase;

/**
 * A live scan measured 20 of 100 and showed "100/100": that must not earn the public badge.
 *
 * @covers \AIHazirSite\Core\Compliance\Badge
 */
final class BadgeCoverageTest extends TestCase {

	/**
	 * A report with the given rows: [weight, ratio|null].
	 *
	 * @param list<array{0: int, 1: float|null}> $rows Rows.
	 */
	private static function report( array $rows ): ScoreReport {
		$results = array();
		foreach ( $rows as $i => [ $weight, $ratio ] ) {
			$results[] = array(
				'id'       => 'c' . $i,
				'weight'   => $weight,
				'ratio'    => $ratio,
				'points'   => null === $ratio ? 0.0 : $weight * $ratio,
				'gain'     => 0.0,
				'level'    => '',
				'findings' => array(),
				'fix'      => '',
			);
		}
		return new ScoreReport( '2026-09-29T08:00:00Z', 3, $results );
	}

	/**
	 * 100 over 20 measured: no badge; 100 over 80 measured: badge; below the threshold: no badge.
	 */
	public function test_coverage(): void {
		$partial = self::report( array( array( 20, 1.0 ), array( 80, null ) ) );
		$this->assertSame( 100, $partial->score() );
		$this->assertFalse( Badge::covered( $partial ) );
		$this->assertFalse( Badge::earned( $partial ) );

		$enough = self::report( array( array( 80, 1.0 ), array( 20, null ) ) );
		$this->assertTrue( Badge::covered( $enough ) );
		$this->assertTrue( Badge::earned( $enough ) );

		$full_low = self::report( array( array( 100, 0.5 ) ) );
		$this->assertTrue( Badge::covered( $full_low ) );
		$this->assertFalse( Badge::earned( $full_low ) );

		$this->assertFalse( Badge::covered( null ) );
		$this->assertFalse( Badge::earned( null ) );
	}
}
