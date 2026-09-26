<?php
/**
 * Static Secret for tests.
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\Tests\Support;

use AIHazirSite\Core\Contracts\Secret;

/**
 * HMAC with a fixed test key.
 */
final class StaticSecret implements Secret {

	/**
	 * Hex HMAC-SHA256.
	 *
	 * @param string $data Data.
	 */
	public function hmac( string $data ): string {
		return hash_hmac( 'sha256', $data, 'test-secret' );
	}
}
