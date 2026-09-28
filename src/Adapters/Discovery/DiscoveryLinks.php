<?php
/**
 * Links that announce our AI resources on the home page.
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\Adapters\Discovery;

/**
 * Platform-neutral producer (1.7.0). Agents read the home page but do not look for /llms.txt on
 * their own (seen on a live site), so the home page points to our resources with registered links:
 *
 * - llms.txt: rel="describedby", type text/markdown, as the llms.txt proposal (https://llmstxt.org/)
 *   describes, in an HTML <link> or an HTTP Link header;
 * - REST API: rel="service-desc" (RFC 8631), the machine-readable service description.
 *
 * Both forms follow RFC 8288 (Web Linking). The A2A Agent Card is not linked: A2A defines its
 * discovery by the well-known URI only.
 *
 * @phpstan-type Link array{rel: string, href: string, type: string, title: string}
 */
final class DiscoveryLinks {

	public const LLMS_REL  = 'describedby';
	public const LLMS_TYPE = 'text/markdown';
	public const API_REL   = 'service-desc';
	public const API_TYPE  = 'application/json';

	/**
	 * Links for the resources that are on ('' = off).
	 *
	 * @param string $llms_url /llms.txt URL, or ''.
	 * @param string $api_url  REST API index URL, or ''.
	 * @return list<array{rel: string, href: string, type: string, title: string}>
	 */
	public static function links( string $llms_url, string $api_url ): array {
		$links = array();
		if ( '' !== $llms_url ) {
			$links[] = array(
				'rel'   => self::LLMS_REL,
				'href'  => $llms_url,
				'type'  => self::LLMS_TYPE,
				'title' => 'llms.txt',
			);
		}
		if ( '' !== $api_url ) {
			$links[] = array(
				'rel'   => self::API_REL,
				'href'  => $api_url,
				'type'  => self::API_TYPE,
				'title' => 'AI Katalog API',
			);
		}
		return $links;
	}

	/**
	 * HTML <link> elements (one per line).
	 *
	 * @param list<array{rel: string, href: string, type: string, title: string}> $links Links.
	 */
	public static function html( array $links ): string {
		$html = '';
		foreach ( $links as $link ) {
			$html .= sprintf(
				'<link rel="%s" type="%s" href="%s" title="%s">' . "\n",
				self::attr( $link['rel'] ),
				self::attr( $link['type'] ),
				self::attr( $link['href'] ),
				self::attr( $link['title'] )
			);
		}
		return $html;
	}

	/**
	 * Value of one HTTP Link header (RFC 8288: <uri>; rel="…"; type="…", comma-separated).
	 * URIs with characters that would break the header are left out.
	 *
	 * @param list<array{rel: string, href: string, type: string, title: string}> $links Links.
	 */
	public static function header( array $links ): string {
		$values = array();
		foreach ( $links as $link ) {
			if ( 1 === preg_match( '/[\s<>"\x00-\x1f\x7f]/', $link['href'] ) ) {
				continue;
			}
			$values[] = sprintf( '<%s>; rel="%s"; type="%s"', $link['href'], $link['rel'], $link['type'] );
		}
		return implode( ', ', $values );
	}

	/**
	 * HTML attribute escaping.
	 *
	 * @param string $value Value.
	 */
	private static function attr( string $value ): string {
		return htmlspecialchars( $value, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
	}
}
