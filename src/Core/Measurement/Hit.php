<?php
/**
 * Hit kinds and path normalization.
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\Core\Measurement;

/**
 * Shared vocabulary of the measurement data.
 */
final class Hit {

	public const KIND_BOT      = 'bot';
	public const KIND_REFERRAL = 'referral';
	public const KIND_MCP      = 'mcp';
	public const KIND_TEST     = 'test';
	public const KIND_NETWORK  = 'network';
	public const PATH_MAX      = 191;

	/**
	 * Path without query string or fragment, starting with "/", no control
	 * characters, at most 191 characters. Invalid UTF-8 counts as "/".
	 *
	 * @param string $path Raw path or request URI.
	 */
	public static function normalize_path( string $path ): string {
		if ( ! mb_check_encoding( $path, 'UTF-8' ) ) {
			$path = '';
		}
		$path = (string) preg_replace( '/[?#].*$/s', '', $path );
		$path = (string) preg_replace( '/[\x00-\x1F\x7F]/', '', $path );
		if ( '' === $path || '/' !== $path[0] ) {
			$path = '/' . $path;
		}
		return mb_substr( $path, 0, self::PATH_MAX );
	}
}
