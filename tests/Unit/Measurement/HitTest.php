<?php
/**
 * Tests for Hit path normalization.
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\Tests\Unit\Measurement;

use AIHazirSite\Core\Measurement\Hit;
use AIHazirSite\Tests\Unit\UnitTestCase;

/**
 * Hit unit tests.
 *
 * @covers \AIHazirSite\Core\Measurement\Hit::normalize_path
 */
final class HitTest extends UnitTestCase {

	/**
	 * Paths and their stored form.
	 *
	 * @return array<string, array{string, string}>
	 */
	public static function paths(): array {
		return array(
			'plain'        => array( '/urunler/', '/urunler/' ),
			'query'        => array( '/ara?s=test&utm_source=chatgpt.com', '/ara' ),
			'fragment'     => array( '/sayfa#bolum', '/sayfa' ),
			'no slash'     => array( 'robots.txt', '/robots.txt' ),
			'empty'        => array( '', '/' ),
			'control char' => array( "/a\nb", '/ab' ),
			'multibyte'    => array( '/' . str_repeat( 'ş', 300 ), '/' . str_repeat( 'ş', 190 ) ),
			'invalid utf8' => array( "/a\xC3(b", '/' ),
		);
	}

	/**
	 * Normalization drops query strings and limits length to 191 characters.
	 *
	 * @dataProvider paths
	 *
	 * @param string $input    Raw path.
	 * @param string $expected Stored path.
	 */
	public function test_normalize_path( string $input, string $expected ): void {
		$this->assertSame( $expected, Hit::normalize_path( $input ) );
	}
}
