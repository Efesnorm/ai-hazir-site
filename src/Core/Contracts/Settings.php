<?php
/**
 * Persistent key–value settings port.
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\Core\Contracts;

/**
 * Small persistent settings (WordPress: options).
 */
interface Settings {

	/**
	 * Stored value, or `$default_value` when missing.
	 *
	 * @param string $key           Key.
	 * @param mixed  $default_value Fallback.
	 */
	public function get( string $key, mixed $default_value = null ): mixed;

	/**
	 * Stores a value.
	 *
	 * @param string $key      Key.
	 * @param mixed  $value    Value.
	 * @param bool   $autoload Load on every request (small values read on every request).
	 */
	public function set( string $key, mixed $value, bool $autoload = false ): void;

	/**
	 * Removes a value.
	 *
	 * @param string $key Key.
	 */
	public function delete( string $key ): void;
}
