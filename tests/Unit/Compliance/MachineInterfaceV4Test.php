<?php
/**
 * Machine interface, scoring version 4 (1.13.0).
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\Tests\Unit\Compliance;

use AIHazirSite\Core\Compliance\Checks\MachineInterfaceCheck;
use AIHazirSite\Core\Contracts\PageResponse;
use AIHazirSite\Tests\Support\ComplianceSites;
use AIHazirSite\Tests\Support\FakePageFetcher;
use PHPUnit\Framework\TestCase;

/**
 * Pilot sites got 20/20 from WordPress's core API and an MCP server that required authentication.
 *
 * @covers \AIHazirSite\Core\Compliance\Checks\MachineInterfaceCheck
 */
final class MachineInterfaceV4Test extends TestCase {

	/**
	 * Ratio for a site whose REST index lists these namespaces and whose MCP routes answer these statuses.
	 *
	 * @param string[]           $namespaces REST namespaces.
	 * @param array<string, int> $mcp        Route → status.
	 */
	private static function ratio( array $namespaces, array $mcp ): float {
		$b         = ComplianceSites::BASE;
		$responses = array(
			$b              => new PageResponse( 200, array( 'link' => '<' . $b . 'wp-json/>; rel="https://api.w.org/"' ), '<p>x</p>' ),
			$b . 'wp-json/' => new PageResponse( 200, array(), (string) json_encode( array( 'namespaces' => $namespaces ) ) ), // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
		);
		foreach ( $mcp as $route => $status ) {
			$responses[ $b . 'wp-json/' . $route ] = new PageResponse( $status, array(), '' );
		}
		return (float) ( new MachineInterfaceCheck() )->run( ComplianceSites::site( new FakePageFetcher( $responses ) ) )->ratio;
	}

	/**
	 * Core API only and an MCP server behind authentication (the pilot sites): 0.25 + 0.1.
	 */
	public function test_core_api_and_closed_mcp(): void {
		$this->assertEqualsWithDelta( 0.35, self::ratio( array( 'wp/v2', 'mcp' ), array( 'mcp/mcp-adapter-default-server' => 401 ) ), 0.0001 );
		$this->assertEqualsWithDelta( 0.35, self::ratio( array( 'wp/v2', 'mcp' ), array( 'mcp/mcp-adapter-default-server' => 403 ) ), 0.0001 );
	}

	/**
	 * Our data API and our open MCP server: full credit, even when another MCP server is closed.
	 */
	public function test_data_api_and_open_mcp(): void {
		$this->assertEqualsWithDelta(
			1.0,
			self::ratio(
				array( 'wp/v2', 'aihs/v1', 'aihs', 'mcp' ),
				array(
					'aihs/mcp'                       => 405,
					'mcp/mcp-adapter-default-server' => 401,
				)
			),
			0.0001
		);
	}

	/**
	 * WooCommerce Store API counts as a data API; no MCP at all.
	 */
	public function test_store_api_without_mcp(): void {
		$this->assertEqualsWithDelta( 0.5, self::ratio( array( 'wp/v2', 'wc/store/v1' ), array() ), 0.0001 );
	}
}
