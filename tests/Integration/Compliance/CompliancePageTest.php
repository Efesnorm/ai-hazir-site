<?php
/**
 * Compliance module, admin page and full WordPress scan path.
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\Tests\Integration\Compliance;

use AIHazirSite\Core\Compliance\ScanStore;
use AIHazirSite\Core\Compliance\ScanToken;
use AIHazirSite\Core\Contracts\PageResponse;
use AIHazirSite\Core\Features;
use AIHazirSite\Tests\Support\ComplianceSites;
use AIHazirSite\WordPress\Compliance\Admin\CompliancePage;
use AIHazirSite\WordPress\Compliance\ComplianceModule;
use AIHazirSite\WordPress\Platform\WpSecret;
use DOMDocument;
use DOMXPath;
use WP_UnitTestCase;
use WPDieException;

/**
 * Compliance page integration tests; HTTP is served from ComplianceSites via pre_http_request.
 *
 * @covers \AIHazirSite\WordPress\Compliance\ComplianceModule
 * @covers \AIHazirSite\WordPress\Compliance\Admin\CompliancePage
 */
final class CompliancePageTest extends WP_UnitTestCase {

	/**
	 * Headers of every outgoing request.
	 *
	 * @var list<array<string, string>>
	 */
	private array $sent = array();

	/**
	 * Clean state.
	 */
	public function set_up(): void {
		parent::set_up();
		delete_option( Features::OPTION );
		delete_option( ScanStore::OPTION );
		$this->sent = array();
	}

	/**
	 * Tears down the admin context.
	 */
	public function tear_down(): void {
		set_current_screen( 'front' );
		parent::tear_down();
	}

	/**
	 * Serves the scripted site at home_url().
	 *
	 * @param \AIHazirSite\Tests\Support\FakePageFetcher $fake Scripted site on ComplianceSites::BASE.
	 */
	private function serve( \AIHazirSite\Tests\Support\FakePageFetcher $fake ): void {
		$home = home_url( '/' );
		add_filter(
			'pre_http_request',
			function ( $pre, array $args, string $url ) use ( $fake, $home ) {
				$this->sent[] = $args['headers'] + array( 'User-Agent' => (string) $args['user-agent'] );
				$response     = $fake->fetch( str_replace( $home, ComplianceSites::BASE, $url ), $this->sent[ count( $this->sent ) - 1 ] );
				if ( ! $response->reached() ) {
					return new \WP_Error( 'http_request_failed', $response->error );
				}
				return array(
					'headers'  => array_map( static fn( string $v ): string => str_replace( ComplianceSites::BASE, $home, $v ), $response->headers ),
					'body'     => str_replace( ComplianceSites::BASE, $home, $response->body ),
					'response' => array(
						'code'    => $response->status,
						'message' => '',
					),
					'cookies'  => array(),
				);
			},
			10,
			3
		);
	}

	/**
	 * With the feature off the page and the action are not registered; turning it on registers them.
	 */
	public function test_feature_key_controls_registration(): void {
		set_current_screen( 'dashboard' );
		remove_all_actions( 'admin_post_' . CompliancePage::ACTION );

		( new ComplianceModule() )->register();
		$this->assertFalse( has_action( 'admin_post_' . CompliancePage::ACTION ) );

		Features::set( Features::COMPLIANCE_SCAN, true );
		( new ComplianceModule() )->register();
		$this->assertNotFalse( has_action( 'admin_post_' . CompliancePage::ACTION ) );
	}

	/**
	 * A scan through the real WordPress HTTP path scores the ideal site 100, stores it and signs every request.
	 */
	public function test_full_scan_through_wordpress(): void {
		$this->serve( ComplianceSites::perfect() );

		$report = ComplianceModule::run();

		$this->assertSame( 100, $report->score() );
		$this->assertSame( 100, ComplianceModule::store()->latest()?->score() );
		$this->assertNotEmpty( $this->sent );
		foreach ( $this->sent as $headers ) {
			$this->assertSame( ScanToken::value( new WpSecret() ), $headers['X-AIHS-Scan'] ?? '' );
		}
		$this->assertContains( 'Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko; compatible; GPTBot/1.4; +https://openai.com/gptbot)', array_column( $this->sent, 'User-Agent' ) );
	}

