<?php
/**
 * Links between sibling portals and their measurement (1.23.0).
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\Core\Network;

/**
 * Our links to a sibling carry the standard campaign parameters (utm_source = our host, utm_medium = portal-agi,
 * utm_campaign = komsu-ulkeler), which Google Analytics 4 and other analytics read. A visit counts as a network
 * referral when its Referer host, or its utm_source with utm_medium = portal-agi, is a verified sibling's host.
 * The stored source is always the sibling's host from our own verified list, never the raw request value.
 */
final class NetworkLink {

	public const UTM_MEDIUM   = 'portal-agi';
	public const UTM_CAMPAIGN = 'komsu-ulkeler';
	public const SOURCE_MAX   = 64;

	/**
	 * The URL with our campaign parameters (before any #fragment; existing query kept).
	 *
	 * @param string $url    Sibling URL.
	 * @param string $source Our host.
	 */
	public static function tag( string $url, string $source ): string {
		$hash     = strpos( $url, '#' );
		$fragment = false === $hash ? '' : substr( $url, $hash );
		$base     = false === $hash ? $url : substr( $url, 0, $hash );
		$query    = http_build_query(
			array(
				'utm_source'   => $source,
				'utm_medium'   => self::UTM_MEDIUM,
				'utm_campaign' => self::UTM_CAMPAIGN,
			),
			'',
			'&',
			PHP_QUERY_RFC3986
		);
		return $base . ( str_contains( $base, '?' ) ? '&' : '?' ) . $query . $fragment;
	}

	/**
	 * Lower-case host without a leading "www." ('' for none).
	 *
	 * @param string $url_or_host URL or bare host.
	 */
	public static function host( string $url_or_host ): string {
		$value = strtolower( trim( $url_or_host ) );
		$host  = str_contains( $value, '://' ) ? (string) parse_url( $value, PHP_URL_HOST ) : $value; // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- Platform-neutral core (no WordPress functions).
		$host  = rtrim( $host, '.' );
		return str_starts_with( $host, 'www.' ) ? substr( $host, 4 ) : $host;
	}

	/**
	 * The sibling a visit came from, or null.
	 *
	 * @param string   $referer    Referer header.
	 * @param string   $utm_source utm_source value.
	 * @param string   $utm_medium utm_medium value.
	 * @param string[] $siblings   Verified siblings' URLs or hosts.
	 * @return string|null The sibling's host (without "www."), at most SOURCE_MAX characters.
	 */
	public static function referral( string $referer, string $utm_source, string $utm_medium, array $siblings ): ?string {
		$known = array();
		foreach ( $siblings as $sibling ) {
			$host = self::host( $sibling );
			if ( '' !== $host && strlen( $host ) <= self::SOURCE_MAX ) {
				$known[ $host ] = true;
			}
		}
		$from = '' === $referer ? '' : self::host( str_contains( $referer, '://' ) ? $referer : '' );
		if ( '' !== $from && isset( $known[ $from ] ) ) {
			return $from;
		}
		$source = self::host( $utm_source );
		if ( self::UTM_MEDIUM === strtolower( trim( $utm_medium ) ) && '' !== $source && isset( $known[ $source ] ) ) {
			return $source;
		}
		return null;
	}
}
