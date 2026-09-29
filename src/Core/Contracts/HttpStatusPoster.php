<?php
/**
 * Outgoing HTTP POST port that reports the status code.
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\Core\Contracts;

/**
 * Sends a JSON body and returns the HTTP status (1.10.0; IndexNow needs to tell 403 from 429).
 * Called only by IndexNowService (guarded by TelemetrySendsOnlyWithConsentTest and
 * IndexNowSendsOnlyPublicUrlsTest).
 */
interface HttpStatusPoster {

	/**
	 * Posts a JSON body; the HTTP status code, or 0 when there was no answer. Never throws.
	 *
	 * @param string $url  URL (https).
	 * @param string $json JSON body.
	 */
	public function post_json( string $url, string $json ): int;
}
