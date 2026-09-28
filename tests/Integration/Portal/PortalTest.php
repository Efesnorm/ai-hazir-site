<?php
/**
 * Portal mode: storage, business users, admin screens, business pages.
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\Tests\Integration\Portal;

use AIHazirSite\Core\Catalog\ListingType;
use AIHazirSite\Core\Features;
use AIHazirSite\Core\Measurement\Hit;
use AIHazirSite\Core\Portal\Business;
use AIHazirSite\WordPress\Catalog\Admin\FormState;
use AIHazirSite\WordPress\Catalog\CatalogModule;
use AIHazirSite\WordPress\Catalog\WpListingRepository;
use AIHazirSite\WordPress\Portal\BusinessAdmin;
use AIHazirSite\WordPress\Portal\Portal;
use AIHazirSite\WordPress\Portal\PortalAdmin;
use AIHazirSite\WordPress\Portal\PortalModule;
use AIHazirSite\WordPress\Portal\WpBusinessRepository;
use AIHazirSite\WordPress\Schema\CatalogPage;
use AIHazirSite\WordPress\Schema\SchemaModule;
use AIHazirSite\WordPress\Storage\WpdbHitRepository;
use AIHazirSite\WordPress\Uninstaller;
use WP_UnitTestCase;
use WPDieException;

/**
 * Business users see and change only their own listings; per-business figures add up.
 *
 * @covers \AIHazirSite\WordPress\Portal\Portal
 * @covers \AIHazirSite\WordPress\Portal\PortalAdmin
 * @covers \AIHazirSite\WordPress\Portal\BusinessAdmin
 * @covers \AIHazirSite\WordPress\Portal\WpBusinessRepository
 * @covers \AIHazirSite\WordPress\Portal\PortalModule
 */
final class PortalTest extends WP_UnitTestCase {

	/**
	 * Admin id.
	 *
	 * @var int
	 */
	private int $admin;

