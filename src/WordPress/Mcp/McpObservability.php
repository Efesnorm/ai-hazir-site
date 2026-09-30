<?php
/**
 * Counts our MCP server's tool calls in the A0 measurement.
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\WordPress\Mcp;

use AIHazirSite\Core\Measurement\McpCalls;
use AIHazirSite\WordPress\Platform\WpClock;
use AIHazirSite\WordPress\Storage\WpdbHitRepository;
use WP\MCP\Infrastructure\Observability\Contracts\McpObservabilityHandlerInterface;

/**
 * The MCP Adapter reports every request as an `mcp.request` event (tags: method, tool_name,
 * status …). A `tools/call` of our server becomes one `kind = mcp` counter; nothing else is kept.
 */
final class McpObservability implements McpObservabilityHandlerInterface {

	/**
	 * Records an event.
	 *
	 * @param string       $event       Event name.
	 * @param array<mixed> $tags        Tags.
	 * @param float|null   $duration_ms Duration.
	 */
	public function record_event( string $event, array $tags = array(), ?float $duration_ms = null ): void {
		if ( 'mcp.request' !== $event || 'tools/call' !== ( $tags['method'] ?? null ) || ! is_string( $tags['tool_name'] ?? null ) ) {
			return;
		}
		$user_agent = isset( $_SERVER['HTTP_USER_AGENT'] ) && is_string( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotValidated -- Checked with isset() and is_string().
		( new McpCalls( new WpdbHitRepository(), new WpClock() ) )->count( $tags['tool_name'], McpModule::path(), $user_agent );
	}
}
