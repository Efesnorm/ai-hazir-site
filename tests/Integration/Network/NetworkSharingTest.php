<?php
/**
 * "Ağda yayınla" end to end (1.26.0).
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\Tests\Integration\Network;

use AIHazirSite\Core\Features;
use AIHazirSite\Core\Network\NetworkSettings;
use AIHazirSite\WordPress\Abilities\AbilitiesModule;
use AIHazirSite\WordPress\Catalog\Admin\CatalogAdmin;
use AIHazirSite\WordPress\Catalog\CatalogModule;
use AIHazirSite\WordPress\Catalog\WpListingRepository;
use AIHazirSite\WordPress\Llms\LlmsModule;
use AIHazirSite\WordPress\Network\NetworkBlock;
use AIHazirSite\WordPress\Network\NetworkCatalog;
use AIHazirSite\WordPress\Network\NetworkModule;
use AIHazirSite\WordPress\Rest\RestModule;
use AIHazirSite\WordPress\Schema\CatalogPage;
use AIHazirSite\WordPress\Schema\SchemaModule;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * Sharing side (this site as the mother of Kosova and Yunanistan) and receiving side (this site as a member of
 * Makedonya, whose public answers are faked).
 *
 * @covers \AIHazirSite\WordPress\Network\NetworkSharing
 * @covers \AIHazirSite\WordPress\Catalog\Admin\CatalogAdmin
 * @covers \AIHazirSite\WordPress\Catalog\WpListingRepository
 */
final class NetworkSharingTest extends WP_UnitTestCase {

	private const MOTHER = 'https://www.makedonya.tr/';
	private const KOSOVA = 'https://www.kosova.org.tr/';
	private const YUNAN  = 'https://www.yunanistan.org.tr/';

	/**
	 * Features on; https home; the mother's public answers faked.
	 */
	public function set_up(): void {
		parent::set_up();
		global $wp_rest_server;
		foreach ( array( Features::OPTION, NetworkModule::OPTION, NetworkModule::STATE ) as $option ) {
			delete_option( $option );
		}
		delete_transient( NetworkCatalog::PREFIX . md5( self::MOTHER ) );
		add_filter( 'home_url', static fn( string $url ): string => (string) preg_replace( '#^https?://[^/]+#', 'https://www.kosova.org.tr', $url ) );
		add_filter( 'aihs_rest_rate_limit', static fn(): int => 10000 );
		add_filter( 'aihs_abilities_rate_limit', static fn(): int => 10000 );
		foreach ( array( Features::CATALOG, Features::REST_API, Features::SCHEMA_OUTPUT, Features::LLMS_TXT, Features::ABILITIES, Features::PORTAL_NETWORK, Features::NETWORK_SHARE, Features::NETWORK_BLOCK ) as $feature ) {
			Features::set( $feature, true );
		}
		CatalogModule::service()->save_profile( array( 'name' => 'Kosova Portalı' ) );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$wp_rest_server = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Test isolation of the core REST server.
		( new RestModule() )->register();

		add_filter(
			'pre_http_request',
			static function ( $pre, array $args, string $url ) {
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
				$listing = static fn( int $id, string $title, mixed $share ): array => array(
					'id'            => $id,
					'type'          => 'offer',
					'title'         => $title,
					'category'      => 'Tur',
					'region'        => 'Balkanlar',
					'url'           => self::MOTHER . 'ai-katalog/#ilan-' . $id,
					'updated_at'    => '2026-10-0' . $id . 'T10:00:00Z',
					'network_share' => $share,
				);
				$api     = self::MOTHER . 'wp-json/aihs/v1/';
				return match ( $url ) {
					$api . 'profile'                     => $json(
						array(
							'name'    => 'Makedonya Türkiye Consulting',
							'country' => 'MK',
						)
					),
					$api . 'listings?per_page=50&page=1' => $json(
						array(
							'items'       => array(
								$listing(
									1,
									'Balkan turu – son 5 kişi, 399 €',
									array(
										'all'   => true,
										'sites' => array(),
									)
								),
								$listing(
									2,
									'Atina hafta sonu',
									array(
										'all'   => false,
										'sites' => array( self::YUNAN ),
									)
								),
								$listing( 3, 'Üsküp ofis kiralama', null ),
							),
							'total_pages' => 1,
						)
					),
					default                              => $json( array(), 404 ),
				};
			},
			10,
			3
		);
	}

