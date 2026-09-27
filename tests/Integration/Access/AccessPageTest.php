<?php
/**
 * Tools → AI Bot Erişimi page.
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\Tests\Integration\Access;

use AIHazirSite\Core\Access\Presets;
use AIHazirSite\Core\Access\RobotsRules;
use AIHazirSite\Core\Features;
use AIHazirSite\Core\Measurement\Registry;
use AIHazirSite\WordPress\Access\AccessModule;
use AIHazirSite\WordPress\Access\Admin\AccessPage;
use DOMDocument;
use DOMXPath;
use WP_UnitTestCase;
use WPDieException;

/**
 * Access page integration tests.
 *
 * @covers \AIHazirSite\WordPress\Access\Admin\AccessPage
 */
final class AccessPageTest extends WP_UnitTestCase {

	/**
	 * Physical robots.txt created by a test.
	 *
	 * @var string|null
	 */
	private ?string $physical = null;

	/**
	 * Admin with the feature on and the filter registered.
	 */
	public function set_up(): void {
		parent::set_up();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		delete_option( 'aihs_bot_policy' );
		Features::set( Features::BOT_ACCESS, true );
		remove_filter( 'robots_txt', array( AccessModule::class, 'filter_robots' ), AccessModule::FILTER_PRIORITY );
		add_filter( 'robots_txt', array( AccessModule::class, 'filter_robots' ), AccessModule::FILTER_PRIORITY );
	}

	/**
	 * Cleanup.
	 */
	public function tear_down(): void {
		unset( $_REQUEST['_wpnonce'] );
		if ( null !== $this->physical && file_exists( $this->physical ) ) {
			unlink( $this->physical ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
		}
		update_option( 'blog_public', '1' );
		parent::tear_down();
	}

	/**
	 * Parsed page.
	 */
	private static function page(): DOMXPath {
		$dom = new DOMDocument();
		$dom->loadHTML( '<?xml encoding="utf-8"?><body>' . AccessPage::render_content() . '</body>', LIBXML_NOERROR );
		return new DOMXPath( $dom );
	}

	/**
	 * Actual state column per bot.
	 *
	 * @return array<string, string>
	 */
	private static function states(): array {
		$states = array();
		foreach ( self::page()->query( '//table[@id="aihs-bots"]/tbody/tr' ) as $tr ) {
			$states[ $tr->getAttribute( 'data-bot' ) ] = trim( $tr->lastChild->textContent ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		}
		return $states;
	}

	/**
	 * A preset button saves the preset; the page then shows the new actual state and preview.
	 */
	public function test_preset_and_state(): void {
		$_REQUEST['_wpnonce'] = wp_create_nonce( AccessPage::ACTION );

		$redirect = ( new AccessPage() )->handle_save( array( 'preset' => Presets::BLOCK_TRAINING ) );

		$this->assertStringContainsString( 'updated=1', $redirect );
		$states = self::states();
		$this->assertCount( count( Registry::bots() ), $states );
		$this->assertSame( 'Engelli', $states['gptbot'] );
		$this->assertSame( 'İzinli', $states['perplexitybot'] );
		$preview = self::page()->query( '//textarea[@id="aihs-preview"]' )->item( 0 )->textContent; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		$this->assertStringContainsString( RobotsRules::BEGIN, $preview );
		$this->assertStringContainsString( "User-agent: *\nDisallow: /wp-admin/", $preview );
	}

	/**
	 * Per-bot choices are saved; unknown bots and modes are ignored.
	 */
	public function test_per_bot_choices(): void {
		$_REQUEST['_wpnonce'] = wp_create_nonce( AccessPage::ACTION );

		( new AccessPage() )->handle_save(
			array(
				'modes' => array(
					'claudebot' => 'disallow',
					'ccbot'     => 'allow',
					'gptbot'    => 'default',
					'uydurma'   => 'disallow',
					'amazonbot' => '<script>',
				),
			)
		);

		$this->assertSame(
			array(
				'claudebot' => 'disallow',
				'ccbot'     => 'allow',
			),
			AccessModule::store()->get()->to_array()
		);
		$selected = self::page()->query( '//select[@name="modes[claudebot]"]/option[@selected]' )->item( 0 );
		$this->assertSame( 'disallow', $selected?->getAttribute( 'value' ) );
		$this->assertSame( 'Engelli', self::states()['claudebot'] );
	}

	/**
	 * Unauthorized users and missing nonces are rejected.
	 */
	public function test_requires_capability_and_nonce(): void {
		try {
			( new AccessPage() )->handle_save( array( 'preset' => Presets::ALLOW_ALL ) );
			$this->fail( 'Missing nonce must be rejected.' );
		} catch ( WPDieException $e ) {
			$this->assertSame( array(), AccessModule::store()->get()->to_array() );
		}

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$_REQUEST['_wpnonce'] = wp_create_nonce( AccessPage::ACTION );
		foreach ( array( fn() => ( new AccessPage() )->handle_save( array( 'preset' => Presets::ALLOW_ALL ) ), fn() => ( new AccessPage() )->render() ) as $call ) {
			try {
				$call();
				$this->fail( 'Editor must be rejected.' );
			} catch ( WPDieException $e ) {
				$this->assertStringContainsString( 'yetki', $e->getMessage() );
			}
		}
		$this->assertSame( array(), AccessModule::store()->get()->to_array() );
	}

	/**
	 * With a physical file: warning plus the block to copy; the file itself is untouched.
	 */
	public function test_physical_file_warning(): void {
		$path = trailingslashit( get_home_path() ) . 'robots.txt';
		if ( file_exists( $path ) ) {
			$this->markTestSkipped( 'A robots.txt already exists in the site root.' );
		}
		$content        = "User-agent: *\nDisallow: /\n";
		$this->physical = $path;
		file_put_contents( $path, $content ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents

		$_REQUEST['_wpnonce'] = wp_create_nonce( AccessPage::ACTION );
		( new AccessPage() )->handle_save( array( 'preset' => Presets::BLOCK_TRAINING ) );

		$page = self::page();
		$this->assertSame( 1, $page->query( '//*[@id="aihs-physical"]' )->length );
		$copy = $page->query( '//textarea[@id="aihs-copy"]' )->item( 0 )->textContent; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		$this->assertStringStartsWith( RobotsRules::BEGIN, $copy );
		$this->assertStringContainsString( "User-agent: GPTBot\nDisallow: /", $copy );
		$this->assertSame( 'Engelli', self::states()['perplexitybot'], 'State comes from the physical file.' );
		$this->assertSame( $content, file_get_contents( $path ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
	}

	/**
	 * "Discourage search engines" shows a warning.
	 */
	public function test_not_public_warning(): void {
		$this->assertSame( 0, self::page()->query( '//*[@id="aihs-not-public"]' )->length );
		update_option( 'blog_public', '0' );
		$this->assertSame( 1, self::page()->query( '//*[@id="aihs-not-public"]' )->length );
	}
}
