<?php
/**
 * A10 acceptance: canary rollout, rollback, no data without consent.
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\Tests\Integration\Updates;

use AIHazirSite\Core\Contracts\PackageInstaller;
use AIHazirSite\Core\Features;
use AIHazirSite\Core\Migrations\Migrator;
use AIHazirSite\Core\Telemetry\TelemetryService;
use AIHazirSite\Core\Telemetry\TelemetrySummary;
use AIHazirSite\Core\Updates\CanaryPolicy;
use AIHazirSite\Tests\Support\MovableClock;
use AIHazirSite\WordPress\Inquiry\WpInquiryRepository;
use AIHazirSite\WordPress\Lifecycle;
use AIHazirSite\WordPress\Platform\WpSettings;
use AIHazirSite\WordPress\Plugin;
use AIHazirSite\WordPress\Storage\WpdbHitRepository;
use AIHazirSite\WordPress\Updates\UpdateModule;
use AIHazirSite\WordPress\Updates\UpdatesAdmin;
use AIHazirSite\WordPress\Uninstaller;
use WP_UnitTestCase;
use WPDieException;

/**
 * WordPress side of A10 with every outgoing HTTP request intercepted and recorded.
 *
 * @covers \AIHazirSite\WordPress\Updates\UpdateModule
 * @covers \AIHazirSite\WordPress\Updates\UpdatesAdmin
 * @covers \AIHazirSite\WordPress\Platform\WpHttpPoster
 */
final class UpdatesIntegrationTest extends WP_UnitTestCase {

	private const SERVER = 'https://guncelleme.example/ai-hazir-site.json';
	private const PANEL  = 'https://panel.example/ozet';

	/**
	 * Outgoing requests: [method, url, body].
	 *
	 * @var list<array{0: string, 1: string, 2: string}>
	 */
	private array $requests = array();

	/**
	 * Clock.
	 *
	 * @var MovableClock
	 */
	private MovableClock $clock;

	/**
	 * Clean settings, recorded HTTP, fake clock, an admin.
	 */
	public function set_up(): void {
		parent::set_up();
		foreach ( array( Features::OPTION, UpdateModule::SETTINGS, TelemetryService::OPTION ) as $option ) {
			delete_option( $option );
		}
		delete_site_transient( UpdateModule::MANIFEST_CACHE );
		wp_clear_scheduled_hook( UpdateModule::TELEMETRY_HOOK );
		$this->clock = new MovableClock( '2026-10-01T10:00:00Z' );
		UpdateModule::use_clock( $this->clock );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$requests = &$this->requests;
		add_filter(
			'pre_http_request',
			static function ( $preempt, array $args, string $url ) use ( &$requests ) {
				$requests[] = array( (string) ( $args['method'] ?? 'GET' ), $url, is_string( $args['body'] ?? null ) ? $args['body'] : '' );
				$body       = self::SERVER === $url ? self::manifest() : '';
				return array(
					'headers'  => array(),
					'body'     => $body,
					'response' => array(
						'code'    => 200,
						'message' => 'OK',
					),
					'cookies'  => array(),
					'filename' => null,
				);
			},
			10,
			3
		);
	}

	/**
	 * Real clock back; schema at the latest version.
	 */
	public function tear_down(): void {
		UpdateModule::use_clock( null );
		unset( $_REQUEST['_wpnonce'] );
		( new Migrator( Plugin::migrations(), new WpSettings() ) )->migrate();
		wp_clear_scheduled_hook( UpdateModule::TELEMETRY_HOOK );
		parent::tear_down();
	}

	/**
	 * The update server's manifest: the previous release (db 200) and a new one released now.
	 */
	private static function manifest(): string {
		return (string) wp_json_encode(
			array(
				'slug'     => 'ai-hazir-site',
				'releases' => array(
					array(
						'version'      => '0.2.0',
						'download_url' => 'https://guncelleme.example/ai-hazir-site-0.2.0.zip',
						'released_at'  => '2026-09-27T00:00:00Z',
						'db_version'   => 200,
					),
					array(
						'version'      => '9.0.0',
						'download_url' => 'https://guncelleme.example/ai-hazir-site-9.0.0.zip',
						'released_at'  => '2026-10-01T10:00:00Z',
						'db_version'   => 1200,
						'tested'       => '7.1',
						'sections'     => array( 'changelog' => '<p>Yeni sürüm</p>' ),
					),
				),
			)
		);
	}

