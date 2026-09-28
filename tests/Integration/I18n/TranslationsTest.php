<?php
/**
 * Translation storage, the Çeviriler screen and multilingual plugin detection.
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\Tests\Integration\I18n;

use AIHazirSite\Core\Catalog\ListingType;
use AIHazirSite\Core\Features;
use AIHazirSite\WordPress\Catalog\Admin\FormState;
use AIHazirSite\WordPress\Catalog\CatalogModule;
use AIHazirSite\WordPress\Catalog\WpListingRepository;
use AIHazirSite\WordPress\Catalog\WpProfileRepository;
use AIHazirSite\WordPress\I18n\LanguageSource;
use AIHazirSite\WordPress\I18n\Multilingual;
use AIHazirSite\WordPress\I18n\TranslationsAdmin;
use AIHazirSite\WordPress\Uninstaller;
use WP_UnitTestCase;
use WPDieException;

/**
 * WordPress side of A8.
 *
 * @covers \AIHazirSite\WordPress\I18n\LanguageSource
 * @covers \AIHazirSite\WordPress\I18n\Multilingual
 * @covers \AIHazirSite\WordPress\I18n\TranslationsAdmin
 * @covers \AIHazirSite\WordPress\Catalog\WpListingRepository
 * @covers \AIHazirSite\WordPress\Catalog\WpProfileRepository
 */
final class TranslationsTest extends WP_UnitTestCase {

	/**
	 * Admin user, features on, two languages.
	 */
	public function set_up(): void {
		parent::set_up();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		foreach ( array( LanguageSource::OPTION, WpProfileRepository::OPTION, WpProfileRepository::TRANSLATIONS_OPTION, Features::OPTION ) as $option ) {
			delete_option( $option );
		}
		Features::set( Features::CATALOG, true );
		Features::set( Features::MULTILINGUAL, true );
		update_option(
			LanguageSource::OPTION,
			array(
				'default'   => 'tr',
				'languages' => array( 'en', 'de' ),
			)
		);
	}

	/**
	 * Clears the request.
	 */
	public function tear_down(): void {
		unset( $_REQUEST['_wpnonce'], $GLOBALS['aihs_test_polylang'] );
		parent::tear_down();
	}

	/**
	 * A stored listing.
	 */
	private static function listing(): int {
		$result = CatalogModule::service()->save_listing(
			array(
				'type'        => ListingType::OFFER,
				'title'       => 'NYY kablo',
				'description' => 'Bakır iletkenli enerji kablosu.',
				'category'    => 'Kablo',
				'region'      => 'Marmara',
			)
		);
		return (int) $result->listing()?->id;
	}

	/**
	 * Stores through the service, reads back through the repositories, gone with the listing.
	 */
	public function test_storage(): void {
		$id       = self::listing();
		$settings = Multilingual::settings();
		$this->assertSame( array(), CatalogModule::service()->save_listing_translation( $id, 'en', array( 'title' => 'NYY cable <b>' ), $settings ) );
		$this->assertSame( array( 'en' => array( 'title' => 'NYY cable <b>' ) ), ( new WpListingRepository() )->translations( $id ) );

		$localized = Multilingual::listing( ( new WpListingRepository() )->find( $id ), 'en' );
		$this->assertSame( 'NYY cable <b>', $localized->record->title );
		$this->assertSame( array( 'description', 'category', 'region' ), $localized->missing );

		$this->assertSame( array(), CatalogModule::service()->save_profile_translation( 'de', array( 'sector' => 'Kabelherstellung' ), $settings ) );
		$this->assertSame( array( 'de' => array( 'sector' => 'Kabelherstellung' ) ), ( new WpProfileRepository() )->profile_translations() );
		$this->assertContains( WpProfileRepository::TRANSLATIONS_OPTION, Uninstaller::options() );
		$this->assertContains( LanguageSource::OPTION, Uninstaller::options() );

		CatalogModule::service()->delete_listing( $id );
		$this->assertSame( array(), get_post_meta( $id ), 'Translations removed with the listing.' );
	}

	/**
	 * Inactive (feature off or one language): no answer language at all.
	 */
	public function test_active_only_with_two_languages(): void {
		$this->assertTrue( Multilingual::active() );
		$this->assertSame( 'en', Multilingual::language( null, 'en-US,en;q=0.9' ) );

		update_option( LanguageSource::OPTION, array( 'default' => 'tr' ) );
		$this->assertFalse( Multilingual::active() );
		$this->assertNull( Multilingual::language( 'en' ) );

		update_option(
			LanguageSource::OPTION,
			array(
				'default'   => 'tr',
				'languages' => array( 'en' ),
			)
		);
		Features::set( Features::MULTILINGUAL, false );
		$this->assertNull( Multilingual::language( 'en' ) );
	}

