<?php
/**
 * Features switched on together.
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\Tests\Integration;

use AIHazirSite\Adapters\A2A\A2ASkills;
use AIHazirSite\Adapters\A2A\JsonRpcServer;
use AIHazirSite\Adapters\Rest\RestSchemas;
use AIHazirSite\Core\Catalog\ListingType;
use AIHazirSite\Core\Features;
use AIHazirSite\Core\Inquiry\Inquiry;
use AIHazirSite\WordPress\A2A\A2AModule;
use AIHazirSite\WordPress\Catalog\CatalogModule;
use AIHazirSite\WordPress\Catalog\WpProfileRepository;
use AIHazirSite\WordPress\I18n\LanguageSource;
use AIHazirSite\WordPress\I18n\Multilingual;
use AIHazirSite\WordPress\Inquiry\WpAuditRepository;
use AIHazirSite\WordPress\Inquiry\WpInquiryRepository;
use AIHazirSite\WordPress\Portal\Portal;
use AIHazirSite\WordPress\Portal\WpBusinessRepository;
use AIHazirSite\WordPress\Rest\RestModule;
use AIHazirSite\WordPress\Templates\TemplatesModule;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * Multilingual + portal in REST; A2A on a fee-less (law firm) site.
 *
 * @coversNothing
 */
final class FeatureInteractionsTest extends WP_UnitTestCase {

	/**
	 * Clean state.
	 */
	public function set_up(): void {
		parent::set_up();
		global $wpdb, $wp_rest_server;
		foreach ( array( WpInquiryRepository::table(), WpAuditRepository::table() ) as $table ) {
			$wpdb->query( $wpdb->prepare( 'DELETE FROM %i', $table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}
		foreach ( array( Features::OPTION, WpBusinessRepository::OPTION, LanguageSource::OPTION, WpProfileRepository::OPTION, 'aihs_inquiry_settings' ) as $option ) {
			delete_option( $option );
		}
		$wp_rest_server = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Test isolation.
		TemplatesModule::reset();
		add_filter( 'aihs_rest_rate_limit', static fn(): int => 10000 );
		add_filter( 'aihs_a2a_rate_limit', static fn(): int => 10000 );
		add_filter( 'wp_mail_from', static fn(): string => 'wordpress@ornek.example' );
		$_SERVER['REMOTE_ADDR'] = '203.0.113.' . wp_rand( 1, 250 );
	}

	/**
	 * Clears the REST server.
	 */
	public function tear_down(): void {
		global $wp_rest_server;
		$wp_rest_server = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Test isolation.
		TemplatesModule::reset();
		parent::tear_down();
	}

	/**
	 * REST with ?lang= and ?business= together: translated text, business reference, both markers,
	 * and the body still matches the published schema (which carries both extensions).
	 */
	public function test_multilingual_portal_rest(): void {
		foreach ( array( Features::CATALOG, Features::REST_API, Features::MULTILINGUAL, Features::PORTAL_MODE ) as $feature ) {
			Features::set( $feature, true );
		}
		update_option(
			LanguageSource::OPTION,
			array(
				'default'   => 'tr',
				'languages' => array( 'en' ),
			)
		);
		$business = Portal::service()->save_business( array( 'name' => 'A Balon Turları' ) )['business'];
		$this->assertNotNull( $business );
		$id = (int) Portal::service()->save_business_listing(
			(int) $business->id,
			array(
				'type'     => ListingType::OFFER,
				'title'    => 'Balon turu',
				'category' => 'Tur',
			)
		)->listing()?->id;
		CatalogModule::service()->save_listing(
			array(
				'type'     => ListingType::OFFER,
				'title'    => 'Portal paketi',
				'category' => 'Tur',
			)
		);
		CatalogModule::service()->save_listing_translation( $id, 'en', array( 'title' => 'Balloon tour' ), Multilingual::settings() );
		( new RestModule() )->register();

		$request = new WP_REST_Request( 'GET', '/aihs/v1/listings' );
		$request->set_query_params(
			array(
				'lang'     => 'en',
				'business' => $business->slug,
			)
		);
		$response = rest_get_server()->dispatch( $request );
		$body     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'en', $response->get_headers()['Content-Language'] );
		$this->assertSame( 1, $body['total'], 'Only the business\'s listing.' );
		$item = $body['items'][0];
		$this->assertSame( 'Balloon tour', $item['title'] );
		$this->assertSame( $business->slug, $item['business']['slug'] );
		$this->assertSame( array( 'category' ), $item['translation']['missing'] );

		$schema = RestSchemas::with_portal( 'listings', RestSchemas::get( 'listings', true ) );
		$result = rest_validate_value_from_schema( $body, $schema );
		$this->assertTrue( true === $result, is_wp_error( $result ) ? $result->get_error_message() : '' );
		$this->assertEquals( $schema, rest_get_server()->dispatch( new WP_REST_Request( 'GET', '/aihs/v1/schema/listings' ) )->get_data() );
	}

	/**
	 * A2A on a fee-less (law firm) site: "teklif-iste" becomes a referral request; a fee question
	 * and a quote request are rejected; nothing is approved automatically.
	 */
	public function test_a2a_on_a_law_firm_site(): void {
		foreach ( array( Features::A2A, Features::INQUIRIES, Features::TEMPLATES ) as $feature ) {
			Features::set( $feature, true );
		}
		$saved = CatalogModule::service()->save_profile(
			array(
				'name'          => 'Örnek Hukuk Bürosu',
				'country'       => 'TR',
				'contact_email' => 'buro@ornekhukuk.example',
				'template'      => 'service',
			)
		);
		$this->assertTrue( $saved->is_valid(), implode( ' | ', $saved->errors ) );
		( new A2AModule() )->register();

		$send    = static function ( array $data ): array {
			$request = new WP_REST_Request( 'POST', '/aihs/a2a' );
			$request->set_header( 'A2A-Version', '1.0' );
			$request->set_body( (string) wp_json_encode( JsonRpcServer::send_message( wp_generate_uuid4(), array_merge( array( 'skill' => A2ASkills::QUOTE ), $data ) ) ) );
			return (array) rest_get_server()->dispatch( $request )->get_data();
		};
		$contact = array(
			'name'    => 'Hukuk müşavirliği',
			'company' => 'Alıcı A.Ş.',
			'email'   => 'hukuk@alici.example',
		);

		$referral = $send(
			array(
				'message' => 'Uluslararası tahkim davalarında deneyiminiz ve referans işleriniz hakkında bilgi rica ederiz.',
				'contact' => $contact,
			)
		);
		$this->assertSame( 'TASK_STATE_COMPLETED', $referral['result']['task']['status']['state'] );

		$fee = $send(
			array(
				'message' => 'Tahkim davası için ücret ne kadar olur?',
				'contact' => $contact,
			)
		);
		$this->assertSame( 'TASK_STATE_REJECTED', $fee['result']['task']['status']['state'] );

		$quote = $send(
			array(
				'kind'    => Inquiry::KIND_QUOTE_REQUEST,
				'message' => 'Danışmanlık için teklif rica ederiz.',
				'contact' => $contact,
			)
		);
		$this->assertSame( 'TASK_STATE_REJECTED', $quote['result']['task']['status']['state'], 'Quote requests are not a kind this site takes.' );

		$stored = ( new WpInquiryRepository() )->list_inquiries();
		$this->assertCount( 1, $stored );
		$this->assertSame( array( Inquiry::KIND_REFERRAL, Inquiry::STATUS_NEW, Inquiry::CHANNEL_A2A ), array( $stored[0]->kind, $stored[0]->status, $stored[0]->channel ) );
	}
}
