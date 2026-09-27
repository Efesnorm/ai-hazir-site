<?php
/**
 * Contract tests: every endpoint answers in its JSON schema.
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\Tests\Integration\Rest;

use AIHazirSite\Adapters\Rest\RestSchemas;
use AIHazirSite\Core\Features;
use AIHazirSite\WordPress\Rest\RestModule;

/**
 * Bodies validated with WordPress's own rest_validate_value_from_schema().
 *
 * @covers \AIHazirSite\WordPress\Rest\RestModule
 * @covers \AIHazirSite\Adapters\Rest\RestResponder
 */
final class RestContractTest extends RestTestCase {

	/**
	 * Asserts a body matches a schema.
	 *
	 * @param string $schema Schema name.
	 * @param mixed  $body   Body.
	 */
	private function assertMatchesSchema( string $schema, mixed $body ): void {
		$result = rest_validate_value_from_schema( $body, RestSchemas::get( $schema ), $schema );
		$this->assertTrue( true === $result, is_wp_error( $result ) ? $result->get_error_message() : '' );
	}

	/**
	 * Empty catalog: all endpoints still answer within their schema.
	 */
	public function test_empty_catalog(): void {
		$routes = array(
			'/profile'   => 'profile',
			'/listings'  => 'listings',
			'/templates' => 'templates',
		);
		foreach ( $routes as $route => $schema ) {
			$response = self::get( $route );
			$this->assertSame( 200, $response->get_status(), $route );
			$this->assertMatchesSchema( $schema, $response->get_data() );
		}
		$this->assertSame( array(), self::get( '/listings' )->get_data()['items'] );
	}

	/**
	 * Full catalog (four templates): every body within its schema.
	 */
	public function test_full_catalog(): void {
		$ids = self::catalog();

		$this->assertMatchesSchema( 'profile', self::get( '/profile' )->get_data() );
		$listings = self::get( '/listings', array( 'per_page' => 50 ) )->get_data();
		$this->assertMatchesSchema( 'listings', $listings );
		$this->assertCount( 4, $listings['items'] );
		foreach ( array( 'cable', 'service', 'tour', 'demand' ) as $name ) {
			$response = self::get( '/listings/' . $ids[ $name ] );
			$this->assertSame( 200, $response->get_status(), $name );
			$this->assertMatchesSchema( 'listing', $response->get_data() );
		}
		$templates = self::get( '/templates' )->get_data();
		$this->assertMatchesSchema( 'templates', $templates );
		$this->assertSame( array( 'general', 'product', 'service', 'tour' ), array_column( $templates['items'], 'id' ) );
	}

	/**
	 * The schemas are published for agents.
	 */
	public function test_schema_endpoints(): void {
		foreach ( RestSchemas::NAMES as $name ) {
			$response = self::get( '/schema/' . $name );
			$this->assertSame( 200, $response->get_status() );
			$this->assertSame( RestSchemas::get( $name ), $response->get_data() );
		}
	}

	/**
	 * Feature off: every endpoint is 404 and the namespace is not announced.
	 */
	public function test_feature_off(): void {
		Features::set( Features::REST_API, false );
		remove_all_actions( 'rest_api_init' );
		self::boot();

		foreach ( array( '/profile', '/listings', '/listings/1', '/templates', '/schema/listings' ) as $route ) {
			$this->assertSame( 404, self::get( $route )->get_status(), $route );
		}
		$this->assertNotContains( RestModule::NAMESPACE, rest_get_server()->get_namespaces() );
	}
}
