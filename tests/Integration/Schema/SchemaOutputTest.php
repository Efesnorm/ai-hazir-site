<?php
/**
 * Schema.org output inside WordPress.
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\Tests\Integration\Schema;

use AIHazirSite\Adapters\Schema\SchemaCache;
use AIHazirSite\Adapters\Schema\SchemaValidator;
use AIHazirSite\Core\Features;
use AIHazirSite\WordPress\Catalog\CatalogModule;
use AIHazirSite\WordPress\Catalog\WpProfileRepository;
use AIHazirSite\WordPress\Compliance\ComplianceModule;
use AIHazirSite\WordPress\Schema\CatalogPage;
use AIHazirSite\WordPress\Schema\SchemaModule;
use AIHazirSite\WordPress\Schema\SeoConflict;
use WP_UnitTestCase;

/**
 * Schema output integration tests.
 *
 * @covers \AIHazirSite\WordPress\Schema\SchemaModule
 * @covers \AIHazirSite\WordPress\Schema\CatalogPage
 * @covers \AIHazirSite\WordPress\Schema\SeoConflict
 */
final class SchemaOutputTest extends WP_UnitTestCase {

	/**
	 * Catalog data and the feature on.
	 */
	public function set_up(): void {
		parent::set_up();
		foreach ( array( Features::OPTION, SchemaCache::OPTION, SchemaCache::ERROR_OPTION, 'aihs_scans' ) as $option ) {
			delete_option( $option );
		}
		remove_all_filters( 'aihs_schema_seo_conflict' );
		Features::set( Features::SCHEMA_OUTPUT, true );

		$service = CatalogModule::service();
		$service->save_profile(
			array(
				'name'          => 'Örnek Kablo A.Ş.',
				'country'       => 'TR',
				'contact_email' => 'satis@ornek.com.tr',
			)
		);
		$future = gmdate( 'Y-m-d', time() + 30 * DAY_IN_SECONDS );
		$service->save_listing( array( 'type' => 'offer', 'title' => 'NYY kablo', 'price_min' => '42.5', 'currency' => 'TRY', 'valid_until' => $future ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		$service->save_listing( array( 'type' => 'supply', 'title' => 'Özel kesim demet', 'price_min' => '120', 'currency' => 'EUR', 'lead_time_days' => '21' ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		$service->save_listing( array( 'type' => 'demand', 'title' => 'Bakır katot', 'quantity' => '20', 'unit' => 'ton', 'valid_until' => $future ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
	}

	/**
	 * JSON-LD documents inside HTML.
	 *
	 * @param string $html HTML.
	 * @return list<array<string, mixed>>
	 */
	private static function json_ld( string $html ): array {
		preg_match_all( '#<script type="application/ld\+json">(.*?)</script>#s', $html, $m );
		return array_values( array_map( static fn( string $j ): array => (array) json_decode( $j, true ), $m[1] ) );
	}

	/**
	 * Home page wp_head output.
	 */
	private function home_head(): string {
		$this->go_to( home_url( '/' ) );
		ob_start();
		SchemaModule::print_home();
		return (string) ob_get_clean();
	}

	/**
	 * Home: Organization + WebPage, valid, dateModified set.
	 */
	public function test_home_page_organization(): void {
		$docs = self::json_ld( $this->home_head() );

		$this->assertCount( 1, $docs );
		$this->assertSame( array( 'Organization', 'WebPage' ), array_column( $docs[0]['@graph'], '@type' ) );
		$this->assertSame( 'Örnek Kablo A.Ş.', $docs[0]['@graph'][0]['name'] );
		$this->assertSame( array(), ( new SchemaValidator() )->validate( $docs[0] )['errors'] );
		$this->assertMatchesRegularExpression( '/^\d{4}-\d{2}-\d{2}T/', $docs[0]['@graph'][1]['dateModified'] );
	}

	/**
	 * Catalog page: DataFeed with the three listings; an expired listing is not there.
	 */
	public function test_catalog_page_and_expired_listing(): void {
		$id = (int) CatalogModule::service()->save_listing( array( 'type' => 'offer', 'title' => 'Eski kampanya', 'valid_until' => gmdate( 'Y-m-d', time() + DAY_IN_SECONDS ) ) )->listing()?->id; // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		update_post_meta( $id, '_aihs_valid_until', '2020-01-01' );

		$html = CatalogPage::render_html();
		$docs = self::json_ld( $html );

		$this->assertCount( 1, $docs );
		$this->assertSame( 'DataFeed', $docs[0]['@type'] );
		$this->assertCount( 3, $docs[0]['dataFeedElement'] );
		$this->assertSame( array(), ( new SchemaValidator() )->validate( $docs[0] )['errors'] );
		$this->assertStringNotContainsString( 'Eski kampanya', $html );
		$this->assertStringContainsString( 'Bakır katot', $html );
	}

	/**
	 * Feature off: no output, no rewrite rule.
	 */
	public function test_feature_off(): void {
		Features::set( Features::SCHEMA_OUTPUT, false );
		remove_all_actions( 'wp_head' );
		( new SchemaModule() )->register();
		SchemaModule::rewrite();

		$this->go_to( home_url( '/' ) );
		ob_start();
		do_action( 'wp_head' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core hook.
		$this->assertStringNotContainsString( 'application/ld+json', (string) ob_get_clean() );
		$this->assertArrayNotHasKey( SchemaModule::REWRITE, (array) get_option( 'rewrite_rules' ) );
	}

	/**
	 * A validation error: the broken output is not published, the last valid one is, and admins are told.
	 */
	public function test_invalid_output_serves_last_valid_and_warns(): void {
		$valid = self::json_ld( $this->home_head() )[0];

		$stored         = get_option( WpProfileRepository::OPTION );
		$stored['name'] = '';
		update_option( WpProfileRepository::OPTION, $stored );
		update_option( 'blogname', '' ); // 1.6.1: an empty profile name falls back to the site name, so blank both.

		$served = self::json_ld( $this->home_head() );
		$this->assertSame( $valid, $served[0], 'Last valid output is served.' );
		$this->assertArrayHasKey( 'home', SchemaModule::cache()->errors() );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		ob_start();
		SchemaModule::admin_notice();
		$this->assertStringContainsString( 'name zorunlu', (string) ob_get_clean() );
	}

	/**
	 * An SEO plugin owning Organization: ours is not added, a warning is shown, listings stay.
	 */
	public function test_seo_plugin_conflict(): void {
		$this->assertSame( array( 'WPSEO_VERSION', 'RANK_MATH_VERSION', 'AIOSEO_VERSION', 'SEOPRESS_VERSION', 'THE_SEO_FRAMEWORK_VERSION' ), array_keys( SeoConflict::KNOWN ) );
		$this->assertNull( SeoConflict::detect() );

		add_filter( 'aihs_schema_seo_conflict', static fn(): string => 'Yoast SEO' );

		$this->assertSame( '', $this->home_head(), 'No Organization of ours.' );
		$feed = self::json_ld( CatalogPage::render_html() )[0];
		$this->assertCount( 3, $feed['dataFeedElement'] );
		$this->assertSame( 'Örnek Kablo A.Ş.', $feed['publisher']['name'] );
		$this->assertArrayNotHasKey( '@id', $feed['publisher'] );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		ob_start();
		SchemaModule::admin_notice();
		$this->assertStringContainsString( 'Yoast SEO zaten Organization şeması üretiyor', (string) ob_get_clean() );
	}

	/**
	 * U1 scan of the home page and the catalog page: structured_data and freshness score full.
	 */
	public function test_u1_scan_full_score(): void {
		$home    = '<!doctype html><html><head>' . $this->home_head() . '</head><body><nav><a href="' . SchemaModule::catalog_url() . '">AI Katalog</a></nav></body></html>';
		$catalog = CatalogPage::render_html();
		$pages   = array(
			home_url( '/' )             => $home,
			SchemaModule::catalog_url() => $catalog,
		);
		add_filter(
			'pre_http_request',
			static function ( $pre, array $args, string $url ) use ( $pages ) {
				return array(
					'headers'  => array(),
					'cookies'  => array(),
					'body'     => $pages[ $url ] ?? 'yok',
					'response' => array(
						'code'    => isset( $pages[ $url ] ) ? 200 : 404,
						'message' => '',
					),
				);
			},
			10,
			3
		);

		$report = ComplianceModule::run();

		$this->assertSame( 1.0, $report->result( 'structured_data' )['ratio'], implode( ' | ', $report->result( 'structured_data' )['findings'] ) );
		$this->assertSame( 1.0, $report->result( 'freshness' )['ratio'], implode( ' | ', $report->result( 'freshness' )['findings'] ) );
	}
}
