<?php
/**
 * The update server's manifest.
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\Core\Updates;

/**
 * Parses the manifest JSON: {"slug": "...", "releases": [{version, download_url, released_at,
 * db_version, requires, requires_php, tested, sections}, ...]}. Invalid releases are skipped;
 * a package must be served over https.
 */
final class ReleaseManifest {

	/**
	 * Releases, newest first.
	 *
	 * @param string $json Manifest body.
	 * @param string $slug Expected plugin slug.
	 * @return list<Release>
	 */
	public static function parse( string $json, string $slug ): array {
		$data = json_decode( $json, true );
		if ( ! is_array( $data ) || ( $data['slug'] ?? null ) !== $slug || ! is_array( $data['releases'] ?? null ) ) {
			return array();
		}
		$releases = array();
		foreach ( $data['releases'] as $item ) {
			$release = self::release( $item );
			if ( null !== $release ) {
				$releases[] = $release;
			}
		}
		usort( $releases, static fn( Release $a, Release $b ): int => version_compare( $b->version, $a->version ) );
		return $releases;
	}

	/**
	 * One release, or null when a required field is missing or invalid.
	 *
	 * @param mixed $item Raw item.
	 */
	private static function release( mixed $item ): ?Release {
		if ( ! is_array( $item ) ) {
			return null;
		}
		$text     = static fn( string $k ): string => isset( $item[ $k ] ) && is_scalar( $item[ $k ] ) ? trim( (string) $item[ $k ] ) : '';
		$version  = $text( 'version' );
		$url      = $text( 'download_url' );
		$released = strtotime( $text( 'released_at' ) );
		if ( 1 !== preg_match( '/^\d+\.\d+\.\d+$/', $version ) || ! str_starts_with( $url, 'https://' ) || false === $released || ! is_numeric( $item['db_version'] ?? null ) ) {
			return null;
		}
		$sections = array();
		foreach ( is_array( $item['sections'] ?? null ) ? $item['sections'] : array() as $name => $html ) {
			if ( is_string( $name ) && is_string( $html ) ) {
				$sections[ $name ] = $html;
			}
		}
		return new Release( $version, $url, gmdate( 'Y-m-d\TH:i:s\Z', $released ), (int) $item['db_version'], $text( 'requires' ), $text( 'requires_php' ), $text( 'tested' ), $sections );
	}
}
