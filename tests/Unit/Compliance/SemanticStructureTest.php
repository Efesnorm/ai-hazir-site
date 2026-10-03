<?php
/**
 * Semantic structure advice (1.19.0).
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\Tests\Unit\Compliance;

use AIHazirSite\Core\Compliance\ScoreReport;
use AIHazirSite\Core\Compliance\SemanticStructure;
use PHPUnit\Framework\TestCase;

/**
 * Each rule passes and fails on small pages; the advice never changes the score.
 *
 * @covers \AIHazirSite\Core\Compliance\SemanticStructure
 * @covers \AIHazirSite\Core\Compliance\Html
 * @covers \AIHazirSite\Core\Compliance\ScoreReport
 */
final class SemanticStructureTest extends TestCase {

	private const URL = 'https://ornek.com/';

	/**
	 * A page that follows every rule.
	 *
	 * @param string $body Body.
	 * @param string $root Root element attributes.
	 * @param string $head Head content.
	 */
	private static function page( string $body = '<main><h1>Ürünler</h1><h2>Kablolar</h2><h3>NYY</h3></main>', string $root = ' lang="tr"', string $head = '<title>Örnek</title>' ): string {
		return '<!doctype html><html' . $root . '><head>' . $head . '</head><body><nav><a href="/">Ana sayfa</a></nav>' . $body . '<footer>Altbilgi</footer></body></html>';
	}

	/**
	 * Findings without the URL prefix.
	 *
	 * @param string $html Page.
	 * @return list<string>
	 */
	private static function findings( string $html ): array {
		return array_map( static fn( string $f ): string => substr( $f, strlen( self::URL . ': ' ) ), SemanticStructure::page( self::URL, $html ) );
	}

	/**
	 * A well-built page has no advice.
	 */
	public function test_good_page(): void {
		$this->assertSame( array(), self::findings( self::page() ) );
		$this->assertSame( array(), self::findings( self::page( '<div role="main"><h1>Ürünler</h1></div>' ) ), 'role="main" counts.' );
		$this->assertSame( array(), self::findings( self::page( '<main><h1>A</h1></main><main hidden><h2>B</h2></main>' ) ), 'A hidden main is allowed.' );
	}

	/**
	 * Language and title.
	 */
	public function test_language_and_title(): void {
		$this->assertSame( array( 'Sayfanın dili belirtilmemiş (<html lang>).' ), self::findings( self::page( '<main><h1>A</h1></main>', '' ) ) );
		$this->assertSame( array( 'Sayfa başlığı (<title>) yok.' ), self::findings( self::page( '<main><h1>A</h1></main>', ' lang="tr"', '<title>  </title>' ) ) );
	}

	/**
	 * Main landmark: missing or more than one.
	 */
	public function test_main(): void {
		$this->assertSame( array( 'Ana içerik <main> ile işaretlenmemiş; agentlar menü, altbilgi ve içeriği ayıramaz.' ), self::findings( self::page( '<div><h1>A</h1></div>' ) ) );
		$this->assertSame( array( '2 ana içerik alanı (<main>) var; bir tane olmalı.' ), self::findings( self::page( '<main><h1>A</h1></main><main><p>B</p></main>' ) ) );
	}

	/**
	 * Headings: no h1, several h1, a skipped level (reported once).
	 */
	public function test_headings(): void {
		$this->assertSame( array( 'Ana başlık (<h1>) yok.' ), self::findings( self::page( '<main><h2>A</h2></main>' ) ) );
		$this->assertSame( array( '3 tane <h1> var; bir tane olmalı.' ), self::findings( self::page( '<main><h1>A</h1><h1>B</h1><h1>C</h1></main>' ) ) );
		$this->assertSame( array( 'Başlık seviyesi atlanıyor: <h2> sonrasında <h4>.' ), self::findings( self::page( '<main><h1>A</h1><h2>B</h2><h4>C</h4><h6>D</h6></main>' ) ) );
		$this->assertSame( array(), self::findings( self::page( '<main><h1>A</h1><h2>B</h2><h3>C</h3><h2>D</h2></main>' ) ), 'Going back up is fine.' );
	}

	/**
	 * Findings carry the page URL.
	 */
	public function test_url_prefix(): void {
		$this->assertSame( array( self::URL . ': Ana başlık (<h1>) yok.' ), SemanticStructure::page( self::URL, self::page( '<main><p>Metin</p></main>' ) ) );
	}

	/**
	 * Stored reports: advice is kept, older reports read without it, and the score ignores it.
	 */
	public function test_report_storage_and_score(): void {
		$row  = array(
			'id'       => 'readability',
			'weight'   => 20,
			'ratio'    => 0.5,
			'points'   => 10.0,
			'gain'     => 10.0,
			'level'    => 'warning',
			'findings' => array(),
			'fix'      => '',
		);
		$with = new ScoreReport( '2026-10-04T00:00:00Z', 4, array( $row ), 10, array( 'https://ornek.com/: Ana başlık (<h1>) yok.' ) );
		$bare = new ScoreReport( '2026-10-04T00:00:00Z', 4, array( $row ), 10 );

		$this->assertSame( $bare->score(), $with->score() );
		$this->assertArrayNotHasKey( 'advice', $bare->to_array(), 'Reports without advice are stored as before.' );
		$this->assertSame( $with->advice, ScoreReport::from_array( $with->to_array() )?->advice );
		$this->assertSame( array(), ScoreReport::from_array( $bare->to_array() )?->advice );
	}
}
