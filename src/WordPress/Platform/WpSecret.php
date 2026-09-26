<?php
/**
 * Secret backed by the WordPress salts.
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\WordPress\Platform;

use AIHazirSite\Core\Contracts\Secret;

/**
 * HMAC with the site's AUTH salt.
 */
final class WpSecret implements Secret {

	/**
	 * Hex HMAC-SHA256.
	 *
	 * @param string $data Data.
	 */
	public function hmac( string $data ): string {
		return hash_hmac( 'sha256', $data, wp_salt( 'auth' ) );
	}
}
