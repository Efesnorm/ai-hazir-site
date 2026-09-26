<?php
/**
 * Scripted HttpClient for tests.
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\Tests\Support;

use AIHazirSite\Core\Contracts\HttpClient;

/**
 * Returns bodies from a map (missing URL = failure) and records requests.
 */
final class FakeHttpClient implements HttpClient {

	/**
	 * Requested URLs.
	 *
	 * @var list<string>
	 */
	public array $requests = array();

	/**
	 * Constructor.
	 *
	 * @param array<string, string> $bodies URL → body.
	 */
	public function __construct( public array $bodies = array() ) {
	}

	/**
	 * Body or null.
	 *
	 * @param string $url URL.
	 */
	public function get( string $url ): ?string {
		$this->requests[] = $url;
		return $this->bodies[ $url ] ?? null;
	}
}
