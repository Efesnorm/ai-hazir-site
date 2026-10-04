<?php
/**
 * Security software screen and actions (1.21.0).
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\Tests\Integration\Integrations;

use AIHazirSite\Core\Features;
use AIHazirSite\Core\Network\NetworkCheck;
use AIHazirSite\Core\Security\WpConfigBlock;
use AIHazirSite\WordPress\Integrations\IntegrationsPage;
use AIHazirSite\WordPress\Integrations\SecuritySoftware;
use AIHazirSite\WordPress\Network\NetworkModule;
use AIHazirSite\WordPress\Uninstaller;
use WP_UnitTestCase;

/**
 * On a copy of a wp-config.php; Wordfence replaced by a class with its public function's signature.
 *
 * @covers \AIHazirSite\WordPress\Integrations\SecuritySoftware
 * @covers \AIHazirSite\WordPress\Integrations\IntegrationsPage
 */
final class SecuritySoftwareTest extends WP_UnitTestCase {

	private const CONFIG = "<?php\ndefine( 'DB_NAME', 'wp' );\nif ( ! defined( 'ABSPATH' ) ) {\n\tdefine( 'ABSPATH', __DIR__ . '/' );\n}\nrequire_once ABSPATH . 'wp-settings.php';\n";

	/**
	 * Temporary wp-config.php copy.
	 *
	 * @var string
	 */
	private string $config;

