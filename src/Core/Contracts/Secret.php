<?php
/**
 * Keyed hashing port.
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\Core\Contracts;

/**
 * Keyed hashing with a site-specific secret (used instead of storing personal data).
 */
interface Secret {

	/**
	 * Hex HMAC-SHA256 of `$data` with the site secret.
	 *
	 * @param string $data Data.
	 */
	public function hmac( string $data ): string;
}