	/**
	 * Clears request globals.
	 */
	public function tear_down(): void {
		unset( $_REQUEST['_wpnonce'] );
		parent::tear_down();
	}

	/**
	 * This site as the verified mother of Kosova and Yunanistan.
	 */
	private static function as_mother(): void {
		update_option( NetworkModule::OPTION, ( new NetworkSettings( NetworkSettings::ROLE_MOTHER, 'Balkan Portalları', NetworkModule::self_url(), array( self::YUNAN, 'https://www.sirbistan.org.tr/' ) ) )->to_array() );
		$verified = array(
			'status'      => 'verified',
			'name'        => 'Yunanistan',
			'country'     => 'GR',
			'checked_at'  => time(),
			'verified_at' => time(),
		);
		update_option( NetworkModule::STATE, array( 'members' => array( self::YUNAN => $verified ) ) );
	}

	/**
	 * This site (Kosova) as a verified member of the Makedonya mother.
	 */
	private static function as_member(): void {
		update_option( NetworkModule::OPTION, ( new NetworkSettings( NetworkSettings::ROLE_MEMBER, '', self::MOTHER ) )->to_array() );
		update_option(
			NetworkModule::STATE,
			array(
				'self'       => array(
					'status'      => 'verified',
					'checked_at'  => time(),
					'verified_at' => time(),
				),
				'mother_doc' => array(
					'role'    => 'mother',
					'name'    => 'Balkan Portalları',
					'mother'  => self::MOTHER,
					'members' => array(
						array(
							'url'      => self::MOTHER,
							'name'     => 'Makedonya Türkiye Consulting',
							'country'  => 'MK',
							'verified' => true,
						),
						array(
							'url'      => NetworkModule::self_url(),
							'name'     => 'Kosova Portalı',
							'country'  => 'XK',
							'verified' => true,
						),
					),
				),
			)
		);
	}

	/**
	 * Saves a listing through the admin form.
	 *
	 * @param array<string, mixed> $more Extra posted fields.
	 */
	private static function save_listing( array $more ): int {
		$_REQUEST['_wpnonce'] = wp_create_nonce( CatalogAdmin::SAVE_LISTING );
		( new CatalogAdmin() )->handle_save_listing(
			array(
				'type'        => 'offer',
				'id'          => '',
				'title'       => 'Balkan turu – son 5 kişi',
				'valid_until' => gmdate( 'Y-m-d', time() + 10 * DAY_IN_SECONDS ),
			) + $more
		);
		$ids = ( new WpListingRepository() )->ids();
		return (int) max( $ids );
	}

