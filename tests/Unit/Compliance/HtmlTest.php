<?php
/**
 * Tests for Html.
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\Tests\Unit\Compliance;

use AIHazirSite\Core\Compliance\Html;
use PHPUnit\Framework\TestCase;

/**
 * Html unit tests.
 *
 * @covers \AIHazirSite\Core\Compliance\Html
 */
final class HtmlTest extends TestCase {

	private const PAGE = <<<'HTML'
<!doctype html>
<html><head>
<meta name="Robots" content="NoIndex, follow">
<link rel="alternate https://api.w.org/" href="https://ornek.com/wp-json/">
<script type="application/ld+json">{"@context":"https://schema.org","@graph":[{"@type":"Organization","name":"Örnek A.Ş."},{"@type":"WebSite","name":"Örnek"}]}</script>
<script type="APPLICATION/LD+JSON">[{"@type":"Product","name":"Kablo"}]</script>
<script type="application/ld+json">{ bozuk json </script>
<style>.x{color:red}</style>
</head><body>
<header><a href="/header-link">H</a></header>
<nav><a href="/urunler/">Ürünler</a><a href="https://ornek.com/iletisim/#form">İletişim</a></nav>
<p>Kablo   ve   tel üretiyoruz.</p>
<script>document.write("gizli")</script>
<noscript>JS kapalı</noscript>
</body></html>
HTML;

	/**
	 * JSON-LD blocks are parsed, @graph and lists flattened, broken blocks counted.
	 */
	public function test_json_ld(): void {
		$json_ld = ( new Html( self::PAGE ) )->json_ld();

		$this->assertSame( 3, $json_ld['blocks'] );
		$this->assertSame( 1, $json_ld['invalid'] );
		$this->assertSame( array( 'Organization', 'WebSite', 'Product' ), array_column( $json_ld['items'], '@type' ) );
		$this->assertSame( 'Örnek A.Ş.', $json_ld['items'][0]['name'] );
	}

	/**
	 * Visible text excludes scripts, styles and noscript; whitespace collapsed.
	 */
	public function test_visible_text(): void {
		$text = ( new Html( self::PAGE ) )->visible_text();

		$this->assertStringContainsString( 'Kablo ve tel üretiyoruz.', $text );
		$this->assertStringNotContainsString( 'gizli', $text );
		$this->assertStringNotContainsString( 'color', $text );
		$this->assertStringNotContainsString( 'JS kapalı', $text );
	}

	/**
	 * Meta robots, rel links and navigation links.
	 */
	public function test_meta_and_links(): void {
		$html = new Html( self::PAGE );

		$this->assertSame( 'noindex, follow', $html->meta_robots() );
		$this->assertSame( 'https://ornek.com/wp-json/', $html->link_rel( 'https://api.w.org/' ) );
		$this->assertSame( '', $html->link_rel( 'nothing' ) );
		$this->assertSame( array( '/urunler/', 'https://ornek.com/iletisim/#form' ), $html->nav_links() );
		$this->assertSame( array( '/header-link' ), ( new Html( '<header><a href="/header-link">x</a></header>' ) )->nav_links() );
	}

	/**
	 * Empty input is harmless.
	 */
	public function test_empty_html(): void {
		$html = new Html( '' );

		$this->assertSame( '', $html->visible_text() );
		$this->assertSame( 0, $html->json_ld()['blocks'] );
	}
}
