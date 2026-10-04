<?php
/**
 * Portal network wiring (1.20.0).
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\Tests\Integration\Network;

use AIHazirSite\Core\Features;
use AIHazirSite\Core\Network\NetworkCheck;
use AIHazirSite\WordPress\Catalog\CatalogModule;
use AIHazirSite\WordPress\Llms\LlmsModule;
use AIHazirSite\WordPress\Network\NetworkModule;
use AIHazirSite\WordPress\Network\NetworkPage;
use AIHazirSite\WordPress\Rest\RestModule;
use AIHazirSite\WordPress\Schema\SchemaModule;
use AIHazirSite\WordPress\Uninstaller;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * With every outgoing request answered by fake sites.
 *
 * @covers \AIHazirSite\WordPress\Network\NetworkModule
 * @covers \AIHazirSite\WordPress\Network\NetworkPage
 */
final class NetworkModuleTest extends WP_UnitTestCase {

	private const OK      = 'https://www.kosova.org.tr/';
	private const OTHER   = 'https://www.yunanistan.org.tr/';
	private const LIMITED = 'https://www.arnavutluk.org.tr/';
	private const DOWN    = 'https://www.sirbistan.org.tr/';

	/**
	 * Requested URLs and the X-AIHS-Network header sent.
	 *
	 * @var list<array{0: string, 1: string}>
	 */
	private array $requests = array();

	/**
	 * Network, REST, catalog and Schema.org on; fake sites answer.
	 */
	public function set_up(): void {
		parent::set_up();
		global $wp_rest_server;
		foreach ( array( Features::OPTION, NetworkModule::OPTION, NetworkModule::STATE ) as $option ) {
			delete_option( $option );
		}
		// The network accepts https sites only; the test site runs on http://localhost (WP_HOME).
		add_filter( 'home_url', static fn( string $url ): string => (string) preg_replace( '#^https?://[^/]+#', 'https://portal.example', $url ) );
		foreach ( array( Features::CATALOG, Features::REST_API, Features::SCHEMA_OUTPUT, Features::LLMS_TXT, Features::PORTAL_NETWORK ) as $feature ) {
			Features::set( $feature, true );
		}
		CatalogModule::service()->save_profile(
			array(
				'name'    => 'Makedonya Portalı',
				'country' => 'MK',
			)
		);
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$wp_rest_server = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Test isolation of the core REST server.

		$own_url  = NetworkModule::self_url();
		$requests = &$this->requests;
		add_filter(
			'pre_http_request',
			static function ( $pre, array $args, string $url ) use ( &$requests, $own_url ) {
				$requests[] = array( $url, (string) ( $args['headers'][ NetworkModule::HEADER ] ?? '' ) );
				if ( false !== $pre ) {
					return $pre; // A test's own fake answer (registered earlier) wins.
				}
				$json   = static fn( array $body, int $code = 200, array $headers = array() ): array => array(
					'headers'  => $headers,
					'body'     => (string) wp_json_encode( $body ),
					'response' => array(
						'code'    => $code,
						'message' => 'x',
					),
					'cookies'  => array(),
				);
				$member = static fn( string $mother ): array => array(
					'role'    => 'member',
					'name'    => '',
					'mother'  => $mother,
					'members' => array(),
				);
				return match ( $url ) {
					self::OK . 'wp-json/aihs/v1/network'      => $json( $member( $own_url ) ),
					self::OK . 'wp-json/aihs/v1/profile'      => $json(
						array(
							'name'    => 'Kosova <b>Portalı</b>',
							'country' => 'XK',
						)
					),
					self::OTHER . 'wp-json/aihs/v1/network'   => $json( $member( 'https://baska.example/' ) ),
					self::LIMITED . 'wp-json/aihs/v1/network' => $json( array(), 429, array( 'retry-after' => '120' ) ),
					default                                   => new \WP_Error( 'http_request_failed', 'down' ),
				};
			},
			10,
			3
		);
	}

