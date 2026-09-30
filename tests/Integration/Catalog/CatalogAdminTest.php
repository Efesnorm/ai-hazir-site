<?php
/**
 * AI Katalog admin screens.
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\Tests\Integration\Catalog;

use AIHazirSite\WordPress\Admin\AdminMenu;
use AIHazirSite\Core\Catalog\Listing;
use AIHazirSite\Core\Catalog\ProfileValidator;
use AIHazirSite\Core\Features;
use AIHazirSite\WordPress\Catalog\Admin\CatalogAdmin;
use AIHazirSite\WordPress\Catalog\Admin\FormState;
use AIHazirSite\WordPress\Catalog\CatalogModule;
use AIHazirSite\WordPress\Catalog\WpListingRepository;
use AIHazirSite\WordPress\Catalog\WpProfileRepository;
use WP_UnitTestCase;
use WPDieException;

/**
 * Catalog admin integration tests.
 *
 * @covers \AIHazirSite\WordPress\Catalog\Admin\CatalogAdmin
 * @covers \AIHazirSite\WordPress\Catalog\Admin\FormState
 * @covers \AIHazirSite\WordPress\Catalog\CatalogModule
 */
final class CatalogAdminTest extends WP_UnitTestCase {

	/**
	 * Admin under test.
	 *
	 * @var CatalogAdmin
	 */
	private CatalogAdmin $admin;

	/**
	 * Administrator with the catalog on.
	 */
	public function set_up(): void {
		parent::set_up();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		Features::set( Features::CATALOG, true );
		$this->admin = new CatalogAdmin();
	}

	/**
	 * Clears request globals.
	 */
	public function tear_down(): void {
		unset( $_REQUEST['_wpnonce'], $_GET['message'] );
		set_current_screen( 'front' );
		parent::tear_down();
	}

	/**
	 * Sets a valid nonce for an action.
	 *
	 * @param string $action Nonce action.
	 */
	private function nonce( string $action ): void {
		$_REQUEST['_wpnonce'] = wp_create_nonce( $action );
	}

	/**
	 * A form submission.
	 *
	 * @param array<string, string> $overrides Changes.
	 * @return array<string, string>
	 */
	private static function post( array $overrides = array() ): array {
		return array_merge(
			array(
				'type'        => 'offer',
				'id'          => '',
				'title'       => 'NYY kablo <script>alert(1)</script>',
				'description' => "Satır 1\nSatır 2",
				'price_min'   => '10',
				'price_max'   => '12,5',
				'currency'    => 'try',
				'valid_until' => gmdate( 'Y-m-d', time() + DAY_IN_SECONDS * 10 ),
				'attributes'  => 'kesit: 3x2,5',
			),
			$overrides
		);
	}

	/**
	 * A valid submission is sanitized and saved through the service.
	 */
	public function test_save_listing(): void {
		$this->nonce( CatalogAdmin::SAVE_LISTING );

		$redirect = $this->admin->handle_save_listing( self::post() );

		$this->assertStringContainsString( 'message=saved', $redirect );
		$listings = ( new WpListingRepository() )->all( 'offer' );
		$this->assertCount( 1, $listings );
		$this->assertSame( 'NYY kablo', $listings[0]->title, 'Tags stripped by sanitize_text_field.' );
		$this->assertSame( "Satır 1\nSatır 2", $listings[0]->description );
		$this->assertSame( '12.5', $listings[0]->price_max );
	}

