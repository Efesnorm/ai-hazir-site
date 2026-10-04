<?php
/**
 * Network report end to end (1.24.0).
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\Tests\Integration\Network;

use AIHazirSite\Core\Features;
use AIHazirSite\Core\Network\NetworkReport;
use AIHazirSite\Core\Network\NetworkSettings;
use AIHazirSite\WordPress\Network\NetworkModule;
use AIHazirSite\WordPress\Network\NetworkReportModule;
use AIHazirSite\WordPress\Network\NetworkReportPage;
use AIHazirSite\WordPress\Uninstaller;
use WP_Application_Passwords;
use WP_REST_Request;
use WP_UnitTestCase;
use WP_User;
use WPDieException;

/**
 * Member: key, authenticated endpoint. Mother: encrypted keys, daily reading of fake members, screen, CSV.
 *
 * @covers \AIHazirSite\WordPress\Network\NetworkReportModule
 * @covers \AIHazirSite\WordPress\Network\NetworkReportPage
 * @covers \AIHazirSite\WordPress\Uninstaller
 */
final class NetworkReportFlowTest extends WP_UnitTestCase {

	private const MOTHER  = 'https://www.makedonya.tr/';
	private const KOSOVA  = 'https://www.kosova.org.tr/';
	private const YUNAN   = 'https://www.yunanistan.org.tr/';
	private const ARNAVUT = 'https://www.arnavutluk.org.tr/';

	/**
	 * Requests: URL → Authorization header.
	 *
	 * @var array<string, string>
	 */
	private array $requests = array();

	/**
	 * Report on; https home; application passwords available; fake sites answer.
	 */
	public function set_up(): void {
		parent::set_up();
		global $wp_rest_server;
		foreach ( array( Features::OPTION, NetworkModule::OPTION, NetworkModule::STATE, NetworkReportModule::KEY_OPTION, NetworkReportModule::CREDS, NetworkReportModule::DATA ) as $option ) {
			delete_option( $option );
		}
		add_filter( 'home_url', static fn( string $url ): string => (string) preg_replace( '#^https?://[^/]+#', 'https://www.site.example', $url ) );
		add_filter( 'wp_is_application_passwords_available', '__return_true' );
		foreach ( array( Features::CATALOG, Features::REST_API, Features::PORTAL_NETWORK, Features::NETWORK_REPORT ) as $feature ) {
			Features::set( $feature, true );
		}
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$wp_rest_server = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Test isolation of the core REST server.

		$requests = &$this->requests;
		add_filter(
			'pre_http_request',
			static function ( $pre, array $args, string $url ) use ( &$requests ) {
				$requests[ $url ] = (string) ( $args['headers']['Authorization'] ?? '' );
				if ( false !== $pre ) {
					return $pre;
				}
				$answer = static fn( int $code, array $body = array(), array $headers = array() ): array => array(
					'headers'  => $headers,
					'body'     => (string) wp_json_encode( $body ),
					'response' => array(
						'code'    => $code,
						'message' => 'x',
					),
					'cookies'  => array(),
				);
				if ( str_starts_with( $url, self::KOSOVA . 'wp-json/aihs/v1/network/stats' ) ) {
					return 'Basic ' . base64_encode( 'aihs-ag-raporu:abcd efgh ijkl mnop qrst uvwx' ) === ( $args['headers']['Authorization'] ?? '' ) // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Expected Basic header.
						? $answer(
							200,
							array(
								'version'    => '1.24.0',
								'days'       => (int) preg_replace( '/^.*days=/', '', $url ),
								'since'      => '2026-09-07',
								'features'   => array( 'catalog' ),
								'bots'       => array(
									'total'    => 40,
									'verified' => 10,
									'by_bot'   => array( 'gptbot' => 40 ),
								),
								'referrals'  => array(
									'total'     => 2,
									'by_source' => array( 'chatgpt' => 2 ),
								),
								'network'    => array(
									'total'      => 5,
									'by_sibling' => array( 'site.example' => 5 ),
								),
								'mcp'        => array( 'total' => 1 ),
								'inquiries'  => array(
									'total'   => 1,
									'by_kind' => array( 'quote_request' => 1 ),
								),
								'listings'   => 7,
								'compliance' => array(
									'score'      => 81,
									'scanned_at' => '2026-10-01T09:00:00Z',
								),
							)
						)
						: $answer( 401 );
				}
				if ( str_starts_with( $url, self::YUNAN ) ) {
					return $answer( 401 );
				}
				if ( str_starts_with( $url, self::ARNAVUT ) ) {
					return $answer( 429, array(), array( 'retry-after' => '600' ) );
				}
				if ( str_contains( $url, 'www.site.example' ) ) {
					return $answer( 200, array( 'days' => 7 ) );
				}
				return new \WP_Error( 'http_request_failed', 'down' );
			},
			10,
			3
		);
	}

