<?php
/**
 * Minimum PHP and WordPress version check.
 *
 * IMPORTANT: This file is loaded before the Composer autoloader and must run on
 * unsupported PHP versions too. Keep it free of PHP 7.4+ syntax (typed properties,
 * union types, enums, readonly, named arguments, match, etc.).
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\Core;

/**
 * Checks the runtime environment and shows an admin notice when it is not supported.
 */
final class Requirements {

	/**
	 * Minimum supported PHP version.
	 */
	public const MIN_PHP = '8.1';

	/**
	 * Minimum supported WordPress version.
	 */
	public const MIN_WP = '6.9';

	/**
	 * Detected PHP version.
	 *
	 * @var string
	 */
	private $php_version;

	/**
	 * Detected WordPress version.
	 *
	 * @var string
	 */
	private $wp_version;

	/**
	 * Constructor.
	 *
	 * @param string $php_version Detected PHP version.
	 * @param string $wp_version  Detected WordPress version.
	 */
	public function __construct( string $php_version, string $wp_version ) {
		$this->php_version = $php_version;
		$this->wp_version  = $wp_version;
	}

	/**
	 * Whether the PHP version is supported.
	 */
	public function is_php_supported(): bool {
		return version_compare( $this->php_version, self::MIN_PHP, '>=' );
	}

	/**
	 * Whether the WordPress version is supported.
	 */
	public function is_wp_supported(): bool {
		return version_compare( $this->wp_version, self::MIN_WP, '>=' );
	}

	/**
	 * Whether all requirements are met.
	 */
	public function are_met(): bool {
		return $this->is_php_supported() && $this->is_wp_supported();
	}

	/**
	 * Runs the boot callback when requirements are met; otherwise registers an
	 * admin notice and does NOT run the callback.
	 *
	 * @param callable $boot Callback that starts the plugin.
	 * @return bool True when the plugin was booted.
	 */
	public function run( callable $boot ): bool {
		if ( ! $this->are_met() ) {
			add_action( 'admin_notices', array( $this, 'render_notice' ) );
			return false;
		}

		$boot();
		return true;
	}

	/**
	 * Human-readable error messages.
	 *
	 * Translation functions are called here (not in the constructor) so that they
	 * only run on `admin_notices`, after text domains may be loaded.
	 *
	 * @return string[]
	 */
	public function errors(): array {
		$errors = array();

		if ( ! $this->is_php_supported() ) {
			$errors[] = sprintf(
				/* translators: 1: required PHP version, 2: current PHP version. */
				__( 'AI Hazır Site en az PHP %1$s gerektirir. Sunucunuzdaki sürüm: %2$s.', 'ai-hazir-site' ),
				self::MIN_PHP,
				$this->php_version
			);
		}

		if ( ! $this->is_wp_supported() ) {
			$errors[] = sprintf(
				/* translators: 1: required WordPress version, 2: current WordPress version. */
				__( 'AI Hazır Site en az WordPress %1$s gerektirir. Sitenizdeki sürüm: %2$s.', 'ai-hazir-site' ),
				self::MIN_WP,
				$this->wp_version
			);
		}

		return $errors;
	}

	/**
	 * Prints the admin notice listing unmet requirements.
	 */
	public function render_notice(): void {
		$errors = $this->errors();
		if ( array() === $errors ) {
			return;
		}

		echo '<div class="notice notice-error"><p><strong>'
			. esc_html__( 'AI Hazır Site çalışmıyor.', 'ai-hazir-site' )
			. '</strong></p>';
		foreach ( $errors as $error ) {
			echo '<p>' . esc_html( $error ) . '</p>';
		}
		echo '</div>';
	}
}