	/**
	 * Portal on, clean data, an admin logged in.
	 */
	public function set_up(): void {
		parent::set_up();
		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i', WpdbHitRepository::table() ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		foreach ( array( Features::OPTION, WpBusinessRepository::OPTION, 'aihs_schema_cache' ) as $option ) {
			delete_option( $option );
		}
		Features::set( Features::CATALOG, true );
		Features::set( Features::PORTAL_MODE, true );
		Features::set( Features::SCHEMA_OUTPUT, true );
		$this->admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->admin );
		add_filter( 'user_has_cap', array( Portal::class, 'grant' ), 10, 4 );
		$this->set_permalink_structure( '/%postname%/' );
	}

	/**
	 * Clears the request.
	 */
	public function tear_down(): void {
		unset( $_REQUEST['_wpnonce'] );
		parent::tear_down();
	}

	/**
	 * A business saved through the admin form.
	 *
	 * @param string $name Name.
	 */
	private function business( string $name ): int {
		$_REQUEST['_wpnonce'] = wp_create_nonce( PortalAdmin::SAVE_BUSINESS );
		$url                  = PortalAdmin::handle_save_business(
			array(
				'name'          => $name,
				'country'       => 'TR',
				'sector'        => 'Turizm',
				'contact_email' => 'info@' . Business::slugify( $name ) . '.com.tr',
			)
		);
		$this->assertStringContainsString( 'message=saved', $url );
		parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $args );
		return (int) $args['edit'];
	}

	/**
	 * A subscriber linked to a business through the admin form.
	 *
	 * @param int $business Business id.
	 */
	private function business_user( int $business ): int {
		wp_set_current_user( $this->admin );
		$user                 = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$_REQUEST['_wpnonce'] = wp_create_nonce( PortalAdmin::LINK_USER );
		$url                  = PortalAdmin::handle_link_user(
			array(
				'user'     => get_userdata( $user )->user_login,
				'business' => (string) $business,
			)
		);
		$this->assertStringContainsString( 'message=linked', $url );
		return $user;
	}

	/**
	 * A listing saved by a business user through "İşletmem".
	 *
	 * @param int    $user  User id.
	 * @param string $title Title.
	 * @param int    $id    Listing to update (0 = new).
	 */
	private function save_as( int $user, string $title, int $id = 0 ): string {
		wp_set_current_user( $user );
		$_REQUEST['_wpnonce'] = wp_create_nonce( BusinessAdmin::SAVE );
		return BusinessAdmin::handle_save(
			array(
				'id'    => (string) $id,
				'type'  => ListingType::OFFER,
				'title' => $title,
			)
		);
	}

	/**
	 * A business user can neither see nor change another business's listing.
	 */
	public function test_business_users_are_isolated(): void {
		$a      = $this->business( 'A Balon Turları' );
		$b      = $this->business( 'B Kaya Otel' );
		$user_a = $this->business_user( $a );
		$user_b = $this->business_user( $b );

		$this->assertTrue( user_can( $user_a, Portal::CAPABILITY ) );
		$this->assertFalse( user_can( self::factory()->user->create( array( 'role' => 'subscriber' ) ), Portal::CAPABILITY ) );
		$this->assertFalse( user_can( $user_a, 'manage_options' ) );

		$this->assertStringContainsString( 'message=saved', $this->save_as( $user_a, 'Gün doğumu balon turu' ) );
		$listings = ( new WpListingRepository() )->listings_of( $a );
		$this->assertCount( 1, $listings );
		$listing = $listings[0];

		// B's page does not show A's listing, and B cannot open it for editing.
		wp_set_current_user( $user_b );
		$page_b = BusinessAdmin::page( Portal::user_business( $user_b ), $listing, FormState::take() );
		$this->assertStringNotContainsString( 'Gün doğumu balon turu', $page_b );
		$this->assertStringContainsString( 'id="aihs-my-listing-form"', $page_b );
		$this->assertStringContainsString( 'value="0"', $page_b, 'The form is a new-listing form, not A\'s listing.' );

		// B cannot update it...
		$url = $this->save_as( $user_b, 'Ele geçirildi', $listing );
		$this->assertStringNotContainsString( 'message=saved', $url );
		$this->assertArrayHasKey( 'id', FormState::take()['errors'] );
		$this->assertSame( 'Gün doğumu balon turu', ( new WpListingRepository() )->find( $listing )?->title );

		// ...nor delete it.
		$_REQUEST['_wpnonce'] = wp_create_nonce( BusinessAdmin::DELETE . '_' . $listing );
		try {
			BusinessAdmin::handle_delete( array( 'id' => (string) $listing ) );
			$this->fail( 'Deleting another business\'s listing must die.' );
		} catch ( WPDieException $e ) {
			$this->assertNotNull( ( new WpListingRepository() )->find( $listing ) );
		}

		// A can.
		$this->assertStringContainsString( 'message=saved', $this->save_as( $user_a, 'Balon turu (güncel)', $listing ) );
		$this->assertStringContainsString( 'Balon turu (güncel)', BusinessAdmin::page( Portal::user_business( $user_a ), 0, FormState::take() ) );
		$_REQUEST['_wpnonce'] = wp_create_nonce( BusinessAdmin::DELETE . '_' . $listing );
		$this->assertStringContainsString( 'message=deleted', BusinessAdmin::handle_delete( array( 'id' => (string) $listing ) ) );
		$this->assertNull( ( new WpListingRepository() )->find( $listing ) );

		// A user without a business, and the portal feature off, get nothing.
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$this->assertNull( BusinessAdmin::current() );
		$_REQUEST['_wpnonce'] = wp_create_nonce( BusinessAdmin::SAVE );
		$this->expectException( WPDieException::class );
		BusinessAdmin::handle_save( array( 'title' => 'x' ) );
	}

	/**
	 * Admin: forms refuse bad input; linking rules; deleting a business with listings is refused.
	 */
	public function test_admin_rules(): void {
		$a = $this->business( 'A Balon Turları' );
		$this->assertSame( 'a-balon-turlari', Portal::businesses()->business( $a )?->slug );

		$_REQUEST['_wpnonce'] = wp_create_nonce( PortalAdmin::SAVE_BUSINESS );
		PortalAdmin::handle_save_business(
			array(
				'name' => 'Başka',
				'slug' => 'a-balon-turlari',
			)
		);
		$this->assertArrayHasKey( 'slug', FormState::take()['errors'] );

		$_REQUEST['_wpnonce'] = wp_create_nonce( PortalAdmin::LINK_USER );
		PortalAdmin::handle_link_user(
			array(
				'user'     => get_userdata( $this->admin )->user_login,
				'business' => (string) $a,
			)
		);
		$this->assertArrayHasKey( 'user', FormState::take()['errors'], 'Admins are not linked.' );
		PortalAdmin::handle_link_user(
			array(
				'user'     => 'olmayan-kullanici',
				'business' => (string) $a,
			)
		);
		$this->assertArrayHasKey( 'user', FormState::take()['errors'] );

		$user = $this->business_user( $a );
		$this->save_as( $user, 'Balon turu' );
		wp_set_current_user( $this->admin );
		$_REQUEST['_wpnonce'] = wp_create_nonce( PortalAdmin::DELETE_BUSINESS . '_' . $a );
		$this->assertStringContainsString( 'edit=' . $a, PortalAdmin::handle_delete_business( array( 'id' => (string) $a ) ) );
		$this->assertNotNull( Portal::businesses()->business( $a ) );

		// Moving the listing to the portal allows deleting; the user's link goes with the business.
		$listing              = ( new WpListingRepository() )->listings_of( $a )[0];
		$_REQUEST['_wpnonce'] = wp_create_nonce( PortalAdmin::ASSIGN );
		$this->assertStringContainsString( 'message=assigned', PortalAdmin::handle_assign( array( 'assign' => array( $listing => '0' ) ) ) );
		$_REQUEST['_wpnonce'] = wp_create_nonce( PortalAdmin::DELETE_BUSINESS . '_' . $a );
		$this->assertStringContainsString( 'message=deleted', PortalAdmin::handle_delete_business( array( 'id' => (string) $a ) ) );
		$this->assertNull( Portal::user_business( $user ) );
		$this->assertSame( '', get_user_meta( $user, Portal::USER_META, true ) );
		$this->assertContains( WpBusinessRepository::OPTION, Uninstaller::options() );

		// Non-admins cannot use the admin handlers.
		wp_set_current_user( $user );
		$_REQUEST['_wpnonce'] = wp_create_nonce( PortalAdmin::SAVE_BUSINESS );
		$this->expectException( WPDieException::class );
		PortalAdmin::handle_save_business( array( 'name' => 'X' ) );
	}

	/**
	 * Per-business report rows add up to the totals; the business page is served at its own path.
	 */
	public function test_report_and_pages(): void {
		$a = $this->business( 'A Balon Turları' );
		$b = $this->business( 'B Kaya Otel' );
		$this->save_as( $this->business_user( $a ), 'Balon turu' );
		$this->save_as( $this->business_user( $b ), 'Kaya otel odası' );
		wp_set_current_user( $this->admin );
		CatalogModule::service()->save_listing(
			array(
				'type'  => ListingType::OFFER,
				'title' => 'Portal paketi',
			)
		);

		$hits = new WpdbHitRepository();
		$day  = current_time( 'Y-m-d' );
		$hits->increment( $day, Hit::KIND_BOT, 'gptbot', Portal::page_path( Portal::businesses()->business( $a ) ), true );
		$hits->increment( $day, Hit::KIND_BOT, 'claudebot', Portal::page_path( Portal::businesses()->business( $a ) ), false );
		$hits->increment( $day, Hit::KIND_BOT, 'gptbot', Portal::page_path( Portal::businesses()->business( $b ) ), true );
		$hits->increment( $day, Hit::KIND_BOT, 'gptbot', '/ai-katalog/', true );

		$report = PortalAdmin::report();
		$this->assertSame( 2, $report['rows'][ $a ]['page_hits'] );
		$this->assertSame( 1, $report['rows'][ $b ]['page_hits'] );
		$this->assertSame( array( 1, 1, 1 ), array( $report['rows'][ $a ]['listings'], $report['rows'][ $b ]['listings'], $report['rows'][0]['listings'] ) );
		foreach ( array( 'page_hits', 'listings', 'inquiries' ) as $column ) {
			$this->assertSame( $report['totals'][ $column ], array_sum( array_column( $report['rows'], $column ) ), $column );
		}
		$html = PortalAdmin::page( 0, FormState::take() );
		$this->assertStringContainsString( 'data-business="' . $a . '"><td>A Balon Turları</td><td data-value="page_hits">2</td>', $html );

		// Business page: its own listings only; the whole catalog links to it and names the business.
		( new PortalModule() )->register();
		PortalModule::rewrite();
		$this->assertArrayHasKey( Portal::REWRITE, (array) get_option( 'rewrite_rules' ) );
		$this->go_to( Portal::page_url( Portal::businesses()->business( $a ) ) );
		$this->assertSame( 'a-balon-turlari', get_query_var( Portal::QUERY_VAR ) );

		$page = CatalogPage::render_html( null, Portal::businesses()->business( $a ) );
		$this->assertStringContainsString( 'Balon turu', $page );
		$this->assertStringNotContainsString( 'Kaya otel odası', $page );
		$this->assertStringNotContainsString( 'Portal paketi', $page );
		$this->assertStringContainsString( '<link rel="canonical" href="' . esc_url( Portal::page_url( Portal::businesses()->business( $a ) ) ) . '">', $page );

		$all = CatalogPage::render_html();
		$this->assertStringContainsString( 'id="aihs-businesses"', $all );
		$this->assertStringContainsString( 'İşletme: <a href="' . esc_url( Portal::page_url( Portal::businesses()->business( $b ) ) ) . '">B Kaya Otel</a>', $all );

		$document = SchemaModule::catalog_document();
		$sellers  = array();
		foreach ( $document['dataFeedElement'] as $entry ) {
			$sellers[ $entry['item']['name'] ] = $entry['item']['offers']['seller']['name'] ?? ( $entry['item']['offers']['seller']['@id'] ?? '' );
		}
		$this->assertSame( 'A Balon Turları', $sellers['Balon turu'] );
		$this->assertSame( 'B Kaya Otel', $sellers['Kaya otel odası'] );
		$this->assertStringEndsWith( '#organization', $sellers['Portal paketi'], 'Portal listing: the portal Organization.' );
	}

	/**
	 * Portal mode off: no capability, no page rule, catalog unchanged.
	 */
	public function test_off(): void {
		$a    = $this->business( 'A Balon Turları' );
		$user = $this->business_user( $a );
		Features::set( Features::PORTAL_MODE, false );
		remove_filter( 'user_has_cap', array( Portal::class, 'grant' ), 10 );
		global $wp_rewrite;
		unset( $wp_rewrite->extra_rules_top[ Portal::REWRITE ] ); // A new request starts without rules added by earlier tests.
		( new PortalModule() )->register();
		PortalModule::rewrite();
		$this->assertFalse( user_can( $user, Portal::CAPABILITY ) );
		$this->assertArrayNotHasKey( Portal::REWRITE, (array) get_option( 'rewrite_rules' ) );
		$this->assertStringNotContainsString( 'id="aihs-businesses"', CatalogPage::render_html() );
	}
}