	/**
	 * Clears request globals.
	 */
	public function tear_down(): void {
		unset( $_REQUEST['_wpnonce'], $_GET['days'] );
		parent::tear_down();
	}

	/**
	 * Authorization headers sent to a member's stats for a period (1.24.1: the address also carries a one-time
	 * `_aihs` value, so requests are matched by site and period).
	 *
	 * @param string $site Member URL.
	 * @param int    $days Period.
	 * @return list<string>
	 */
	private function sent( string $site, int $days ): array {
		$sent = array();
		foreach ( $this->requests as $url => $authorization ) {
			if ( 1 === preg_match( '#^' . preg_quote( $site . 'wp-json/aihs/v1/network/stats?days=' . $days, '#' ) . '&_aihs=[0-9a-f]{32}$#', $url ) ) {
				$sent[] = $authorization;
			}
		}
		return $sent;
	}

	/**
	 * Network state: this site as mother of three verified members.
	 */
	private static function as_mother(): void {
		$own = NetworkModule::self_url();
		update_option( NetworkModule::OPTION, ( new NetworkSettings( NetworkSettings::ROLE_MOTHER, 'Balkan Ülkeleri Portal Ağı', $own, array( self::KOSOVA, self::YUNAN, self::ARNAVUT ) ) )->to_array() );
		$members = array();
		foreach ( array(
			self::KOSOVA  => 'Kosova',
			self::YUNAN   => 'Yunanistan',
			self::ARNAVUT => 'Arnavutluk',
		) as $url => $name ) {
			$members[ $url ] = array(
				'status'      => 'verified',
				'name'        => $name,
				'country'     => '',
				'checked_at'  => time(),
				'verified_at' => time(),
				'retry_at'    => 0,
			);
		}
		update_option( NetworkModule::STATE, array( 'members' => $members ) );
	}

	/**
	 * Network state: this site as a verified member of MOTHER.
	 */
	private static function as_member(): void {
		update_option( NetworkModule::OPTION, ( new NetworkSettings( NetworkSettings::ROLE_MEMBER, '', self::MOTHER ) )->to_array() );
		update_option(
			NetworkModule::STATE,
			array(
				'self'       => array(
					'status'      => 'verified',
					'checked_at'  => time(),
					'verified_at' => time(),
				),
				'mother_doc' => array(
					'role'    => 'mother',
					'name'    => 'Balkan Ülkeleri Portal Ağı',
					'mother'  => self::MOTHER,
					'members' => array(),
				),
			)
		);
	}

	/**
	 * Posts an admin action with its nonce.
	 *
	 * @param string               $action Action.
	 * @param array<string, mixed> $input  Input.
	 */
	private static function post( string $action, array $input = array() ): string {
		$_REQUEST['_wpnonce'] = wp_create_nonce( $action );
		return NetworkReportPage::handle( $action, $input );
	}