	/**
	 * Invalid data is not saved; the form comes back with clear messages and the input kept.
	 */
	public function test_invalid_listing_shows_errors(): void {
		$this->nonce( CatalogAdmin::SAVE_LISTING );

		$redirect = $this->admin->handle_save_listing( self::post( array( 'title' => '', 'price_min' => '20' ) ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound

		$this->assertStringContainsString( 'new=1', $redirect );
		$this->assertSame( array(), ( new WpListingRepository() )->all( 'offer' ) );

		$html = CatalogAdmin::render_form( 'offer', null, FormState::take() );
		$this->assertStringContainsString( 'id="aihs-errors"', $html );
		$this->assertStringContainsString( 'Başlık boş olamaz.', $html );
		$this->assertStringContainsString( 'En düşük fiyat en yüksek fiyattan büyük olamaz.', $html );
		$this->assertStringContainsString( 'value="20"', $html, 'Input is kept.' );
		$this->assertSame( array(), FormState::take()['errors'], 'State is read once.' );
	}

	/**
	 * Without a nonce nothing is saved.
	 */
	public function test_save_requires_nonce(): void {
		$this->expectException( WPDieException::class );
		try {
			$this->admin->handle_save_listing( self::post() );
		} finally {
			$this->assertSame( array(), ( new WpListingRepository() )->all( 'offer' ) );
		}
	}

	/**
	 * Users without the capability can neither save nor open the screens.
	 */
	public function test_unauthorized_user_is_rejected(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$this->nonce( CatalogAdmin::SAVE_LISTING );

		foreach ( array( fn() => $this->admin->handle_save_listing( self::post() ), fn() => $this->admin->render_type( 'offer' ), fn() => $this->admin->render_profile() ) as $call ) {
			try {
				$call();
				$this->fail( 'Editor must be rejected.' );
			} catch ( WPDieException $e ) {
				$this->assertStringContainsString( 'yetki', $e->getMessage() );
			}
		}
		$this->assertSame( array(), ( new WpListingRepository() )->all( 'offer' ) );
	}

	/**
	 * Delete needs the per-listing nonce and the right type.
	 */
	public function test_delete(): void {
		$id = (int) CatalogModule::service()->save_listing(
			array(
				'type'  => 'supply',
				'title' => 'Tedarik',
			)
		)->listing()?->id;

		$this->nonce( CatalogAdmin::DELETE_LISTING . '_' . $id );
		$this->assertStringContainsString( 'message=not_found', $this->admin->handle_delete_listing( array( 'id' => (string) $id, 'type' => 'offer' ) ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		$this->assertNotNull( ( new WpListingRepository() )->find( $id ), 'Another type\'s screen cannot delete it.' );

		$this->assertStringContainsString( 'message=deleted', $this->admin->handle_delete_listing( array( 'id' => (string) $id, 'type' => 'supply' ) ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		$this->assertNull( ( new WpListingRepository() )->find( $id ) );

		$this->nonce( CatalogAdmin::DELETE_LISTING . '_999' );
		$this->expectException( WPDieException::class );
		$this->admin->handle_delete_listing( array( 'id' => (string) $id, 'type' => 'supply' ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
	}

	/**
	 * The list shows type, validity and last update; expired listings are marked, not removed.
	 */
	public function test_list_columns_and_expired_mark(): void {
		$html = CatalogAdmin::render_list(
			'demand',
			array(
				new Listing( 1, 'demand', 'Güncel', valid_until: '2026-12-31', updated_at: '2026-09-27T10:00:00Z' ),
				new Listing( 2, 'demand', 'Eski <b>', valid_until: '2026-01-31', updated_at: '2026-01-01T10:00:00Z' ),
			),
			'2026-09-27'
		);

		foreach ( array( 'Tür', 'Geçerlilik', 'Son güncelleme' ) as $column ) {
			$this->assertStringContainsString( '<th>' . $column . '</th>', $html );
		}
		$this->assertSame( 1, substr_count( $html, 'aihs-expired' ) );
		$this->assertStringContainsString( 'süresi doldu', $html );
		$this->assertStringContainsString( 'Eski &lt;b&gt;', $html, 'Output is escaped.' );
		$this->assertStringContainsString( '_wpnonce=', $html, 'Delete links carry a nonce.' );
	}

	/**
	 * A personal e-mail saves the profile and shows the warning; a corporate one does not.
	 */
	public function test_profile_personal_email_warning(): void {
		$this->nonce( CatalogAdmin::SAVE_PROFILE );

		$redirect = $this->admin->handle_save_profile( array( 'name' => 'Örnek A.Ş.', 'contact_email' => 'ali.veli@gmail.com' ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		$this->assertStringContainsString( 'message=saved', $redirect );
		$this->assertSame( 'ali.veli@gmail.com', ( new WpProfileRepository() )->get()->contact_email );

		$html = CatalogAdmin::render_profile_form( ( new WpProfileRepository() )->get(), FormState::take() );
		$this->assertSame( 1, substr_count( $html, ProfileValidator::PERSONAL_EMAIL_WARNING ) );
		$this->assertSame( 1, substr_count( CatalogAdmin::render_profile_form( ( new WpProfileRepository() )->get(), FormState::take() ), 'aihs-warning' ), 'Warning stays while the address is personal.' );

		$this->admin->handle_save_profile( array( 'name' => 'Örnek A.Ş.', 'contact_email' => 'satis@ornek.com.tr' ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		$this->assertStringNotContainsString( 'aihs-warning', CatalogAdmin::render_profile_form( ( new WpProfileRepository() )->get(), FormState::take() ) );
	}

	/**
	 * With the feature off no menu or handler is registered, and stored data stays.
	 */
	public function test_feature_off_hides_menu_and_keeps_data(): void {
		global $submenu;
		$id = (int) CatalogModule::service()->save_listing(
			array(
				'type'  => 'offer',
				'title' => 'Kalıcı',
			)
		)->listing()?->id;
		set_current_screen( 'dashboard' );

		remove_all_actions( 'admin_post_' . CatalogAdmin::SAVE_LISTING );
		remove_all_actions( 'admin_menu' );
		Features::set( Features::CATALOG, false );
		( new CatalogModule() )->register();

		$this->assertFalse( has_action( 'admin_post_' . CatalogAdmin::SAVE_LISTING ) );
		$this->assertFalse( has_action( 'admin_menu' ) );
		$this->assertNotNull( ( new WpListingRepository() )->find( $id ), 'Data kept.' );

		Features::set( Features::CATALOG, true );
		( new CatalogModule() )->register();
		do_action( 'admin_menu' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core hook.
		// 1.15.0: under the plugin's single top-level menu.
		$catalog = array( 'aihs-catalog', 'aihs-catalog-demand', 'aihs-catalog-supply', 'aihs-catalog-profile' );
		$this->assertSame(
			$catalog,
			array_values( array_intersect( array_column( $submenu[ AdminMenu::PARENT ] ?? array(), 2 ), $catalog ) )
		);
	}
}
