<?php
/**
 * "Komşu ülkelerde" block and network referrals (1.23.0).
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\Tests\Integration\Network;

use AIHazirSite\Core\Features;
use AIHazirSite\Core\Measurement\Hit;
use AIHazirSite\Core\Measurement\Report;
use AIHazirSite\Core\Network\NetworkLink;
use AIHazirSite\WordPress\Catalog\CatalogModule;
use AIHazirSite\WordPress\Measurement\Admin\ReportPage;
use AIHazirSite\WordPress\Measurement\MeasurementModule;
use AIHazirSite\WordPress\Measurement\RequestListener;
use AIHazirSite\WordPress\Network\NetworkBlock;
use AIHazirSite\WordPress\Network\NetworkCatalog;
use AIHazirSite\WordPress\Network\NetworkModule;
use AIHazirSite\WordPress\Network\NetworkPage;
use AIHazirSite\WordPress\Platform\WpClock;
use AIHazirSite\WordPress\Storage\WpdbHitRepository;
use WP_Block_Type_Registry;
use WP_UnitTestCase;

/**
 * This site is the mother; Kosova is a verified member with two listings.
 *
 * @covers \AIHazirSite\WordPress\Network\NetworkBlock
 * @covers \AIHazirSite\WordPress\Network\NetworkCatalog
 * @covers \AIHazirSite\WordPress\Measurement\MeasurementModule
 * @covers \AIHazirSite\WordPress\Measurement\RequestListener
 * @covers \AIHazirSite\WordPress\Measurement\Admin\ReportPage
 */
final class NetworkBlockTest extends WP_UnitTestCase {

	private const KOSOVA = 'https://www.kosova.org.tr/';
	private const CHROME = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36';

	/**
	 * Network and block on (suggestions off), a fake Kosova, network saved and the hourly job run.
	 */
	public function set_up(): void {
		parent::set_up();
		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i', WpdbHitRepository::table() ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		foreach ( array( Features::OPTION, NetworkModule::OPTION, NetworkModule::STATE ) as $option ) {
			delete_option( $option );
		}
		delete_transient( NetworkCatalog::PREFIX . md5( self::KOSOVA ) );
		add_filter( 'home_url', static fn( string $url ): string => (string) preg_replace( '#^https?://[^/]+#', 'https://www.portal.example', $url ) );
		foreach ( array( Features::CATALOG, Features::REST_API, Features::PORTAL_NETWORK, Features::NETWORK_BLOCK ) as $feature ) {
			Features::set( $feature, true );
		}
		CatalogModule::service()->save_profile( array( 'name' => 'Makedonya Portalı' ) );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$own_url = NetworkModule::self_url();
		add_filter(
			'pre_http_request',
			static function ( $pre, array $args, string $url ) use ( $own_url ) {
				if ( false !== $pre ) {
					return $pre;
				}
				$json    = static fn( array $body, int $code = 200 ): array => array(
					'headers'  => array(),
					'body'     => (string) wp_json_encode( $body ),
					'response' => array(
						'code'    => $code,
						'message' => 'x',
					),
					'cookies'  => array(),
				);
				$listing = static fn( int $id, string $title, string $category ): array => array(
					'id'         => $id,
					'type'       => 'offer',
					'title'      => $title,
					'category'   => $category,
					'region'     => 'Prizren',
					'url'        => self::KOSOVA . 'ai-katalog/#ilan-' . $id,
					'updated_at' => '2026-10-0' . $id . 'T10:00:00Z',
				);
				$api     = self::KOSOVA . 'wp-json/aihs/v1/';
				return match ( $url ) {
					$api . 'network'                     => $json(
						array(
							'role'    => 'member',
							'name'    => '',
							'mother'  => $own_url,
							'members' => array(),
						)
					),
					$api . 'profile'                     => $json(
						array(
							'name'    => 'Kosova Portalı',
							'country' => 'XK',
							'nace'    => 'I',
						)
					),
					$api . 'listings?per_page=50&page=1' => $json(
						array(
							'items'       => array( $listing( 1, 'Prizren & Rugova turu', 'Tur' ), $listing( 2, 'Prizren oteli', 'Otel' ) ),
							'total_pages' => 1,
						)
					),
					default                              => $json( array(), 404 ),
				};
			},
			10,
			3
		);

		$_REQUEST['_wpnonce'] = wp_create_nonce( NetworkPage::SAVE );
		NetworkPage::handle_save(
			array(
				'role'    => 'mother',
				'name'    => 'Balkan Portalları',
				'members' => self::KOSOVA,
			)
		);
		( new NetworkModule() )->register();
		do_action( NetworkModule::HOOK ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- Our hook, by its constant.
	}

	/**
	 * Clears request globals and the screen.
	 */
	public function tear_down(): void {
		unset( $_REQUEST['_wpnonce'] );
		set_current_screen( 'front' );
		parent::tear_down();
	}

