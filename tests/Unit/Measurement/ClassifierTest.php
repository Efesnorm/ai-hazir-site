<?php
/**
 * Tests for Classifier.
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\Tests\Unit\Measurement;

use AIHazirSite\Modules\Measurement\Classifier;
use AIHazirSite\Tests\Unit\UnitTestCase;
use Brain\Monkey\Functions;

/**
 * Classifier unit tests with the bundled data files.
 *
 * @covers \AIHazirSite\Modules\Measurement\Classifier
 * @covers \AIHazirSite\Modules\Measurement\Referrer
 */
final class ClassifierTest extends UnitTestCase {

	/**
	 * Classifier under test.
	 *
	 * @var Classifier
	 */
	private Classifier $classifier;

	/**
	 * Loads the bundled data.
	 */
	protected function setUp(): void {
		parent::setUp();
		Functions\when( 'wp_parse_url' )->alias( 'parse_url' );
		$this->classifier = Classifier::from_data();
	}

	/**
	 * Real user agents published by the operators.
	 *
	 * @return array<string, array{string, string, string}>
	 */
	public static function bot_user_agents(): array {
		return array(
			'GPTBot'             => array( 'Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko; compatible; GPTBot/1.4; +https://openai.com/gptbot)', 'gptbot', 'training' ),
			'OAI-SearchBot'      => array( 'Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko; compatible; OAI-SearchBot/1.4; +https://openai.com/searchbot)', 'oai-searchbot', 'search' ),
			'ChatGPT-User'       => array( 'Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko); compatible; ChatGPT-User/1.0; +https://openai.com/bot', 'chatgpt-user', 'user_agent' ),
			'ClaudeBot'          => array( 'Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko; compatible; ClaudeBot/1.0; +claudebot@anthropic.com)', 'claudebot', 'training' ),
			'Claude-User'        => array( 'Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko; compatible; Claude-User/1.0; +Claude-User@anthropic.com)', 'claude-user', 'user_agent' ),
			'Claude-SearchBot'   => array( 'Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko; compatible; Claude-SearchBot/1.0; +Claude-SearchBot@anthropic.com)', 'claude-searchbot', 'search' ),
			'PerplexityBot'      => array( 'Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko; compatible; PerplexityBot/1.0; +https://perplexity.ai/perplexitybot)', 'perplexitybot', 'search' ),
			'Perplexity-User'    => array( 'Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko; compatible; Perplexity-User/1.0; +https://perplexity.ai/perplexity-user)', 'perplexity-user', 'user_agent' ),
			'CCBot'              => array( 'CCBot/2.0 (https://commoncrawl.org/faq/)', 'ccbot', 'training' ),
			'Amazonbot'          => array( 'Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko; compatible; Amazonbot/0.1; +https://developer.amazon.com/support/amazonbot) Chrome/119.0.6045.214 Safari/537.36', 'amazonbot', 'training' ),
			'Bytespider'         => array( 'Mozilla/5.0 (Linux; Android 5.0) AppleWebKit/537.36 (KHTML, like Gecko) Mobile Safari/537.36 (compatible; Bytespider; spider-feedback@bytedance.com)', 'bytespider', 'training' ),
			'meta-externalagent' => array( 'meta-externalagent/1.1 (+https://developers.facebook.com/docs/sharing/webmasters/crawler)', 'meta-externalagent', 'training' ),
			'lower case'         => array( 'gptbot/1.0', 'gptbot', 'training' ),
		);
	}

	/**
	 * Each sample UA maps to the right bot and category.
	 *
	 * @dataProvider bot_user_agents
	 *
	 * @param string $user_agent User agent.
	 * @param string $id         Expected bot id.
	 * @param string $category   Expected category.
	 */
	public function test_bot_user_agents_match( string $user_agent, string $id, string $category ): void {
		$bot = $this->classifier->match_bot( $user_agent );

		$this->assertNotNull( $bot );
		$this->assertSame( $id, $bot->id );
		$this->assertSame( $category, $bot->category );
	}

	/**
	 * Browsers and non-AI crawlers.
	 *
	 * @return array<string, array{string}>
	 */
	public static function other_user_agents(): array {
		return array(
			'Chrome'    => array( 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36' ),
			'Safari'    => array( 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_6 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.6 Mobile/15E148 Safari/604.1' ),
			'Firefox'   => array( 'Mozilla/5.0 (X11; Linux x86_64; rv:143.0) Gecko/20100101 Firefox/143.0' ),
			'Googlebot' => array( 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)' ),
			'empty'     => array( '' ),
		);
	}

	/**
	 * Normal browsers do not match.
	 *
	 * @dataProvider other_user_agents
	 *
	 * @param string $user_agent User agent.
	 */
	public function test_other_user_agents_do_not_match( string $user_agent ): void {
		$this->assertNull( $this->classifier->match_bot( $user_agent ) );
	}

	/**
	 * Referer and utm_source samples.
	 *
	 * @return array<string, array{string, string, string|null}>
	 */
	public static function referrers(): array {
		return array(
			'chatgpt'           => array( 'https://chatgpt.com/', '', 'chatgpt' ),
			'perplexity www'    => array( 'https://www.perplexity.ai/search/abc', '', 'perplexity' ),
			'claude'            => array( 'https://claude.ai/', '', 'claude' ),
			'gemini'            => array( 'https://gemini.google.com/app', '', 'gemini' ),
			'copilot'           => array( 'https://copilot.microsoft.com/', '', 'copilot' ),
			'utm only'          => array( '', 'chatgpt.com', 'chatgpt' ),
			'google'            => array( 'https://www.google.com/', '', null ),
			'lookalike prefix'  => array( 'https://evilchatgpt.com/', '', null ),
			'lookalike suffix'  => array( 'https://chatgpt.com.evil.example/', '', null ),
			'google not gemini' => array( 'https://google.com/', '', null ),
			'nothing'           => array( '', '', null ),
			'unrelated utm'     => array( '', 'newsletter', null ),
		);
	}

	/**
	 * Referrers match by host or utm_source only.
	 *
	 * @dataProvider referrers
	 *
	 * @param string      $referer  Referer header.
	 * @param string      $utm      utm_source.
	 * @param string|null $expected Expected referrer id.
	 */
	public function test_referrers( string $referer, string $utm, ?string $expected ): void {
		$referrer = $this->classifier->match_referrer( $referer, $utm );

		$this->assertSame( $expected, $referrer?->id );
	}
}
