<?php
/**
 * Portal mode in every channel.
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\Tests\Integration\Portal;

use AIHazirSite\Adapters\Rest\RestSchemas;
use AIHazirSite\Core\Catalog\ListingType;
use AIHazirSite\Core\Features;
use AIHazirSite\Tests\Integration\Rest\RestTestCase;
use AIHazirSite\WordPress\Abilities\AbilitiesModule;
use AIHazirSite\WordPress\Catalog\CatalogModule;
use AIHazirSite\WordPress\Llms\LlmsModule;
use AIHazirSite\WordPress\Mcp\McpModule;
use AIHazirSite\WordPress\Portal\Portal;
use AIHazirSite\WordPress\Portal\WpBusinessRepository;
use AIHazirSite\WordPress\Schema\CatalogPage;
use AIHazirSite\WordPress\Schema\SchemaModule;

/**
 * REST, abilities (MCP), llms.txt; single-company sites unchanged.
 *
 * @covers \AIHazirSite\WordPress\Rest\RestModule
 * @covers \AIHazirSite\WordPress\Abilities\AbilitiesModule
 * @covers \AIHazirSite\WordPress\Llms\LlmsModule
 * @covers \AIHazirSite\Adapters\Rest\RestResponder
 * @covers \AIHazirSite\Adapters\Abilities\AbilitySchemas
 */
final class PortalChannelsTest extends RestTestCase {

