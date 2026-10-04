<?php
/**
 * Sector field and sibling suggestions (1.22.0).
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\Tests\Integration\Network;

use AIHazirSite\Core\Features;
use AIHazirSite\WordPress\Abilities\AbilitiesModule;
use AIHazirSite\WordPress\Catalog\Admin\CatalogAdmin;
use AIHazirSite\WordPress\Catalog\Admin\FormState;
use AIHazirSite\WordPress\Catalog\CatalogModule;
use AIHazirSite\WordPress\Catalog\WpProfileRepository;
use AIHazirSite\WordPress\Llms\LlmsModule;
use AIHazirSite\WordPress\Network\NetworkCatalog;
use AIHazirSite\WordPress\Network\NetworkModule;
use AIHazirSite\WordPress\Network\NetworkPage;
use AIHazirSite\WordPress\Rest\RestModule;
use WP_REST_Request;
use WP_REST_Response;
use WP_UnitTestCase;
use WPDieException;

/**
 * This site is the mother; Kosova is a verified member with a two-page catalog; Arnavutluk answers 429.
 *
 * @covers \AIHazirSite\WordPress\Network\NetworkCatalog
 * @covers \AIHazirSite\WordPress\Catalog\CatalogReader
 * @covers \AIHazirSite\WordPress\Rest\RestModule
 * @covers \AIHazirSite\WordPress\Abilities\AbilitiesModule
 * @covers \AIHazirSite\WordPress\Catalog\Admin\CatalogAdmin
 */
final class NetworkCatalogTest extends WP_UnitTestCase {

	private const KOSOVA  = 'https://www.kosova.org.tr/';
	private const LIMITED = 'https://www.arnavutluk.org.tr/';

	/**
	 * Requested URLs.
	 *
	 * @var list<string>
	 */
	private array $requests = array();

	/**
	 * Whether Kosova is reachable.
	 *
	 * @var bool
	 */
	private bool $kosova_up = true;

