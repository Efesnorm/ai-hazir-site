<?php
/**
 * Machine interface (REST / MCP) check.
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\Core\Compliance\Checks;

use AIHazirSite\Core\Compliance\Check;
use AIHazirSite\Core\Compliance\CheckResult;
use AIHazirSite\Core\Compliance\Html;
use AIHazirSite\Core\Compliance\Site;

/**
 * A REST API is discoverable (WordPress: Link header or <link> with rel="https://api.w.org/",
 * developer.wordpress.org/rest-api/using-the-rest-api/discovery/) and an MCP endpoint answers
 * (WordPress MCP Adapter default server: <rest root>/mcp/mcp-adapter-default-server).
 * MCP Server Cards (/.well-known/mcp/server-cards.json, SEP-2127) are still a draft and only reported.
 *
 * Scoring version 4 (1.13.0): what counts is whether AI agents can reach the site's business data.
 * WordPress's core REST API (posts, pages) exists on every site, and many sites have an MCP server that
 * requires authentication (401/403: closed to agents); pilot scans gave both full credit. Now:
 * REST 0.5 with a public data API (DATA_NAMESPACES), 0.25 with the core API only; MCP 0.5 when an
 * endpoint does not require authentication, 0.1 when it does.
 */
final class MachineInterfaceCheck implements Check {

	public const REST_REL        = 'https://api.w.org/';
	public const MCP_ROUTE       = 'mcp/mcp-adapter-default-server';
	public const AIHS_MCP_ROUTE  = 'aihs/mcp';
	public const MCP_OPEN        = array( 200, 400, 405, 406 );
	public const MCP_CLOSED      = array( 401, 403 );
	public const DATA_NAMESPACES = array( 'aihs/v1', 'wc/store/v1', 'wc/store' );

	/**
	 * Statuses that show an MCP endpoint exists (open or closed).
	 */
	public const MCP_STATUS = array( 200, 400, 401, 403, 405, 406 );

	/**
	 * Id.
	 */
	public function id(): string {
		return 'machine_interface';
	}

	/**
	 * Weight.
	 */
	public function weight(): int {
		return 20;
	}

	/**
	 * REST: 0.5 with a public data API, 0.25 with the core API only, 0 without REST.
	 * MCP: 0.5 when an endpoint answers without asking for authentication, 0.1 when it only answers 401/403.
	 *
	 * @param Site $site Site.
	 */
	public function run( Site $site ): CheckResult {
		$home = $site->home();
		if ( ! $home->reached() ) {
			return CheckResult::unmeasured( 'Ana sayfaya erişilemedi.' );
		}

		$findings = array();
		$ratio    = 0.0;

		$rest_root = self::rest_from_link_header( $home->header( 'link' ) );
		if ( '' === $rest_root && $home->ok() ) {
			$rest_root = ( new Html( $home->body ) )->link_rel( self::REST_REL );
		}

		$index = '' === $rest_root ? null : self::json( $site, $rest_root );
		if ( null !== $index ) {
			$findings[] = 'REST API keşfedilebilir: ' . $rest_root;
		} else {
			$index = self::json( $site, 'wp-json/' );
			if ( null !== $index ) {
				$findings[] = 'REST API /wp-json/ adresinde çalışıyor ama sayfalarda keşif bağlantısı (rel="https://api.w.org/") yok.';
			}
			$rest_root = $site->url( 'wp-json/' );
		}

		$namespaces = is_array( $index['namespaces'] ?? null ) ? array_filter( $index['namespaces'], 'is_string' ) : array();
		$data       = array_values( array_intersect( self::DATA_NAMESPACES, $namespaces ) );
		if ( null === $index ) {
			$findings[] = 'REST API bulunamadı.';
		} elseif ( array() !== $data ) {
			$ratio     += 0.5;
			$findings[] = 'Herkese açık veri API\'si var: ' . implode( ', ', $data ) . '.';
		} else {
			$ratio     += 0.25;
			$findings[] = 'Yalnızca WordPress\'in çekirdek API\'si var (yazılar, sayfalar); ürün ya da ilan verisi sunan bir API yok.';
		}

		$routes = array( self::MCP_ROUTE );
		if ( in_array( 'aihs', $namespaces, true ) ) {
			array_unshift( $routes, self::AIHS_MCP_ROUTE );
		}
		$closed = false;
		$open   = false;
		foreach ( $routes as $route ) {
			$status = $site->fetch( rtrim( $rest_root, '/' ) . '/' . $route )->status;
			$open   = $open || in_array( $status, self::MCP_OPEN, true );
			$closed = $closed || in_array( $status, self::MCP_CLOSED, true );
		}
		if ( $open ) {
			$ratio     += 0.5;
			$findings[] = 'MCP uç noktası yanıt veriyor ve kimlik doğrulama istemiyor.';
		} elseif ( $closed ) {
			$ratio     += 0.1;
			$findings[] = 'MCP uç noktası var ama kimlik doğrulama istiyor (401/403): AI agentlara kapalı.';
		} else {
			$findings[] = 'MCP uç noktası bulunamadı.';
		}

		if ( $site->fetch( '.well-known/mcp/server-cards.json' )->ok() ) {
			$findings[] = 'MCP Server Card bulundu (standart taslak; puana katılmadı).';
		}

		return CheckResult::measured( $ratio, $findings, 'Sitenin verisini REST API ve MCP üzerinden sunun (AI Hazır Site REST ve MCP modülleri).' );
	}

	/**
	 * REST root from a Link header, or ''.
	 *
	 * @param string $link Link header.
	 */
	private static function rest_from_link_header( string $link ): string {
		foreach ( explode( ',', $link ) as $part ) {
			if ( str_contains( $part, 'rel="' . self::REST_REL . '"' ) && preg_match( '/<([^>]+)>/', $part, $m ) ) {
				return $m[1];
			}
		}
		return '';
	}

	/**
	 * The decoded JSON (object or list) of a 2xx answer, or null.
	 *
	 * @param Site   $site Site.
	 * @param string $url  URL or path.
	 * @return array<mixed>|null
	 */
	private static function json( Site $site, string $url ): ?array {
		$response = $site->fetch( $url );
		$decoded  = $response->ok() ? json_decode( $response->body, true ) : null;
		return is_array( $decoded ) ? $decoded : null;
	}
}