	/**
	 * Features on, clean businesses.
	 */
	public function set_up(): void {
		parent::set_up();
		foreach ( array( WpBusinessRepository::OPTION, 'aihs_schema_cache', 'aihs_llms_cache' ) as $option ) {
			delete_option( $option );
		}
		foreach ( array( Features::CATALOG, Features::SCHEMA_OUTPUT, Features::LLMS_TXT, Features::ABILITIES ) as $feature ) {
			Features::set( $feature, true );
		}
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
	 * Registers our abilities on a clean init.
	 */
	private static function register_abilities(): void {
		self::unregister_abilities();
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
	 * Removes our abilities (portal ones included) and category.
	 */
	private static function unregister_abilities(): void {
		foreach ( array( 'aihs/get-profile', 'aihs/search-listings', 'aihs/get-listing', 'aihs/check-availability', 'aihs/list-businesses' ) as $name ) {
			if ( wp_has_ability( $name ) ) {
				wp_unregister_ability( $name );
			}
		}
		if ( wp_has_ability_category( AbilitiesModule::CATEGORY ) ) {
			wp_unregister_ability_category( AbilitiesModule::CATEGORY );
		}
	}

	/**
	 * Two businesses with a tour each; the portal keeps its own package.
	 *
	 * @return array{a: int, b: int, tour_a: int, tour_b: int, own: int}
	 */
	private static function portal(): array {
		$service = Portal::service();
		$a       = (int) $service->save_business(
			array(
				'name'   => 'A Balon Turları',
				'sector' => 'Balon turu',
			)
		)['business']?->id;
		$b       = (int) $service->save_business(
			array(
				'name'   => 'B Vadi Turları',
				'sector' => 'Yürüyüş turu',
			)
		)['business']?->id;
		$tour    = static fn( int $business, string $title ): int => (int) $service->save_business_listing(
			$business,
			array(
				'type'     => ListingType::OFFER,
				'title'    => $title,
				'category' => 'Tur',
			)
		)->listing()?->id;
		$own     = (int) CatalogModule::service()->save_listing(
			array(
				'type'     => ListingType::OFFER,
				'title'    => 'Portal tur paketi',
				'category' => 'Tur',
			)
		)->listing()?->id;
		return array(
			'a'      => $a,
			'b'      => $b,
			'tour_a' => $tour( $a, 'Gün doğumu balon turu' ),
			'tour_b' => $tour( $b, 'Güvercinlik vadisi turu' ),
			'own'    => $own,
		);
	}

	/**
	 * Every channel's output.
	 *
	 * @param int $id A listing id.
	 * @return array<string, mixed>
	 */
	private function outputs( int $id ): array {
		self::boot();
		self::register_abilities();
		return array(
			'rest_listings' => self::get( '/listings' )->get_data(),
			'rest_listing'  => self::get( '/listings/' . $id )->get_data(),
			'rest_schema'   => self::get( '/schema/listings' )->get_data(),
			'rest_routes'   => array_keys( rest_get_server()->get_routes( 'aihs/v1' ) ),
			'abilities'     => array_keys( AbilitiesModule::schemas() ),
			'mcp_tools'     => McpModule::tools(),
			'search'        => wp_get_ability( 'aihs/search-listings' )?->execute( array( 'keyword' => 'tur' ) ),
			'llms'          => LlmsModule::response( LlmsModule::path() ),
			'page'          => CatalogPage::render_html(),
			'jsonld'        => SchemaModule::catalog_document(),
		);
	}

	/**
	 * Portal mode off: nothing changes, even with businesses stored.
	 */
	public function test_single_company_unchanged(): void {
		$ids      = self::catalog();
		$baseline = $this->outputs( $ids['cable'] );

		Features::set( Features::PORTAL_MODE, true );
		$portal = self::portal();
		Portal::service()->assign_listing( $ids['cable'], $portal['a'] );
		Features::set( Features::PORTAL_MODE, false );
		foreach ( array( $portal['tour_a'], $portal['tour_b'], $portal['own'] ) as $id ) {
			CatalogModule::service()->delete_listing( $id );
		}

		$this->assertEquals( $baseline, $this->outputs( $ids['cable'] ) );
	}

	/**
	 * One query returns results from several businesses; business filter; /businesses.
	 */
	public function test_one_query_all_businesses(): void {
		Features::set( Features::PORTAL_MODE, true );
		$ids = self::portal();
		self::boot();
		self::register_abilities();

		// REST: all businesses in one list, each item names its business.
		$body = self::get( '/listings', array( 'category' => 'tur' ) )->get_data();
		$this->assertSame( 3, $body['total'] );
		$owners = array_column( array_map( static fn( array $i ): array => array( $i['title'], $i['business']['slug'] ?? 'portal' ), $body['items'] ), 1, 0 );
		$this->assertSame( 'a-balon-turlari', $owners['Gün doğumu balon turu'] );
		$this->assertSame( 'b-vadi-turlari', $owners['Güvercinlik vadisi turu'] );
		$this->assertSame( 'portal', $owners['Portal tur paketi'] );
		foreach ( array(
			'listings' => $body,
			'listing'  => self::get( '/listings/' . $ids['tour_a'] )->get_data(),
		) as $name => $data ) {
			$result = rest_validate_value_from_schema( $data, RestSchemas::with_portal( $name, RestSchemas::get( $name ) ) );
			$this->assertTrue( true === $result, $name . ': ' . ( is_wp_error( $result ) ? $result->get_error_message() : '' ) );
			$this->assertEquals( RestSchemas::with_portal( $name, RestSchemas::get( $name ) ), self::get( '/schema/' . $name )->get_data() );
		}

		// Business filter.
		$only_b = self::get( '/listings', array( 'business' => 'b-vadi-turlari' ) )->get_data();
		$this->assertSame( array( 'Güvercinlik vadisi turu' ), array_column( $only_b['items'], 'title' ) );
		$this->assertSame( 404, self::get( '/listings', array( 'business' => 'yok' ) )->get_status() );

		// /businesses.
		$businesses = self::get( '/businesses' )->get_data();
		$this->assertSame( array( 'A Balon Turları', 'B Vadi Turları' ), array_column( $businesses['items'], 'name' ) );
		$this->assertSame( Portal::page_url( Portal::businesses()->business( $ids['a'] ) ), $businesses['items'][0]['catalog_url'] );
		$result = rest_validate_value_from_schema( $businesses, self::get( '/schema/businesses' )->get_data() );
		$this->assertTrue( true === $result, is_wp_error( $result ) ? $result->get_error_message() : '' );

		// Abilities / MCP: one search across businesses, a business filter, and the business list.
		$search = wp_get_ability( 'aihs/search-listings' );
		$this->assertNotNull( $search );
		$found = $search->execute( array( 'keyword' => 'tur' ) );
		$this->assertIsArray( $found );
		$this->assertSame( 3, $found['total'] );
		$this->assertCount( 3, array_unique( array_map( static fn( array $i ): string => $i['business']['slug'] ?? 'portal', $found['items'] ) ) );
		$result = rest_validate_value_from_schema( $found, $search->get_output_schema() );
		$this->assertTrue( true === $result, is_wp_error( $result ) ? $result->get_error_message() : '' );
		$this->assertSame( array( 'a-balon-turlari', 'b-vadi-turlari' ), $search->get_input_schema()['properties']['business']['enum'] );
		$this->assertSame(
			array( 'Gün doğumu balon turu' ),
			array_column(
				$search->execute(
					array(
						'keyword'  => 'tur',
						'business' => 'a-balon-turlari',
					)
				)['items'],
				'title'
			)
		);
		$this->assertContains( 'aihs/list-businesses', McpModule::tools() );
		$list = wp_get_ability( 'aihs/list-businesses' )?->execute( array() );
		$this->assertIsArray( $list );
		$this->assertCount( 2, $list['items'] );

		// llms.txt: the business directory and the business on each listing line.
		$llms = (string) LlmsModule::response( LlmsModule::path() );
		$this->assertStringContainsString( "## İşletmeler\n\n- [A Balon Turları](" . Portal::page_url( Portal::businesses()->business( $ids['a'] ) ) . '): Balon turu', $llms );
		$this->assertMatchesRegularExpression( '/^- \[Güvercinlik vadisi turu\]\(.+; İşletme: B Vadi Turları$/mu', $llms );
		$this->assertMatchesRegularExpression( '/^- \[Portal tur paketi\]\((?:(?!İşletme:).)+$/mu', $llms, 'The portal\'s own listing names no business.' );
	}
}
