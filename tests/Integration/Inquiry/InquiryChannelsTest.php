<?php
/**
 * Inquiry channels, notification, the law-firm rule and the admin screen.
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\Tests\Integration\Inquiry;

use AIHazirSite\Adapters\Abilities\InquirySchemas;
use AIHazirSite\Core\Features;
use AIHazirSite\Core\Inquiry\Inquiry;
use AIHazirSite\Tests\Integration\Rest\RestTestCase;
use AIHazirSite\WordPress\Abilities\AbilitiesModule;
use AIHazirSite\WordPress\Catalog\CatalogModule;
use AIHazirSite\WordPress\Inquiry\Admin\InquiryAdmin;
use AIHazirSite\WordPress\Inquiry\InquiryChannels;
use AIHazirSite\WordPress\Inquiry\InquiryModule;
use AIHazirSite\WordPress\Inquiry\WpAuditRepository;
use AIHazirSite\WordPress\Inquiry\WpInquiryRepository;
use AIHazirSite\WordPress\Mcp\McpModule;
use AIHazirSite\WordPress\Templates\TemplatesModule;
use WP_REST_Request;
use WP_REST_Response;
use WPDieException;

/**
 * REST and ability channels over one service.
 *
 * @covers \AIHazirSite\WordPress\Inquiry\InquiryChannels
 * @covers \AIHazirSite\WordPress\Inquiry\WpInquiryNotifier
 * @covers \AIHazirSite\WordPress\Inquiry\Admin\InquiryAdmin
 * @covers \AIHazirSite\WordPress\Inquiry\InquiryModule
 */
final class InquiryChannelsTest extends RestTestCase {

