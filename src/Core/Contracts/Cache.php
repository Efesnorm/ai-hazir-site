<?php
/**
 * Expiring cache port.
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\Core\Contracts;

/**
 * Expiring cache (WordPress: transients).
 */
interface Cache {

	/**
	 * Cached value, or null when missing or expired.
	 *
	 * @param string $key Key.
	 */
	public function get( string $key ): mixed;

	/**
	 * Stores a non-null value for `$ttl` seconds.
	 *
	 * @param string $key   Key.
	 * @param mixed  $value Value.
	 * @param int    $ttl   Lifetime in seconds.
	 */
	public function set( string $key, mixed $value, int $ttl ): void;
}
