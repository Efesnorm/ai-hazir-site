<?php
/**
 * Portal network fixes from the live trial (1.23.1).
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\Tests\Integration\Network;

use AIHazirSite\Core\Features;
use AIHazirSite\Core\Network\NetworkSettings;
use AIHazirSite\WordPress\Catalog\CatalogModule;
use AIHazirSite\WordPress\Network\NetworkModule;
use AIHazirSite\WordPress\Network\NetworkPage;
use AIHazirSite\WordPress\Portal\Portal;
use AIHazirSite\WordPress\Schema\SchemaModule;
use WP_UnitTestCase;

/**
 * Mother with an SEO plugin; Kosova has an empty profile (name from its site title), Yunanistan a named profile.
 *
 * @covers \AIHazirSite\WordPress\Network\NetworkModule
 * @covers \AIHazirSite\WordPress\Network\NetworkPage
 * @covers \AIHazirSite\Adapters\Schema\NetworkSchema
 * @covers \AIHazirSite\Core\Network\NetworkSettings
 */
final class NetworkFixesTest extends WP_UnitTestCase {

	private const KOSOVA = 'https://www.kosova.org.tr/';
	private const YUNAN  = 'https://www.yunanistan.org.tr/';

	/**
	 * Requested URLs.
	 *
	 * @var list<string>
	 */
	private array $requests = array();

