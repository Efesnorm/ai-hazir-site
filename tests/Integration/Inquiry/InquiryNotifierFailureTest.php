<?php
/**
 * The inquiry channels keep working when the server cannot send e-mail (1.15.1).
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\Tests\Integration\Inquiry;

use AIHazirSite\Adapters\A2A\A2ASkills;
use AIHazirSite\Adapters\A2A\JsonRpcServer;
use AIHazirSite\Core\Features;
use AIHazirSite\Core\Security\AuditLog;
use AIHazirSite\WordPress\A2A\A2AModule;
use AIHazirSite\WordPress\Catalog\CatalogModule;
use AIHazirSite\WordPress\Inquiry\Admin\InquiryAdmin;
use AIHazirSite\WordPress\Inquiry\WpAuditRepository;
use AIHazirSite\WordPress\Inquiry\WpInquiryRepository;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * Found live on makedonya.tr: the host disabled PHP's mail(), PHPMailer threw an Error, and the A2A answer was
 * an HTTP 500 with a stack trace although the inquiry had been stored.
 *
 * @covers \AIHazirSite\WordPress\Inquiry\WpInquiryNotifier
 * @covers \AIHazirSite\WordPress\A2A\A2AModule
 * @covers \AIHazirSite\WordPress\Inquiry\Admin\InquiryAdmin
 */
final class InquiryNotifierFailureTest extends WP_UnitTestCase {

	/**
	 * Catalog, inquiries and A2A on; empty tables; no rate limit in the way.
	 */
	public function set_up(): void {
		parent::set_up();
		global $wpdb;
		foreach ( array( WpInquiryRepository::table(), WpAuditRepository::table() ) as $table ) {
			$wpdb->query( $wpdb->prepare( 'DELETE FROM %i', $table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}
		delete_option( Features::OPTION );
		delete_option( 'aihs_inquiry_settings' );
		foreach ( array( Features::CATALOG, Features::INQUIRIES, Features::A2A ) as $feature ) {
			Features::set( $feature, true );
		}
		update_option( 'admin_email', 'sahip@ornek.example' );
		add_filter( 'wp_mail_from', static fn(): string => 'wordpress@ornek.example' );
		add_filter( 'aihs_a2a_rate_limit', static fn(): int => 10000 );
		reset_phpmailer_instance();
		$_SERVER['REMOTE_ADDR'] = '198.51.100.' . wp_rand( 1, 250 );
	}

	/**
	 * Mail() missing: the A2A answer is a completed task, the inquiry is stored, the failure is logged and shown.
	 */
	public function test_mail_error_does_not_break_the_channel(): void {
		add_action(
			'phpmailer_init',
			static function (): void {
				throw new \Error( 'Call to undefined function PHPMailer\PHPMailer\mail()' );
			}
		);

		$data = A2AModule::handle( self::quote_request() )->get_data();

		$this->assertSame( 'TASK_STATE_COMPLETED', $data['result']['task']['status']['state'] ?? null, (string) wp_json_encode( $data ) );
		$this->assertCount( 1, ( new WpInquiryRepository() )->list_inquiries( '', '', 10 ) );
		$this->assertContains( AuditLog::OUTCOME_NOTIFY_FAIL, array_column( ( new WpAuditRepository() )->latest(), 'outcome' ) );
		$this->assertTrue( InquiryAdmin::last_notification_failed() );
		$this->assertStringContainsString( 'aihs-notify-failed', InquiryAdmin::render_html() );
	}

	/**
	 * Mail works: no warning; the owner e-mail is declared plain text.
	 */
	public function test_mail_works(): void {
		$headers = array();
		add_filter(
			'wp_mail',
			static function ( array $args ) use ( &$headers ): array {
				$headers = (array) $args['headers'];
				return $args;
			}
		);

		A2AModule::handle( self::quote_request() );

		$this->assertContains( 'Content-Type: text/plain; charset=UTF-8', $headers );
		$this->assertFalse( InquiryAdmin::last_notification_failed() );
		$this->assertStringNotContainsString( 'aihs-notify-failed', InquiryAdmin::render_html() );
	}

	/**
	 * An unexpected error inside a skill: JSON-RPC Internal error, no server path in the answer.
	 */
	public function test_skill_error_is_json_rpc_internal_error(): void {
		$listing = (int) CatalogModule::service()->save_listing(
			array(
				'type'     => 'offer',
				'title'    => 'NYY kablo',
				'category' => 'Kablo',
			)
		)->listing()?->id;
		add_filter(
			'get_post_metadata',
			static function () {
				throw new \RuntimeException( '/home/site/secret/path.php' );
			}
		);

		$request = new WP_REST_Request( 'POST', '/aihs/a2a' );
		$request->set_header( 'content-type', 'application/json' );
		$request->set_header( 'A2A-Version', '1.0' );
		$request->set_body(
			(string) wp_json_encode(
				JsonRpcServer::send_message(
					'm-1',
					array(
						'skill'      => A2ASkills::AVAILABILITY,
						'listing_id' => $listing,
						'quantity'   => 1,
					)
				)
			)
		);
		$response = A2AModule::handle( $request );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( -32603, $response->get_data()['error']['code'] ?? null );
		$this->assertStringNotContainsString( '/home/site', (string) wp_json_encode( $response->get_data() ) );
	}

	/**
	 * A2A "teklif-iste" message.
	 */
	private static function quote_request(): WP_REST_Request {
		$request = new WP_REST_Request( 'POST', '/aihs/a2a' );
		$request->set_header( 'content-type', 'application/json' );
		$request->set_header( 'A2A-Version', '1.0' );
		$request->set_body(
			(string) wp_json_encode(
				JsonRpcServer::send_message(
					'm-' . wp_rand(),
					array(
						'skill'   => A2ASkills::QUOTE,
						'subject' => 'Teklif',
						'message' => 'NYY 3x2,5 kablo için 500 metre fiyat teklifi rica ederim.',
						'contact' => array(
							'name'  => 'Ayşe',
							'email' => 'ayse@alici.example',
						),
					)
				)
			)
		);
		return $request;
	}
}