	/**
	 * Member: the key is created and shown once, works for the endpoint only, and can be revoked.
	 */
	public function test_member_key_and_endpoint(): void {
		self::as_member();
		( new NetworkReportModule() )->register();
		do_action( 'rest_api_init' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core hook.

		$this->assertStringContainsString( 'Anne site için rapor anahtarı oluştur', NetworkReportPage::render_html() );
		self::post( NetworkReportPage::CREATE );
		$html = NetworkReportPage::render_html();
		$this->assertStringContainsString( 'id="aihs-report-new-key"', $html );
		$this->assertStringContainsString( '<code>aihs-ag-raporu</code>', $html );
		$this->assertStringContainsString( 'Deneme başarılı', $html );
		$this->assertMatchesRegularExpression( '#Uygulama parolası: <code>([A-Za-z0-9 ]{24,})</code>#', $html );
		preg_match( '#Uygulama parolası: <code>([A-Za-z0-9 ]+)</code>#', $html, $match );
		$this->assertStringNotContainsString( 'id="aihs-report-new-key"', NetworkReportPage::render_html(), 'Shown once.' );
		$this->assertStringContainsString( 'id="aihs-report-key"', NetworkReportPage::render_html() );

		// The password authenticates the reader for the REST API; the reader cannot do anything else.
		add_filter( 'application_password_is_api_request', '__return_true' );
		$reader = wp_authenticate_application_password( null, 'aihs-ag-raporu', (string) $match[1] );
		$this->assertInstanceOf( WP_User::class, $reader );
		$this->assertTrue( user_can( $reader, NetworkReportModule::CAP ) );
		$this->assertFalse( user_can( $reader, 'read' ) );
		$this->assertFalse( user_can( $reader, 'edit_posts' ) );

		$request = new WP_REST_Request( 'GET', '/aihs/v1/network/stats' );
		$request->set_query_params( array( 'days' => 7 ) );
		$this->assertSame( 403, rest_get_server()->dispatch( $request )->get_status(), 'An administrator is not the reader.' );
		wp_set_current_user( 0 );
		$this->assertSame( 401, rest_get_server()->dispatch( $request )->get_status() );
		wp_set_current_user( $reader->ID );
		$response = rest_get_server()->dispatch( $request );
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 7, $response->get_data()['days'] );
		$this->assertSame( 'private, no-store', $response->get_headers()['Cache-Control'] );
		$this->assertArrayNotHasKey( 'contact', $response->get_data()['inquiries'] );

		// Revoked: the password no longer works.
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		self::post( NetworkReportPage::REVOKE );
		$this->assertNull( NetworkReportModule::key() );
		$this->assertSame( array(), WP_Application_Passwords::get_user_application_passwords( $reader->ID ) );
		$this->assertInstanceOf( \WP_Error::class, wp_authenticate_application_password( null, 'aihs-ag-raporu', (string) $match[1] ) );
	}

