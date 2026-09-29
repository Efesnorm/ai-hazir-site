<?php
/**
 * Clock for bin/tara.
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

// phpcs:disable WordPress.Files.FileName, WordPress.WP.AlternativeFunctions -- Standalone CLI tool (bin/tara) outside WordPress; curl is its HTTP client.

namespace AIHazirSite\Tools\Tara;

use AIHazirSite\Core\Contracts\Clock;

/**
 * UTC system time.
 */
final class SystemClock implements Clock {

	/**
	 * Today (UTC).
	 */
	public function today(): string {
		return gmdate( 'Y-m-d' );
	}

	/**
	 * Unix time.
	 */
	public function now(): int {
		return time();
	}
}