	/**
	 * Network, catalog and Schema.org on; fake members answer.
	 */
	public function set_up(): void {
		parent::set_up();
		global $wp_rest_server;
		foreach ( array( Features::OPTION, NetworkModule::OPTION, NetworkModule::STATE ) as $option ) {
			delete_option( $option );
		}
		add_filter( 'home_url', static fn( string $url ): string => (string) preg_replace( '#^https?://[^/]+#', 'https://www.anne.example', $url ) );
		foreach ( array( Features::CATALOG, Features::REST_API, Features::SCHEMA_OUTPUT, Features::PORTAL_NETWORK ) as $feature ) {
			Features::set( $feature, true );
		}
		CatalogModule::service()->save_profile(
			array(
				'name'    => 'Makedonya Türkiye Consulting',
				'country' => 'MK',
			)
		);
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$wp_rest_server = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Test isolation of the core REST server.

		$own_url  = NetworkModule::self_url();
		$requests = &$this->requests;
		add_filter(
			'pre_http_request',
			static function ( $pre, array $args, string $url ) use ( &$requests, $own_url ) {
				$requests[] = $url;
				if ( false !== $pre ) {
					return $pre;
				}
				$json   = static fn( array $body ): array => array(
					'headers'  => array(),
					'body'     => (string) wp_json_encode( $body ),
					'response' => array(
						'code'    => 200,
						'message' => 'OK',
					),
					'cookies'  => array(),
				);
				$member = array(
					'role'    => 'member',
					'name'    => '',
					'mother'  => $own_url,
					'members' => array(),
				);
				return match ( $url ) {
					self::KOSOVA . 'wp-json/aihs/v1/network', self::YUNAN . 'wp-json/aihs/v1/network' => $json( $member ),
					self::KOSOVA . 'wp-json/aihs/v1/profile' => $json( array( 'name' => '' ) ),
					self::KOSOVA . 'wp-json/'                => $json( array( 'name' => 'Kosova Kültür &amp; <b>Turizm</b> Portalı' ) ),
					self::YUNAN . 'wp-json/aihs/v1/profile'  => $json(
						array(
							'name'    => 'Yunanistan Portalı',
							'country' => 'GR',
						)
					),
					default                                  => new \WP_Error( 'http_request_failed', 'down' ),
				};
			},
			10,
			3
		);

		$_REQUEST['_wpnonce'] = wp_create_nonce( NetworkPage::SAVE );
		NetworkPage::handle_save(
			array(
				'role'    => 'mother',
				'name'    => 'Balkan Ülkeleri Portal Ağı',
				'members' => self::KOSOVA . "\n" . self::YUNAN,
			)
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
	 * An empty profile name falls back to the site title (cleaned); a named profile needs no extra request.
	 */
	public function test_member_name_fallback(): void {
		$state = NetworkModule::state();
		$this->assertSame( 'Kosova Kültür & Turizm Portalı', $state['members'][ self::KOSOVA ]['name'] );
		$this->assertSame( '', $state['members'][ self::KOSOVA ]['country'], 'No fallback for the country.' );
		$this->assertSame( 'Yunanistan Portalı', $state['members'][ self::YUNAN ]['name'] );
		$this->assertContains( self::KOSOVA . 'wp-json/', $this->requests );
		$this->assertNotContains( self::YUNAN . 'wp-json/', $this->requests );

		$html = NetworkPage::render_html();
		$this->assertStringContainsString( self::KOSOVA . ' – Kosova Kültür &amp; Turizm Portalı (profilde ülke yok)', $html );
		$this->assertStringContainsString( self::YUNAN . ' – Yunanistan Portalı</td>', $html );
	}

	/**
	 * With an SEO plugin owning the home page, the mother's catalog still publishes the network and its members;
	 * a business page names the network only.
	 */
	public function test_mother_catalog_with_seo_plugin(): void {
		add_filter( 'aihs_schema_seo_conflict', static fn(): string => 'Rank Math' );
		$this->assertNull( SchemaModule::home_document() );

		$catalog = SchemaModule::catalog_document();
		$this->assertIsArray( $catalog, 'Valid Schema.org (published).' );
		$network = $catalog['publisher']['parentOrganization'];
		$this->assertSame( NetworkModule::self_url() . '#network', $network['@id'] );
		$this->assertSame( 'Balkan Ülkeleri Portal Ağı', $network['name'] );
		$this->assertSame( array( NetworkModule::self_url(), self::KOSOVA, self::YUNAN ), array_column( $network['subOrganization'], 'url' ) );
		$this->assertSame( array( 'Makedonya Türkiye Consulting', 'Kosova Kültür & Turizm Portalı', 'Yunanistan Portalı' ), array_column( $network['subOrganization'], 'name' ) );

		Features::set( Features::PORTAL_MODE, true );
		$business = Portal::service()->save_business( array( 'name' => 'Ohrid Turları' ) )['business'];
		$this->assertNotNull( $business );
		$page = SchemaModule::catalog_document( null, $business );
		$this->assertIsArray( $page );
		$this->assertArrayNotHasKey( 'subOrganization', $page['publisher']['parentOrganization'] ?? array( 'subOrganization' => 'missing parent' ) );
	}

	/**
	 * A member's catalog names the network only.
	 */
	public function test_member_catalog_unchanged(): void {
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
					'name'    => 'Balkan Ülkeleri Portal Ağı',
					'mother'  => self::KOSOVA,
					'members' => array(
						array(
							'url'      => self::KOSOVA,
							'name'     => 'Anne',
							'country'  => 'XK',
							'verified' => true,
						),
						array(
							'url'      => NetworkModule::self_url(),
							'name'     => 'Biz',
							'country'  => 'MK',
							'verified' => true,
						),
					),
				),
			)
		);
		update_option( NetworkModule::OPTION, ( new NetworkSettings( NetworkSettings::ROLE_MEMBER, '', self::KOSOVA ) )->to_array() );
		$parent = ( (array) SchemaModule::catalog_document() )['publisher']['parentOrganization'];
		$this->assertSame( self::KOSOVA . '#network', $parent['@id'] );
		$this->assertArrayNotHasKey( 'subOrganization', $parent );

		// The member screen shows the network's name, read-only, and only the member's fields by role.
		$html = NetworkPage::render_html();
		$this->assertStringContainsString( '<tr class="aihs-for-member" id="aihs-network-joined"><th>Ağ adı</th><td><strong>Balkan Ülkeleri Portal Ağı</strong>', $html );
		$this->assertStringContainsString( '<tr class="aihs-for-mother"><th><label for="aihs-network-name">', $html );
		$this->assertStringContainsString( '<tr class="aihs-for-member"><th><label for="aihs-network-mother">', $html );
		$this->assertStringContainsString( '#aihs-network-form:has(input[name="role"]:not([value="mother"]):checked) .aihs-for-mother', $html );
	}

	/**
	 * Choosing "mother" without a name explains that members leave it empty.
	 */
	public function test_error_text(): void {
		[ , $errors ] = NetworkSettings::from_input( array( 'role' => 'mother' ), NetworkModule::self_url() );
		$this->assertSame( array( 'Ağ adı gerekli (yalnızca anne site için). Bu site bir anneye bağlanacaksa rolü "Üye" seçin.' ), $errors );
	}
}
