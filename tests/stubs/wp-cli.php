<?php
/**
 * Minimal WP-CLI stubs for PHPStan (only what the plugin uses).
 *
 * php-stubs/wp-cli-stubs conflicts with the WordPress 7 stubs, so the few
 * signatures we need are declared here. Never loaded at runtime.
 *
 * @package AIHazirSite
 */

// phpcs:ignoreFile -- Stub file for static analysis only.

namespace {
	class WP_CLI {
		/**
		 * @param string          $name     Command name.
		 * @param callable|string $callable Command class or callable.
		 * @param array<string, mixed> $args Registration arguments.
		 */
		public static function add_command( $name, $callable, $args = array() ): bool {}

		/**
		 * @param string $message Message.
		 */
		public static function log( $message ): void {}

		/**
		 * @param string|\WP_Error|\Exception|\Throwable $message Message.
		 * @param bool|int                               $exit    Exit code.
		 * @return never
		 */
		public static function error( $message, $exit = true ) {}
	}
}

namespace WP_CLI\Utils {
	/**
	 * @param string                    $format Output format.
	 * @param array<array<string, mixed>> $items  Rows.
	 * @param string|string[]           $fields Fields.
	 */
	function format_items( $format, $items, $fields ): void {}
}
