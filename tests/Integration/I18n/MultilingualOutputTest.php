<?php
/**
 * A8 acceptance: TR/EN contract, fallback marking, single-language sites unchanged.
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\Tests\Integration\I18n;

use AIHazirSite\Adapters\Abilities\AbilitySchemas;
use AIHazirSite\Adapters\Rest\RestSchemas;
use AIHazirSite\Core\Features;
use AIHazirSite\Tests\Integration\Rest\RestTestCase;
use AIHazirSite\WordPress\Abilities\AbilitiesModule;
use AIHazirSite\WordPress\Catalog\CatalogModule;
use AIHazirSite\WordPress\Catalog\WpProfileRepository;
use AIHazirSite\WordPress\I18n\LanguageSource;
use AIHazirSite\WordPress\I18n\Multilingual;
use AIHazirSite\WordPress\Llms\LlmsModule;
use AIHazirSite\WordPress\Schema\CatalogPage;
use AIHazirSite\WordPress\Schema\SchemaModule;

/**
 * Every channel: REST, abilities (MCP), llms.txt, the catalog page and its JSON-LD.
 *
 * @covers \AIHazirSite\WordPress\Rest\RestModule
 * @covers \AIHazirSite\WordPress\Abilities\AbilitiesModule
 * @covers \AIHazirSite\WordPress\Llms\LlmsModule
 * @covers \AIHazirSite\WordPress\Schema\CatalogPage
 * @covers \AIHazirSite\Adapters\Rest\RestResponder
 * @covers \AIHazirSite\Adapters\Rest\RestSchemas
 * @covers \AIHazirSite\Adapters\Llms\LlmsTxtBuilder
 */
final class MultilingualOutputTest extends RestTestCase {

	/**
	 * Catalog, schema and llms.txt on; languages cleared.
	 */
	public function set_up(): void {
		parent::set_up();
		foreach ( array( LanguageSource::OPTION, 'aihs_profile_translations', 'aihs_schema_cache', 'aihs_llms_cache' ) as $option ) {
			delete_option( $option );
		}
		Features::set( Features::CATALOG, true );
		Features::set( Features::SCHEMA_OUTPUT, true );
		Features::set( Features::LLMS_TXT, true );
		Features::set( Features::ABILITIES, true );
		add_filter( 'aihs_abilities_rate_limit', static fn(): int => 10000 );
	}

	/**
	 * Unregisters our abilities.
	 */
	public function tear_down(): void {
		self::unregister_abilities();
		parent::tear_down();
	}

	/**
	 * Two languages, the multilingual feature on.
	 */
	private static function two_languages(): void {
		Features::set( Features::MULTILINGUAL, true );
		update_option(
			LanguageSource::OPTION,
			array(
				'default'   => 'tr',
				'languages' => array( 'en' ),
			)
		);
		self::boot();
	}

	/**
	 * The catalog fixture plus a profile sector (a translatable profile field).
	 *
	 * @return array<string, int> Listing ids.
	 */
	private static function fixture(): array {
		$ids     = self::catalog();
		$profile = ( new WpProfileRepository() )->get()->to_array();
		$result  = CatalogModule::service()->save_profile(
			array_merge(
				$profile,
				array(
					'sector'         => 'Kablo üretimi',
					'languages'      => 'tr, en',
					'certifications' => '',
				)
			)
		);
		self::assertTrue( $result->is_valid(), implode( ' | ', $result->errors ) );
		return $ids;
	}

	/**
	 * English translation of the cable listing (all its text fields) and of the profile sector;
	 * the service listing stays untranslated.
	 *
	 * @param array<string, int> $ids Listing ids.
	 */
	private static function translate( array $ids ): void {
		$settings = Multilingual::settings();
		CatalogModule::service()->save_listing_translation(
			$ids['cable'],
			'en',
			array(
				'title'    => 'NYY cable',
				'category' => 'Cable',
				'region'   => 'Turkey',
			),
			$settings
		);
		CatalogModule::service()->save_profile_translation( 'en', array( 'sector' => 'Cable manufacturing' ), $settings );
	}

