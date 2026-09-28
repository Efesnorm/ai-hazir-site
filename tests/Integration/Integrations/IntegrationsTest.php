<?php
/**
 * Integrations with page-cache and SEO plugins (1.8.0).
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\Tests\Integration\Integrations;

use AIHazirSite\Core\Features;
use AIHazirSite\Core\Integrations\BotTokens;
use AIHazirSite\WordPress\Catalog\CatalogModule;
use AIHazirSite\WordPress\Integrations\CatalogSitemap;
use AIHazirSite\WordPress\Integrations\CatalogSitemapProvider;
use AIHazirSite\WordPress\Integrations\IntegrationsModule;
use AIHazirSite\WordPress\Integrations\IntegrationsPage;
use AIHazirSite\WordPress\Integrations\LiteSpeedBypass;
use AIHazirSite\WordPress\Integrations\WpRocketBypass;
use AIHazirSite\WordPress\Uninstaller;
use WP_UnitTestCase;

require_once dirname( __DIR__, 2 ) . '/Support/wp-rocket-stubs.php';

/**
 * WP Rocket and LiteSpeed Cache are simulated through their documented hooks and functions;
 * WordPress core sitemaps are real.
 *
 * @covers \AIHazirSite\WordPress\Integrations\IntegrationsModule
 * @covers \AIHazirSite\WordPress\Integrations\IntegrationsPage
 * @covers \AIHazirSite\WordPress\Integrations\WpRocketBypass
 * @covers \AIHazirSite\WordPress\Integrations\LiteSpeedBypass
 * @covers \AIHazirSite\WordPress\Integrations\CatalogSitemap
 * @covers \AIHazirSite\WordPress\Integrations\CatalogSitemapProvider
 */
final class IntegrationsTest extends WP_UnitTestCase {

	/**
	 * LiteSpeed Cache's stored "Do Not Cache User Agents" (simulated).
	 *
	 * @var list<string>
	 */
	private static array $litespeed = array();

	/**
	 * Clean state.
	 */
	public function set_up(): void {
		parent::set_up();
		foreach ( array( Features::OPTION, LiteSpeedBypass::ADDED_OPTION, 'aihs_profile' ) as $option ) {
			delete_option( $option );
		}
		remove_all_filters( 'rocket_cache_reject_ua' );
		remove_all_filters( 'litespeed_conf' );
		remove_all_actions( 'litespeed_save_conf' );
		$GLOBALS['aihs_test_rocket_regenerated'] = 0;
		self::$litespeed                         = array( 'OwnerBot' );
	}

	/**
	 * Clears the request.
	 */
	public function tear_down(): void {
		unset( $_REQUEST['_wpnonce'] );
		parent::tear_down();
	}

