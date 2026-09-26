<?php
/**
 * In-memory Settings for tests.
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\Tests\Support;

use AIHazirSite\Core\Contracts\Settings;

/**
 * Array-backed settings; `$autoload` flags are recorded for assertions.
 */
final class MemorySettings implements Settings {

	/**
	 * Values.
	 *
	 * @var array<string, mixed>
	 */
	public array $values = array();

	/**
	 * Autoload flags.
	 *
	 * @var array<string, bool>
	 */
	public array $autoload = array();

	/**
	 * Stored value or default.
	 *
	 * @param string $key           Key.
	 * @param mixed  $default_value Fallback.
	 */
	public function get( string $key, mixed $default_value = null ): mixed {
		return array_key_exists( $key, $this->values ) ? $this->values[ $key ] : $default_value;
	}

	/**
	 * Stores a value.
	 *
	 * @param string $key      Key.
	 * @param mixed  $value    Value.
	 * @param bool   $autoload Autoload flag.
	 */
	public function set( string $key, mixed $value, bool $autoload = false ): void {
		$this->values[ $key ]   = $value;
		$this->autoload[ $key ] = $autoload;
	}

	/**
	 * Removes a value.
	 *
	 * @param string $key Key.
	 */
	public function delete( string $key ): void {
		unset( $this->values[ $key ], $this->autoload[ $key ] );
	}
}