	/**
	 * Language form: nonce and capability; codes normalised; unknown codes reported.
	 */
	public function test_language_form(): void {
		$_REQUEST['_wpnonce'] = wp_create_nonce( TranslationsAdmin::SAVE_LANGUAGES );
		$url                  = TranslationsAdmin::handle_save_languages(
			array(
				'default_language' => 'TR',
				'languages'        => 'en-GB, ar; xx1, en',
			)
		);
		$this->assertSame( array( 'tr', 'en', 'ar' ), Multilingual::settings()->languages );
		$this->assertStringNotContainsString( 'message=saved', $url );
		$this->assertStringContainsString( 'xx1', FormState::take()['errors']['languages'] );

		unset( $_REQUEST['_wpnonce'] );
		$this->expectException( WPDieException::class );
		TranslationsAdmin::handle_save_languages( array( 'languages' => 'de' ) );
	}

	/**
	 * Listing form: every translated language saved; a too long value is reported and kept in the form.
	 */
	public function test_listing_form(): void {
		$id                   = self::listing();
		$_REQUEST['_wpnonce'] = wp_create_nonce( TranslationsAdmin::SAVE_LISTING );
		$url                  = TranslationsAdmin::handle_save_listing(
			array(
				'id' => (string) $id,
				't'  => array(
					'en' => array(
						'title'       => 'NYY cable',
						'description' => "Line 1\nLine 2",
					),
					'de' => array( 'title' => 'NYY-Kabel<script>x</script>' ),
				),
			)
		);
		$this->assertStringContainsString( 'message=saved', $url );
		$stored = ( new WpListingRepository() )->translations( $id );
		$this->assertSame( "Line 1\nLine 2", $stored['en']['description'] );
		$this->assertSame( 'NYY-Kabel', $stored['de']['title'], 'Sanitized.' );

		$url = TranslationsAdmin::handle_save_listing(
			array(
				'id' => (string) $id,
				't'  => array( 'en' => array( 'title' => str_repeat( 'a', 201 ) ) ),
			)
		);
		$this->assertStringContainsString( 'listing=' . $id, $url );
		$state = FormState::take();
		$this->assertArrayHasKey( 'en.title', $state['errors'] );
		$this->assertSame( 'NYY cable', ( new WpListingRepository() )->translations( $id )['en']['title'], 'Rejected language unchanged.' );

		$html = TranslationsAdmin::page( $id, $state );
		$this->assertStringContainsString( 'lang="en" name="t[en][title]"', $html );
		$this->assertStringContainsString( str_repeat( 'a', 201 ), $html, 'Submitted value kept in the form.' );
	}

	/**
	 * Overview: status per language; profile form; single-language hint.
	 */
	public function test_overview(): void {
		$id = self::listing();
		CatalogModule::service()->save_listing_translation(
			$id,
			'en',
			array(
				'title'       => 'NYY cable',
				'description' => 'Copper power cable.',
				'category'    => 'Cable',
				'region'      => 'Marmara',
			),
			Multilingual::settings()
		);
		$empty = array(
			'errors'   => array(),
			'input'    => array(),
			'warnings' => array(),
		);
		$html  = TranslationsAdmin::page( 0, $empty );
		$this->assertStringContainsString( 'data-language="en" data-missing="0"', $html );
		$this->assertStringContainsString( 'data-language="de" data-missing="4"', $html );
		$this->assertStringContainsString( 'name="t[de][sector]"', $html );

		update_option( LanguageSource::OPTION, array( 'default' => 'tr' ) );
		$this->assertStringContainsString( 'id="aihs-translations-single"', TranslationsAdmin::page( 0, $empty ) );
	}

	/**
	 * Polylang (its public functions) and WPML (its filters) are followed when present.
	 */
	public function test_multilingual_plugins(): void {
		$this->assertNull( LanguageSource::plugin() );

		// WPML's documented filters.
		add_filter(
			'wpml_active_languages',
			static fn(): array => array(
				'en' => array(),
				'fr' => array(),
			)
		); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WPML API.
		add_filter( 'wpml_default_language', static fn(): string => 'en' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WPML API.
		add_filter( 'wpml_current_language', static fn(): string => 'fr' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WPML API.
		$this->assertSame( LanguageSource::WPML, LanguageSource::plugin() );
		$this->assertSame( array( 'en', 'fr' ), Multilingual::settings()->languages );
		$this->assertSame( 'fr', LanguageSource::current() );
		$this->assertStringContainsString( 'id="aihs-languages-plugin"', TranslationsAdmin::page( 0, FormState::take() ) );
		$this->assertStringNotContainsString( 'id="aihs-languages-form"', TranslationsAdmin::page( 0, FormState::take() ) );
		remove_all_filters( 'wpml_active_languages' );
		remove_all_filters( 'wpml_default_language' );
		remove_all_filters( 'wpml_current_language' );

		// Polylang's documented functions (stand-ins driven by a global; without languages = inactive).
		require_once __DIR__ . '/polylang-functions.php';
		$GLOBALS['aihs_test_polylang'] = array(
			'languages' => array( 'de', 'en', 'tr' ),
			'default'   => 'de',
			'current'   => 'en',
		);
		$this->assertSame( LanguageSource::POLYLANG, LanguageSource::plugin() );
		$this->assertSame( array( 'de', 'en', 'tr' ), Multilingual::settings()->languages );
		$this->assertSame( 'en', LanguageSource::current() );
		unset( $GLOBALS['aihs_test_polylang'] );
		$this->assertNull( LanguageSource::plugin(), 'Polylang without languages is not followed.' );
	}
}
