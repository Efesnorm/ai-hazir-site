<?php
/**
 * Adding a compliance check never requires changing existing checks.
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\Tests\Unit\Architecture;

use AIHazirSite\Core\Compliance\Scanner;
use AIHazirSite\Tests\Support\ComplianceSites;
use AIHazirSite\Tests\Support\FixedClock;
use AIHazirSite\Tests\Unit\Compliance\ScannerTest;
use PhpToken;
use PHPUnit\Framework\TestCase;

/**
 * Checks do not reference each other, and a new check leaves the others' results unchanged.
 *
 * @coversNothing
 */
final class ChecksAreIndependentTest extends TestCase {

	/**
	 * No check file mentions another check class.
	 */
	public function test_checks_do_not_reference_each_other(): void {
		$dir     = dirname( __DIR__, 3 ) . '/src/Core/Compliance/Checks';
		$files   = glob( $dir . '/*.php' );
		$classes = array_map( static fn( string $f ): string => basename( $f, '.php' ), is_array( $files ) ? $files : array() );
		$this->assertCount( 7, $classes );

		foreach ( $classes as $class ) {
			$names = array();
			foreach ( PhpToken::tokenize( (string) file_get_contents( $dir . '/' . $class . '.php' ) ) as $token ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
				if ( $token->is( array( T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED ) ) ) {
					$names[] = basename( str_replace( '\\', '/', $token->text ) );
				}
			}
			$others = array_intersect( array_diff( $classes, array( $class ) ), $names );
			$this->assertSame( array(), array_values( $others ), $class . ' must not use other checks.' );
		}
	}

	/**
	 * Adding a check changes neither the code nor the results of the existing checks.
	 */
	public function test_new_check_does_not_change_existing_results(): void {
		$before = ( new Scanner( Scanner::default_checks(), new FixedClock() ) )->scan( ComplianceSites::site( ComplianceSites::perfect() ) );

		$checks   = Scanner::default_checks();
		$checks[] = ScannerTest::check( 'webmcp', 5, 0.0 );
		$after    = ( new Scanner( $checks, new FixedClock() ) )->scan( ComplianceSites::site( ComplianceSites::perfect() ) );

		foreach ( $before->results as $row ) {
			$this->assertSame( $row, $after->result( $row['id'] ) );
		}
		$this->assertSame( 105, $after->total_weight() );
		$this->assertSame( 95, $after->score(), '100 of 105 points.' );
	}
}
