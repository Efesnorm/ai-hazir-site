<?php
/**
 * Tests for Site.
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\Tests\Unit\Compliance;

use AIHazirSite\Core\Compliance\Site;
use AIHazirSite\Core\Contracts\PageResponse;
use AIHazirSite\Tests\Support\FakePageFetcher;
use PHPUnit\Framework\TestCase;

/**
 * Site unit tests.
 *
 * @covers \AIHazirSite\Core\Compliance\Site
 */
final class SiteTest extends TestCase {

	private const BASE = 'https://ornek.com/';

	/**
	 * Each URL is fetched once per header set; site-wide headers are always sent.
	 */
	public function test_fetch_is_cached_and_sends_headers(): void {
		$fetcher = new FakePageFetcher( array( self::BASE => '<p>ana</p>' ) );
		$site    = new Site( 'https://ornek.com', $fetcher, array( 'X-AIHS-Scan' => 'imza' ) );

		$site->home();
		$site->fetch( self::BASE );
		$site->fetch( '/', array( 'User-Agent' => 'GPTBot' ) );

		$this->assertSame( array( self::BASE, self::BASE ), $fetcher->urls() );
		$this->assertSame( 'imza', $fetcher->requests[0]['headers']['X-AIHS-Scan'] );
		$this->assertSame( 'GPTBot', $fetcher->requests[1]['headers']['User-Agent'] );
		$this->assertSame( Site::TIMEOUT, $fetcher->requests[0]['timeout'] );
	}

	/**
	 * Home, then navigation links, then sitemap entries; same site only; at most 10.
	 */
	public function test_page_selection(): void {
		$nav  = '<nav><a href="/urunler/">Ü</a><a href="hakkimizda/">H</a><a href="https://baska.com/x">X</a><a href="mailto:a@b.c">M</a><a href="/urunler/#top">Ü</a></nav>';
		$locs = '';
		for ( $i = 1; $i <= 12; $i++ ) {
			$locs .= '<url><loc>https://ornek.com/sayfa-' . $i . '/</loc></url>';
		}
		$fetcher = new FakePageFetcher(
			array(
				self::BASE                                 => $nav,
				self::BASE . 'robots.txt'                  => new PageResponse( 200, array(), "User-agent: *\nSitemap: https://ornek.com/wp-sitemap.xml\n" ),
				self::BASE . 'wp-sitemap.xml'              => new PageResponse( 200, array(), '<sitemapindex><sitemap><loc>https://ornek.com/wp-sitemap-posts-page-1.xml</loc></sitemap></sitemapindex>' ),
				self::BASE . 'wp-sitemap-posts-page-1.xml' => new PageResponse( 200, array(), '<urlset>' . $locs . '</urlset>' ),
			)
		);

		$pages = ( new Site( self::BASE, $fetcher ) )->pages();

		$this->assertCount( Site::MAX_PAGES, $pages );
		$this->assertSame(
			array( self::BASE, self::BASE . 'urunler/', self::BASE . 'hakkimizda/', self::BASE . 'sayfa-1/' ),
			array_slice( $pages, 0, 4 )
		);
		$this->assertSame( self::BASE . 'sayfa-7/', $pages[9] );
	}

	/**
	 * Without robots.txt sitemap lines, /wp-sitemap.xml then /sitemap.xml are tried.
	 */
	public function test_sitemap_fallback(): void {
		$fetcher = new FakePageFetcher(
			array(
				self::BASE                 => '<p>no nav</p>',
				self::BASE . 'sitemap.xml' => new PageResponse( 200, array(), '<urlset><url><loc>https://ornek.com/a/</loc></url></urlset>' ),
			)
		);

		$this->assertSame( array( self::BASE, self::BASE . 'a/' ), ( new Site( self::BASE, $fetcher ) )->pages() );
		$this->assertContains( self::BASE . 'wp-sitemap.xml', $fetcher->urls() );
	}

	/**
	 * Alias hosts (e.g. the public address when scanning through a local one) are treated as the site.
	 */
	public function test_alias_hosts_are_rewritten(): void {
		$site = new Site( 'http://host.docker.internal:8888/', new FakePageFetcher(), array(), array( 'localhost:8888' ) );

		$this->assertSame( 'http://host.docker.internal:8888/urunler/', $site->same_site( 'http://localhost:8888/urunler/' ) );
		$this->assertNull( $site->same_site( 'http://localhost:9999/urunler/' ) );
	}

	/**
	 * After 60 s of requests no further request is made.
	 */
	public function test_time_budget(): void {
		$fetcher = new FakePageFetcher( array(), 5000 );
		$site    = new Site( self::BASE, $fetcher );

		for ( $i = 0; $i < 20; $i++ ) {
			$site->fetch( '/sayfa-' . $i . '/' );
		}

		$this->assertCount( 12, $fetcher->requests );
		$this->assertTrue( $site->budget_exhausted() );
		$this->assertSame( 60000, $site->elapsed_ms() );
		$this->assertSame( Site::SKIPPED_ERROR, $site->fetch( '/yeni/' )->error );
	}
}
