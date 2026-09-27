<?php
/**
 * Result of fetching a URL.
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\Core\Contracts;

/**
 * Status, headers and body of one HTTP response. Status 0 means the request
 * did not complete (network error, timeout or skipped because of the time budget).
 */
final class PageResponse {

	/**
	 * Constructor.
	 *
	 * @param int                   $status     HTTP status, 0 when no response.
	 * @param array<string, string> $headers    Headers with lower-case names; repeated headers joined by ", ".
	 * @param string                $body       Response body.
	 * @param int                   $elapsed_ms Time spent, in milliseconds.
	 * @param string                $error      Error message when status is 0.
	 */
	public function __construct(
		public readonly int $status,
		public readonly array $headers = array(),
		public readonly string $body = '',
		public readonly int $elapsed_ms = 0,
		public readonly string $error = ''
	) {
	}

	/**
	 * A request that failed before any response.
	 *
	 * @param string $error      Error message.
	 * @param int    $elapsed_ms Time spent.
	 */
	public static function failed( string $error, int $elapsed_ms = 0 ): self {
		return new self( 0, array(), '', $elapsed_ms, $error );
	}

	/**
	 * Whether a response arrived (any status).
	 */
	public function reached(): bool {
		return $this->status > 0;
	}

	/**
	 * Whether the status is 2xx.
	 */
	public function ok(): bool {
		return $this->status >= 200 && $this->status < 300;
	}

	/**
	 * Header value ('' when absent).
	 *
	 * @param string $name Header name (any case).
	 */
	public function header( string $name ): string {
		return $this->headers[ strtolower( $name ) ] ?? '';
	}
}
