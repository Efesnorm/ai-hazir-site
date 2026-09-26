<?php
/**
 * Clock in the WordPress site timezone.
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\WordPress\Platform;

use AIHazirSite\Core\Contracts\Clock;

/**
 * Site timezone from Settings → General.
 */
final class WpClock implements Clock {

	/**
	 * Today in the site timezone.
	 */
	public function today(): string {
		return current_time( 'Y-m-d' );
	}

	/**
	 * Unix timestamp.
	 */
	public function now(): int {
		return time();
	}
}
