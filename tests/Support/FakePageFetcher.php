<?php
/**
 * Scripted PageFetcher for tests.
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\Tests\Support;

use AIHazirSite\Core\Contracts\PageFetcher;
use AIHazirSite\Core\Contracts\PageResponse;

/**
 * Serves responses from a URL map; unknown URLs return 404. Records every request.
 */
final class FakePageFetcher implements PageFetcher {

	/**
	 * Requests made: url + headers.
	 *
	 * @var list<array{url: string, headers: array<string, string>, timeout: int}>
	 */
	public array $requests = array();

	/**
	 * Constructor.
	 *
	 * @param array<string, PageResponse|string> $responses URL → response (a string is a 200 HTML body).
	 * @param int                                $elapsed   Milliseconds each request "takes".
	 */
	public function __construct( public array $responses = array(), public int $elapsed = 50 ) {
	}

	/**
	 * Adds or replaces a response.
	 *
	 * @param string              $url      URL.
	 * @param PageResponse|string $response Response or 200 body.
	 */
	public function on( string $url, PageResponse|string $response ): self {
		$this->responses[ $url ] = $response;
		return $this;
	}

	/**
	 * Scripted response.
	 *
	 * @param string                $url     URL.
	 * @param array<string, string> $headers Headers.
	 * @param int                   $timeout Timeout.
	 */
	public function fetch( string $url, array $headers = array(), int $timeout = 5 ): PageResponse {
		$this->requests[] = array(
			'url'     => $url,
			'headers' => $headers,
			'timeout' => $timeout,
		);

		$response = $this->responses[ $url ] ?? new PageResponse( 404, array(), 'Not found' );
		if ( is_string( $response ) ) {
			$response = new PageResponse( 200, array( 'content-type' => 'text/html; charset=utf-8' ), $response );
		}

		return new PageResponse( $response->status, $response->headers, $response->body, max( $response->elapsed_ms, $this->elapsed ), $response->error );
	}

	/**
	 * URLs requested, in order.
	 *
	 * @return list<string>
	 */
	public function urls(): array {
		return array_column( $this->requests, 'url' );
	}
}
