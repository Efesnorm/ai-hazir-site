<?php
/**
 * PageFetcher with PHP's curl extension (for bin/tara, outside WordPress).
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

// phpcs:disable WordPress.Files.FileName, WordPress.WP.AlternativeFunctions -- Standalone CLI tool (bin/tara) outside WordPress; curl is its HTTP client.

namespace AIHazirSite\Tools\Tara;

use AIHazirSite\Core\Contracts\PageFetcher;
use AIHazirSite\Core\Contracts\PageResponse;

/**
 * The same contract the plugin's scan uses (WpPageFetcher): GET, up to 3 redirects, bodies capped at 2 MB,
 * header names lower-cased. TLS certificates are verified.
 */
final class CurlPageFetcher implements PageFetcher {

	public const MAX_BYTES = 2097152;

	/**
	 * Fetches a URL.
	 *
	 * @param string                $url     URL.
	 * @param array<string, string> $headers Request headers.
	 * @param int                   $timeout Timeout in seconds.
	 */
	public function fetch( string $url, array $headers = array(), int $timeout = 5 ): PageResponse {
		$start = hrtime( true );
		$out   = array();
		$body  = '';
		$lines = array();
		foreach ( $headers + array( 'User-Agent' => self::user_agent() ) as $name => $value ) {
			$lines[] = $name . ': ' . $value;
		}

		$handle = curl_init( $url );
		curl_setopt_array(
			$handle,
			array(
				CURLOPT_FOLLOWLOCATION => true,
				CURLOPT_MAXREDIRS      => 3,
				CURLOPT_TIMEOUT        => max( 1, $timeout ),
				CURLOPT_CONNECTTIMEOUT => max( 1, $timeout ),
				CURLOPT_HTTPHEADER     => $lines,
				CURLOPT_ENCODING       => '',
				CURLOPT_HEADERFUNCTION => static function ( $h, string $line ) use ( &$out ): int {
					if ( preg_match( '#^HTTP/\S+\s+\d+#', $line ) ) {
						$out = array(); // A redirect: keep only the final response's headers.
					} elseif ( str_contains( $line, ':' ) ) {
						[ $name, $value ]         = explode( ':', $line, 2 );
						$name                     = strtolower( trim( $name ) );
						$out[ $name ]             = isset( $out[ $name ] ) ? $out[ $name ] . ', ' . trim( $value ) : trim( $value );
					}
					return strlen( $line );
				},
				CURLOPT_WRITEFUNCTION  => static function ( $h, string $chunk ) use ( &$body ): int {
					$body .= $chunk;
					return strlen( $body ) > self::MAX_BYTES ? 0 : strlen( $chunk );
				},
			)
		);
		$done    = curl_exec( $handle );
		$status  = (int) curl_getinfo( $handle, CURLINFO_RESPONSE_CODE );
		$error   = curl_error( $handle );
		$elapsed = (int) ( ( hrtime( true ) - $start ) / 1e6 );
		curl_close( $handle );

		if ( false === $done && 0 === $status ) {
			return PageResponse::failed( '' === $error ? 'İstek tamamlanamadı.' : $error, $elapsed );
		}
		return new PageResponse( $status, $out, substr( $body, 0, self::MAX_BYTES ), $elapsed );
	}

	/**
	 * Same identification as the plugin's scan.
	 */
	public static function user_agent(): string {
		return 'AI Hazir Site/tara (uyum taramasi)';
	}
}