	/**
	 * Our entry in the update transient after a check.
	 */
	private static function check(): ?object {
		$transient = UpdateModule::inject_update(
			(object) array(
				'response'  => array(),
				'no_update' => array(),
			)
		);
		return $transient->response[ plugin_basename( AIHS_FILE ) ] ?? null;
	}

	/**
	 * A site setting.
	 *
	 * @param string $channel Channel.
	 */
	private static function site( string $channel ): void {
		update_option(
			UpdateModule::SETTINGS,
			array(
				'server'        => self::SERVER,
				'channel'       => $channel,
				'telemetry_url' => '',
			)
		);
		delete_site_transient( UpdateModule::MANIFEST_CACHE );
	}

	/**
	 * Pilot sites get the release at once; general sites 48 hours later.
	 */
	public function test_canary(): void {
		Features::set( Features::REMOTE_UPDATES, true );

		self::site( CanaryPolicy::PILOT );
		$pilot = self::check();
		$this->assertNotNull( $pilot );
		$this->assertSame( '9.0.0', $pilot->new_version );
		$this->assertSame( 'https://guncelleme.example/ai-hazir-site-9.0.0.zip', $pilot->package );

		self::site( CanaryPolicy::GENERAL );
		$this->assertNull( self::check(), 'General: not yet.' );
		$this->clock->advance( 48 * 3600 - 1 );
		$this->assertNull( self::check() );
		$this->clock->advance( 1 );
		$this->assertSame( '9.0.0', self::check()?->new_version, 'General: after 48 hours.' );

		$info = UpdateModule::plugin_information( false, 'plugin_information', (object) array( 'slug' => 'ai-hazir-site' ) );
		$this->assertIsObject( $info );
		$this->assertSame( '<p>Yeni sürüm</p>', $info->sections['changelog'] );
		$this->assertFalse( UpdateModule::plugin_information( false, 'plugin_information', (object) array( 'slug' => 'baska' ) ) );

		// The manifest is fetched once per site (site() starts each simulated site with an empty cache).
		$this->assertSame( 2, count( array_filter( $this->requests, static fn( array $r ): bool => self::SERVER === $r[1] ) ) );
	}

	/**
	 * Without a server address nothing is requested.
	 */
	public function test_no_server_no_request(): void {
		Features::set( Features::REMOTE_UPDATES, true );
		$this->assertNull( self::check() );
		$this->assertSame( array(), UpdateModule::releases() );
		update_option( UpdateModule::SETTINGS, array( 'server' => 'http://guvensiz.example/x.json' ) );
		$this->assertNull( self::check() );
		$this->assertSame( array(), $this->requests );
	}

