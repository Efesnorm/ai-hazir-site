<?php
/**
 * A12 acceptance: card only with a working endpoint; inbound skills; outbound only with approval.
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\Tests\Integration\A2A;

use AIHazirSite\Adapters\A2A\A2ASkills;
use AIHazirSite\Adapters\A2A\AgentCardBuilder;
use AIHazirSite\Adapters\A2A\AgentCardValidator;
use AIHazirSite\Adapters\A2A\JsonRpcServer;
use AIHazirSite\Core\Catalog\ListingType;
use AIHazirSite\Core\Features;
use AIHazirSite\Core\Inquiry\Inquiry;
use AIHazirSite\WordPress\A2A\A2AAdmin;
use AIHazirSite\WordPress\A2A\A2AModule;
use AIHazirSite\WordPress\Catalog\CatalogModule;
use AIHazirSite\WordPress\Inquiry\WpAuditRepository;
use AIHazirSite\WordPress\Inquiry\WpInquiryRepository;
use AIHazirSite\WordPress\Matching\MatchingModule;
use WP_REST_Request;
use WP_UnitTestCase;
use WPDieException;

/**
 * With every outgoing HTTP request intercepted and recorded.
 *
 * @covers \AIHazirSite\WordPress\A2A\A2AModule
 * @covers \AIHazirSite\WordPress\A2A\A2AAdmin
 * @covers \AIHazirSite\WordPress\A2A\A2AOutbox
 */
final class A2AIntegrationTest extends WP_UnitTestCase {

	private const PARTNER  = 'https://fabrika.example/wp-json/aihs/v1/';
	private const ENDPOINT = 'https://fabrika.example/wp-json/aihs/a2a';

	/**
	 * Outgoing requests: [method, url, headers, body].
	 *
	 * @var list<array{0: string, 1: string, 2: array<string, string>, 3: string}>
	 */
	private array $requests = array();