	/**
	 * Registers our abilities on a clean init (as in AbilitiesTest).
	 */
	private static function register_abilities(): void {
		wp_get_abilities();
		remove_all_actions( 'wp_abilities_api_categories_init' );
		remove_all_actions( 'wp_abilities_api_init' );
		( new AbilitiesModule() )->register();
		if ( wp_has_ability_category( AbilitiesModule::CATEGORY ) ) {
			remove_action( 'wp_abilities_api_categories_init', array( AbilitiesModule::class, 'register_category' ) );
		}
		do_action( 'wp_abilities_api_categories_init' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core hook.
		do_action( 'wp_abilities_api_init' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core hook.
	}

	/**
	 * Removes our abilities and category.
	 */
	private static function unregister_abilities(): void {
		foreach ( array_keys( AbilitySchemas::all() ) as $name ) {
			if ( wp_has_ability( $name ) ) {
				wp_unregister_ability( $name );
			}
		}
		if ( wp_has_ability_category( AbilitiesModule::CATEGORY ) ) {
			wp_unregister_ability_category( AbilitiesModule::CATEGORY );
		}
	}

	/**
	 * Every channel's output for the current state.
	 *
	 * @param array<string, int> $ids Listing ids.
	 * @return array<string, mixed>
	 */
	private function outputs( array $ids ): array {
		self::unregister_abilities();
		self::register_abilities();
		$profile = wp_get_ability( 'aihs/get-profile' );
		$search  = wp_get_ability( 'aihs/search-listings' );
		$this->assertNotNull( $profile );
		$this->assertNotNull( $search );
		return array(
			'rest_profile'    => self::get( '/profile' )->get_data(),
			'rest_listings'   => self::get( '/listings' )->get_data(),
			'rest_listing'    => self::get( '/listings/' . $ids['cable'] )->get_data(),
			'rest_schema'     => self::get( '/schema/listings' )->get_data(),
			'rest_headers'    => array_intersect_key( self::get( '/listings' )->get_headers(), array_flip( array( 'Content-Language', 'Vary' ) ) ),
			'ability_profile' => $profile->execute( array() ),
			'ability_search'  => $search->execute( array() ),
			'ability_schema'  => $search->get_input_schema(),
			'llms'            => LlmsModule::response( LlmsModule::path() ),
			'llms_lang'       => LlmsModule::response( LlmsModule::path() . '?lang=en' ),
			'page'            => CatalogPage::render_html( null ),
			'page_lang'       => CatalogPage::render_html( CatalogPage::language() ),
		);
	}

	/**
	 * Feature off, or on with one language: every output is byte-for-byte what 1.0.0 produced.
	 */
	public function test_single_language_sites_unchanged(): void {
		$ids      = self::fixture();
		$baseline = $this->outputs( $ids );
		$this->assertSame( array(), $baseline['rest_headers'] );

		Features::set( Features::MULTILINGUAL, true );
		self::boot();
		$this->assertEquals( $baseline, $this->outputs( $ids ), 'Multilingual on, one language.' );

		update_option(
			LanguageSource::OPTION,
			array(
				'default'   => 'tr',
				'languages' => array( 'en' ),
			)
		);
		self::translate( $ids );
		Features::set( Features::MULTILINGUAL, false );
		self::boot();
		$this->assertEquals( $baseline, $this->outputs( $ids ), 'Two languages and translations, but the feature off.' );
	}

	/**
	 * TR and EN answers match field by field: same keys and non-text values; translated text differs;
	 * untranslated text falls back and is listed.
	 */
	public function test_tr_en_contract(): void {
		$ids = self::fixture();
		self::two_languages();
		self::translate( $ids );

		$tr = self::get( '/listings/' . $ids['cable'], array( 'lang' => 'tr' ) );
		$en = self::get( '/listings/' . $ids['cable'], array(), array( 'Accept-Language' => 'en-GB,en;q=0.9,tr;q=0.5' ) );
		$this->assertSame( 200, $en->get_status() );
		$this->assertSame( 'en', $en->get_headers()['Content-Language'] );
		$this->assertSame( 'Accept-Language', $en->get_headers()['Vary'] );

		$tr_body = $tr->get_data();
		$en_body = $en->get_data();
		$this->assertSame( array_keys( $tr_body ), array_keys( $en_body ), 'Same fields in both languages.' );
		foreach ( $tr_body as $key => $value ) {
			if ( in_array( $key, array( 'title', 'category', 'region', 'language', 'translation' ), true ) ) {
				continue;
			}
			$this->assertSame( $value, $en_body[ $key ], $key );
		}
		$this->assertSame( array( 'NYY kablo', 'Kablo', 'Türkiye' ), array( $tr_body['title'], $tr_body['category'], $tr_body['region'] ) );
		$this->assertSame( array( 'NYY cable', 'Cable', 'Turkey' ), array( $en_body['title'], $en_body['category'], $en_body['region'] ) );
		$this->assertSame(
			array(
				'language'          => 'tr',
				'missing'           => array(),
				'fallback_language' => null,
			),
			$tr_body['translation']
		);
		$this->assertSame( 'en', $en_body['language'] );

		foreach ( array( array( 'listing', $en_body ), array( 'listings', self::get( '/listings', array( 'lang' => 'en' ) )->get_data() ), array( 'profile', self::get( '/profile', array( 'lang' => 'en' ) )->get_data() ) ) as [ $name, $body ] ) {
			$result = rest_validate_value_from_schema( $body, RestSchemas::get( $name, true ) );
			$this->assertTrue( true === $result, $name . ': ' . ( is_wp_error( $result ) ? $result->get_error_message() : '' ) );
			$this->assertEquals( RestSchemas::get( $name, true ), self::get( '/schema/' . $name )->get_data(), 'Published schema includes the multilingual keys.' );
		}

		$profile = self::get( '/profile', array( 'lang' => 'en' ) )->get_data();
		$this->assertSame( 'Cable manufacturing', $profile['sector'] );
		$this->assertSame( 'Örnek Kablo A.Ş.', $profile['name'] );

		// A language that is not published → the default.
		$this->assertSame( 'tr', self::get( '/listings/' . $ids['cable'], array( 'lang' => 'fr' ) )->get_data()['language'] );
		// Search works on the translated text.
		$this->assertSame(
			1,
			self::get(
				'/listings',
				array(
					'lang'     => 'en',
					'category' => 'cable',
				)
			)->get_data()['total']
		);
	}

	/**
	 * A missing translation falls back to the default language and is marked in every channel.
	 */
	public function test_missing_translation_marked(): void {
		$ids = self::fixture();
		self::two_languages();
		self::translate( $ids );

		$service = self::get( '/listings/' . $ids['service'], array( 'lang' => 'en' ) )->get_data();
		$this->assertSame( 'Tahkim danışmanlığı', $service['title'], 'Untranslated: default-language text.' );
		$this->assertSame(
			array(
				'language'          => 'en',
				'missing'           => array( 'title' ),
				'fallback_language' => 'tr',
			),
			$service['translation']
		);

		// Abilities (MCP): lang input, marked output that matches the published output schema.
		self::unregister_abilities();
		self::register_abilities();
		$search = wp_get_ability( 'aihs/search-listings' );
		$this->assertNotNull( $search );
		$this->assertSame( array( 'tr', 'en' ), $search->get_input_schema()['properties']['lang']['enum'] );
		$found = $search->execute(
			array(
				'lang'    => 'en',
				'keyword' => 'cable',
			)
		);
		$this->assertIsArray( $found );
		$this->assertSame( 'NYY cable', $found['items'][0]['title'] );
		$this->assertSame( array(), $found['items'][0]['translation']['missing'], 'Every text field of the cable is translated.' );
		$result = rest_validate_value_from_schema( $found, $search->get_output_schema() );
		$this->assertTrue( true === $result, is_wp_error( $result ) ? $result->get_error_message() : '' );
		$listing = wp_get_ability( 'aihs/get-listing' )?->execute(
			array(
				'id'   => $ids['service'],
				'lang' => 'en',
			)
		);
		$this->assertIsArray( $listing );
		$this->assertSame( array( 'title' ), $listing['translation']['missing'] );

		// llms.txt: the English text names the fallback fields and links the other language.
		$llms = (string) LlmsModule::response( LlmsModule::path() . '?lang=en' );
		$this->assertStringContainsString( '[NYY cable]', $llms );
		$this->assertStringContainsString( '- Dil: en', $llms );
		$this->assertMatchesRegularExpression( '/^- \[Tahkim danışmanlığı\]\(' . preg_quote( SchemaModule::catalog_url(), '/' ) . '#ilan-' . $ids['service'] . '\): .+ \(çevirisi yok, tr dilinde: başlık\)$/mu', $llms );
		$this->assertStringContainsString( '[llms.txt (tr)](' . home_url( '/llms.txt' ) . ')', $llms );
		$this->assertStringContainsString( '[llms.txt (en)](' . home_url( '/llms.txt?lang=en' ) . ')', (string) LlmsModule::response( LlmsModule::path() ) );

		// Catalog page: lang attribute on the fallback, hreflang alternates, JSON-LD inLanguage.
		$page = CatalogPage::render_html( 'en' );
		$this->assertStringContainsString( '<html lang="en">', $page );
		$this->assertStringContainsString( '<span lang="tr">Tahkim danışmanlığı</span>', $page );
		$this->assertStringContainsString( '<strong>NYY cable</strong>', $page );
		$this->assertStringContainsString( 'hreflang="en" href="' . esc_url( SchemaModule::catalog_url() . '?lang=en' ) . '"', $page );
		$this->assertStringContainsString( 'hreflang="x-default"', $page );
		$this->assertStringContainsString( '<link rel="canonical" href="' . esc_url( SchemaModule::catalog_url( 'en' ) ) . '">', $page );
		$document = SchemaModule::catalog_document( 'en' );
		$this->assertIsArray( $document );
		$this->assertSame( 'en', $document['inLanguage'] );
		$this->assertStringContainsString( 'NYY cable', (string) wp_json_encode( $document ) );
	}
}
