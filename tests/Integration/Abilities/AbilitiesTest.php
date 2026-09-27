<?php
/**
 * The aihs/ abilities inside WordPress.
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\Tests\Integration\Abilities;

use AIHazirSite\Adapters\Abilities\AbilitySchemas;
use AIHazirSite\Core\Features;
use AIHazirSite\Tests\Integration\Rest\RestTestCase;
use AIHazirSite\WordPress\Abilities\AbilitiesModule;
use WP_Error;

/**
 * Registration, schemas, results and the rate limit.
 *
 * @covers \AIHazirSite\WordPress\Abilities\AbilitiesModule
 * @covers \AIHazirSite\WordPress\Catalog\CatalogReader
 */
final class AbilitiesTest extends RestTestCase {

	/**
	 * Abilities on; our category and abilities registered on a clean init.
	 */
	public function set_up(): void {
		parent::set_up();
		Features::set( Features::ABILITIES, true );
		add_filter( 'aihs_abilities_rate_limit', static fn(): int => 10000 );
		self::register_ours();
	}

	/**
	 * Unregisters ours.
	 */
	public function tear_down(): void {
		self::unregister_ours();
		parent::tear_down();
	}

	/**
	 * Registers our category and abilities as WordPress would during its init actions.
	 */
	protected static function register_ours(): void {
		wp_get_abilities(); // Makes sure the registries ran their own init first.
		remove_all_actions( 'wp_abilities_api_categories_init' );
		remove_all_actions( 'wp_abilities_api_init' );
		( new AbilitiesModule() )->register();
		do_action( 'wp_abilities_api_categories_init' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core hook.
		do_action( 'wp_abilities_api_init' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core hook.
	}

	/**
	 * Removes our abilities and category.
	 */
	protected static function unregister_ours(): void {
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
	 * Executes an ability.
	 *
	 * @param string $name  Name.
	 * @param mixed  $input Input (an MCP client always sends an object; {} arrives as an empty array).
	 */
	private static function execute( string $name, mixed $input = array() ): mixed {
		$ability = wp_get_ability( $name );
		self::assertNotNull( $ability, $name );
		return $ability->execute( $input );
	}

	/**
	 * Asserts a value matches a schema.
	 *
	 * @param array<string, mixed> $schema Schema.
	 * @param mixed                $value  Value.
	 */
	private function assertMatches( array $schema, mixed $value ): void {
		$result = rest_validate_value_from_schema( $value, $schema );
		$this->assertTrue( true === $result, is_wp_error( $result ) ? $result->get_error_message() : '' );
	}

	/**
	 * Four read-only abilities in our category.
	 */
	public function test_registered(): void {
		foreach ( array_keys( AbilitySchemas::all() ) as $name ) {
			$ability = wp_get_ability( $name );
			$this->assertNotNull( $ability, $name );
			$this->assertSame( AbilitiesModule::CATEGORY, $ability->get_category() );
			$this->assertTrue( $ability->get_meta()['annotations']['readonly'] );
		}
	}

	/**
	 * Results match their output schemas; the example question is answered.
	 */
	public function test_results(): void {
		$ids     = self::catalog();
		$schemas = AbilitySchemas::all();

		$profile = self::execute( 'aihs/get-profile' );
		$this->assertMatches( $schemas['aihs/get-profile']['output'], $profile );
		$this->assertSame( 'Örnek Kablo A.Ş.', $profile['name'] );

		$found = self::execute( 'aihs/search-listings', array( 'keyword' => 'nyy' ) );
		$this->assertMatches( $schemas['aihs/search-listings']['output'], $found );
		$this->assertSame( array( $ids['cable'] ), array_column( $found['items'], 'id' ) );

		$by_field = self::execute(
			'aihs/search-listings',
			array(
				'type'       => 'offer',
				'attributes' => array( 'kesit' => '2.5' ),
			)
		);
		$this->assertSame( array( $ids['cable'] ), array_column( $by_field['items'], 'id' ) );

		$listing = self::execute( 'aihs/get-listing', array( 'id' => $ids['service'] ) );
		$this->assertMatches( $schemas['aihs/get-listing']['output'], $listing );
		$this->assertArrayNotHasKey( 'price', $listing );

		$answer = self::execute(
			'aihs/check-availability',
			array(
				'id'       => $ids['cable'],
				'quantity' => 500,
			)
		);
		$this->assertMatches( $schemas['aihs/check-availability']['output'], $answer );
		$this->assertSame( array( 'yes', '1500', 'm' ), array( $answer['answer'], $answer['available_quantity'], $answer['unit'] ) );
		$this->assertSame( 'no', self::execute( 'aihs/check-availability', array( 'id' => $ids['demand'] ) )['answer'] );
	}

	/**
	 * Invalid input and expired listings are errors.
	 */
	public function test_errors(): void {
		$ids = self::catalog();

		$this->assertInstanceOf( WP_Error::class, self::execute( 'aihs/search-listings', array( 'per_page' => 99 ) ) );
		$this->assertInstanceOf( WP_Error::class, self::execute( 'aihs/search-listings', array( 'bilinmeyen' => 1 ) ) );
		$this->assertInstanceOf( WP_Error::class, self::execute( 'aihs/get-listing', array() ) );
		$expired = self::execute( 'aihs/get-listing', array( 'id' => $ids['expired'] ) );
		$this->assertInstanceOf( WP_Error::class, $expired );
		$this->assertSame( 'aihs_listing_not_found', $expired->get_error_code() );
		$this->assertSame( 'no', self::execute( 'aihs/check-availability', array( 'id' => $ids['expired'] ) )['answer'] );
	}

	/**
	 * Over the per-client limit: an error with status 429.
	 */
	public function test_rate_limit(): void {
		remove_all_filters( 'aihs_abilities_rate_limit' );
		add_filter( 'aihs_abilities_rate_limit', static fn(): int => 2 );
		$_SERVER['REMOTE_ADDR'] = '192.0.2.44';

		self::execute( 'aihs/get-profile' );
		self::execute( 'aihs/get-profile' );
		$limited = self::execute( 'aihs/get-profile' );
		$this->assertInstanceOf( WP_Error::class, $limited );
		$this->assertSame( 'aihs_rate_limited', $limited->get_error_code() );
	}

	/**
	 * Feature off: nothing registered.
	 */
	public function test_feature_off(): void {
		self::unregister_ours();
		Features::set( Features::ABILITIES, false );
		self::register_ours();

		foreach ( array_keys( AbilitySchemas::all() ) as $name ) {
			$this->assertFalse( wp_has_ability( $name ), $name );
		}
	}
}
