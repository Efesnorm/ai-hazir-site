<?php
/**
 * XML sitemap of our pages.
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\Adapters\Sitemap;

/**
 * A <urlset> in the sitemaps.org protocol (https://www.sitemaps.org/protocol.html): one <url> per page
 * with <loc> and, when known, <lastmod> (W3C Datetime). Values are entity-escaped as the protocol
 * requires. Platform-neutral (1.8.0).
 */
final class SitemapXml {

	public const NAMESPACE = 'http://www.sitemaps.org/schemas/sitemap/0.9';

	/**
	 * The document.
	 *
	 * @param list<array{loc: string, lastmod: string}> $urls Pages ('' lastmod = unknown).
	 */
	public static function urlset( array $urls ): string {
		$xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n" . '<urlset xmlns="' . self::NAMESPACE . '">' . "\n";
		foreach ( $urls as $url ) {
			$xml .= "\t<url>\n\t\t<loc>" . self::escape( $url['loc'] ) . "</loc>\n"
				. ( '' === $url['lastmod'] ? '' : "\t\t<lastmod>" . self::escape( $url['lastmod'] ) . "</lastmod>\n" )
				. "\t</url>\n";
		}
		return $xml . '</urlset>' . "\n";
	}

	/**
	 * A <sitemap> entry for a sitemap index (Rank Math and Yoast SEO append these to their index).
	 *
	 * @param string $loc     Sitemap URL.
	 * @param string $lastmod W3C Datetime, or ''.
	 */
	public static function index_entry( string $loc, string $lastmod ): string {
		return "\t<sitemap>\n\t\t<loc>" . self::escape( $loc ) . "</loc>\n"
			. ( '' === $lastmod ? '' : "\t\t<lastmod>" . self::escape( $lastmod ) . "</lastmod>\n" )
			. "\t</sitemap>\n";
	}

	/**
	 * Entity escaping (sitemaps.org: &, ', ", >, <).
	 *
	 * @param string $value Value.
	 */
	private static function escape( string $value ): string {
		return htmlspecialchars( $value, ENT_QUOTES | ENT_XML1, 'UTF-8' );
	}
}