	/**
	 * Mother: saving runs the check; statuses, retry, what is published everywhere.
	 */
	public function test_mother_end_to_end(): void {
		$_REQUEST['_wpnonce'] = wp_create_nonce( NetworkPage::SAVE );
		NetworkPage::handle_save(
			array(
				'role'    => 'mother',
				'name'    => 'Balkan Portalları',
				'members' => implode( "\n", array( self::OK, self::OTHER, self::LIMITED, self::DOWN ) ),
			)
		);

		$state = NetworkModule::state();
		$this->assertSame( NetworkCheck::VERIFIED, $state['members'][ self::OK ]['status'] );
		$this->assertSame( 'Kosova Portalı', $state['members'][ self::OK ]['name'], 'Remote text cleaned.' );
		$this->assertSame( NetworkCheck::OTHER_MOTHER, $state['members'][ self::OTHER ]['status'] );
		$this->assertSame( NetworkCheck::UNREACHABLE, $state['members'][ self::LIMITED ]['status'] );
		$this->assertGreaterThan( time() + 60, $state['members'][ self::LIMITED ]['retry_at'], 'Retry-After respected.' );
		$this->assertSame( NetworkCheck::UNREACHABLE, $state['members'][ self::DOWN ]['status'] );
		$this->assertContains( array( self::OK . 'wp-json/aihs/v1/network', NetworkModule::self_url() ), $this->requests, 'Requests name us.' );

		$count = count( $this->requests );
		NetworkModule::check();
		$this->assertNotContains( array( self::LIMITED . 'wp-json/aihs/v1/network', NetworkModule::self_url() ), array_slice( $this->requests, $count ), 'No request before Retry-After.' );

		// GET /network: the mother and the verified member only.
		( new NetworkModule() )->register();
		$data = rest_get_server()->dispatch( new WP_REST_Request( 'GET', '/aihs/v1/network' ) )->get_data();
		$this->assertSame( 'mother', $data['role'] );
		$this->assertSame( array( NetworkModule::self_url(), self::OK ), array_column( $data['members'], 'url' ) );
		$this->assertArrayHasKey( '/network', RestModule::openapi()['paths'] );

		// Home JSON-LD: parentOrganization and the network node.
		$home = (array) SchemaModule::home_document();
		$this->assertSame( NetworkModule::self_url() . '#network', $home['@graph'][0]['parentOrganization']['@id'] );
		$this->assertContains( self::OK . '#organization', array_column( (array) end( $home['@graph'] )['subOrganization'], '@id' ) );
		$this->assertSame( 'Balkan Portalları', ( (array) SchemaModule::catalog_document() )['publisher']['parentOrganization']['name'] );

		// llms.txt: the verified sibling only.
		$text = LlmsModule::text();
		$this->assertStringContainsString( '## Kardeş portallar – Balkan Portalları', $text );
		$this->assertStringContainsString( '[Kosova Portalı (XK)](' . self::OK . 'llms.txt)', $text );
		$this->assertStringNotContainsString( self::OTHER, $text, 'One-sided entries are never published.' );

		// A verified site calling us is remembered with its IP; an unknown one is not.
		$_SERVER['REMOTE_ADDR'] = '203.0.113.9';
		NetworkModule::remember_caller( self::OK );
		NetworkModule::remember_caller( self::OTHER );
		$this->assertSame( array( self::OK => '203.0.113.9' ), NetworkModule::state()['seen'] );
	}

	/**
	 * Member: verified once the mother lists it; siblings from the mother.
	 */
	public function test_member(): void {
		$own_url = NetworkModule::self_url();
		add_filter(
			'pre_http_request',
			static function ( $pre, array $args, string $url ) use ( $own_url ) {
				if ( self::OK . 'wp-json/aihs/v1/network' !== $url ) {
					return $pre;
				}
				return array(
					'headers'  => array(),
					'body'     => (string) wp_json_encode(
						array(
							'role'    => 'mother',
							'name'    => 'Balkan Portalları',
							'mother'  => self::OK,
							'members' => array(
								array(
									'url'      => self::OK,
									'name'     => 'Anne',
									'country'  => 'XK',
									'verified' => true,
								),
								array(
									'url'      => $own_url,
									'name'     => 'Biz',
									'country'  => 'MK',
									'verified' => true,
								),
							),
						)
					),
					'response' => array(
						'code'    => 200,
						'message' => 'OK',
					),
					'cookies'  => array(),
				);
			},
			5,
			3
		);
		$_REQUEST['_wpnonce'] = wp_create_nonce( NetworkPage::SAVE );
		NetworkPage::handle_save(
			array(
				'role'   => 'member',
				'mother' => self::OK,
			)
		);

		$view = NetworkModule::view();
		$this->assertSame( NetworkCheck::VERIFIED, $view->self_status() );
		$this->assertSame( array( self::OK ), array_column( $view->siblings(), 'url' ) );
		$this->assertSame( self::OK . '#network', ( (array) SchemaModule::home_document() )['@graph'][0]['parentOrganization']['@id'] );
		$this->assertStringContainsString( '[Anne (XK)](' . self::OK . 'llms.txt)', LlmsModule::text() );
	}

	/**
	 * Off: nothing published, no route, no schedule; deactivation and uninstall clean up.
	 */
	public function test_off_and_cleanup(): void {
		( new NetworkModule() )->register();
		$this->assertNotFalse( wp_next_scheduled( NetworkModule::HOOK ) );
		( new NetworkModule() )->deactivate();
		$this->assertFalse( wp_next_scheduled( NetworkModule::HOOK ) );

		( new NetworkModule() )->register();
		Features::set( Features::PORTAL_NETWORK, false );
		( new NetworkModule() )->register();
		$this->assertFalse( wp_next_scheduled( NetworkModule::HOOK ), 'Turning the key off removes the schedule.' );
		$this->assertArrayNotHasKey( '/network', RestModule::openapi()['paths'] );
		$this->assertSame( array(), NetworkModule::llms() );

		update_option( NetworkModule::OPTION, array( 'role' => 'mother' ) );
		update_option( NetworkModule::STATE, array( 'seen' => array() ) );
		update_option( Uninstaller::DELETE_OPTION, 1 );
		Uninstaller::run();
		$this->assertFalse( get_option( NetworkModule::OPTION ) );
		$this->assertFalse( get_option( NetworkModule::STATE ) );
		$this->assertContains( NetworkModule::HOOK, Uninstaller::cron_hooks() );
	}

	/**
	 * The screen needs the capability and the nonce; invalid input is reported.
	 */
	public function test_screen_guards(): void {
		$_REQUEST['_wpnonce'] = wp_create_nonce( NetworkPage::SAVE );
		NetworkPage::handle_save(
			array(
				'role'    => 'mother',
				'name'    => '',
				'members' => 'http://eski.example/',
			)
		);
		$html = NetworkPage::render_html();
		$this->assertStringContainsString( 'Ağ adı gerekli (yalnızca anne site için). Bu site bir anneye bağlanacaksa rolü &quot;Üye&quot; seçin.', $html );
		$this->assertStringContainsString( 'id="aihs-network-form"', $html );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$this->expectException( \WPDieException::class );
		NetworkPage::handle_save( array( 'role' => 'none' ) );
	}
}