	/**
	 * Mother: keys stored encrypted; members read (200, 401, 429); summary, matrix and CSV.
	 */
	public function test_mother_report(): void {
		self::as_mother();
		self::post(
			NetworkReportPage::CREDS,
			array(
				'url'      => array( self::KOSOVA, self::YUNAN, 'https://baska.example/' ),
				'user'     => array( 'aihs-ag-raporu', 'aihs-ag-raporu', 'x' ),
				'password' => array( 'abcd efgh ijkl mnop qrst uvwx', 'yanlis parola', 'abcd' ),
			)
		);
		$stored = (string) wp_json_encode( get_option( NetworkReportModule::CREDS ) );
		$this->assertStringNotContainsString( 'abcd efgh', $stored, 'Encrypted at rest.' );
		$this->assertSame( array( self::KOSOVA, self::YUNAN ), array_keys( NetworkReportModule::credentials() ), 'Only verified members.' );
		$this->assertSame( array( 'Basic ' . base64_encode( 'aihs-ag-raporu:abcd efgh ijkl mnop qrst uvwx' ) ), $this->sent( self::KOSOVA, 28 ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Expected Basic header.
		$this->assertSame( array(), $this->sent( self::ARNAVUT, 28 ), 'No key, no request.' );

		$sites  = array_column( NetworkReportModule::sites( 28 ), null, 'url' );
		$status = array_map( static fn( array $s ): string => $s['status'], $sites );
		$this->assertSame( NetworkReport::SELF, $status[ NetworkModule::self_url() ] );
		$this->assertSame( NetworkReport::OK, $status[ self::KOSOVA ] );
		$this->assertSame( NetworkReport::UNAUTHORIZED, $status[ self::YUNAN ] );
		$this->assertSame( NetworkReport::NO_KEY, $status[ self::ARNAVUT ] );
		$this->assertSame( 40, $sites[ self::KOSOVA ]['stats']['bots']['total'] ?? null );

		// 429: remembered, no new request before Retry-After.
		NetworkReportModule::save_credentials( self::ARNAVUT, 'aihs-ag-raporu', 'abcd efgh ijkl mnop qrst uvwx' );
		NetworkReportModule::fetch_all();
		$this->assertSame( NetworkReport::LIMITED, array_column( NetworkReportModule::sites( 28 ), 'status', 'url' )[ self::ARNAVUT ] );
		$this->requests = array();
		NetworkReportModule::fetch_all();
		$this->assertSame( array(), $this->sent( self::ARNAVUT, 7 ) );

		$html = NetworkReportPage::render_html( 28 );
		$this->assertStringContainsString( 'id="aihs-network-report-summary"', $html );
		$this->assertStringContainsString( '<td>Kosova</td><td>1.24.0</td><td>81</td><td>40</td>', $html );
		$this->assertStringContainsString( 'anahtar geçersiz veya iptal edilmiş', $html );
		$this->assertStringContainsString( 'id="aihs-network-report-matrix"', $html );
		$this->assertStringContainsString( '<tr><th>kosova.org.tr</th><td>5</td><td>–</td>', $html );
		$this->assertStringContainsString( 'placeholder="kayıtlı (değiştirmek için yazın)"', $html );
		$this->assertStringNotContainsString( 'abcd efgh', $html );

		$csv = NetworkReportPage::csv( 28 );
		$this->assertStringContainsString( 'Kosova,1.24.0,81,40,10,2,5,1,1,7,güncel,', $csv );
		$this->assertStringContainsString( '"Ağ toplamı"', $csv );
	}

	/**
	 * 1.24.1: no cache may keep the answer (200, 401 or 403); the mother never asks the same address twice.
	 */
	public function test_never_cached(): void {
		self::as_member();
		( new NetworkReportModule() )->register();
		do_action( 'rest_api_init' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core hook.
		self::post( NetworkReportPage::CREATE );
		$nocache = 0;
		add_action(
			'litespeed_control_set_nocache',
			static function () use ( &$nocache ): void {
				++$nocache;
			}
		);
		$admin  = get_current_user_id();
		$reader = get_user_by( 'login', NetworkReportModule::USER_LOGIN );
		$this->assertInstanceOf( WP_User::class, $reader );
		foreach ( array(
			0           => 401,
			$admin      => 403,
			$reader->ID => 200,
		) as $user => $code ) {
			wp_set_current_user( $user );
			$request  = new WP_REST_Request( 'GET', '/aihs/v1/network/stats' );
			$response = apply_filters( 'rest_post_dispatch', rest_ensure_response( rest_get_server()->dispatch( $request ) ), rest_get_server(), $request ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core hook.
			$this->assertSame( $code, $response->get_status() );
			$this->assertSame( 'private, no-store', $response->get_headers()['Cache-Control'] ?? null, (string) $code );
			$this->assertSame( 'no-cache', $response->get_headers()['X-LiteSpeed-Cache-Control'] ?? null, (string) $code );
		}
		$this->assertSame( 3, $nocache );
		$this->assertTrue( defined( 'DONOTCACHEPAGE' ) );

		$other = apply_filters( 'rest_post_dispatch', new \WP_REST_Response( array() ), rest_get_server(), new WP_REST_Request( 'GET', '/aihs/v1/profile' ) ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core hook.
		$this->assertArrayNotHasKey( 'X-LiteSpeed-Cache-Control', $other->get_headers(), 'Other routes untouched.' );

		// Mother side: two readings use two different one-time addresses.
		self::as_mother();
		NetworkReportModule::save_credentials( self::KOSOVA, 'aihs-ag-raporu', 'abcd efgh ijkl mnop qrst uvwx' );
		NetworkReportModule::fetch_all();
		NetworkReportModule::fetch_all();
		$urls = array_filter( array_keys( $this->requests ), static fn( string $u ): bool => str_starts_with( $u, self::KOSOVA . 'wp-json/aihs/v1/network/stats?days=28&_aihs=' ) );
		$this->assertCount( 2, $urls );
	}

	/**
	 * Without the nonce nothing is saved; with the key off the endpoint is gone; uninstall cleans up.
	 */
	public function test_guards_and_cleanup(): void {
		self::as_member();
		unset( $_REQUEST['_wpnonce'] );
		try {
			NetworkReportPage::handle( NetworkReportPage::CREATE, array() );
			$this->fail( 'Nonce required.' );
		} catch ( WPDieException $e ) {
			$this->assertNull( NetworkReportModule::key() );
		}

		self::post( NetworkReportPage::CREATE );
		$reader = get_user_by( 'login', NetworkReportModule::USER_LOGIN );
		$this->assertInstanceOf( WP_User::class, $reader );

		Features::set( Features::NETWORK_REPORT, false );
		( new NetworkReportModule() )->register();
		do_action( 'rest_api_init' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core hook.
		$this->assertArrayNotHasKey( '/aihs/v1/network/stats', rest_get_server()->get_routes(), 'Off: no endpoint.' );
		$this->assertFalse( wp_next_scheduled( NetworkReportModule::HOOK ) );

		update_option( Uninstaller::DELETE_OPTION, 1 );
		Uninstaller::run();
		$this->assertFalse( get_user_by( 'login', NetworkReportModule::USER_LOGIN ) );
		$this->assertNull( get_role( NetworkReportModule::ROLE ) );
		$this->assertFalse( get_option( NetworkReportModule::KEY_OPTION ) );
	}
}
