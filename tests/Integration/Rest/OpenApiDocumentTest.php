<?php
/**
 * OpenAPI 3.1 description of the REST API (1.18.0).
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\Tests\Integration\Rest;

use AIHazirSite\Adapters\Discovery\DiscoveryLinks;
use AIHazirSite\Adapters\Rest\OpenApiBuilder;
use AIHazirSite\Adapters\Rest\RestSchemas;
use AIHazirSite\Core\Features;
use AIHazirSite\WordPress\Discovery\DiscoveryModule;
use AIHazirSite\WordPress\Inquiry\InquiryModule;
use AIHazirSite\WordPress\Llms\LlmsModule;
use AIHazirSite\WordPress\Rest\RestModule;
use AIHazirSite\WordPress\Schema\CatalogPage;
use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\CompliantValidator;
use WP_REST_Request;

/**
 * Checked against the official OpenAPI 3.1 JSON Schema (tests/Fixtures/openapi, see KAYNAK.md).
 *
 * @covers \AIHazirSite\Adapters\Rest\OpenApiBuilder
 * @covers \AIHazirSite\WordPress\Rest\RestModule
 * @covers \AIHazirSite\Adapters\Discovery\DiscoveryLinks
 */
final class OpenApiDocumentTest extends RestTestCase {

	/**
	 * The document is valid OpenAPI 3.1 in every state: plain, with the inquiry box, in portal and multilingual mode.
	 */
	public function test_valid_against_official_schema(): void {
		$this->assert_valid( 'plain' );

		foreach ( array( Features::INQUIRIES, Features::PORTAL_MODE, Features::MULTILINGUAL ) as $feature ) {
			Features::set( $feature, true );
		}
		$this->assert_valid( 'all channels' );
	}

	/**
	 * Every aihs/v1 route is described and nothing else (drift check), for the current state.
	 */
	public function test_paths_match_registered_routes(): void {
		$this->assertSame( $this->registered_paths(), $this->documented_paths() );

		Features::set( Features::INQUIRIES, true );
		self::reboot();
		$this->assertContains( '/inquiries', $this->documented_paths() );
		$this->assertSame( $this->registered_paths(), $this->documented_paths() );
	}

	/**
	 * Served with the OpenAPI media type; the response schemas are the ones served at /schema/{name}.
	 */
	public function test_served_and_single_source(): void {
		$response = rest_get_server()->dispatch( new WP_REST_Request( 'GET', '/aihs/v1/openapi.json' ) );
		$this->assertSame( 200, $response->get_status() );
		$this->assertStringStartsWith( OpenApiBuilder::MEDIA_TYPE, $response->get_headers()['Content-Type'] ?? '' );

		$document = $response->get_data();
		$this->assertSame( '3.1.0', $document['openapi'] );
		$this->assertSame( untrailingslashit( RestModule::url() ), $document['servers'][0]['url'] );
		$listing = RestSchemas::get( 'listing' );
		unset( $listing['$schema'] );
		$this->assertSame( $listing, $document['components']['schemas']['Listing'] );
	}

	/**
	 * Discovery points to the document; llms.txt names it; the catalog page links its Markdown version.
	 */
	public function test_discovery(): void {
		foreach ( array( Features::CATALOG, Features::LLMS_TXT, Features::DISCOVERY ) as $feature ) {
			Features::set( $feature, true );
		}
		$this->assertStringContainsString( '<' . RestModule::url( 'openapi.json' ) . '>; rel="service-desc"; type="' . DiscoveryLinks::API_TYPE . '"', DiscoveryLinks::header( DiscoveryModule::links() ) );
		$this->assertSame( 'application/vnd.oai.openapi+json', DiscoveryLinks::API_TYPE );

		$this->assertStringContainsString( '(' . RestModule::url( 'openapi.json' ) . ')', LlmsModule::text() );
		$this->assertStringContainsString( '<link rel="alternate" type="text/markdown" href="' . home_url( '/llms.txt' ) . '">', CatalogPage::render_html() );
	}

	/**
	 * REST off: no document.
	 */
	public function test_rest_off(): void {
		Features::set( Features::REST_API, false );
		remove_action( 'rest_api_init', array( RestModule::class, 'routes' ) );
		self::reboot();
		$this->assertSame( 404, rest_get_server()->dispatch( new WP_REST_Request( 'GET', '/aihs/v1/openapi.json' ) )->get_status() );
	}

	/**
	 * Validates the served document.
	 *
	 * @param string $state Label.
	 */
	private function assert_valid( string $state ): void {
		// Spec-compliant options (e.g. the schema's default values are never written into the document).
		$validator = new CompliantValidator();
		$validator->resolver()->registerRaw( self::official_schema(), 'https://spec.openapis.org/oas/3.1/schema/2022-10-07' );
		$document = json_decode( (string) wp_json_encode( RestModule::openapi() ) );
		$result   = $validator->validate( $document, 'https://spec.openapis.org/oas/3.1/schema/2022-10-07' );
		$error    = $result->error();
		$this->assertTrue( $result->isValid(), $state . ': ' . ( null === $error ? '' : (string) wp_json_encode( ( new ErrorFormatter() )->format( $error, true ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) ) );
	}

	/**
	 * The official schema, read unchanged from the fixture. opis/json-schema resolves `$dynamicRef: "#meta"` to the
	 * document root instead of the `$dynamicAnchor: "meta"` in `$defs/schema` (checked with a minimal schema). With no
	 * extending dialect in scope, JSON Schema 2020-12 resolves it to that anchor, so it is replaced by the equivalent
	 * `$ref: "#/$defs/schema"` in memory only. The file on disk stays byte-identical (see KAYNAK.md).
	 */
	private static function official_schema(): object {
		$json = (string) file_get_contents( dirname( __DIR__, 2 ) . '/Fixtures/openapi/oas-3.1-schema-2022-10-07.json' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local fixture.
		$json = str_replace( '"$dynamicRef": "#meta"', '"$ref": "#/$defs/schema"', $json );
		return json_decode( $json, false, 512, JSON_THROW_ON_ERROR );
	}

	/**
	 * Registered aihs/v1 routes as OpenAPI paths.
	 *
	 * @return list<string>
	 */
	private function registered_paths(): array {
		$paths = array();
		foreach ( array_keys( rest_get_server()->get_routes( 'aihs/v1' ) ) as $route ) {
			$path = (string) preg_replace( '#\(\?P<(\w+)>[^)]*\)#', '{$1}', substr( (string) $route, strlen( '/aihs/v1' ) ) );
			if ( '' !== $path ) {
				$paths[] = $path;
			}
		}
		sort( $paths );
		return $paths;
	}

	/**
	 * Paths of the document.
	 *
	 * @return list<string>
	 */
	private function documented_paths(): array {
		$paths = array_keys( RestModule::openapi()['paths'] );
		sort( $paths );
		return $paths;
	}

	/**
	 * Routes again after a feature change.
	 */
	private static function reboot(): void {
		self::boot();
		( new InquiryModule() )->register();
		rest_get_server();
	}
}