	/**
	 * Feature on; administrator; a writable config copy.
	 */
	public function set_up(): void {
		parent::set_up();
		foreach ( array( Features::OPTION, SecuritySoftware::OPTION, NetworkModule::OPTION, NetworkModule::STATE ) as $option ) {
			delete_option( $option );
		}
		Features::set( Features::SECURITY_INTEGRATIONS, true );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$this->config = get_temp_dir() . 'aihs-wp-config-' . wp_generate_password( 6, false ) . '.php';
		file_put_contents( $this->config, self::CONFIG ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test fixture.
		$config = $this->config;
		add_filter( 'aihs_wp_config_path', static fn(): string => $config );
		update_option( 'active_plugins', array( 'imunify-wp-security/imunify-wp-security.php' ) );
	}

	/**
	 * Removes the copy.
	 */
	public function tear_down(): void {
		wp_delete_file( $this->config );
		unset( $_SERVER['HTTP_CF_RAY'] );
		parent::tear_down();
	}

	/**
	 * The file content now.
	 */
	private function config(): string {
		return (string) file_get_contents( $this->config ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Test fixture.
	}

	/**
	 * Lock writes our one line, the file stays valid PHP; unlock restores the exact original; no copy left behind.
	 */
	public function test_imunify_lock_and_unlock(): void {
		$this->assertSame( '', SecuritySoftware::set_imunify_lock( true ) );
		$this->assertStringContainsString( SecuritySoftware::imunify_line(), $this->config() );
		$this->assertStringNotContainsString( "'monitor'", $this->config() );
		$this->assertTrue( WpConfigBlock::valid( $this->config() ) );
		$this->assertTrue( SecuritySoftware::state()['imunify_locked'] );
		$this->assertSame( array(), glob( dirname( $this->config ) . '/wp-config-aihs-*.php' ), 'No temporary file left.' );

		$this->assertSame( '', SecuritySoftware::set_imunify_lock( false ) );
		$this->assertSame( self::CONFIG, $this->config() );
		$this->assertFalse( SecuritySoftware::state()['imunify_locked'] );
	}

	/**
	 * The site's own constant wins: nothing written, the reason and the manual line are shown.
	 */
	public function test_own_constant_is_respected(): void {
		$own = str_replace( "<?php\n", "<?php\ndefine( 'IMUNIFY_AI_BOT_PROTECTION_PRESET', 'strict' );\n", self::CONFIG );
		file_put_contents( $this->config, $own ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test fixture.

		$this->assertNotSame( '', SecuritySoftware::set_imunify_lock( true ) );
		$this->assertSame( $own, $this->config() );
		$this->assertStringContainsString( esc_html( SecuritySoftware::imunify_line() ), IntegrationsPage::render_html() );
	}

	/**
	 * Turning the feature off and uninstalling remove our block (uninstall even without the data opt-in).
	 */
	public function test_off_and_uninstall_revert(): void {
		SecuritySoftware::set_imunify_lock( true );
		SecuritySoftware::enable( false );
		$this->assertSame( self::CONFIG, $this->config() );
		$this->assertFalse( Features::is_enabled( Features::SECURITY_INTEGRATIONS ) );

		Features::set( Features::SECURITY_INTEGRATIONS, true );
		SecuritySoftware::set_imunify_lock( true );
		delete_option( Uninstaller::DELETE_OPTION );
		Uninstaller::run();
		$this->assertSame( self::CONFIG, $this->config() );
	}

	/**
	 * Wordfence: only the verified network sites' observed IPv4 addresses, through its public function; recorded.
	 */
	public function test_wordfence_allow_list(): void {
		if ( ! class_exists( 'wordfence' ) ) {
			eval( 'class wordfence { public static array $ips = array(); public static function whitelistIP( $ip ) { if ( in_array( $ip, self::$ips, true ) ) { return false; } self::$ips[] = $ip; return true; } }' ); // phpcs:ignore Squiz.PHP.Eval.Discouraged -- Stand-in with Wordfence's public signature.
		}
		add_filter( 'home_url', static fn( string $url ): string => (string) preg_replace( '#^https?://[^/]+#', 'https://anne.example', $url ) );
		Features::set( Features::PORTAL_NETWORK, true );
		Features::set( Features::REST_API, true );
		update_option(
			NetworkModule::OPTION,
			array(
				'role'    => 'mother',
				'name'    => 'Ağ',
				'mother'  => 'https://anne.example/',
				'members' => array( 'https://uye.example/', 'https://yabanci.example/' ),
			)
		);
		update_option(
			NetworkModule::STATE,
			array(
				'members' => array(
					'https://uye.example/'     => array(
						'status'      => NetworkCheck::VERIFIED,
						'verified_at' => time(),
					),
					'https://yabanci.example/' => array( 'status' => NetworkCheck::OTHER_MOTHER ),
				),
				'seen'    => array(
					'https://uye.example/'     => '198.51.100.7',
					'https://yabanci.example/' => '198.51.100.8',
				),
			)
		);

		$this->assertSame( array( 'https://uye.example/' => '198.51.100.7' ), SecuritySoftware::network_ips() );
		$this->assertSame( array( '198.51.100.7' ), SecuritySoftware::allow_network_in_wordfence() );
		$this->assertSame( array(), SecuritySoftware::allow_network_in_wordfence(), 'Already listed.' );
		$this->assertSame( array( '198.51.100.7' ), SecuritySoftware::state()['wordfence'] );

		$html = IntegrationsPage::render_html();
		$this->assertStringContainsString( '<h3>Wordfence</h3>', $html );
		$this->assertStringContainsString( '198.51.100.7', $html );
		$this->assertStringContainsString( '198.51.100.7 (https://uye.example/)', SecuritySoftware::host_text() );
	}

	/**
	 * Screen: only detected software; Cloudflare by its headers; the host text lists the official AI IP lists; the
	 * section is absent while the feature is off; actions need the feature, the capability and the nonce.
	 */
	public function test_screen(): void {
		$_SERVER['HTTP_CF_RAY'] = '8a1b2c3d4e5f-FRA';
		$html                   = IntegrationsPage::render_html();
		$this->assertStringContainsString( 'id="aihs-security"', $html );
		$this->assertStringContainsString( 'Imunify Security', $html );
		$this->assertStringContainsString( '<h3>Cloudflare</h3>', $html );
		$this->assertStringContainsString( 'https://openai.com/gptbot.json', SecuritySoftware::host_text() );

		$_REQUEST['_wpnonce'] = wp_create_nonce( IntegrationsPage::ACTION );
		$redirect             = IntegrationsPage::handle(
			array(
				'integration' => IntegrationsPage::IMUNIFY,
				'state'       => 'on',
			)
		);
		$this->assertStringContainsString( 'message=on', $redirect );
		$this->assertTrue( WpConfigBlock::present( $this->config() ) );

		Features::set( Features::SECURITY_INTEGRATIONS, false );
		$this->assertStringNotContainsString( 'id="aihs-security"', IntegrationsPage::render_html() );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$this->expectException( \WPDieException::class );
		IntegrationsPage::handle(
			array(
				'integration' => IntegrationsPage::IMUNIFY,
				'state'       => 'off',
			)
		);
	}
}
