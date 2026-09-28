<?php
/**
 * Bot tokens, managed lists and the sitemap document (1.8.0).
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\Tests\Unit\Integrations;

use AIHazirSite\Adapters\Sitemap\SitemapXml;
use AIHazirSite\Core\Integrations\BotTokens;
use AIHazirSite\Core\Integrations\ManagedList;
use AIHazirSite\Core\Measurement\Bot;
use PHPUnit\Framework\TestCase;

/**
 * Platform-neutral parts of the integrations.
 *
 * @covers \AIHazirSite\Core\Integrations\BotTokens
 * @covers \AIHazirSite\Core\Integrations\ManagedList
 * @covers \AIHazirSite\Adapters\Sitemap\SitemapXml
 */
final class IntegrationsCoreTest extends TestCase {

	/**
	 * The bundled list gives plain product tokens, once each.
	 */
	public function test_bot_tokens(): void {
		$tokens = BotTokens::all();
		foreach ( array( 'GPTBot', 'ChatGPT-User', 'OAI-SearchBot', 'ClaudeBot', 'PerplexityBot' ) as $token ) {
			$this->assertContains( $token, $tokens );
		}
		$this->assertSame( $tokens, array_values( array_unique( $tokens ) ) );
		foreach ( $tokens as $token ) {
			$this->assertMatchesRegularExpression( '/^[A-Za-z0-9][A-Za-z0-9._-]*$/', $token );
		}

		$bot = static fn( string $name ): Bot => new Bot( strtolower( (string) preg_replace( '/\W/', '', $name ) ) . 'x', $name, 'Op', 'x', 'training', 'none', '', '' );
		$this->assertSame( array( 'Good-Bot' ), BotTokens::all( array( $bot( 'Good-Bot' ), $bot( 'Bad Bot' ), $bot( 'Bad(Bot)' ), $bot( 'Good-Bot' ) ) ) );
	}

	/**
	 * Only our entries are added and later removed; the owner's own entries stay.
	 */
	public function test_managed_list(): void {
		$added = ManagedList::add( array( 'OwnerBot', 'gptbot' ), array( 'GPTBot', 'ClaudeBot' ) );
		$this->assertSame( array( 'OwnerBot', 'gptbot', 'ClaudeBot' ), $added['list'] );
		$this->assertSame( array( 'ClaudeBot' ), $added['added'] );

		$this->assertSame( array( 'OwnerBot', 'gptbot' ), ManagedList::remove( $added['list'], $added['added'] ) );
		$this->assertSame( array(), ManagedList::remove( array(), array( 'ClaudeBot' ) ) );
	}

	/**
	 * A valid sitemaps.org urlset and index entry, escaped.
	 */
	public function test_sitemap_xml(): void {
		$xml = SitemapXml::urlset(
			array(
				array(
					'loc'     => 'https://ornek.com/ai-katalog/?a=1&b=2',
					'lastmod' => '2026-09-28T10:00:00Z',
				),
				array(
					'loc'     => 'https://ornek.com/ai-katalog/isletme/x/',
					'lastmod' => '',
				),
			)
		);
		$doc = simplexml_load_string( $xml );
		$this->assertNotFalse( $doc );
		$this->assertSame( SitemapXml::NAMESPACE, $doc->getNamespaces()[''] ?? '' );
		$this->assertStringContainsString( '<loc>https://ornek.com/ai-katalog/?a=1&amp;b=2</loc>', $xml );
		$this->assertSame( 1, substr_count( $xml, '<lastmod>' ) );

		$entry = SitemapXml::index_entry( 'https://ornek.com/ai-katalog-sitemap.xml', '2026-09-28T10:00:00Z' );
		$this->assertNotFalse( simplexml_load_string( '<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . $entry . '</sitemapindex>' ) );
		$this->assertStringContainsString( '<lastmod>2026-09-28T10:00:00Z</lastmod>', $entry );
	}
}