	/**
	 * Clean tables and options; partner site mocked.
	 */
	public function set_up(): void {
		parent::set_up();
		global $wpdb, $wp_rest_server;
		foreach ( array( WpInquiryRepository::table(), WpAuditRepository::table() ) as $table ) {
			$wpdb->query( $wpdb->prepare( 'DELETE FROM %i', $table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}
		foreach ( array( Features::OPTION, MatchingModule::OPTION, 'aihs_inquiry_settings', 'aihs_profile' ) as $option ) {
			delete_option( $option );
		}
		foreach ( array( 'offer', 'supply' ) as $type ) {
			delete_transient( MatchingModule::CACHE . md5( self::PARTNER . 'listings?type=' . $type . '&per_page=50' ) );
		}
		$wp_rest_server = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Test isolation.
		add_filter( 'wp_mail_from', static fn(): string => 'wordpress@ornek.example' );
		add_filter( 'aihs_a2a_rate_limit', static fn(): int => 10000 );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$_SERVER['REMOTE_ADDR'] = '198.51.100.' . wp_rand( 1, 250 );

		$requests = &$this->requests;
		add_filter(
			'pre_http_request',
			static function ( $preempt, array $args, string $url ) use ( &$requests ) {
				$requests[] = array( (string) ( $args['method'] ?? 'GET' ), $url, (array) ( $args['headers'] ?? array() ), is_string( $args['body'] ?? null ) ? $args['body'] : '' );
				$body       = '';
				if ( 'https://fabrika.example/.well-known/agent-card.json' === $url ) {
					$body = (string) wp_json_encode( AgentCardBuilder::build( new \AIHazirSite\Core\Catalog\CompanyProfile( 'Fabrika A.Ş.' ), 'Fabrika', 'https://fabrika.example/', self::ENDPOINT, '1.6.0', array( A2ASkills::QUOTE ) ) );
				} elseif ( str_starts_with( $url, self::PARTNER . 'listings?type=offer' ) ) {
					$body = (string) wp_json_encode(
						array(
							'items' => array(
								array(
									'id'       => 7,
									'type'     => 'offer',
									'title'    => 'Fabrika NYY 3x2,5',
									'category' => 'Kablo',
									'template' => 'general',
								),
							),
						)
					);
				} elseif ( self::ENDPOINT === $url ) {
					$body = (string) wp_json_encode(
						array(
							'jsonrpc' => '2.0',
							'id'      => 'x',
							'result'  => array(
								'task' => array(
									'id'     => 't1',
									'status' => array(
										'state'   => 'TASK_STATE_COMPLETED',
										'message' => array(
											'messageId' => 'm',
											'role'      => 'ROLE_AGENT',
											'parts'     => array( array( 'text' => 'Talebiniz firmaya iletildi.' ) ),
										),
									),
								),
							),
						)
					);
				} else {
					$body = (string) wp_json_encode( array( 'items' => array() ) );
				}
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
	 * Clears the REST server and nonce.
	 */
	public function tear_down(): void {
		global $wp_rest_server;
		$wp_rest_server = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Test isolation.
		unset( $_REQUEST['_wpnonce'] );
		parent::tear_down();
	}

	/**
	 * Registers the module and returns our routes.
	 *
	 * @return list<string>
	 */
	private static function routes(): array {
		( new A2AModule() )->register();
		return array_values( array_filter( array_keys( rest_get_server()->get_routes() ), static fn( string $r ): bool => '/aihs/a2a' === $r ) );
	}

	/**
	 * The card is published only while the endpoint works; it passes the contract check.
	 */
	public function test_card_only_with_endpoint(): void {
		$this->assertNull( A2AModule::card(), 'Feature off.' );
		Features::set( Features::A2A, true );
		$this->assertNull( A2AModule::card(), 'On, but no working skill (catalog and inquiry box off).' );
		$this->assertSame( array(), self::routes(), 'No endpoint either.' );

		global $wp_rest_server;
		$wp_rest_server = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Test isolation.
		Features::set( Features::CATALOG, true );
		$card = A2AModule::card();
		$this->assertNotNull( $card );
		$this->assertSame( array(), AgentCardValidator::errors( $card ) );
		$this->assertSame( array( A2ASkills::AVAILABILITY ), array_column( $card['skills'], 'id' ) );
		$this->assertSame( array( '/aihs/a2a' ), self::routes() );
		$this->assertSame( A2AModule::endpoint(), $card['supportedInterfaces'][0]['url'], 'The card points at the registered endpoint.' );
		$this->assertSame( '/.well-known/agent-card.json', A2AModule::card_path() );

		Features::set( Features::INQUIRIES, true );
		$this->assertSame( array( A2ASkills::AVAILABILITY, A2ASkills::QUOTE ), array_column( A2AModule::card()['skills'] ?? array(), 'id' ) );
	}

	/**
	 * Inbound: availability and a quote request (stored as new, never approved), all audited.
	 */
	public function test_inbound_messages(): void {
		Features::set( Features::A2A, true );
		Features::set( Features::CATALOG, true );
		Features::set( Features::INQUIRIES, true );
		self::routes();
		$listing = (int) CatalogModule::service()->save_listing(
			array(
				'type'           => ListingType::OFFER,
				'title'          => 'NYY kablo',
				'quantity'       => '1500',
				'unit'           => 'm',
				'lead_time_days' => '7',
			)
		)->listing()?->id;

		$send = static function ( array $data, string $version = '1.0' ): array {
			$request = new WP_REST_Request( 'POST', '/aihs/a2a' );
			$request->set_header( 'content-type', 'application/json' );
			if ( '' !== $version ) {
				$request->set_header( 'A2A-Version', $version );
			}
			$request->set_body( (string) wp_json_encode( JsonRpcServer::send_message( wp_generate_uuid4(), $data ) ) );
			return (array) rest_get_server()->dispatch( $request )->get_data();
		};

		$available = $send(
			array(
				'skill'       => A2ASkills::AVAILABILITY,
				'listing_id'  => $listing,
				'quantity'    => 1000,
				'within_days' => 10,
			)
		);
		$this->assertSame( 'TASK_STATE_COMPLETED', $available['result']['task']['status']['state'] );
		$this->assertSame( 'yes', ( (array) $available['result']['task']['artifacts'][0]['parts'][0]['data'] )['answer'] );

		$quote = $send(
			array(
				'skill'      => A2ASkills::QUOTE,
				'listing_id' => $listing,
				'message'    => '2000 m NYY için teklif rica ederiz, teslim İstanbul.',
				'contact'    => array(
					'name'    => 'Satın alma',
					'company' => 'Dağıtıcı A.Ş.',
					'email'   => 'satinalma@dagitici.example',
				),
			)
		);
		$this->assertSame( 'TASK_STATE_COMPLETED', $quote['result']['task']['status']['state'] );
		$stored = ( new WpInquiryRepository() )->list_inquiries();
		$this->assertCount( 1, $stored );
		$this->assertSame( array( Inquiry::CHANNEL_A2A, Inquiry::STATUS_NEW ), array( $stored[0]->channel, $stored[0]->status ), 'Never approved automatically.' );

		$this->assertSame( JsonRpcServer::VERSION_NOT_SUPPORTED, $send( array( 'skill' => A2ASkills::AVAILABILITY ), '' )['error']['code'] ?? 0 );

		$audit = array_filter( ( new WpAuditRepository() )->latest( 50 ), static fn( array $e ): bool => Inquiry::CHANNEL_A2A === $e['channel'] && 'message' === $e['action'] );
		$this->assertCount( 3, $audit, 'Every inbound message is audited.' );
		$this->assertSame( array(), $this->requests, 'Answering sends nothing out.' );
	}

	/**
	 * Outbound: preview sends nothing; approval sends exactly the previewed message; no match or no card → nothing.
	 */
	public function test_outbound_only_with_approval(): void {
		Features::set( Features::A2A, true );
		Features::set( Features::CATALOG, true );
		Features::set( Features::MATCHING, true );
		update_option(
			'aihs_profile',
			array(
				'name'          => 'Dağıtıcı A.Ş.',
				'contact_email' => 'satinalma@dagitici.example',
			)
		);
		update_option(
			MatchingModule::OPTION,
			array(
				'partners' => array( self::PARTNER ),
				'weights'  => array(),
			)
		);
		$need = (int) CatalogModule::service()->save_listing(
			array(
				'type'     => ListingType::DEMAND,
				'title'    => 'NYY kablo aranıyor',
				'category' => 'Kablo',
				'quantity' => '2000',
				'unit'     => 'm',
			)
		)->listing()?->id;

		$this->assertStringContainsString( 'aihs-a2a-error', A2AAdmin::page( $need, self::PARTNER, 99 ), 'Not among the matches.' );
		$this->assertStringContainsString( 'aihs-a2a-error', A2AAdmin::page( $need, 'https://baska.example/wp-json/aihs/v1/', 7 ), 'Not a partner.' );

		$page = A2AAdmin::page( $need, self::PARTNER, 7 );
		$this->assertStringContainsString( 'id="aihs-a2a-preview"', $page );
		$this->assertStringContainsString( 'Fabrika NYY 3x2,5', $page );
		$posts = static fn( array $requests ): array => array_values( array_filter( $requests, static fn( array $r ): bool => 'POST' === $r[0] ) );
		$this->assertSame( array(), $posts( $this->requests ), 'Showing the preview sends nothing.' );

		preg_match( '/name="token" value="([A-Za-z0-9]+)"/', $page, $m );
		preg_match( '#<pre id="aihs-a2a-preview">(.*?)</pre>#s', $page, $preview );
		$_REQUEST['_wpnonce'] = wp_create_nonce( A2AAdmin::APPROVE );
		$this->assertStringContainsString( 'sent=ok', A2AAdmin::handle_approve( array( 'token' => $m[1] ) ) );

		$sent = $posts( $this->requests );
		$this->assertCount( 1, $sent );
		$this->assertSame( self::ENDPOINT, $sent[0][1] );
		$this->assertSame( '1.0', $sent[0][2]['A2A-Version'] );
		$this->assertEquals( json_decode( html_entity_decode( $preview[1], ENT_QUOTES ), true ), json_decode( $sent[0][3], true ), 'Exactly the approved message.' );
		$audit = array_filter( ( new WpAuditRepository() )->latest( 50 ), static fn( array $e ): bool => 'send' === $e['action'] );
		$this->assertCount( 1, $audit );

		// The token is single use.
		$this->expectException( WPDieException::class );
		A2AAdmin::handle_approve( array( 'token' => $m[1] ) );
	}
}