	/**
	 * Rollback: schema back to the previous release (inquiry tables gone), earlier data intact,
	 * the previous package installed; the admin handler needs the nonce.
	 */
	public function test_rollback(): void {
		global $wpdb;
		remove_filter( 'query', array( $this, '_create_temporary_tables' ) );
		remove_filter( 'query', array( $this, '_drop_temporary_tables' ) );
		( new Migrator( Plugin::migrations(), new WpSettings() ) )->migrate();
		Features::set( Features::REMOTE_UPDATES, true );
		self::site( CanaryPolicy::GENERAL );

		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i', WpdbHitRepository::table() ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		( new WpdbHitRepository() )->increment( '2026-09-30', 'bot', 'gptbot', '/urunler/', true );
		update_option( 'blogname', 'Örnek Kablo' );

		$installed = array();
		$installer = new class( $installed ) implements PackageInstaller {
			/**
			 * Constructor.
			 *
			 * @param array<int, string> $installed Installed URLs.
			 */
			public function __construct( private array &$installed ) {
			}

			/**
			 * Records.
			 *
			 * @param string $package_url URL.
			 */
			public function install( string $package_url ): string {
				$this->installed[] = $package_url;
				return '';
			}
		};
		add_filter( 'aihs_package_installer', static fn() => $installer );

		$_REQUEST['_wpnonce'] = wp_create_nonce( UpdateModule::ROLLBACK );
		$this->assertStringContainsString( 'message=rolled_back', UpdatesAdmin::handle_rollback() );
		$this->assertSame( array( 'https://guncelleme.example/ai-hazir-site-0.2.0.zip' ), $installed );
		$this->assertSame( 200, ( new Migrator( Plugin::migrations(), new WpSettings() ) )->current_version(), 'Schema of the previous release.' );
		$this->assertNull( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', WpInquiryRepository::table() ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$this->assertSame( '1', (string) $wpdb->get_var( $wpdb->prepare( 'SELECT hits FROM %i WHERE source_id = %s', WpdbHitRepository::table(), 'gptbot' ) ), 'Data of the previous release intact.' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$this->assertSame( 'Örnek Kablo', get_option( 'blogname' ) );

		// If the old package had not been installed, the newer code migrates forward again on its next load.
		Lifecycle::maybe_upgrade();
		$this->assertSame( WpInquiryRepository::table(), $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', WpInquiryRepository::table() ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		unset( $_REQUEST['_wpnonce'] );
		$this->expectException( WPDieException::class );
		UpdatesAdmin::handle_rollback();
	}

	/**
	 * Nothing is sent without consent; with consent exactly the listed totals; revoking stops it.
	 */
	public function test_no_data_without_consent(): void {
		Features::set( Features::TELEMETRY, true );
		update_option( UpdateModule::SETTINGS, array( 'telemetry_url' => self::PANEL ) );

		UpdateModule::schedule();
		$this->assertFalse( wp_next_scheduled( UpdateModule::TELEMETRY_HOOK ), 'No consent: not scheduled.' );
		do_action( UpdateModule::TELEMETRY_HOOK ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals -- Our own hook, fired as WP-Cron would.
		$this->assertFalse( UpdateModule::send_telemetry() );
		$this->assertSame( array(), $this->requests, 'Nothing left the site.' );

		// The consent screen shows exactly what would be sent.
		$page = UpdatesAdmin::page();
		$this->assertStringContainsString( 'id="aihs-telemetry-preview"', $page );
		foreach ( TelemetrySummary::FIELDS as $field ) {
			$this->assertStringContainsString( $field, $page );
		}

		$_REQUEST['_wpnonce'] = wp_create_nonce( UpdateModule::CONSENT );
		$this->assertStringContainsString( 'message=consented', UpdatesAdmin::handle_consent( array( 'consent' => 'give' ) ) );
		$this->assertNotFalse( wp_next_scheduled( UpdateModule::TELEMETRY_HOOK ) );
		$this->assertTrue( UpdateModule::send_telemetry() );
		$this->assertCount( 1, $this->requests );
		[ $method, $url, $body ] = $this->requests[0];
		$this->assertSame( array( 'POST', self::PANEL ), array( $method, $url ) );
		$this->assertSame( array_merge( array( 'site_id', 'sent_at' ), TelemetrySummary::FIELDS ), array_keys( (array) json_decode( $body, true ) ) );
		$this->assertStringNotContainsString( (string) wp_parse_url( home_url(), PHP_URL_HOST ), $body, 'The site address is not sent.' );

		$this->assertStringContainsString( 'message=revoked', UpdatesAdmin::handle_consent( array( 'consent' => 'revoke' ) ) );
		$this->assertFalse( wp_next_scheduled( UpdateModule::TELEMETRY_HOOK ) );
		$this->assertFalse( UpdateModule::send_telemetry() );
		$this->assertCount( 1, $this->requests, 'Nothing more after revoking.' );

		$this->assertContains( TelemetryService::OPTION, Uninstaller::options() );
		$this->assertContains( UpdateModule::SETTINGS, Uninstaller::options() );
	}

	/**
	 * Settings form: https addresses only; nonce required.
	 */
	public function test_settings_form(): void {
		Features::set( Features::REMOTE_UPDATES, true );
		$_REQUEST['_wpnonce'] = wp_create_nonce( UpdateModule::SAVE );
		UpdatesAdmin::handle_save(
			array(
				'server'        => 'http://guvensiz.example/x.json',
				'channel'       => 'pilot',
				'telemetry_url' => 'https://panel.example/ozet',
			)
		);
		$this->assertSame(
			array(
				'server'        => '',
				'channel'       => 'pilot',
				'telemetry_url' => 'https://panel.example/ozet',
			),
			UpdateModule::settings()
		);
		UpdatesAdmin::handle_save( array( 'channel' => 'bilinmeyen' ) );
		$this->assertSame( CanaryPolicy::GENERAL, UpdateModule::settings()['channel'] );

		unset( $_REQUEST['_wpnonce'] );
		$this->expectException( WPDieException::class );
		UpdatesAdmin::handle_save( array() );
	}
}