	/**
	 * Features on, a local listing, fake siblings, network saved (runs the check).
	 */
	public function set_up(): void {
		parent::set_up();
		global $wp_rest_server;
		foreach ( array( Features::OPTION, NetworkModule::OPTION, NetworkModule::STATE ) as $option ) {
			delete_option( $option );
		}
		add_filter( 'home_url', static fn( string $url ): string => (string) preg_replace( '#^https?://[^/]+#', 'https://portal.example', $url ) );
		add_filter( 'aihs_rest_rate_limit', static fn(): int => 10000 );
		add_filter( 'aihs_abilities_rate_limit', static fn(): int => 10000 );
		foreach ( array( Features::CATALOG, Features::REST_API, Features::LLMS_TXT, Features::ABILITIES, Features::PORTAL_NETWORK, Features::NETWORK_SUGGESTIONS ) as $feature ) {
			Features::set( $feature, true );
		}
		CatalogModule::service()->save_profile(
			array(
				'name'    => 'Makedonya Portalı',
				'country' => 'MK',
				'nace'    => 'N',
			)
		);
		CatalogModule::service()->save_listing(
			array(
				'type'     => 'offer',
				'title'    => 'Üsküp şehir turu',
				'category' => 'Tur',
				'region'   => 'Üsküp',
			)
		);
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$wp_rest_server = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Test isolation of the core REST server.

		$own_url  = NetworkModule::self_url();
		$requests = &$this->requests;
		$up       = &$this->kosova_up;
		add_filter(
			'pre_http_request',
			static function ( $pre, array $args, string $url ) use ( &$requests, &$up, $own_url ) {
				$requests[] = $url;
				if ( false !== $pre ) {
					return $pre;
				}
				$json    = static fn( array $body, int $code = 200, array $headers = array() ): array => array(
					'headers'  => $headers,
					'body'     => (string) wp_json_encode( $body ),
					'response' => array(
						'code'    => $code,
						'message' => 'x',
					),
					'cookies'  => array(),
				);
				$member  = array(
					'role'    => 'member',
					'name'    => '',
					'mother'  => $own_url,
					'members' => array(),
				);
				$listing = static fn( int $id, string $title, string $category, string $region ): array => array(
					'id'         => $id,
					'type'       => 'offer',
					'title'      => $title,
					'category'   => $category,
					'region'     => $region,
					'url'        => self::KOSOVA . 'ai-katalog/#ilan-' . $id,
					'updated_at' => '2026-10-0' . $id . 'T10:00:00Z',
				);
				$api     = self::KOSOVA . 'wp-json/aihs/v1/';
				if ( ! $up && str_starts_with( $url, $api ) && ! str_ends_with( $url, 'network' ) ) {
					return new \WP_Error( 'http_request_failed', 'down' );
				}
				return match ( $url ) {
					$api . 'network'                                  => $json( $member ),
					$api . 'profile'                                  => $json(
						array(
							'name'    => 'Kosova Portalı',
							'country' => 'XK',
							'nace'    => 'H',
						)
					),
					$api . 'listings?per_page=50&page=1'              => $json(
						array(
							'items'       => array( $listing( 1, 'Prizren – Üsküp nakliye', 'Nakliye', 'Prizren' ) ),
							'total_pages' => 2,
						)
					),
					$api . 'listings?per_page=50&page=2'              => $json(
						array(
							'items'       => array( $listing( 2, 'Priştine depolama', 'Depolama', 'Priştine' ) ),
							'total_pages' => 2,
						)
					),
					self::LIMITED . 'wp-json/aihs/v1/network'         => $json( $member ),
					self::LIMITED . 'wp-json/aihs/v1/profile'         => $json( array(), 429, array( 'retry-after' => '600' ) ),
					default                                           => $json( array( 'code' => 'rest_no_route' ), 404 ),
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
				'members' => self::KOSOVA . "\n" . self::LIMITED,
			)
		);
		( new RestModule() )->register();
		( new NetworkModule() )->register();
	}

	/**
	 * Clears request globals.
	 */
	public function tear_down(): void {
		unset( $_REQUEST['_wpnonce'] );
		parent::tear_down();
	}

	/**
	 * GET /aihs/v1/listings.
	 *
	 * @param array<string, mixed> $params Query.
	 */
	private static function listings( array $params = array() ): WP_REST_Response {
		$request = new WP_REST_Request( 'GET', '/aihs/v1/listings' );
		$request->set_query_params( $params );
		return rest_get_server()->dispatch( $request );
	}

	/**
	 * The hourly job fills the copies; 429 is respected; empty searches get suggestions on REST and MCP.
	 */
	public function test_suggestions_end_to_end(): void {
		do_action( NetworkModule::HOOK ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- Our hook, by its constant.

		$copy = get_transient( NetworkCatalog::PREFIX . md5( self::KOSOVA ) );
		$this->assertIsArray( $copy );
		$this->assertSame( array( 'Prizren – Üsküp nakliye', 'Priştine depolama' ), array_column( $copy['items'], 'title' ), 'Both pages read.' );
		$this->assertFalse( get_transient( NetworkCatalog::PREFIX . md5( self::LIMITED ) ) );
		$this->assertNotFalse( get_transient( NetworkCatalog::RETRY . md5( self::LIMITED ) ), 'Retry-After remembered.' );

		$count = count( $this->requests );
		NetworkCatalog::refresh();
		$this->assertNotContains( self::LIMITED . 'wp-json/aihs/v1/profile', array_slice( $this->requests, $count ), 'No request before Retry-After.' );

		// A local hit: no suggestions unless asked.
		$local = self::listings( array( 'category' => 'tur' ) )->get_data();
		$this->assertSame( 1, $local['total'] );
		$this->assertArrayNotHasKey( 'network_suggestions', $local );
		$this->assertCount( 2, self::listings( array( 'network' => 'true' ) )->get_data()['network_suggestions'] );

		// Nothing here: suggestions from Kosova.
		$none = self::listings( array( 'category' => 'nakliye' ) )->get_data();
		$this->assertSame( 0, $none['total'] );
		$this->assertSame( array( 'Prizren – Üsküp nakliye' ), array_column( $none['network_suggestions'], 'title' ) );
		$this->assertSame( 'XK', $none['network_suggestions'][0]['country'] );
		$this->assertSame( 'H', $none['network_suggestions'][0]['nace'] );

		// Sector: local by the site profile (N), siblings by theirs (H).
		$this->assertSame( 1, self::listings( array( 'sector' => 'N' ) )->get_data()['total'] );
		$by_sector = self::listings( array( 'sector' => 'H' ) )->get_data();
		$this->assertSame( 0, $by_sector['total'] );
		$this->assertCount( 2, $by_sector['network_suggestions'] );
		$this->assertSame( 400, self::listings( array( 'sector' => 'nakliye' ) )->get_status() );

		// MCP / abilities: same answer, same schema.
		$mcp = AbilitiesModule::search_listings( array( 'keyword' => 'depolama' ) );
		$this->assertIsArray( $mcp );
		$this->assertSame( array( 'Priştine depolama' ), array_column( $mcp['network_suggestions'], 'title' ) );
		$this->assertSame( 1, AbilitiesModule::search_listings( array( 'sector' => 'N' ) )['total'] ?? null );
		$this->assertArrayHasKey( 'network', AbilitiesModule::schemas()['aihs/search-listings']['input']['properties'] );
		$this->assertArrayHasKey( 'network_suggestions', AbilitiesModule::schemas()['aihs/search-listings']['output']['properties'] );

		// OpenAPI describes the parameters.
		$params = array_column( RestModule::openapi()['paths']['/listings']['get']['parameters'], 'name' );
		$this->assertContains( 'sector', $params );
		$this->assertContains( 'network', $params );

		// An unreachable sibling's old copy is not shown.
		$this->kosova_up = false;
		NetworkCatalog::refresh();
		$this->assertFalse( get_transient( NetworkCatalog::PREFIX . md5( self::KOSOVA ) ) );
		$this->assertSame( array(), self::listings( array( 'category' => 'nakliye' ) )->get_data()['network_suggestions'] );
	}

	/**
	 * With the key off: no requests for catalogs, no new key, no new parameter.
	 */
	public function test_feature_off(): void {
		Features::set( Features::NETWORK_SUGGESTIONS, false );
		$count = count( $this->requests );
		NetworkCatalog::refresh();
		$this->assertSame( $count, count( $this->requests ) );

		$this->assertArrayNotHasKey( 'network_suggestions', self::listings( array( 'category' => 'nakliye' ) )->get_data() );
		$this->assertArrayNotHasKey( 'network', AbilitiesModule::schemas()['aihs/search-listings']['input']['properties'] );
		$this->assertNotContains( 'network', array_column( RestModule::openapi()['paths']['/listings']['get']['parameters'], 'name' ) );
		$this->assertContains( 'sector', array_column( RestModule::openapi()['paths']['/listings']['get']['parameters'], 'name' ), 'The sector filter is part of the catalog.' );
	}

	/**
	 * The profile form saves the section (nonce required); REST and llms.txt show it.
	 */
	public function test_nace_form_and_output(): void {
		$admin                = new CatalogAdmin();
		$_REQUEST['_wpnonce'] = wp_create_nonce( CatalogAdmin::SAVE_PROFILE );
		$admin->handle_save_profile(
			array(
				'name' => 'Makedonya Portalı',
				'nace' => 'i',
			)
		);
		$this->assertSame( 'I', ( new WpProfileRepository() )->get()->nace );
		$this->assertStringContainsString( '<option value="I" selected', CatalogAdmin::render_profile_form( ( new WpProfileRepository() )->get(), FormState::take() ) );
		$this->assertSame( 'I', rest_get_server()->dispatch( new WP_REST_Request( 'GET', '/aihs/v1/profile' ) )->get_data()['nace'] );
		$this->assertStringContainsString( 'Faaliyet alanı (NACE Rev. 2.1): I – Konaklama ve yiyecek hizmeti faaliyetleri', LlmsModule::text() );

		unset( $_REQUEST['_wpnonce'] );
		$this->expectException( WPDieException::class );
		try {
			$admin->handle_save_profile(
				array(
					'name' => 'Makedonya Portalı',
					'nace' => 'H',
				)
			);
		} finally {
			$this->assertSame( 'I', ( new WpProfileRepository() )->get()->nace );
		}
	}
}
