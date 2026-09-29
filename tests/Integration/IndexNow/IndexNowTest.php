<?php
/**
 * IndexNow in WordPress (1.10.0).
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\Tests\Integration\IndexNow;

use AIHazirSite\Core\Features;
use AIHazirSite\Core\IndexNow\IndexNowService;
use AIHazirSite\WordPress\Catalog\CatalogModule;
use AIHazirSite\WordPress\IndexNow\IndexNowModule;
use AIHazirSite\WordPress\Integrations\IntegrationsPage;
use AIHazirSite\WordPress\Settings\SettingsPage;
use AIHazirSite\WordPress\Uninstaller;
use WP_UnitTestCase;

/**
 * Scheduling on catalog changes, the request (captured, never sent), screens, clean-up.
 *
 * @covers \AIHazirSite\WordPress\IndexNow\IndexNowModule
 * @covers \AIHazirSite\WordPress\Integrations\IntegrationsPage
 */
final class IndexNowTest extends WP_UnitTestCase {

	/**
	 * Captured outgoing requests.
	 *
	 * @var list<array{url: string, body: array<string, mixed>}>
	 */
	private array $requests = array();

	/**
	 * Catalog page on, IndexNow off; outgoing HTTP captured.
	 */
	public function set_up(): void {
		parent::set_up();
		delete_option( Features::OPTION );
		delete_option( IndexNowService::OPTION );
		wp_clear_scheduled_hook( IndexNowModule::HOOK );
		foreach ( array( Features::CATALOG, Features::LLMS_TXT ) as $feature ) {
			Features::set( $feature, true );
		}
		$this->requests = array();
		add_filter( 'pre_http_request', array( $this, 'capture' ), 10, 3 );
	}

	/**
	 * Clean-up.
	 */
	public function tear_down(): void {
		remove_filter( 'pre_http_request', array( $this, 'capture' ), 10 );
		wp_clear_scheduled_hook( IndexNowModule::HOOK );
		unset( $_REQUEST['_wpnonce'] );
		parent::tear_down();
	}

	/**
	 * `pre_http_request`: records the request and answers 202.
	 *
	 * @param mixed                $preempt Preempt.
	 * @param array<string, mixed> $args    Arguments.
	 * @param string               $url     URL.
	 * @return array<string, mixed>
	 */
	public function capture( $preempt, array $args, string $url ): array {
		$this->requests[] = array(
			'url'  => $url,
			'body' => (array) json_decode( (string) $args['body'], true ),
		);
		return array(
			'headers'  => array(),
			'body'     => '',
			'response' => array(
				'code'    => 202,
				'message' => 'Accepted',
			),
			'cookies'  => array(),
		);
	}

	/**
	 * Saves a listing.
	 */
	private static function listing(): void {
		CatalogModule::service()->save_listing(
			array(
				'type'     => 'offer',
				'title'    => 'Ohri Gölü Turu',
				'category' => 'Tur',
			)
		);
	}

	/**
	 * Off: no hooks, no event, no request.
	 */
	public function test_off(): void {
		( new IndexNowModule() )->register();
		self::listing();
		$this->assertFalse( wp_next_scheduled( IndexNowModule::HOOK ) );
		$this->assertNull( IndexNowModule::submit() );
		$this->assertSame( array(), $this->requests );
		$this->assertSame( '', IndexNowModule::key_url() );
	}

	/**
	 * On: a key and its file address; one event 10 minutes after a change, never two.
	 */
	public function test_schedule(): void {
		IndexNowModule::enable( true );
		( new IndexNowModule() )->register();
		$key = IndexNowModule::service()->key();
		$this->assertMatchesRegularExpression( '/^[0-9a-f]{32}$/', $key );
		$this->assertSame( home_url( '/' . $key . '.txt' ), IndexNowModule::key_url() );

		$before = time();
		self::listing();
		$at = wp_next_scheduled( IndexNowModule::HOOK );
		$this->assertIsInt( $at );
		$this->assertGreaterThanOrEqual( $before + IndexNowModule::DELAY, $at );
		self::listing();
		$this->assertSame( $at, wp_next_scheduled( IndexNowModule::HOOK ), 'One event for several changes.' );

		( new IndexNowModule() )->deactivate();
		$this->assertFalse( wp_next_scheduled( IndexNowModule::HOOK ) );
	}

	/**
	 * The request: IndexNow endpoint, our host, key file, the public catalog address only.
	 */
	public function test_submit(): void {
		IndexNowModule::enable( true );
		self::listing();
		$result = IndexNowModule::submit();

		$this->assertSame( 202, $result['status'] ?? null );
		$this->assertCount( 1, $this->requests );
		$this->assertSame( IndexNowService::ENDPOINT, $this->requests[0]['url'] );
		$body = $this->requests[0]['body'];
		$this->assertSame( wp_parse_url( home_url( '/' ), PHP_URL_HOST ), $body['host'] );
		$this->assertSame( IndexNowModule::key_url(), $body['keyLocation'] );
		$this->assertSame( array( home_url( '/ai-katalog/' ) ), $body['urlList'] );

		$this->assertNull( IndexNowModule::submit(), 'Within the hour.' );
		$this->assertCount( 1, $this->requests );
	}

	/**
	 * Screens: Integrations toggle and "Şimdi bildir"; Settings toggle with its requirement.
	 */
	public function test_screens(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$_REQUEST['_wpnonce'] = wp_create_nonce( IntegrationsPage::ACTION );

		IntegrationsPage::handle(
			array(
				'integration' => IntegrationsPage::INDEXNOW,
				'state'       => 'on',
			)
		);
		$this->assertTrue( Features::is_enabled( Features::INDEXNOW ) );
		$html = IntegrationsPage::render_html();
		$this->assertStringContainsString( IndexNowModule::key_url(), $html );
		$this->assertStringContainsString( 'henüz yok', $html );

		self::listing();
		$this->assertStringContainsString( 'message=sent', IntegrationsPage::handle( array( 'integration' => IntegrationsPage::INDEXNOW_NOW ) ) );
		$this->assertStringContainsString( 'yanıt 202', IntegrationsPage::render_html() );
		$this->assertStringContainsString( 'message=not_sent', IntegrationsPage::handle( array( 'integration' => IntegrationsPage::INDEXNOW_NOW ) ) );

		$_REQUEST['_wpnonce'] = wp_create_nonce( SettingsPage::TOGGLE );
		SettingsPage::handle_toggle(
			array(
				'feature' => Features::INDEXNOW,
				'state'   => 'off',
			)
		);
		$this->assertFalse( Features::is_enabled( Features::INDEXNOW ) );

		Features::set( Features::LLMS_TXT, false );
		$this->assertStringContainsString(
			'message=blocked',
			SettingsPage::handle_toggle(
				array(
					'feature' => Features::INDEXNOW,
					'state'   => 'on',
				)
			)
		);
		$this->assertFalse( Features::is_enabled( Features::INDEXNOW ) );
	}

	/**
	 * Uninstall removes the key and the last result.
	 */
	public function test_uninstall_option(): void {
		$this->assertContains( IndexNowService::OPTION, Uninstaller::options() );
	}
}
