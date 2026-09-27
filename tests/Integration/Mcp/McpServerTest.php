<?php
/**
 * The public MCP server end to end (over the REST server, no network).
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\Tests\Integration\Mcp;

use AIHazirSite\Core\Features;
use AIHazirSite\Core\Measurement\Hit;
use AIHazirSite\Core\Measurement\Report;
use AIHazirSite\Tests\Support\FixedClock;
use AIHazirSite\WordPress\Measurement\Admin\ReportPage;
use AIHazirSite\WordPress\Mcp\McpModule;
use AIHazirSite\WordPress\Mcp\StatelessHttpTransport;
use AIHazirSite\WordPress\Storage\WpdbHitRepository;

/**
 * Protocol, tools, parity with REST, switches and measurement.
 *
 * @covers \AIHazirSite\WordPress\Mcp\McpModule
 * @covers \AIHazirSite\WordPress\Mcp\StatelessHttpTransport
 * @covers \AIHazirSite\WordPress\Mcp\McpObservability
 * @covers \AIHazirSite\Core\Measurement\McpCalls
 */
final class McpServerTest extends McpTestCase {

	/**
	 * Anonymous client: initialize, notification, tools/list without a session or login.
	 */
	public function test_protocol_without_login(): void {
		wp_set_current_user( 0 );

		$init = self::rpc(
			'initialize',
			array(
				'protocolVersion' => '2025-06-18',
				'capabilities'    => (object) array(),
				'clientInfo'      => array(
					'name'    => 'test',
					'version' => '1',
				),
			)
		);
		$this->assertSame( 200, $init->get_status() );
		$this->assertSame( '2025-06-18', $init->get_data()['result']['protocolVersion'] );
		$this->assertArrayNotHasKey( 'Mcp-Session-Id', $init->get_headers() );

		$tools = self::rpc( 'tools/list', array(), array( 'MCP-Protocol-Version' => '2025-06-18' ) )->get_data();
		$this->assertSame( self::tool_names(), array_column( $tools['result']['tools'], 'name' ) );
		$this->assertTrue( $tools['result']['tools'][0]['annotations']['readOnlyHint'] ?? false );

		$this->assertSame( 400, self::rpc( 'tools/list', array(), array( 'MCP-Protocol-Version' => '1999-01-01' ) )->get_status() );
	}

	/**
	 * The acceptance question: "Stokta 3x2,5 kablo var mı, kaç günde gelir?"
	 */
	public function test_example_question(): void {
		$ids   = self::catalog();
		$found = self::call( 'aihs-search-listings', array( 'keyword' => 'NYY' ) );
		$this->assertSame( array( $ids['cable'] ), array_column( $found['items'], 'id' ) );

		$answer = self::call(
			'aihs-check-availability',
			array(
				'id'          => $ids['cable'],
				'quantity'    => 1000,
				'within_days' => 10,
			)
		);
		$this->assertSame( 'yes', $answer['answer'] );
		$this->assertSame( array( 'Stokta 1500 m var; istenen 1000.', 'Teslim süresi 7 gün; istenen 10 gün içinde.' ), $answer['reasons'] );
		$this->assertSame( 'unknown', self::call( 'aihs-check-availability', array( 'id' => $ids['service'], 'within_days' => 3 ) )['answer'], 'No lead time given.' ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
	}

	/**
	 * REST and MCP answer the same question with the same bodies.
	 */
	public function test_parity_with_rest(): void {
		$ids = self::catalog();

		$rest = self::get(
			'/listings',
			array(
				'type'     => 'offer',
				'category' => 'Kablo',
			)
		)->get_data();
		$mcp  = self::call(
			'aihs-search-listings',
			array(
				'type'     => 'offer',
				'category' => 'Kablo',
			)
		);
		$this->assertSame( $rest['items'], $mcp['items'] );
		$this->assertSame( array( $rest['total'], $rest['updated_at'] ), array( $mcp['total'], $mcp['updated_at'] ) );

		foreach ( array( 'cable', 'service', 'tour' ) as $name ) {
			$this->assertSame( self::get( '/listings/' . $ids[ $name ] )->get_data(), self::call( 'aihs-get-listing', array( 'id' => $ids[ $name ] ) ), $name );
		}
		$this->assertSame( self::get( '/profile' )->get_data(), self::call( 'aihs-get-profile' ) );
	}

	/**
	 * A foreign browser Origin is refused; GET and DELETE are 405 (no SSE, no sessions).
	 */
	public function test_origin_and_methods(): void {
		$this->assertSame( 401, self::rpc( 'tools/list', array(), array( 'Origin' => 'https://baska-site.example' ) )->get_status() );
		$this->assertSame( 200, self::rpc( 'tools/list', array(), array( 'Origin' => home_url() ) )->get_status() );
		$this->assertTrue( StatelessHttpTransport::origin_allowed( '' ) );

		foreach ( array( 'GET', 'DELETE' ) as $method ) {
			$request = new \WP_REST_Request( $method, '/' . McpModule::NAMESPACE . '/' . McpModule::ROUTE );
			$this->assertSame( 405, rest_get_server()->dispatch( $request )->get_status(), $method );
		}
	}

	/**
	 * `mcp` off: no server hook and no endpoint, while the abilities stay available.
	 */
	public function test_mcp_off_abilities_on(): void {
		Features::set( Features::MCP, false );
		remove_all_actions( 'mcp_adapter_init' );
		remove_all_actions( 'rest_api_init' );
		( new McpModule() )->register();
		self::boot();

		$this->assertFalse( has_action( 'mcp_adapter_init', array( McpModule::class, 'create_server' ) ) );
		$this->assertSame( 404, self::rpc( 'tools/list' )->get_status() );
		$this->assertTrue( wp_has_ability( 'aihs/search-listings' ) );
		$this->assertIsArray( wp_get_ability( 'aihs/get-profile' )?->execute( array() ) );

		Features::set( Features::MCP, true );
		Features::set( Features::ABILITIES, false );
		$this->assertFalse( McpModule::enabled(), 'The server needs the abilities it serves.' );
	}

	/**
	 * Tool calls are counted as kind = mcp and shown in their own report table.
	 */
	public function test_measurement(): void {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i', WpdbHitRepository::table() ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		self::catalog();

		self::call( 'aihs-search-listings', array( 'keyword' => 'kablo' ) );
		self::call( 'aihs-search-listings', array( 'keyword' => 'tur' ) );
		self::call( 'aihs-get-profile' );
		self::rpc( 'tools/list' );

		$rows = ( new Report( new WpdbHitRepository(), new FixedClock( current_time( 'Y-m-d' ) ), 7 ) )->rows();
		$mcp  = Report::section( $rows, Report::SECTION_MCP );
		$this->assertSame(
			array(
				array( 'aihs-search-listings', 2 ),
				array( 'aihs-get-profile', 1 ),
			),
			array_map( static fn( array $r ): array => array( $r['source'], $r['total'] ), $mcp )
		);
		$this->assertSame( array(), Report::section( $rows, Report::SECTION_BOTS ), 'Not mixed with bots.' );
		$this->assertStringContainsString( 'id="aihs-mcp"', ReportPage::render_tables( $rows ) );

		$kinds = $wpdb->get_col( $wpdb->prepare( 'SELECT DISTINCT kind FROM %i', WpdbHitRepository::table() ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$this->assertSame( array( Hit::KIND_MCP ), $kinds );
	}
}
