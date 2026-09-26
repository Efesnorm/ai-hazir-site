<?php
/**
 * In-memory Cache for tests.
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\Tests\Support;

use AIHazirSite\Core\Contracts\Cache;

/**
 * Array-backed cache (ttl recorded, not enforced).
 */
final class MemoryCache implements Cache {

	/**
	 * Values.
	 *
	 * @var array<string, mixed>
	 */
	public array $values = array();

	/**
	 * Lifetimes.
	 *
	 * @var array<string, int>
	 */
	public array $ttl = array();

	/**
	 * Cached value or null.
	 *
	 * @param string $key Key.
	 */
	public function get( string $key ): mixed {
		return $this->values[ $key ] ?? null;
	}

	/**
	 * Stores a value.
	 *
	 * @param string $key   Key.
	 * @param mixed  $value Value.
	 * @param int    $ttl   Lifetime.
	 */
	public function set( string $key, mixed $value, int $ttl ): void {
		$this->values[ $key ] = $value;
		$this->ttl[ $key ]    = $ttl;
	}
}
