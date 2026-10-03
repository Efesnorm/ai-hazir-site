<?php
/**
 * LiteSpeed server cache rule (1.14.0).
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\Tests\Integration\Integrations;

use AIHazirSite\Core\Features;
use AIHazirSite\Core\Integrations\UserAgentRewriteRule;
use AIHazirSite\WordPress\Integrations\IntegrationsModule;
use AIHazirSite\WordPress\Integrations\IntegrationsPage;
use AIHazirSite\WordPress\Integrations\LiteSpeedServerBypass;
use AIHazirSite\WordPress\Settings\SettingsPage;
use AIHazirSite\WordPress\Uninstaller;
use WP_UnitTestCase;

/**
 * The rule goes first in WordPress's .htaccess block and out again; the screen shows it only on LiteSpeed.
 *
 * @covers \AIHazirSite\WordPress\Integrations\LiteSpeedServerBypass
 * @covers \AIHazirSite\WordPress\Integrations\IntegrationsPage
 * @covers \AIHazirSite\WordPress\Integrations\IntegrationsModule
 */
final class LiteSpeedServerTest extends WP_UnitTestCase {

	/**
	 * Pretty permalinks (WordPress writes rewrite rules only then), feature off, filter clean.
	 */
	public function set_up(): void {
		parent::set_up();
		$this->set_permalink_structure( '/%postname%/' );
		delete_option( Features::OPTION );
		delete_option( LiteSpeedServerBypass::OPTION );
		remove_all_filters( 'mod_rewrite_rules' );
		remove_all_filters( 'aihs_is_litespeed_server' );
	}

	/**
	 * Clean-up.
	 */
	public function tear_down(): void {
		remove_all_filters( 'mod_rewrite_rules' );
		remove_all_filters( 'aihs_is_litespeed_server' );
		unset( $_REQUEST['_wpnonce'] );
		parent::tear_down();
	}

	/**
	 * WordPress's rewrite block.
	 */
	private static function block(): string {
		global $wp_rewrite;
		return (string) $wp_rewrite->mod_rewrite_rules();
	}

	/**
	 * On: our rule is the first thing in the block, before WordPress's own rules; off: gone.
	 */
	public function test_rule_first_in_block_and_removed(): void {
		$this->assertStringNotContainsString( UserAgentRewriteRule::FIRST_LINE, self::block() );

		IntegrationsModule::set_server_bypass( true );
		$block = self::block();
		$this->assertStringStartsWith( UserAgentRewriteRule::FIRST_LINE, $block );
		$this->assertLessThan( strpos( $block, 'RewriteRule ^index\.php$' ), strpos( $block, 'E=Cache-Control:no-cache' ) );
		$this->assertTrue( Features::is_enabled( Features::LITESPEED_SERVER_BYPASS ) );
		$this->assertNotNull( LiteSpeedServerBypass::last() );

		IntegrationsModule::set_server_bypass( false );
		$this->assertStringNotContainsString( UserAgentRewriteRule::FIRST_LINE, self::block() );
		$this->assertFalse( Features::is_enabled( Features::LITESPEED_SERVER_BYPASS ) );
	}

	/**
	 * Deactivation removes the rule but keeps the setting; activation writes the rule again (1.19.1).
	 */
	public function test_deactivation(): void {
		IntegrationsModule::set_server_bypass( true );
		( new IntegrationsModule() )->deactivate();
		$this->assertTrue( Features::is_enabled( Features::LITESPEED_SERVER_BYPASS ) );
		$this->assertStringNotContainsString( UserAgentRewriteRule::FIRST_LINE, self::block() );

		IntegrationsModule::activate();
		$this->assertStringContainsString( UserAgentRewriteRule::FIRST_LINE, self::block() );
	}

	/**
	 * The section is shown only on a LiteSpeed server; the handlers work from both screens.
	 */
	public function test_screens(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		add_filter( 'aihs_is_litespeed_server', '__return_false' );
		$this->assertStringNotContainsString( 'LiteSpeed sunucu önbelleği', IntegrationsPage::render_html() );

		remove_all_filters( 'aihs_is_litespeed_server' );
		add_filter( 'aihs_is_litespeed_server', '__return_true' );
		$html = IntegrationsPage::render_html();
		$this->assertStringContainsString( 'LiteSpeed sunucu önbelleği', $html );
		if ( defined( 'LSCWP_V' ) ) {
			// Another test in this process simulated the LiteSpeed Cache plugin: then this row is not needed.
			$this->assertStringContainsString( 'LiteSpeed Cache eklentisi kurulu', $html );
		} else {
			$this->assertStringContainsString( 'name="integration" value="litespeed_server_bypass"', $html );
		}

		$_REQUEST['_wpnonce'] = wp_create_nonce( IntegrationsPage::ACTION );
		IntegrationsPage::handle(
			array(
				'integration' => IntegrationsPage::SERVER,
				'state'       => 'on',
			)
		);
		$this->assertTrue( Features::is_enabled( Features::LITESPEED_SERVER_BYPASS ) );

		$_REQUEST['_wpnonce'] = wp_create_nonce( SettingsPage::TOGGLE );
		SettingsPage::handle_toggle(
			array(
				'feature' => Features::LITESPEED_SERVER_BYPASS,
				'state'   => 'off',
			)
		);
		$this->assertFalse( Features::is_enabled( Features::LITESPEED_SERVER_BYPASS ) );
		$this->assertStringNotContainsString( UserAgentRewriteRule::FIRST_LINE, self::block() );
	}

	/**
	 * Uninstall removes the record.
	 */
	public function test_uninstall_option(): void {
		$this->assertContains( LiteSpeedServerBypass::OPTION, Uninstaller::options() );
	}
}
