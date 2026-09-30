<?php
/**
 * Counts MCP tool calls.
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\Core\Measurement;

use AIHazirSite\Core\Contracts\Clock;
use AIHazirSite\Core\Contracts\HitRepository;
use AIHazirSite\Core\Features;

/**
 * One `kind = mcp` counter per day, tool and endpoint. Nothing about the client is stored
 * (no IP, no user agent, no arguments). Counted only while measurement is on.
 */
final class McpCalls {

	/**
	 * Constructor.
	 *
	 * @param HitRepository $hits  Counter storage.
	 * @param Clock         $clock Today.
	 */
	public function __construct(
		private readonly HitRepository $hits,
		private readonly Clock $clock
	) {
	}

	/**
	 * Counts one tool call.
	 *
	 * @param string $tool     MCP tool name.
	 * @param string $endpoint   Endpoint path (e.g. /wp-json/aihs/mcp).
	 * @param string $user_agent Client User-Agent (1.17.0: test calls are counted apart).
	 * @return bool True when counted.
	 */
	public function count( string $tool, string $endpoint, string $user_agent = '' ): bool {
		if ( ! Features::is_enabled( Features::MEASUREMENT ) ) {
			return false;
		}
		$tool = substr( (string) preg_replace( '/[^a-zA-Z0-9_.-]/', '', $tool ), 0, 64 );
		if ( '' === $tool ) {
			return false;
		}
		$kind = Features::is_enabled( Features::MEASUREMENT_TEST_FILTER ) && TestTraffic::is_test( $user_agent ) ? Hit::KIND_TEST : Hit::KIND_MCP;
		return $this->hits->increment( $this->clock->today(), $kind, $tool, Hit::normalize_path( $endpoint ), false );
	}
}
