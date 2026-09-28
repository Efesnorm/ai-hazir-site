<?php
/**
 * Announcing our AI resources on the site's pages (1.7.0).
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\Tests\Integration\Discovery;

use AIHazirSite\Adapters\Discovery\DiscoveryLinks;
use AIHazirSite\Core\Features;
use AIHazirSite\WordPress\Discovery\DiscoveryModule;
use AIHazirSite\WordPress\Rest\RestModule;
use AIHazirSite\WordPress\Uninstaller;
use WP_UnitTestCase;

/**
 * Feature key, links per resource, visible line, settings form, uninstall.
 *
 * @covers \AIHazirSite\WordPress\Discovery\DiscoveryModule
 */
final class DiscoveryTest extends WP_UnitTestCase {

	/**
	 * Clean state: our resources on, discovery off.
	 */
	public function set_up(): void {
		parent::set_up();
		delete_option( Features::OPTION );
		delete_option( DiscoveryModule::VISIBLE_OPTION );
		foreach ( array( Features::LLMS_TXT, Features::REST_API, Features::SCHEMA_OUTPUT ) as $feature ) {
			Features::set( $feature, true );
		}
		remove_all_actions( 'wp_head' );
		remove_all_actions( 'wp_footer' );
		remove_all_actions( 'template_redirect' );
	}

	/**
	 * Clears the request.
	 */
	public function tear_down(): void {
		unset( $_REQUEST['_wpnonce'] );
		parent::tear_down();
	}

	/**
	 * Output of an action.
	 *
	 * @param string $hook Hook.
	 */
	private static function output( string $hook ): string {
		ob_start();
		do_action( $hook ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- Core hooks (wp_head, wp_footer).
		return (string) ob_get_clean();
	}

	/**
	 * Off by default: no links, no line, no header hook.
	 */
	public function test_off_by_default(): void {
		$this->assertFalse( Features::is_enabled( Features::DISCOVERY ) );
		( new DiscoveryModule() )->register();
		update_option( DiscoveryModule::VISIBLE_OPTION, 1 );

		$this->assertStringNotContainsString( 'describedby', self::output( 'wp_head' ) );
		$this->assertSame( '', self::output( 'wp_footer' ) );
		$this->assertFalse( has_action( 'template_redirect', array( DiscoveryModule::class, 'send_header' ) ) );
	}

	/**
	 * On: <link> elements for the resources that are on; the header value matches.
	 */
	public function test_links_on_pages(): void {
		Features::set( Features::DISCOVERY, true );
		( new DiscoveryModule() )->register();
		$this->go_to( home_url( '/' ) );

		$head = self::output( 'wp_head' );
		$this->assertStringContainsString( '<link rel="describedby" type="text/markdown" href="' . home_url( '/llms.txt' ) . '"', $head );
		$this->assertStringContainsString( '<link rel="service-desc" type="application/json" href="' . RestModule::url() . '"', $head );
		$this->assertSame( '<' . home_url( '/llms.txt' ) . '>; rel="describedby"; type="text/markdown", <' . RestModule::url() . '>; rel="service-desc"; type="application/json"', DiscoveryLinks::header( DiscoveryModule::links() ) );
		$this->assertNotFalse( has_action( 'template_redirect', array( DiscoveryModule::class, 'send_header' ) ) );

		Features::set( Features::REST_API, false );
		$this->assertSame( array( 'describedby' ), array_column( DiscoveryModule::links(), 'rel' ) );
		Features::set( Features::LLMS_TXT, false );
		$this->assertSame( array(), DiscoveryModule::links() );
		$this->assertSame( '', self::output( 'wp_head' ) );
	}

	/**
	 * The visible line only when the site owner turned it on.
	 */
	public function test_visible_line(): void {
		Features::set( Features::DISCOVERY, true );
		( new DiscoveryModule() )->register();
		$this->assertSame( '', self::output( 'wp_footer' ) );

		update_option( DiscoveryModule::VISIBLE_OPTION, 1 );
		$line = self::output( 'wp_footer' );
		$this->assertStringContainsString( 'AI asistanları için:', $line );
		$this->assertStringContainsString( 'href="' . home_url( '/ai-katalog/' ) . '">AI Katalog</a>', $line );
		$this->assertStringContainsString( 'href="' . home_url( '/llms.txt' ) . '">llms.txt</a>', $line );
	}

	/**
	 * Settings form: capability and nonce, both settings saved.
	 */
	public function test_settings_form(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$this->assertStringContainsString( 'name="announce"', DiscoveryModule::settings_html() );

		$_REQUEST['_wpnonce'] = wp_create_nonce( DiscoveryModule::SAVE );
		DiscoveryModule::handle_save(
			array(
				'announce' => '1',
				'visible'  => '1',
			)
		);
		$this->assertTrue( Features::is_enabled( Features::DISCOVERY ) );
		$this->assertSame( 1, (int) get_option( DiscoveryModule::VISIBLE_OPTION ) );

		DiscoveryModule::handle_save( array() );
		$this->assertFalse( Features::is_enabled( Features::DISCOVERY ) );
		$this->assertSame( 0, (int) get_option( DiscoveryModule::VISIBLE_OPTION ) );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$this->expectException( \WPDieException::class );
		DiscoveryModule::handle_save( array( 'announce' => '1' ) );
	}

	/**
	 * Uninstall removes the visible-line setting.
	 */
	public function test_uninstall_option(): void {
		$this->assertContains( DiscoveryModule::VISIBLE_OPTION, Uninstaller::options() );
	}
}
