<?php
/**
 * Edge cases of 1.1.0–1.6.0 not covered by the acceptance tests.
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\Tests\Integration;

use AIHazirSite\Adapters\A2A\A2ASkills;
use AIHazirSite\Adapters\A2A\JsonRpcServer;
use AIHazirSite\Core\Features;
use AIHazirSite\Core\Inquiry\Inquiry;
use AIHazirSite\Core\Security\AuditLog;
use AIHazirSite\WordPress\A2A\A2AModule;
use AIHazirSite\WordPress\Catalog\Admin\FormState;
use AIHazirSite\WordPress\Catalog\WpProfileRepository;
use AIHazirSite\WordPress\I18n\LanguageSource;
use AIHazirSite\WordPress\I18n\TranslationsAdmin;
use AIHazirSite\WordPress\Inquiry\WpAuditRepository;
use AIHazirSite\WordPress\Portal\BusinessPage;
use AIHazirSite\WordPress\Portal\Portal;
use AIHazirSite\WordPress\Portal\PortalModule;
use AIHazirSite\WordPress\Portal\WpBusinessRepository;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * Unknown business page, profile translation form, A2A rate limit.
 *
 * @covers \AIHazirSite\WordPress\Portal\BusinessPage
 * @covers \AIHazirSite\WordPress\I18n\TranslationsAdmin
 * @covers \AIHazirSite\WordPress\A2A\A2AModule
 */
final class EdgeCasesTest extends WP_UnitTestCase {

	/**
	 * Clean options; admin user.
	 */
	public function set_up(): void {
		parent::set_up();
		foreach ( array( Features::OPTION, WpBusinessRepository::OPTION, LanguageSource::OPTION, WpProfileRepository::TRANSLATIONS_OPTION ) as $option ) {
			delete_option( $option );
		}
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
	}

	/**
	 * Clears request state.
	 */
	public function tear_down(): void {
		global $wp_rest_server;
		$wp_rest_server = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Test isolation.
		unset( $_REQUEST['_wpnonce'] );
		parent::tear_down();
	}

	/**
	 * A business page for a slug that does not exist is a 404 (nothing rendered, no exit).
	 */
	public function test_unknown_business_page_is_404(): void {
		Features::set( Features::PORTAL_MODE, true );
		Features::set( Features::SCHEMA_OUTPUT, true );
		$this->set_permalink_structure( '/%postname%/' );
		( new PortalModule() )->register();
		PortalModule::rewrite();

		$this->go_to( home_url( '/' . Portal::BASE . '/olmayan-isletme/' ) );
		$this->assertSame( 'olmayan-isletme', get_query_var( Portal::QUERY_VAR ) );
		BusinessPage::maybe_render();
		$this->assertTrue( is_404() );
	}

	/**
	 * Profile translation form: every translated language saved; a too long value reported per language.
	 */
	public function test_profile_translation_form(): void {
		Features::set( Features::CATALOG, true );
		Features::set( Features::MULTILINGUAL, true );
		update_option(
			LanguageSource::OPTION,
			array(
				'default'   => 'tr',
				'languages' => array( 'en', 'de' ),
			)
		);

		$_REQUEST['_wpnonce'] = wp_create_nonce( TranslationsAdmin::SAVE_PROFILE );
		$url                  = TranslationsAdmin::handle_save_profile(
			array(
				't' => array(
					'en' => array( 'sector' => 'Cable manufacturing' ),
					'de' => array( 'sector' => 'Kabelherstellung<b>' ),
				),
			)
		);
		$this->assertStringContainsString( 'message=saved', $url );
		$this->assertSame(
			array(
				'en' => array( 'sector' => 'Cable manufacturing' ),
				'de' => array( 'sector' => 'Kabelherstellung' ),
			),
			( new WpProfileRepository() )->profile_translations()
		);

		$url = TranslationsAdmin::handle_save_profile( array( 't' => array( 'en' => array( 'sector' => str_repeat( 'x', 201 ) ) ) ) );
		$this->assertStringNotContainsString( 'message=saved', $url );
		$this->assertArrayHasKey( 'en.sector', FormState::take()['errors'] );
		$this->assertSame( 'Cable manufacturing', ( new WpProfileRepository() )->profile_translations()['en']['sector'], 'Rejected language unchanged.' );
		$this->assertArrayNotHasKey( 'de', ( new WpProfileRepository() )->profile_translations(), 'An empty field removes that translation.' );
	}

	/**
	 * A2A endpoint: over the limit → 429 with Retry-After, a JSON-RPC error body, audited, no skill run.
	 */
	public function test_a2a_rate_limit(): void {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i', WpAuditRepository::table() ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		Features::set( Features::A2A, true );
		Features::set( Features::CATALOG, true );
		add_filter( 'aihs_a2a_rate_limit', static fn(): int => 1 );
		$_SERVER['REMOTE_ADDR'] = '192.0.2.' . wp_rand( 1, 250 );
		( new A2AModule() )->register();

		$send = static function (): \WP_REST_Response {
			$request = new WP_REST_Request( 'POST', '/aihs/a2a' );
			$request->set_header( 'A2A-Version', '1.0' );
			$request->set_body(
				(string) wp_json_encode(
					JsonRpcServer::send_message(
						wp_generate_uuid4(),
						array(
							'skill'      => A2ASkills::AVAILABILITY,
							'listing_id' => 1,
						)
					)
				)
			);
			return rest_get_server()->dispatch( $request );
		};

		$this->assertSame( 200, $send()->get_status() );
		$limited = $send();
		$this->assertSame( 429, $limited->get_status() );
		$this->assertArrayHasKey( 'Retry-After', $limited->get_headers() );
		$this->assertSame( -32000, $limited->get_data()['error']['code'] );

		$outcomes = array_column(
			array_filter( ( new WpAuditRepository() )->latest( 10 ), static fn( array $e ): bool => Inquiry::CHANNEL_A2A === $e['channel'] ),
			'outcome'
		);
		$this->assertContains( AuditLog::OUTCOME_RATE_LIMITED, $outcomes );
		$this->assertContains( AuditLog::OUTCOME_ACCEPTED, $outcomes );
	}
}