	/**
	 * An unreachable site: the scan finishes, every check is unmeasured, no score.
	 */
	public function test_unreachable_site(): void {
		$this->serve( ComplianceSites::unreachable() );

		$report = ComplianceModule::run();

		$this->assertNull( $report->score() );
		$this->assertSame( array( null ), array_values( array_unique( array_column( $report->results, 'ratio' ) ) ) );
		$this->assertStringContainsString( 'id="aihs-unreachable"', CompliancePage::render_report( $report ) );
	}

	/**
	 * The page lists the biggest missing gain first and compares with the previous scan.
	 */
	public function test_page_shows_gain_order_and_comparison(): void {
		$this->serve( ComplianceSites::weak() );
		ComplianceModule::run();

		$fake = ComplianceSites::perfect();
		$fake->on( ComplianceSites::BASE . '.well-known/agent-card.json', new PageResponse( 404 ) );
		$fake->on( ComplianceSites::BASE . 'llms.txt', new PageResponse( 404 ) );
		remove_all_filters( 'pre_http_request' );
		$this->serve( $fake );
		ComplianceModule::run();

		$scans = ComplianceModule::store()->all();
		$html  = CompliancePage::render_report( $scans[0], $scans[1] );

		$dom = new DOMDocument();
		$dom->loadHTML( '<?xml encoding="utf-8"?><body>' . $html . '</body>', LIBXML_NOERROR );
		$xpath = new DOMXPath( $dom );
		$order = array();
		foreach ( $xpath->query( '//table[@id="aihs-checks"]/tbody/tr' ) as $tr ) {
			$order[] = $tr->getAttribute( 'data-check' );
		}

		$this->assertSame( 'llms_txt', $order[0], 'llms.txt (10) is the biggest missing gain.' );
		$this->assertSame( 'advanced', $order[1] );
		$this->assertSame( '85/100', trim( $xpath->query( '//*[@id="aihs-score"]' )->item( 0 )->textContent ) ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		$this->assertSame( '(+85)', trim( $xpath->query( '//*[@id="aihs-delta"]' )->item( 0 )->textContent ) ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		$this->assertStringContainsString( 'Puanlama sürümü: 2', $html );
	}

	/**
	 * With the AI bot access setting on, bots blocked there are "bilerek engellendi" and not penalized;
	 * with it off the same robots.txt lowers the score.
	 */
	public function test_scan_recognizes_intentional_blocks(): void {
		$fake = ComplianceSites::perfect();
		$fake->on( ComplianceSites::BASE . 'robots.txt', new PageResponse( 200, array(), "User-agent: GPTBot\nDisallow: /\n" ) );
		$this->serve( $fake );
		\AIHazirSite\WordPress\Access\AccessModule::store()->save( ( new \AIHazirSite\Core\Access\BotPolicy() )->with( 'gptbot', 'disallow' ), \AIHazirSite\Core\Measurement\Registry::bots() );

		$off = ComplianceModule::run()->result( 'bot_access' );
		$this->assertLessThan( 1.0, (float) $off['ratio'], 'Setting off: not recognized.' );

		Features::set( Features::BOT_ACCESS, true );
		$on = ComplianceModule::run()->result( 'bot_access' );
		$this->assertSame( 1.0, $on['ratio'] );
		$this->assertStringContainsString( 'Bilerek engellendi', implode( ' ', $on['findings'] ) );
	}

	/**
	 * Running a scan requires the capability and a valid nonce.
	 */
	public function test_run_requires_capability_and_nonce(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		try {
			( new CompliancePage() )->authorize();
			$this->fail( 'Editor must be rejected.' );
		} catch ( WPDieException $e ) {
			$this->assertStringContainsString( 'yetkiniz yok', $e->getMessage() );
		}

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$_REQUEST['_wpnonce'] = 'gecersiz';
		try {
			( new CompliancePage() )->authorize();
			$this->fail( 'Invalid nonce must be rejected.' );
		} catch ( WPDieException $e ) {
			$this->assertNull( ComplianceModule::store()->latest() );
		} finally {
			unset( $_REQUEST['_wpnonce'] );
		}
	}
}
