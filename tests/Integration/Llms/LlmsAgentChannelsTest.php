<?php
/**
 * The ways an AI agent can act on the site, named in llms.txt (1.16.0).
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\Tests\Integration\Llms;

use AIHazirSite\Adapters\Llms\LlmsCache;
use AIHazirSite\Core\Features;
use AIHazirSite\WordPress\Catalog\CatalogModule;
use AIHazirSite\WordPress\Llms\LlmsModule;
use WP_UnitTestCase;

/**
 * Found live: makedonya.tr had its inquiry box, A2A and MCP open, but llms.txt (what agents read) named none of
 * them.
 *
 * @covers \AIHazirSite\WordPress\Llms\LlmsModule
 * @covers \AIHazirSite\Adapters\Llms\LlmsTxtBuilder
 */
final class LlmsAgentChannelsTest extends WP_UnitTestCase {

	/**
	 * Catalog with one listing; llms.txt on; every channel off.
	 */
	public function set_up(): void {
		parent::set_up();
		foreach ( array( Features::OPTION, LlmsCache::OPTION, 'aihs_inquiry_settings' ) as $option ) {
			delete_option( $option );
		}
		foreach ( array( Features::CATALOG, Features::LLMS_TXT ) as $feature ) {
			Features::set( $feature, true );
		}
		CatalogModule::service()->save_profile( array( 'name' => 'Örnek Kablo A.Ş.' ) );
		CatalogModule::service()->save_listing(
			array(
				'type'  => 'offer',
				'title' => 'NYY kablo',
			)
		);
	}

	/**
	 * No channel open: no section, the text is as before.
	 */
	public function test_no_channel_no_section(): void {
		$this->assertSame( array(), LlmsModule::agent_channels() );
		$this->assertStringNotContainsString( '## AI agentlar için', LlmsModule::text() );
	}

	/**
	 * Open channels are listed before "Optional"; closing one removes its line (the cached text is renewed).
	 */
	public function test_open_channels_listed_before_optional(): void {
		$before = LlmsModule::text();
		foreach ( array( Features::REST_API, Features::INQUIRIES, Features::A2A, Features::ABILITIES, Features::MCP ) as $feature ) {
			Features::set( $feature, true );
		}

		$text     = LlmsModule::text();
		$section  = strpos( $text, '## AI agentlar için' );
		$optional = strpos( $text, '## Optional' );
		$this->assertNotSame( $before, $text );
		$this->assertIsInt( $section );
		$this->assertIsInt( $optional );
		$this->assertLessThan( $optional, $section );

		$agents = substr( $text, $section, $optional - $section );
		$this->assertStringContainsString( '(' . rest_url( 'aihs/v1/inquiries' ) . '): POST, JSON: kind (quote_request | offer | referral)', $agents );
		$this->assertStringContainsString( '(' . home_url( '/.well-known/agent-card.json' ) . '): A2A 1.0 (JSON-RPC); beceriler: müsaitlik sorma, teklif isteme.', $agents );
		$this->assertStringContainsString( '(' . rest_url( 'aihs/mcp' ) . '): Model Context Protocol', $agents );
		$this->assertStringContainsString( 'talep bırakma aracı', $agents );

		Features::set( Features::INQUIRIES, false );
		$after = LlmsModule::text();
		$this->assertStringNotContainsString( 'aihs/v1/inquiries', $after );
		$this->assertStringContainsString( 'beceriler: müsaitlik sorma.', $after );
		$this->assertStringContainsString( 'salt okunur katalog araçları', $after );
	}

	/**
	 * The inquiry line needs the REST API: inquiries alone (A2A/MCP off) add nothing.
	 */
	public function test_inquiries_without_rest_add_nothing(): void {
		Features::set( Features::INQUIRIES, true );
		$this->assertSame( array(), LlmsModule::agent_channels() );
	}
}
