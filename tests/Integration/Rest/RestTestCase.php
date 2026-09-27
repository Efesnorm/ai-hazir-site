<?php
/**
 * Shared setup for the REST API integration tests.
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\Tests\Integration\Rest;

use AIHazirSite\Core\Features;
use AIHazirSite\WordPress\Catalog\CatalogModule;
use AIHazirSite\WordPress\Rest\RestModule;
use AIHazirSite\WordPress\Templates\TemplatesModule;
use WP_REST_Request;
use WP_REST_Response;
use WP_UnitTestCase;

/**
 * REST on (with templates), a fresh REST server per test.
 */
abstract class RestTestCase extends WP_UnitTestCase {

	/**
	 * Features on, module hooked, server reset.
	 */
	public function set_up(): void {
		parent::set_up();
		delete_option( Features::OPTION );
		Features::set( Features::REST_API, true );
		Features::set( Features::TEMPLATES, true );
		TemplatesModule::reset();
		add_filter( 'aihs_rest_rate_limit', static fn(): int => 10000 );
		$_SERVER['REMOTE_ADDR'] = '203.0.113.' . wp_rand( 1, 250 );
		self::boot();
	}

	/**
	 * Resets the REST server and registers the module as the plugin would on a new request.
	 */
	protected static function boot(): void {
		global $wp_rest_server;
		$wp_rest_server = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Test isolation of the core REST server.
		( new RestModule() )->register();
	}

	/**
	 * Resets the server after the test.
	 */
	public function tear_down(): void {
		global $wp_rest_server;
		$wp_rest_server = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Test isolation of the core REST server.
		TemplatesModule::reset();
		parent::tear_down();
	}

	/**
	 * GET request.
	 *
	 * @param string                $route   Route under aihs/v1 (with leading slash).
	 * @param array<string, mixed>  $params  Query parameters.
	 * @param array<string, string> $headers Headers.
	 */
	protected static function get( string $route, array $params = array(), array $headers = array() ): WP_REST_Response {
		$request = new WP_REST_Request( 'GET', '/' . RestModule::NAMESPACE . $route );
		$request->set_query_params( $params );
		foreach ( $headers as $name => $value ) {
			$request->set_header( $name, $value );
		}
		return rest_get_server()->dispatch( $request );
	}

	/**
	 * Profile plus listings of every template kind; returns listing ids by name.
	 *
	 * @return array<string, int>
	 */
	protected static function catalog(): array {
		$service = CatalogModule::service();
		$service->save_profile(
			array(
				'name'          => 'Örnek Kablo A.Ş.',
				'country'       => 'TR',
				'contact_email' => 'satis@ornek.com.tr',
				'template'      => 'product',
			)
		);
		$future = gmdate( 'Y-m-d', time() + 30 * DAY_IN_SECONDS );
		$ids    = array();
		$save   = static function ( array $input ) use ( $service ): int {
			$result = $service->save_listing( $input );
			self::assertTrue( $result->is_valid(), implode( ' | ', $result->errors ) );
			return (int) $result->listing()?->id;
		};

		$ids['cable']   = $save(
			array(
				'type'       => 'offer',
				'title'      => 'NYY kablo',
				'category'   => 'Kablo',
				'region'     => 'Türkiye',
				'quantity'   => '1500',
				'unit'       => 'm',
				'price_min'  => '42,50',
				'currency'   => 'TRY',
				'template'   => 'product',
				'attributes' => array( 'kesit' => '2,5' ),
			)
		);
		$ids['service'] = $save(
			array(
				'type'       => 'offer',
				'title'      => 'Tahkim danışmanlığı',
				'template'   => 'service',
				'attributes' => array( 'uzmanlik_alani' => 'Tahkim' ),
			)
		);
		$ids['tour']    = $save(
			array(
				'type'        => 'offer',
				'title'       => 'Kapadokya turu',
				'price_min'   => '250',
				'currency'    => 'EUR',
				'valid_until' => $future,
				'template'    => 'tour',
				'attributes'  => array(
					'baslangic_tarihi' => $future,
					'kalan_yer'        => '6',
				),
			)
		);
		$ids['demand']  = $save(
			array(
				'type'     => 'demand',
				'title'    => 'Bakır katot',
				'category' => 'Hammadde',
				'region'   => 'Marmara',
			)
		);
		$ids['expired'] = $save(
			array(
				'type'        => 'offer',
				'title'       => 'Eski kampanya',
				'valid_until' => $future,
			)
		);
		update_post_meta( $ids['expired'], '_aihs_valid_until', '2020-01-01' );

		// A price stored for the service listing (e.g. before its template forbade prices).
		update_post_meta( $ids['service'], '_aihs_price_min', '1000' );
		update_post_meta( $ids['service'], '_aihs_currency', 'TRY' );
		return $ids;
	}
}
