<?php
/**
 * Marker that identifies the site's own compliance scan requests.
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\Core\Compliance;

use AIHazirSite\Core\Contracts\Secret;

/**
 * The scanner sends {@see Site::SCAN_HEADER} with this value; the measurement
 * listener ignores such requests so a scan never counts as an AI bot visit.
 * The value is keyed with the site secret, so outsiders cannot forge it.
 */
final class ScanToken {

	/**
	 * Header value for this site.
	 *
	 * @param Secret $secret Site secret.
	 */
	public static function value( Secret $secret ): string {
		return $secret->hmac( 'aihs-compliance-scan' );
	}

	/**
	 * Whether a received header value is this site's token.
	 *
	 * @param Secret $secret Site secret.
	 * @param string $value  Received value.
	 */
	public static function matches( Secret $secret, string $value ): bool {
		return '' !== $value && hash_equals( self::value( $secret ), $value );
	}

	/**
	 * Headers to send with every scan request.
	 *
	 * @param Secret $secret Site secret.
	 * @return array<string, string>
	 */
	public static function headers( Secret $secret ): array {
		return array( Site::SCAN_HEADER => self::value( $secret ) );
	}
}
