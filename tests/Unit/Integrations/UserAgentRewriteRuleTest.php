<?php
/**
 * Tests for UserAgentRewriteRule (1.14.0).
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\Tests\Unit\Integrations;

use AIHazirSite\Core\Integrations\BotTokens;
use AIHazirSite\Core\Integrations\UserAgentRewriteRule;
use PHPUnit\Framework\TestCase;

/**
 * The LiteSpeed rule: one condition over the bot tokens, escaped, case-insensitive; nothing without tokens.
 *
 * @covers \AIHazirSite\Core\Integrations\UserAgentRewriteRule
 */
final class UserAgentRewriteRuleTest extends TestCase {

	/**
	 * Shape of the block.
	 */
	public function test_lines(): void {
		$lines = UserAgentRewriteRule::lines( array( 'GPTBot', 'ChatGPT-User', 'GPTBot', 'meta.bot' ) );
		$this->assertSame(
			array(
				UserAgentRewriteRule::FIRST_LINE,
				'<IfModule LiteSpeed>',
				'RewriteEngine On',
				'RewriteCond %{HTTP_USER_AGENT} (GPTBot|ChatGPT\-User|meta\.bot) [NC]',
				'RewriteRule .* - [E=Cache-Control:no-cache]',
				'</IfModule>',
				UserAgentRewriteRule::LAST_LINE,
			),
			$lines
		);
		$this->assertSame( array(), UserAgentRewriteRule::lines( array() ) );
		$this->assertSame( array(), UserAgentRewriteRule::lines( array( '' ) ) );
	}

	/**
	 * The bundled bot list: every token appears in the condition, and the condition matches real user agents.
	 */
	public function test_bundled_bots(): void {
		$condition = UserAgentRewriteRule::lines( BotTokens::all() )[3];
		$this->assertMatchesRegularExpression( '/^RewriteCond %\{HTTP_USER_AGENT\} \((.+)\) \[NC\]$/', $condition );
		preg_match( '/\((.+)\) \[NC\]$/', $condition, $m );
		$pattern = '/' . $m[1] . '/i';
		$this->assertSame( 1, preg_match( $pattern, 'Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko; compatible; GPTBot/1.3; +https://openai.com/gptbot)' ) );
		$this->assertSame( 1, preg_match( $pattern, 'Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko; compatible; ClaudeBot/1.0; +claudebot@anthropic.com)' ) );
		$this->assertSame( 0, preg_match( $pattern, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0 Safari/537.36' ) );
	}
}