	/**
	 * LiteSpeed Cache 7.2+ API, simulated.
	 */
	private static function simulate_litespeed(): void {
		if ( ! defined( 'LSCWP_V' ) ) {
			define( 'LSCWP_V', '7.2' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- LiteSpeed Cache's constant.
		}
		add_filter( 'litespeed_conf', static fn( $key ) => LiteSpeedBypass::SETTING === $key ? self::$litespeed : $key ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- LiteSpeed Cache API.
		add_action(
			'litespeed_save_conf',
			static function ( $matrix ): void { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- LiteSpeed Cache API.
				self::$litespeed = array_values( $matrix[ LiteSpeedBypass::SETTING ] );
			}
		);
	}

	/**
	 * Off by default: no hooks.
	 */
	public function test_off_by_default(): void {
		( new IntegrationsModule() )->register();
		$this->assertFalse( Features::is_enabled( Features::BOT_CACHE_BYPASS ) );
		$this->assertFalse( Features::is_enabled( Features::CATALOG_SITEMAP ) );
		$this->assertFalse( has_filter( 'rocket_cache_reject_ua', array( WpRocketBypass::class, 'reject_ua' ) ) );
		$this->assertFalse( has_filter( 'rank_math/sitemap/index', array( CatalogSitemap::class, 'index' ) ) );
	}

	/**
	 * WP Rocket: the filter adds our tokens to its list and both files are regenerated; off removes it.
	 */
	public function test_wp_rocket(): void {
		WpRocketBypass::apply( true );
		$list = apply_filters( 'rocket_cache_reject_ua', array( 'facebookexternalhit' ) ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WP Rocket filter.
		$this->assertSame( 'facebookexternalhit', $list[0] );
		$this->assertContains( 'GPTBot', $list );
		$this->assertSame( 2, $GLOBALS['aihs_test_rocket_regenerated'], 'flush_rocket_htaccess() and rocket_generate_config_file()' );

		WpRocketBypass::apply( false );
		$this->assertSame( array( 'facebookexternalhit' ), apply_filters( 'rocket_cache_reject_ua', array( 'facebookexternalhit' ) ) ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WP Rocket filter.
		$this->assertSame( 4, $GLOBALS['aihs_test_rocket_regenerated'] );
	}

	/**
	 * LiteSpeed Cache: our tokens are added and removed; the owner's entries stay, even one we also want.
	 */
	public function test_litespeed(): void {
		self::simulate_litespeed();
		self::$litespeed = array( 'OwnerBot', 'GPTBot' );
		$this->assertTrue( LiteSpeedBypass::supported() );

		LiteSpeedBypass::apply( true );
		$this->assertSame( array( 'OwnerBot', 'GPTBot' ), array_slice( self::$litespeed, 0, 2 ) );
		$this->assertContains( 'ClaudeBot', self::$litespeed );
		$this->assertCount( count( BotTokens::all() ) + 1, self::$litespeed );
		$this->assertNotContains( 'GPTBot', get_option( LiteSpeedBypass::ADDED_OPTION ), 'GPTBot was the owner\'s.' );

		LiteSpeedBypass::apply( false );
		$this->assertSame( array( 'OwnerBot', 'GPTBot' ), self::$litespeed );
		$this->assertFalse( get_option( LiteSpeedBypass::ADDED_OPTION ) );
	}

	/**
	 * The sitemap: our URLs, our own XML listed in the SEO plugins' index, the core provider.
	 */
	public function test_catalog_sitemap(): void {
		foreach ( array( Features::CATALOG, Features::LLMS_TXT ) as $feature ) {
			Features::set( $feature, true );
		}
		CatalogModule::service()->save_listing(
			array(
				'type'     => 'offer',
				'title'    => 'NYY kablo',
				'category' => 'Kablo',
			)
		);

		$urls = CatalogSitemap::urls();
		$this->assertSame( home_url( '/ai-katalog/' ), $urls[0]['loc'] );
		$this->assertMatchesRegularExpression( '/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ$/', $urls[0]['lastmod'] );

		$index = CatalogSitemap::index( '<sitemap><loc>x</loc></sitemap>' );
		$this->assertStringStartsWith( '<sitemap><loc>x</loc></sitemap>', $index );
		$this->assertStringContainsString( '<loc>' . home_url( '/ai-katalog-sitemap.xml' ) . '</loc>', $index );

		$provider = new CatalogSitemapProvider();
		$this->assertSame( 1, $provider->get_max_num_pages() );
		$this->assertSame( home_url( '/ai-katalog/' ), $provider->get_url_list( 1 )[0]['loc'] );
		$this->assertSame( array(), $provider->get_url_list( 2 ) );
		CatalogSitemap::register_provider();
		$this->assertArrayHasKey( CatalogSitemap::PROVIDER, wp_get_sitemap_providers() );

		Features::set( Features::LLMS_TXT, false );
		$this->assertSame( array(), CatalogSitemap::urls(), 'No catalog page, nothing to list.' );
		$this->assertSame( 'x', CatalogSitemap::index( 'x' ) );
	}

	/**
	 * The page: capability and nonce, on and off, found plugins shown.
	 */
	public function test_page(): void {
		self::simulate_litespeed();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$html = IntegrationsPage::render_html();
		$this->assertStringContainsString( 'LiteSpeed Cache', $html );
		$this->assertStringContainsString( 'name="integration" value="bot_cache_bypass"', $html );

		$_REQUEST['_wpnonce'] = wp_create_nonce( IntegrationsPage::ACTION );
		$redirect             = IntegrationsPage::handle(
			array(
				'integration' => 'bot_cache_bypass',
				'state'       => 'on',
			)
		);
		$this->assertStringContainsString( 'message=on', $redirect );
		$this->assertTrue( Features::is_enabled( Features::BOT_CACHE_BYPASS ) );
		$this->assertContains( 'GPTBot', self::$litespeed );

		( new IntegrationsModule() )->deactivate();
		$this->assertFalse( Features::is_enabled( Features::BOT_CACHE_BYPASS ), 'Deactivation turns it off...' );
		$this->assertSame( array( 'OwnerBot' ), self::$litespeed, '...and reverts LiteSpeed Cache.' );

		IntegrationsPage::handle(
			array(
				'integration' => 'catalog_sitemap',
				'state'       => 'on',
			)
		);
		$this->assertTrue( Features::is_enabled( Features::CATALOG_SITEMAP ) );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$this->expectException( \WPDieException::class );
		IntegrationsPage::handle(
			array(
				'integration' => 'catalog_sitemap',
				'state'       => 'off',
			)
		);
	}

	/**
	 * Unknown integration ids are refused.
	 */
	public function test_unknown_integration(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$_REQUEST['_wpnonce'] = wp_create_nonce( IntegrationsPage::ACTION );
		$this->expectException( \WPDieException::class );
		IntegrationsPage::handle( array( 'integration' => 'x' ) );
	}

	/**
	 * Uninstall removes our record of LiteSpeed entries.
	 */
	public function test_uninstall_option(): void {
		$this->assertContains( LiteSpeedBypass::ADDED_OPTION, Uninstaller::options() );
	}
}
