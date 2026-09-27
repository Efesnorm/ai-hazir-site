<?php
/**
 * The llms.txt file inside WordPress.
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\Tests\Integration\Llms;

use AIHazirSite\Adapters\Llms\LlmsCache;
use AIHazirSite\Core\Features;
use AIHazirSite\WordPress\Catalog\CatalogModule;
use AIHazirSite\WordPress\Compliance\ComplianceModule;
use AIHazirSite\WordPress\Llms\LlmsModule;
use AIHazirSite\WordPress\Schema\CatalogPage;
use AIHazirSite\WordPress\Schema\SchemaModule;
use WP_UnitTestCase;

/**
 * The llms.txt integration tests.
 *
 * @covers \AIHazirSite\WordPress\Llms\LlmsModule
 * @covers \AIHazirSite\WordPress\Schema\CatalogPage
 */
final class LlmsTxtTest extends WP_UnitTestCase {

	/**
	 * Catalog data and the feature on.
	 */
	public function set_up(): void {
		parent::set_up();
		foreach ( array( Features::OPTION, LlmsCache::OPTION, 'aihs_scans' ) as $option ) {
			delete_option( $option );
		}
		Features::set( Features::LLMS_TXT, true );

		$service = CatalogModule::service();
		$service->save_profile(
			array(
				'name'          => 'Örnek Kablo A.Ş.',
				'sector'        => 'Enerji kabloları',
				'country'       => 'TR',
				'contact_email' => 'satis@ornek.com.tr',
			)
		);
		$service->save_listing( array( 'type' => 'offer', 'title' => 'NYY kablo', 'price_min' => '42.5', 'currency' => 'TRY' ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
	}

	/**
	 * Adding and deleting a listing updates llms.txt; unchanged data is served without a write.
	 */
	public function test_listing_added_and_deleted(): void {
		$writes = 0;
		add_action(
			'update_option_' . LlmsCache::OPTION,
			static function () use ( &$writes ): void {
				++$writes;
			}
		);

		$before = (string) LlmsModule::response( '/llms.txt' );
		$this->assertStringContainsString( '[NYY kablo]', $before );
		$this->assertStringNotContainsString( 'Özel kesim demet', $before );

		$id    = (int) CatalogModule::service()->save_listing( array( 'type' => 'supply', 'title' => 'Özel kesim demet', 'lead_time_days' => '21' ) )->listing()?->id; // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		$added = (string) LlmsModule::response( '/llms.txt' );
		$this->assertStringContainsString( "## Tedarik edilebilenler\n\n- [Özel kesim demet](" . SchemaModule::catalog_url() . '#ilan-' . $id . '): Teslim süresi: 21 gün', $added );

		$writes = 0;
		$this->assertSame( $added, LlmsModule::response( '/llms.txt' ) );
		$this->assertSame( 0, $writes, 'Served from the cache.' );

		CatalogModule::service()->delete_listing( $id );
		$this->assertSame( $before, LlmsModule::response( '/llms.txt' ) );
		$this->assertSame( 1, $writes );
	}

	/**
	 * A physical llms.txt: the virtual address stays out of the way and admins are warned.
	 */
	public function test_physical_file_wins(): void {
		$path = LlmsModule::physical_file() ?? trailingslashit( get_home_path() ) . LlmsModule::FILE;
		$this->assertFalse( file_exists( $path ), 'The test site has no llms.txt of its own.' );

		file_put_contents( $path, "# Elle yazılmış\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		try {
			$this->assertNull( LlmsModule::response( '/llms.txt' ) );
			$this->assertSame( "# Elle yazılmış\n", file_get_contents( $path ), 'The file is not touched.' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

			wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
			ob_start();
			LlmsModule::admin_notice();
			$notice = (string) ob_get_clean();
			$this->assertStringContainsString( 'llms.txt dosyası var', $notice );
			$this->assertStringContainsString( '# Örnek Kablo A.Ş.', $notice );
		} finally {
			unlink( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
		}
		$this->assertNotNull( LlmsModule::response( '/llms.txt' ) );
	}

	/**
	 * Served as text/plain in UTF-8; Turkish characters come through byte for byte.
	 */
	public function test_content_type_and_utf8(): void {
		$text = (string) LlmsModule::response( '/llms.txt' );

		$this->assertContains( 'Content-Type: text/plain; charset=utf-8', LlmsModule::HEADERS );
		$this->assertTrue( mb_check_encoding( $text, 'UTF-8' ) );
		$this->assertStringStartsWith( "# Örnek Kablo A.Ş.\n\n> Örnek Kablo A.Ş. (Enerji kabloları): ", $text );
		$this->assertStringContainsString( '- [Web sitesi](' . home_url( '/' ) . '): E-posta: satis@ornek.com.tr', $text );
	}

	/**
	 * Only /llms.txt under the home path is ours, with any permalink setting.
	 */
	public function test_path_matching_and_permalinks(): void {
		foreach ( array( '/%postname%/', '' ) as $structure ) {
			$this->set_permalink_structure( $structure );
			$this->assertNotNull( LlmsModule::response( '/llms.txt' ), $structure );
			$this->assertNotNull( LlmsModule::response( '/llms.txt?x=1' ), $structure );
			$this->assertNull( LlmsModule::response( '/LLMS.txt' ), $structure );
			$this->assertNull( LlmsModule::response( '/blog/llms.txt' ), $structure );
			$this->assertNull( LlmsModule::response( '/?p=1' ), $structure );
		}
	}

	/**
	 * Feature off: /llms.txt is not hooked.
	 */
	public function test_feature_off(): void {
		Features::set( Features::LLMS_TXT, false );
		remove_all_actions( 'parse_request' );
		( new LlmsModule() )->register();

		$this->assertFalse( has_action( 'parse_request', array( LlmsModule::class, 'maybe_serve' ) ) );

		Features::set( Features::LLMS_TXT, true );
		( new LlmsModule() )->register();
		$this->assertSame( 20, has_action( 'parse_request', array( LlmsModule::class, 'maybe_serve' ) ) );
	}

	/**
	 * With llms_txt alone the catalog page is served (anchors, details) but has no JSON-LD.
	 */
	public function test_catalog_page_without_schema(): void {
		$this->assertTrue( SchemaModule::catalog_enabled() );
		$this->assertFalse( Features::is_enabled( Features::SCHEMA_OUTPUT ) );

		$html = CatalogPage::render_html();

		$this->assertStringNotContainsString( 'application/ld+json', $html );
		$this->assertMatchesRegularExpression( '#<li id="ilan-\d+"><strong>NYY kablo</strong><br><small>Fiyat: 42.5 TRY; Geçerlilik: \d{4}-\d{2}-\d{2}</small></li>#', $html );
		$this->assertStringContainsString( '<li>Sektör: Enerji kabloları</li>', $html );
	}

	/**
	 * U1 scan of the served text: the llms_txt check scores full.
	 */
	public function test_u1_llms_txt_full_score(): void {
		$text = (string) LlmsModule::response( '/llms.txt' );
		add_filter(
			'pre_http_request',
			static function ( $pre, array $args, string $url ) use ( $text ) {
				$ours = home_url( '/llms.txt' ) === $url;
				return array(
					'headers'  => array( 'content-type' => $ours ? 'text/plain; charset=utf-8' : 'text/html' ),
					'cookies'  => array(),
					'body'     => $ours ? $text : '<!doctype html><html><head></head><body></body></html>',
					'response' => array(
						'code'    => 200,
						'message' => '',
					),
				);
			},
			10,
			3
		);

		$report = ComplianceModule::run();

		$this->assertSame( 1.0, $report->result( 'llms_txt' )['ratio'], implode( ' | ', $report->result( 'llms_txt' )['findings'] ) );
	}
}