	/**
	 * The block alone fills the cache; the shortcode lists Kosova's listings with tagged, escaped links.
	 */
	public function test_shortcode(): void {
		$this->assertIsArray( get_transient( NetworkCatalog::PREFIX . md5( self::KOSOVA ) ), 'Read for the block although suggestions are off.' );
		( new NetworkBlock() )->register();

		$html = do_shortcode( '[aihs_komsu_ulkeler]' );
		$this->assertStringContainsString( '<section class="aihs-komsu-ulkeler" aria-labelledby="', $html );
		$this->assertStringContainsString( '<h2 id="aihs-komsu-ulkeler-', $html );
		$this->assertStringContainsString( '>Komşu ülkelerde</h2>', $html );
		$this->assertStringContainsString( '>Prizren &amp; Rugova turu</a> – Prizren · Kosova Portalı (XK)</li>', $html );
		$this->assertStringContainsString( 'href="https://www.kosova.org.tr/ai-katalog/?utm_source=portal.example&#038;utm_medium=portal-agi&#038;utm_campaign=komsu-ulkeler#ilan-2"', $html );
		$this->assertSame( 2, substr_count( $html, '<li>' ) );

		$this->assertSame( 1, substr_count( do_shortcode( '[aihs_komsu_ulkeler count="1" title="Balkanlarda"]' ), '<li>' ) );
		$this->assertStringContainsString( '>Balkanlarda</h2>', do_shortcode( '[aihs_komsu_ulkeler title="Balkanlarda"]' ) );
		$this->assertStringContainsString( 'Prizren oteli', do_shortcode( '[aihs_komsu_ulkeler category="otel"]' ) );
		$this->assertStringNotContainsString( 'Rugova', do_shortcode( '[aihs_komsu_ulkeler category="otel"]' ) );
		$this->assertSame( 2, substr_count( do_shortcode( '[aihs_komsu_ulkeler sector="I"]' ), '<li>' ) );
		$this->assertSame( '', do_shortcode( '[aihs_komsu_ulkeler sector="H"]' ), 'Nothing matching: nothing drawn.' );
		$this->assertSame( '', do_shortcode( '[aihs_komsu_ulkeler site="https://baska.example/"]' ) );

		Features::set( Features::NETWORK_BLOCK, false );
		$this->assertSame( '', do_shortcode( '[aihs_komsu_ulkeler]' ), 'Off: the tag is not shown either.' );
	}

	/**
	 * The block is registered with the editor's choices; it renders like the shortcode.
	 */
	public function test_block(): void {
		set_current_screen( 'edit-post' );
		$registry = WP_Block_Type_Registry::get_instance();
		if ( $registry->is_registered( NetworkBlock::BLOCK ) ) {
			$registry->unregister( NetworkBlock::BLOCK );
		}
		NetworkBlock::register_block();
		$this->assertTrue( $registry->is_registered( NetworkBlock::BLOCK ) );
		$inline = implode( '', (array) wp_scripts()->get_data( generate_block_asset_handle( NetworkBlock::BLOCK, 'editorScript' ), 'before' ) );
		$data   = json_decode( (string) preg_replace( '/^window\.aihsNetworkBlock = (.*);$/s', '$1', $inline ), true );
		$this->assertSame( 'H – Ulaştırma ve depolama', $data['sectors']['H'] ?? null );
		$this->assertSame(
			array(
				array(
					'url'  => self::KOSOVA,
					'name' => 'Kosova Portalı',
				),
			),
			$data['sites'] ?? null
		);

		$html = render_block(
			array(
				'blockName'    => NetworkBlock::BLOCK,
				'attrs'        => array( 'count' => 1 ),
				'innerBlocks'  => array(),
				'innerHTML'    => '',
				'innerContent' => array(),
			)
		);
		$this->assertSame( 1, substr_count( $html, '<li>' ) );
		$registry->unregister( NetworkBlock::BLOCK );
	}

	/**
	 * With `network_referrals` on, visits from Kosova are counted (Referer or UTM) and shown in the report.
	 */
	public function test_network_referrals(): void {
		$hits    = new WpdbHitRepository();
		$request = RequestListener::request_from(
			array(
				'HTTP_USER_AGENT' => self::CHROME,
				'REQUEST_URI'     => '/turlar/?utm_source=kosova.org.tr&utm_medium=portal-agi',
			),
			array(
				'utm_source' => 'kosova.org.tr',
				'utm_medium' => 'portal-agi',
			)
		);
		$this->assertSame( NetworkLink::UTM_MEDIUM, $request->utm_medium );

		$this->assertFalse( MeasurementModule::tracker()->handle( $request ), 'Key off: not counted.' );

		Features::set( Features::NETWORK_REFERRALS, true );
		$this->assertTrue( MeasurementModule::tracker()->handle( $request ) );
		$this->assertTrue(
			MeasurementModule::tracker()->handle(
				RequestListener::request_from(
					array(
						'HTTP_USER_AGENT' => self::CHROME,
						'REQUEST_URI'     => '/',
						'HTTP_REFERER'    => 'https://www.kosova.org.tr/ai-katalog/',
					)
				)
			)
		);
		$totals = $hits->totals( Hit::KIND_NETWORK, ( new WpClock() )->today(), WpdbHitRepository::GROUP_SOURCE );
		$this->assertSame( array( 'kosova.org.tr' ), array_column( $totals, 'key' ) );
		$this->assertSame( 2, (int) $totals[0]['total'] );

		$rows = ( new Report( $hits, new WpClock(), 7 ) )->rows();
		$html = ReportPage::render_tables( $rows );
		$this->assertStringContainsString( 'Ağ yönlendirmeleri: kardeş portallardan gelen insan ziyaretleri', $html );
		$this->assertStringContainsString( 'kosova.org.tr', $html );
		$this->assertStringNotContainsString( 'Ağ yönlendirmeleri', ReportPage::render_tables( array() ), 'No table on sites without network visits.' );
	}
}
