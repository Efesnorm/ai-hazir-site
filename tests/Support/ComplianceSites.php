<?php
/**
 * Scripted sites for compliance tests.
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\Tests\Support;

use AIHazirSite\Core\Compliance\Site;
use AIHazirSite\Core\Contracts\PageResponse;

/**
 * A site that earns every point, one that earns none, and one that cannot be reached.
 */
final class ComplianceSites {

	public const BASE = 'https://ornek.com/';

	/**
	 * Full JSON-LD for a product page.
	 */
	public const PRODUCT_JSON_LD = '{"@context":"https://schema.org","@graph":[{"@type":"Organization","name":"Örnek Kablo A.Ş.","url":"https://ornek.com/"},{"@type":"Product","name":"NYY 3x2,5 kablo","dateModified":"2026-09-20","offers":{"@type":"Offer","price":"42.50","priceCurrency":"TRY","priceValidUntil":"2026-12-31"}}]}';

	/**
	 * A complete A2A agent card (§4.4.1 required fields).
	 */
	public const AGENT_CARD = '{"name":"Örnek Kablo","description":"Kablo satış agentı","supportedInterfaces":[{"url":"https://ornek.com/a2a","protocolBinding":"JSONRPC"}],"version":"1.0.0","capabilities":{},"defaultInputModes":["text/plain"],"defaultOutputModes":["application/json"],"skills":[]}';

	/**
	 * A readable page with JSON-LD and contact information.
	 *
	 * @param string $title Title.
	 */
	public static function good_page( string $title = 'Ana sayfa' ): string {
		$words = str_repeat( 'Firmamız enerji ve telekom sektörüne yüksek kaliteli kablo üretir ve teslim eder. ', 8 );
		return '<!doctype html><html><head><title>' . $title . '</title>'
			. '<link rel="https://api.w.org/" href="https://ornek.com/wp-json/">'
			. '<script type="application/ld+json">' . self::PRODUCT_JSON_LD . '</script></head>'
			. '<body><nav><a href="/urunler/">Ürünler</a><a href="/iletisim/">İletişim</a></nav>'
			. '<h1>' . $title . '</h1><p>' . $words . '</p><p>E-posta: satis@ornek.com Telefon: +90 212 555 12 34</p></body></html>';
	}

	/**
	 * Every check scores 1.
	 */
	public static function perfect(): FakePageFetcher {
		return new FakePageFetcher(
			array(
				self::BASE                                 => new PageResponse( 200, array( 'link' => '<https://ornek.com/wp-json/>; rel="https://api.w.org/"' ), self::good_page() ),
				self::BASE . 'urunler/'                    => self::good_page( 'Ürünler' ),
				self::BASE . 'iletisim/'                   => self::good_page( 'İletişim' ),
				self::BASE . 'robots.txt'                  => new PageResponse( 200, array(), "User-agent: *\nDisallow: /wp-admin/\n" ),
				self::BASE . 'llms.txt'                    => new PageResponse( 200, array(), "# Örnek Kablo A.Ş.\n\n> Enerji ve telekom kabloları üreticisi.\n" ),
				self::BASE . 'wp-json/'                    => new PageResponse( 200, array(), '{"name":"Örnek","namespaces":["wp/v2","aihs/v1"]}' ),
				self::BASE . 'wp-json/mcp/mcp-adapter-default-server' => new PageResponse( 405, array(), '' ),
				self::BASE . '.well-known/agent-card.json' => new PageResponse( 200, array(), self::AGENT_CARD ),
			)
		);
	}

	/**
	 * Every check scores 0.
	 */
	public static function weak(): FakePageFetcher {
		$page = '<!doctype html><html><head><meta name="robots" content="noindex"></head><body><div id="app"></div><script>render()</script></body></html>';
		return new FakePageFetcher(
			array(
				self::BASE                => static fn( array $headers ): PageResponse => isset( $headers['User-Agent'] ) && str_contains( $headers['User-Agent'], 'GPTBot' )
					? new PageResponse( 403, array(), 'Forbidden' )
					: new PageResponse( 200, array(), $page ),
				self::BASE . 'robots.txt' => new PageResponse( 200, array(), "User-agent: *\nDisallow: /\n" ),
			)
		);
	}

	/**
	 * Nothing answers.
	 */
	public static function unreachable(): FakePageFetcher {
		$fetcher = new FakePageFetcher();
		foreach ( array( '', 'robots.txt', 'llms.txt', 'wp-json/', 'wp-sitemap.xml', 'sitemap.xml', '.well-known/agent-card.json', '.well-known/mcp/server-cards.json', 'wp-json/mcp/mcp-adapter-default-server' ) as $path ) {
			$fetcher->on( self::BASE . $path, PageResponse::failed( 'cURL error 7: Failed to connect' ) );
		}
		return $fetcher;
	}

	/**
	 * Site over a fetcher.
	 *
	 * @param FakePageFetcher $fetcher Fetcher.
	 */
	public static function site( FakePageFetcher $fetcher ): Site {
		return new Site( self::BASE, $fetcher );
	}
}
