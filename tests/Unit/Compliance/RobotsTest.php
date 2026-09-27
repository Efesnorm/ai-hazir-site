<?php
/**
 * Tests for Robots (RFC 9309).
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\Tests\Unit\Compliance;

use AIHazirSite\Core\Compliance\Robots;
use AIHazirSite\Core\Contracts\PageResponse;
use PHPUnit\Framework\TestCase;

/**
 * Robots unit tests.
 *
 * @covers \AIHazirSite\Core\Compliance\Robots
 */
final class RobotsTest extends TestCase {

	private const FILE = "# ornek\nUser-agent: GPTBot\nUser-agent: CCBot\nDisallow: /\n\nUser-agent: ClaudeBot\nDisallow: /ozel/\nAllow: /ozel/acik\n\nUser-agent: *\nDisallow: /wp-admin/\nAllow: /wp-admin/admin-ajax.php\nDisallow: /*.pdf$\n\nUser-agent: claudebot\nDisallow: /gizli\n\nSitemap: https://ornek.com/wp-sitemap.xml\n";

	/**
	 * Matching samples.
	 *
	 * @return array<string, array{string, string, bool}>
	 */
	public static function cases(): array {
		return array(
			'group with two agents (1)'    => array( 'GPTBot', '/urunler/', false ),
			'group with two agents (2)'    => array( 'CCBot', '/', false ),
			'case-insensitive token'       => array( 'gptbot', '/', false ),
			'longest match wins'           => array( 'ClaudeBot', '/ozel/acik', true ),
			'disallowed prefix'            => array( 'ClaudeBot', '/ozel/x', false ),
			'matching groups are combined' => array( 'ClaudeBot', '/gizli', false ),
			'specific group ignores *'     => array( 'ClaudeBot', '/wp-admin/', true ),
			'no group → * group'           => array( 'PerplexityBot', '/wp-admin/', false ),
			'* group allow longer'         => array( 'PerplexityBot', '/wp-admin/admin-ajax.php', true ),
			'* and $ special characters'   => array( 'PerplexityBot', '/dosya/katalog.pdf', false ),
			'$ anchors the end'            => array( 'PerplexityBot', '/dosya/katalog.pdf?x=1', true ),
			'no rule matches → allowed'    => array( 'PerplexityBot', '/urunler/', true ),
		);
	}

	/**
	 * RFC 9309 matching.
	 *
	 * @dataProvider cases
	 *
	 * @param string $token    Product token.
	 * @param string $path     Path.
	 * @param bool   $expected Allowed.
	 */
	public function test_matching( string $token, string $path, bool $expected ): void {
		$this->assertSame( $expected, ( new Robots( self::FILE ) )->allows( $token, $path ) );
	}

	/**
	 * Equal-length allow and disallow: allow wins.
	 */
	public function test_tie_prefers_allow(): void {
		$this->assertTrue( ( new Robots( "User-agent: *\nDisallow: /a\nAllow: /a\n" ) )->allows( 'X', '/a' ) );
	}

	/**
	 * Sitemap lines are collected.
	 */
	public function test_sitemaps(): void {
		$this->assertSame( array( 'https://ornek.com/wp-sitemap.xml' ), ( new Robots( self::FILE ) )->sitemaps() );
	}

	/**
	 * Status handling: 4xx allows all, 5xx and unreachable disallow all.
	 */
	public function test_status_handling(): void {
		$this->assertTrue( Robots::from_response( new PageResponse( 404 ) )->allows( 'GPTBot', '/' ) );
		$this->assertFalse( Robots::from_response( new PageResponse( 503 ) )->allows( 'GPTBot', '/' ) );
		$this->assertFalse( Robots::from_response( PageResponse::failed( 'timeout' ) )->allows( 'GPTBot', '/' ) );
		$this->assertFalse( Robots::from_response( new PageResponse( 200, array(), self::FILE ) )->allows( 'GPTBot', '/' ) );
	}
}
