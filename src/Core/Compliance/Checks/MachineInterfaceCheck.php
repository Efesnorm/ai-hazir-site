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
 */
final class MachineInterfaceCheck implements Check {

	public const REST_REL   = 'https://api.w.org/';
	public const MCP_ROUTE  = 'mcp/mcp-adapter-default-server';
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
	 * REST: 0.5 when discovered and answering, 0.25 when only found at /wp-json/ by convention.
	 * MCP: 0.5 when the endpoint exists (any of MCP_STATUS).
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

		if ( '' !== $rest_root && self::is_json( $site, $rest_root ) ) {
			$ratio     += 0.5;
			$findings[] = 'REST API keşfedilebilir: ' . $rest_root;
		} elseif ( self::is_json( $site, 'wp-json/' ) ) {
			$rest_root  = $site->url( 'wp-json/' );
			$ratio     += 0.25;
			$findings[] = 'REST API /wp-json/ adresinde çalışıyor ama sayfalarda keşif bağlantısı (rel="https://api.w.org/") yok.';
		} else {
			$rest_root  = $site->url( 'wp-json/' );
			$findings[] = 'REST API bulunamadı.';
		}

		$mcp = $site->fetch( self::mcp_url( $rest_root ) );
		if ( in_array( $mcp->status, self::MCP_STATUS, true ) ) {
			$ratio     += 0.5;
			$findings[] = 'MCP uç noktası yanıt veriyor.';
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
	 * Whether a URL answers 2xx with a JSON object or list.
	 *
	 * @param Site   $site Site.
	 * @param string $url  URL or path.
	 */
	private static function is_json( Site $site, string $url ): bool {
		$response = $site->fetch( $url );
		return $response->ok() && is_array( json_decode( $response->body, true ) );
	}

	/**
	 * MCP adapter URL for a REST root; works for /wp-json/ and ?rest_route=/ roots alike.
	 *
	 * @param string $rest_root REST root URL.
	 */
	private static function mcp_url( string $rest_root ): string {
		return rtrim( $rest_root, '/' ) . '/' . self::MCP_ROUTE;
	}
}