	/**
	 * Inquiries, abilities and REST on; empty tables; a fresh mailer.
	 */
	public function set_up(): void {
		parent::set_up();
		global $wpdb;
		foreach ( array( WpInquiryRepository::table(), WpAuditRepository::table() ) as $table ) {
			$wpdb->query( $wpdb->prepare( 'DELETE FROM %i', $table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}
		delete_option( 'aihs_inquiry_settings' );
		Features::set( Features::INQUIRIES, true );
		Features::set( Features::ABILITIES, true );
		update_option( 'admin_email', 'sahip@ornek.example' );
		// The test site runs on "localhost", so WordPress's default sender (wordpress@localhost) is invalid.
		add_filter( 'wp_mail_from', static fn(): string => 'wordpress@ornek.example' );
		reset_phpmailer_instance();
		self::start();
	}

	/**
	 * Removes our inquiry abilities.
	 */
	public function tear_down(): void {
		foreach ( array( InquirySchemas::SUBMIT, InquirySchemas::REFERRAL ) as $name ) {
			if ( wp_has_ability( $name ) ) {
				wp_unregister_ability( $name );
			}
		}
		parent::tear_down();
	}

	/**
	 * Hooks the module as a new request would, and registers the inquiry ability.
	 */
	private static function start(): void {
		foreach ( array( InquirySchemas::SUBMIT, InquirySchemas::REFERRAL ) as $name ) {
			if ( wp_has_ability( $name ) ) {
				wp_unregister_ability( $name );
			}
		}
		wp_get_abilities();
		remove_all_actions( 'wp_abilities_api_categories_init' );
		remove_all_actions( 'wp_abilities_api_init' );
		if ( ! wp_has_ability_category( AbilitiesModule::CATEGORY ) ) {
			add_action( 'wp_abilities_api_categories_init', array( AbilitiesModule::class, 'register_category' ) );
		}
		( new InquiryModule() )->register();
		do_action( 'wp_abilities_api_categories_init' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core hook.
		do_action( 'wp_abilities_api_init' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core hook.
		self::boot();
	}

	/**
	 * POST /inquiries.
	 *
	 * @param array<string, mixed> $body Body.
	 */
	private static function post( array $body ): WP_REST_Response {
		$request = new WP_REST_Request( 'POST', '/aihs/v1/inquiries' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( (string) wp_json_encode( $body ) );
		return rest_get_server()->dispatch( $request );
	}

	/**
	 * A valid quote request body.
	 *
	 * @param int $listing Listing id.
	 * @return array<string, mixed>
	 */
	private static function body( int $listing ): array {
		return array(
			'kind'       => 'quote_request',
			'listing_id' => $listing,
			'subject'    => 'NYY kablo teklifi',
			'message'    => 'NYY kablodan 500 metre için teklif rica ederiz, teslim İstanbul.',
			'source'     => 'ai',
			'status'     => 'approved',
			'contact'    => array(
				'name'  => 'Ayşe Alıcı',
				'email' => 'ayse@alici.example',
			),
		);
	}

	/**
	 * REST: stored as new (the posted status is ignored), receipt only, owner e-mailed without contact data.
	 */
	public function test_rest_accepts_and_notifies(): void {
		$failures = array();
		add_action(
			'wp_mail_failed',
			static function ( $error ) use ( &$failures ): void {
				$failures[] = $error->get_error_message();
			}
		);
		$ids      = self::catalog();
		$response = self::post( self::body( $ids['cable'] ) );

		$this->assertSame( 201, $response->get_status() );
		$this->assertSame( array( 'received', 'reference', 'message' ), array_keys( $response->get_data() ) );
		$stored = ( new WpInquiryRepository() )->find( (int) $response->get_data()['reference'] );
		$this->assertSame( array( Inquiry::STATUS_NEW, 'ai', 'rest', $ids['cable'] ), array( $stored?->status, $stored?->source, $stored?->channel, $stored?->listing_id ) );

		$mail = tests_retrieve_phpmailer_instance()->get_sent( 0 );
		$this->assertNotFalse( $mail, 'Audit: ' . implode( ',', array_column( ( new WpAuditRepository() )->latest(), 'outcome' ) ) . ' | wp_mail_failed: ' . implode( ' / ', $failures ) . ' | mailer: ' . get_class( tests_retrieve_phpmailer_instance() ) );
		$this->assertSame( 'sahip@ornek.example', $mail->to[0][0] );
		$this->assertStringContainsString( 'NYY kablo', $mail->body );
		$this->assertStringNotContainsString( 'ayse@alici.example', $mail->body, 'No contact data in the e-mail.' );
		$this->assertStringNotContainsString( 'Ayşe', $mail->body );
		$this->assertFalse( tests_retrieve_phpmailer_instance()->get_sent( 1 ), 'Only the owner is e-mailed.' );
	}

	/**
	 * Invalid → 400 with field errors; over the limit → 429 with Retry-After; spam → quarantine, no e-mail.
	 */
	public function test_refusals_and_spam(): void {
		$ids = self::catalog();

		$invalid = self::post( array( 'message' => 'kısa' ) );
		$this->assertSame( 400, $invalid->get_status() );
		$this->assertArrayHasKey( 'contact', $invalid->get_data()['data']['errors'] );

		$spam = self::post( array_merge( self::body( $ids['cable'] ), array( 'message' => 'Visit http://a.example http://b.example http://c.example now' ) ) );
		$this->assertSame( 201, $spam->get_status(), 'Same answer for spam: nothing to learn.' );
		$this->assertSame( Inquiry::STATUS_QUARANTINE, ( new WpInquiryRepository() )->find( (int) $spam->get_data()['reference'] )?->status );
		$this->assertFalse( tests_retrieve_phpmailer_instance()->get_sent( 0 ), 'Quarantined: not notified.' );

		update_option( 'aihs_inquiry_settings', array( 'per_minute' => 1 ) );
		$_SERVER['REMOTE_ADDR'] = '192.0.2.99';
		$this->assertSame( 201, self::post( self::body( $ids['cable'] ) )->get_status() );
		$limited = self::post( self::body( $ids['cable'] ) );
		$this->assertSame( 429, $limited->get_status() );
		$this->assertGreaterThan( 0, (int) $limited->get_headers()['Retry-After'] );
		$this->assertSame( 'rate_limited', ( new WpAuditRepository() )->latest( 1 )[0]['outcome'] );
	}

	/**
	 * Ordinary site: aihs/submit-inquiry is registered, callable, and in the MCP tool list.
	 */
	public function test_ability_on_ordinary_site(): void {
		$ids = self::catalog();
		$this->assertTrue( wp_has_ability( InquirySchemas::SUBMIT ) );
		$this->assertFalse( wp_has_ability( InquirySchemas::REFERRAL ) );
		$this->assertContains( InquirySchemas::SUBMIT, McpModule::tools() );

		$body = self::body( $ids['cable'] );
		unset( $body['source'], $body['status'] );
		$result = wp_get_ability( InquirySchemas::SUBMIT )?->execute( $body );
		$this->assertIsArray( $result );
		$stored = ( new WpInquiryRepository() )->find( (int) $result['reference'] );
		$this->assertSame( array( 'ai', 'mcp', Inquiry::STATUS_NEW ), array( $stored?->source, $stored?->channel, $stored?->status ) );
	}

	/**
	 * Law firm (service template): no offer ability; only referral requests, never about fees.
	 */
	public function test_law_firm_takes_only_referrals(): void {
		CatalogModule::service()->save_profile(
			array(
				'name'          => 'Örnek Hukuk Bürosu',
				'contact_email' => 'buro@hukuk.example',
				'template'      => 'service',
			)
		);
		TemplatesModule::reset();
		self::start();

		$this->assertTrue( InquiryChannels::referral_only() );
		$this->assertFalse( wp_has_ability( InquirySchemas::SUBMIT ), 'The offer ability is not registered at all.' );
		$this->assertTrue( wp_has_ability( InquirySchemas::REFERRAL ) );
		$this->assertNotContains( InquirySchemas::SUBMIT, McpModule::tools() );
		$this->assertArrayNotHasKey( 'kind', wp_get_ability( InquirySchemas::REFERRAL )?->get_input_schema()['properties'] ?? array() );

		$this->assertSame( 400, self::post( array_merge( self::body( 0 ), array( 'listing_id' => null ) ) )->get_status(), 'quote_request refused.' );

		$fee = self::post(
			array(
				'kind'    => 'referral',
				'message' => 'Tahkim davası için vekalet ücretiniz ne kadar?',
				'contact' => array( 'email' => 'a@b.example' ),
			)
		);
		$this->assertSame( 400, $fee->get_status() );
		$this->assertStringContainsString( 'ücret sorulamaz', $fee->get_data()['message'] );

		$ok = wp_get_ability( InquirySchemas::REFERRAL )?->execute(
			array(
				'subject' => 'Tahkim',
				'message' => 'Uluslararası tahkimde uzmanlığınız ve benzer dava türlerindeki referans işleriniz hakkında bilgi rica ederiz.',
				'contact' => array( 'phone' => '+90 212 555 00 00' ),
			)
		);
		$this->assertIsArray( $ok );
		$this->assertSame( Inquiry::KIND_REFERRAL, ( new WpInquiryRepository() )->find( (int) $ok['reference'] )?->kind );
	}

	/**
	 * Feature off: no route, no ability.
	 */
	public function test_feature_off(): void {
		Features::set( Features::INQUIRIES, false );
		remove_all_actions( 'rest_api_init' );
		self::start();

		$this->assertSame( 404, self::post( array( 'message' => 'Merhaba, bilgi rica ederiz.' ) )->get_status() );
		$this->assertFalse( wp_has_ability( InquirySchemas::SUBMIT ) );
		$this->assertNotContains( InquirySchemas::SUBMIT, McpModule::tools() );
	}

	/**
	 * Admin: warning before switching on; a person approves, rejects, deletes; settings are clamped.
	 */
	public function test_admin(): void {
		$ids   = self::catalog();
		$admin = new InquiryAdmin();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		Features::set( Features::INQUIRIES, false );
		$this->assertStringContainsString( 'id="aihs-inquiry-warning"', InquiryAdmin::render_html() );
		$_REQUEST['_wpnonce'] = wp_create_nonce( InquiryAdmin::ENABLE );
		$admin->handle_enable( array() );
		$this->assertFalse( Features::is_enabled( Features::INQUIRIES ), 'Not without confirming the warning.' );
		$admin->handle_enable( array( 'confirm' => '1' ) );
		$this->assertTrue( Features::is_enabled( Features::INQUIRIES ) );

		$id   = (int) self::post( self::body( $ids['cable'] ) )->get_data()['reference'];
		$html = InquiryAdmin::render_html();
		$this->assertStringContainsString( 'ayse@alici.example', $html, 'Contact decrypted for the owner.' );
		$this->assertStringContainsString( 'id="aihs-kvkk"', $html );

		$_REQUEST['_wpnonce'] = wp_create_nonce( InquiryAdmin::STATUS . '_' . $id );
		$admin->handle_status(
			array(
				'id'     => $id,
				'status' => 'approved',
			)
		);
		$this->assertSame( Inquiry::STATUS_APPROVED, ( new WpInquiryRepository() )->find( $id )?->status );

		$_REQUEST['_wpnonce'] = wp_create_nonce( InquiryAdmin::SETTINGS );
		$admin->handle_settings(
			array(
				'retention_days' => '99999',
				'per_minute'     => '0',
				'per_day'        => '50',
				'spam_threshold' => '70',
			)
		);
		$this->assertSame(
			array(
				'retention_days' => 3650,
				'per_minute'     => 1,
				'per_day'        => 50,
				'spam_threshold' => 70,
			),
			InquiryModule::settings()->to_array()
		);

		$_REQUEST['_wpnonce'] = wp_create_nonce( InquiryAdmin::DELETE . '_' . $id );
		$admin->handle_delete( array( 'id' => $id ) );
		$this->assertNull( ( new WpInquiryRepository() )->find( $id ) );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$this->expectException( WPDieException::class );
		$admin->handle_status(
			array(
				'id'     => $id,
				'status' => 'approved',
			)
		);
	}
}
