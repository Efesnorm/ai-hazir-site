<?php
/**
 * Platform-neutral view of an incoming request.
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\Core\Measurement;

/**
 * The few request fields measurement needs. Built by the platform adapter,
 * which also decides whether the request should be counted at all.
 *
 * Nothing here is stored as-is: only the matched bot/referrer id and the
 * normalized path end up in the counters.
 */
final class Request {

	/**
	 * Constructor.
	 *
	 * @param string $user_agent User-Agent header.
	 * @param string $uri        Request URI (path and query).
	 * @param string $ip         Client IP ('' when unknown or invalid).
	 * @param string $referer    Referer header.
	 * @param string $utm_source Value of the utm_source query parameter.
	 */
	public function __construct(
		public readonly string $user_agent = '',
		public readonly string $uri = '/',
		public readonly string $ip = '',
		public readonly string $referer = '',
		public readonly string $utm_source = ''
	) {
	}
}
