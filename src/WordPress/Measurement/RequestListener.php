<?php
/**
 * Feeds WordPress requests to the core tracker.
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\WordPress\Measurement;

use AIHazirSite\Core\Compliance\ScanToken;
use AIHazirSite\Core\Measurement\Request;
use AIHazirSite\Core\Measurement\Tracker;
use AIHazirSite\WordPress\Platform\WpSecret;

/**
 * Hooked on `parse_request` (front-end, REST and robots.txt all pass through it)
 * and `shutdown`. Admin, cron and AJAX requests, and the site's own compliance
 * scan requests (signed X-AIHS-Scan header), are never counted.
 */
final class RequestListener {

	/**
	 * Constructor.
	 *
	 * @param Tracker $tracker Core tracker.
	 */
	public function __construct( private readonly Tracker $tracker ) {
	}

	/**
	 * `parse_request` callback.
	 */
	public function on_parse_request(): void {
		if ( ! self::is_countable() || self::is_scan_request( $_SERVER ) ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only analytics; utm_source / utm_medium are only compared, never stored.
		$this->tracker->capture( self::request_from( $_SERVER, $_GET ) );
	}

	/**
	 * `shutdown` callback.
	 */
	public function on_shutdown(): void {
		$this->tracker->flush();
	}

	/**
	 * Whether the current WordPress request may be counted.
	 */
	public static function is_countable(): bool {
		return ! is_admin() && ! wp_doing_cron() && ! wp_doing_ajax();
	}

	/**
	 * Whether the request is this site's own compliance scan.
	 *
	 * @param array<mixed> $server $_SERVER-like array.
	 */
	public static function is_scan_request( array $server ): bool {
		return ScanToken::matches( new WpSecret(), self::field( $server, 'HTTP_X_AIHS_SCAN' ) );
	}

	/**
	 * Core request from $_SERVER / $_GET-like arrays (WordPress slashes them).
	 *
	 * @param array<mixed> $server $_SERVER-like array.
	 * @param array<mixed> $query  $_GET-like array.
	 */
	public static function request_from( array $server, array $query = array() ): Request {
		$ip = self::field( $server, 'REMOTE_ADDR' );

		return new Request(
			self::field( $server, 'HTTP_USER_AGENT' ),
			self::field( $server, 'REQUEST_URI' ),
			false === filter_var( $ip, FILTER_VALIDATE_IP ) ? '' : $ip,
			self::field( $server, 'HTTP_REFERER' ),
			sanitize_text_field( self::field( $query, 'utm_source' ) ),
			sanitize_text_field( self::field( $query, 'utm_medium' ) )
		);
	}

	/**
	 * Unslashed string field.
	 *
	 * @param array<mixed> $data Array.
	 * @param string       $key  Key.
	 */
	private static function field( array $data, string $key ): string {
		return isset( $data[ $key ] ) && is_string( $data[ $key ] ) ? (string) wp_unslash( $data[ $key ] ) : '';
	}
}
