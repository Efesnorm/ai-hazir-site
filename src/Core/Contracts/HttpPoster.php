<?php
/**
 * Outgoing HTTP POST port.
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\Core\Contracts;

/**
 * Sends a JSON body. Called only by TelemetryService (guarded by TelemetrySendsOnlyWithConsentTest).
 */
interface HttpPoster {

	/**
	 * Posts a JSON body; true on a 2xx answer. Never throws.
	 *
	 * @param string $url  URL (https).
	 * @param string $json JSON body.
	 */
	public function post( string $url, string $json ): bool;
}
