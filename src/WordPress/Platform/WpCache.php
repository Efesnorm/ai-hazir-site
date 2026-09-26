<?php
/**
 * Cache backed by WordPress transients.
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\WordPress\Platform;

use AIHazirSite\Core\Contracts\Cache;

/**
 * WordPress transients (object cache when available).
 */
final class WpCache implements Cache {

	/**
	 * Cached value, or null.
	 *
	 * @param string $key Transient name.
	 */
	public function get( string $key ): mixed {
		$value = get_transient( $key );
		return false === $value ? null : $value;
	}

	/**
	 * Stores a value.
	 *
	 * @param string $key   Transient name.
	 * @param mixed  $value Value.
	 * @param int    $ttl   Lifetime in seconds.
	 */
	public function set( string $key, mixed $value, int $ttl ): void {
		set_transient( $key, $value, $ttl );
	}
}
