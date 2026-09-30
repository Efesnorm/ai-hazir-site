<?php
/**
 * The "AI agentlar için" section of llms.txt (1.16.0).
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\Tests\Unit\Llms;

use AIHazirSite\Adapters\Llms\LlmsTxtBuilder;
use AIHazirSite\Tests\Support\SchemaFixtures as F;
use PHPUnit\Framework\TestCase;

/**
 * Agent channels passed to the builder.
 *
 * @covers \AIHazirSite\Adapters\Llms\LlmsTxtBuilder
 */
final class LlmsTxtBuilderAgentsTest extends TestCase {

	/**
	 * Channels become a link list right before "Optional"; notes are made single-line, link text escaped.
	 */
	public function test_section_before_optional(): void {
		$builder = new LlmsTxtBuilder(
			F::SITE_URL,
			F::SITE_URL . 'ai-katalog/',
			'Örnek Site',
			array(),
			null,
			'',
			array(
				array(
					'name' => 'Teklif [REST]',
					'url'  => F::SITE_URL . 'wp-json/aihs/v1/inquiries',
					'note' => "POST,\n JSON",
				),
				array(
					'name' => 'MCP sunucusu',
					'url'  => F::SITE_URL . 'wp-json/aihs/mcp',
					'note' => '',
				),
			)
		);
		$text    = $builder->build( F::profile(), array(), F::TODAY, '' );

		$expected = "## AI agentlar için\n\n- [Teklif \\[REST\\]](" . F::SITE_URL . "wp-json/aihs/v1/inquiries): POST, JSON\n- [MCP sunucusu](" . F::SITE_URL . "wp-json/aihs/mcp)\n\n## Optional";
		$this->assertStringContainsString( $expected, $text );
	}

	/**
	 * Without channels the section is absent.
	 */
	public function test_no_channels_no_section(): void {
		$text = ( new LlmsTxtBuilder( F::SITE_URL, F::SITE_URL . 'ai-katalog/', 'Örnek Site' ) )->build( F::profile(), array(), F::TODAY, '' );
		$this->assertStringNotContainsString( 'AI agentlar için', $text );
	}
}
