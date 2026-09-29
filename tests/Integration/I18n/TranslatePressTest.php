<?php
/**
 * TranslatePress as a language source (1.12.0).
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\Tests\Integration\I18n;

use AIHazirSite\Core\Features;
use AIHazirSite\WordPress\Catalog\Admin\FormState;
use AIHazirSite\WordPress\I18n\LanguageSource;
use AIHazirSite\WordPress\I18n\Multilingual;
use AIHazirSite\WordPress\I18n\TranslationsAdmin;
use WP_UnitTestCase;

/**
 * Published languages (locales → ISO 639-1), default first, current language; inactive without languages.
 * TranslatePress's API is simulated with stand-ins (translatepress-functions.php).
 *
 * @covers \AIHazirSite\WordPress\I18n\LanguageSource
 * @covers \AIHazirSite\WordPress\I18n\TranslationsAdmin
 */
final class TranslatePressTest extends WP_UnitTestCase {

	/**
	 * Admin, catalog and multilingual on, stand-ins loaded.
	 */
	public function set_up(): void {
		parent::set_up();
		require_once __DIR__ . '/translatepress-functions.php';
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		delete_option( LanguageSource::OPTION );
		delete_option( Features::OPTION );
		Features::set( Features::CATALOG, true );
		Features::set( Features::MULTILINGUAL, true );
	}

	/**
	 * Clears the simulated state.
	 */
	public function tear_down(): void {
		unset( $GLOBALS['aihs_test_trp'], $GLOBALS['TRP_LANGUAGE'] );
		parent::tear_down();
	}

	/**
	 * A setup like balkantrade.com.tr: Turkish default, English and Macedonian published.
	 */
	public function test_languages_default_and_current(): void {
		$GLOBALS['aihs_test_trp'] = array(
			'published' => array( 'tr_TR', 'en_US', 'mk_MK' ),
			'default'   => 'tr_TR',
		);
		$GLOBALS['TRP_LANGUAGE']  = 'en_US'; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- TranslatePress's global, simulated.

		$this->assertSame( LanguageSource::TRANSLATEPRESS, LanguageSource::plugin() );
		$settings = Multilingual::settings();
		$this->assertSame( 'tr', $settings->default );
		$this->assertSame( array( 'tr', 'en', 'mk' ), $settings->languages );
		$this->assertSame( 'en', LanguageSource::current() );

		$page = TranslationsAdmin::page( 0, FormState::take() );
		$this->assertStringContainsString( 'TranslatePress', $page );
		$this->assertStringNotContainsString( 'id="aihs-languages-form"', $page, 'Our own language form is hidden while TranslatePress is in charge.' );
	}

	/**
	 * The default comes first even when TranslatePress lists another language first.
	 */
	public function test_default_first(): void {
		$GLOBALS['aihs_test_trp'] = array(
			'published' => array( 'en_US', 'de_DE', 'tr_TR' ),
			'default'   => 'tr_TR',
		);
		$this->assertSame( array( 'tr', 'en', 'de' ), Multilingual::settings()->languages );
	}

	/**
	 * Without published languages TranslatePress is not followed; our own setting applies.
	 */
	public function test_inactive_without_languages(): void {
		$this->assertNull( LanguageSource::plugin() );
		$this->assertSame( LanguageSource::site_language(), Multilingual::settings()->default );
	}
}