	/**
	 * Sharing side: the form row, stored choice (verified siblings only), the list tag and the REST field.
	 */
	public function test_sharing_side(): void {
		self::as_mother();
		$form = CatalogAdmin::render_form(
			'offer',
			null,
			array(
				'errors' => array(),
				'input'  => array(),
			)
		);
		$this->assertStringContainsString( 'id="aihs-network-share"', $form );
		$this->assertStringContainsString( 'value="' . self::YUNAN . '"', $form );
		$this->assertStringNotContainsString( 'sirbistan', $form, 'Only verified siblings.' );

		$id = self::save_listing(
			array(
				'network_share_form'  => '1',
				'network_share_sites' => array( self::YUNAN, 'https://kotu.example/' ),
			)
		);
		$this->assertSame(
			array(
				'all'   => false,
				'sites' => array( self::YUNAN ),
			),
			( new WpListingRepository() )->network_share( $id )
		);
		$this->assertStringContainsString( 'class="aihs-shared"', CatalogAdmin::render_list( 'offer', ( new WpListingRepository() )->all( 'offer' ), gmdate( 'Y-m-d' ) ) );

		$listing = rest_get_server()->dispatch( new WP_REST_Request( 'GET', '/aihs/v1/listings' ) )->get_data();
		$this->assertSame( array( self::YUNAN ), $listing['items'][0]['network_share']['sites'] ?? null );
		$this->assertSame( 'object', RestModule::openapi()['components']['schemas']['Listings']['properties']['items']['items']['properties']['network_share']['type'] ?? null );

		// Saving again without the choice removes the share; a form without the row leaves it as it is.
		$second = self::save_listing( array( 'network_share_form' => '1' ) );
		$this->assertNull( ( new WpListingRepository() )->network_share( $second ) );
		$third = self::save_listing( array( 'network_share_all' => '1' ) );
		$this->assertNull( ( new WpListingRepository() )->network_share( $third ), 'No row on the form, nothing saved.' );

		Features::set( Features::NETWORK_SHARE, false );
		$this->assertArrayNotHasKey( 'network_share', rest_get_server()->dispatch( new WP_REST_Request( 'GET', '/aihs/v1/listings' ) )->get_data()['items'][2] ?? array() );
		$this->assertStringNotContainsString( 'id="aihs-network-share"', CatalogAdmin::render_form( 'offer', null, array( 'errors' => array(), 'input' => array() ) ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
	}

	/**
	 * Receiving side: only what is shared with this site, everywhere, linking to the original.
	 */
	public function test_receiving_side(): void {
		self::as_member();
		NetworkCatalog::refresh();

		$rest = rest_get_server()->dispatch( new WP_REST_Request( 'GET', '/aihs/v1/listings' ) )->get_data();
		$this->assertSame( array( 'Balkan turu – son 5 kişi, 399 €' ), array_column( $rest['network_listings'] ?? array(), 'title' ), 'Shared with all: yes; with Yunanistan only: no; not shared: no.' );
		$this->assertSame( self::MOTHER . 'ai-katalog/#ilan-1', $rest['network_listings'][0]['url'] );
		$this->assertSame( 0, $rest['total'], 'Not counted as this site\'s own listing.' );

		$mcp = AbilitiesModule::search_listings( array( 'keyword' => 'balkan turu' ) );
		$this->assertIsArray( $mcp );
		$this->assertCount( 1, $mcp['network_listings'] ?? array() );
		$this->assertArrayHasKey( 'network_listings', AbilitiesModule::schemas()['aihs/search-listings']['output']['properties'] );

		$page = CatalogPage::render_html();
		$this->assertStringContainsString( '<section id="aihs-network-listings"><h2>Ağdaki ilanlar</h2>', $page );
		$this->assertStringContainsString( '<a href="' . self::MOTHER . 'ai-katalog/#ilan-1">Balkan turu – son 5 kişi, 399 €</a>', $page );
		$this->assertStringNotContainsString( 'Atina hafta sonu', $page );

		$document = (array) SchemaModule::catalog_document();
		$this->assertSame(
			array(
				array(
					'@id'  => self::MOTHER . 'ai-katalog/#ilan-1',
					'url'  => self::MOTHER . 'ai-katalog/#ilan-1',
					'name' => 'Balkan turu – son 5 kişi, 399 €',
				),
			),
			$document['mentions'] ?? null
		);
		$this->assertStringNotContainsString( 'Balkan turu', (string) wp_json_encode( $document['dataFeedElement'] ?? array() ), 'No Offer of this site.' );

		$this->assertStringContainsString( '## Ağdaki ilanlar', LlmsModule::text() );

		$block = NetworkBlock::render( array( 'count' => 1 ) );
		$this->assertStringContainsString( 'Balkan turu – son 5 kişi, 399 €', $block, 'Shared first in the block.' );

		Features::set( Features::NETWORK_SHARE, false );
		$this->assertArrayNotHasKey( 'network_listings', rest_get_server()->dispatch( new WP_REST_Request( 'GET', '/aihs/v1/listings' ) )->get_data() );
		$this->assertStringNotContainsString( 'aihs-network-listings', CatalogPage::render_html() );
	}
}
