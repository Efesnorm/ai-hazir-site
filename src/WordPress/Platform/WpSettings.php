<?php
/**
 * Settings backed by WordPress options.
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\WordPress\Platform;

use AIHazirSite\Core\Contracts\Settings;

/**
 * WordPress options.
 */
final class WpSettings implements Settings {

	/**
	 * Stored value, or `$default_value` when missing.
	 *
	 * @param string $key           Option name.
	 * @param mixed  $default_value Fallback.
	 */
	public function get( string $key, mixed $default_value = null ): mixed {
		return get_option( $key, $default_value );
	}

	/**
	 * Stores a value.
	 *
	 * @param string $key      Option name.
	 * @param mixed  $value    Value.
	 * @param bool   $autoload Autoload flag.
	 */
	public function set( string $key, mixed $value, bool $autoload = false ): void {
		update_option( $key, $value, $autoload );
	}

	/**
	 * Removes a value.
	 *
	 * @param string $key Option name.
	 */
	public function delete( string $key ): void {
		delete_option( $key );
	}
}
