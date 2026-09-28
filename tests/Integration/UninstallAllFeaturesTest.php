<?php
/**
 * Uninstall after every feature was used leaves no plugin data behind.
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\Tests\Integration;

use AIHazirSite\Core\Catalog\ListingType;
use AIHazirSite\Core\Features;
use AIHazirSite\WordPress\Catalog\CatalogModule;
use AIHazirSite\WordPress\I18n\LanguageSource;
use AIHazirSite\WordPress\I18n\Multilingual;
use AIHazirSite\WordPress\Matching\MatchingModule;
use AIHazirSite\WordPress\Portal\Portal;
use AIHazirSite\WordPress\Updates\UpdateModule;
use AIHazirSite\WordPress\Uninstaller;
use WP_UnitTestCase;

/**
 * Options, post meta, user meta and transients of 1.1.0–1.6.0 are all removed on opt-in uninstall.
 *
 * @coversNothing
 */
final class UninstallAllFeaturesTest extends WP_UnitTestCase {

	/**
	 * Real DDL (the rollback drops tables).
	 */
	public function set_up(): void {
		parent::set_up();
		remove_filter( 'query', array( $this, '_create_temporary_tables' ) );
		remove_filter( 'query', array( $this, '_drop_temporary_tables' ) );
	}

	/**
	 * Schema back to the latest version for the other tests.
	 */
	public function tear_down(): void {
		( new \AIHazirSite\Core\Migrations\Migrator( \AIHazirSite\WordPress\Plugin::migrations(), new \AIHazirSite\WordPress\Platform\WpSettings() ) )->migrate();
		parent::tear_down();
	}

	/**
	 * Every stored trace of the new features disappears.
	 */
	public function test_no_data_left(): void {
		global $wpdb;
		foreach ( array_keys( Features::defaults() ) as $key ) {
			Features::set( $key, true );
		}
		update_option(
			LanguageSource::OPTION,
			array(
				'default'   => 'tr',
				'languages' => array( 'en' ),
			)
		);
		$service = CatalogModule::service();
		$service->save_profile(
			array(
				'name'    => 'Örnek A.Ş.',
				'country' => 'TR',
			)
		);
		$service->save_profile_translation( 'en', array( 'sector' => 'Cables' ), Multilingual::settings() );
		$business = Portal::service()->save_business( array( 'name' => 'A Turizm' ) )['business'];
		$listing  = (int) Portal::service()->save_business_listing(
			(int) $business?->id,
			array(
				'type'  => ListingType::OFFER,
				'title' => 'Tur',
			)
		)->listing()?->id;
		$service->save_listing_translation( $listing, 'en', array( 'title' => 'Tour' ), Multilingual::settings() );
		$user = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		update_user_meta( $user, Portal::USER_META, (int) $business?->id );
		update_option( MatchingModule::OPTION, array( 'partners' => array( 'https://ortak.example/wp-json/aihs/v1/' ) ) );
		set_transient( MatchingModule::CACHE . md5( 'x' ), array( 'body' => null ), 3600 );
		set_site_transient( UpdateModule::MANIFEST_CACHE, '{}', 3600 );
		UpdateModule::telemetry()->give_consent();
		update_option( Uninstaller::DELETE_OPTION, true );

		$this->assertTrue( Uninstaller::run() );

		$options = $wpdb->get_col( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE 'aihs\\_%' OR option_name LIKE '\\_transient\\_aihs\\_%' OR option_name LIKE '\\_site\\_transient\\_aihs\\_%' OR option_name LIKE '\\_transient\\_timeout\\_aihs\\_%' OR option_name LIKE '\\_site\\_transient\\_timeout\\_aihs\\_%'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$this->assertSame( array(), $options, 'No plugin options or transients left.' );
		$this->assertSame( array(), get_post_meta( $listing ), 'No listing meta left.' );
		$this->assertSame( '', get_user_meta( $user, Portal::USER_META, true ), 'No user link to a business left.' );
	}
}
