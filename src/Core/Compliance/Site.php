<?php
/**
 * The site being scanned.
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\Core\Compliance;

use AIHazirSite\Core\Contracts\PageFetcher;
use AIHazirSite\Core\Contracts\PageResponse;

/**
 * Fetches the site's own URLs for the checks: every URL at most once per header set,
 * at most 5 s per request and 60 s in total. A request is only started when it can
 * finish within the budget even at its full timeout; otherwise it is not made and
 * returns status 0 ("skipped").
 */
final class Site {

	public const MAX_PAGES     = 10;
	public const TIMEOUT       = 5;
	public const BUDGET_MS     = 60000;
	public const SCAN_HEADER   = 'X-AIHS-Scan';
	public const SKIPPED_ERROR = 'Zaman bütçesi doldu; istek yapılmadı.';

	/**
	 * Responses by cache key.
	 *
	 * @var array<string, PageResponse>
	 */
	private array $cache = array();

	/**
	 * Milliseconds spent so far.
	 *
	 * @var int
	 */
	private int $elapsed_ms = 0;

	/**
	 * Sample page URLs, computed on first use.
	 *
	 * @var list<string>|null
	 */
	private ?array $pages = null;

	/**
	 * Base URL: scheme://host[:port]/path/.
	 *
	 * @var string
	 */
	private string $base;

	/**
	 * Constructor.
	 *
	 * @param string                $base_url      Site address.
	 * @param PageFetcher           $fetcher       HTTP access.
	 * @param array<string, string> $headers       Headers sent with every request (e.g. the scan marker).
	 * @param string[]              $alias_hosts   Other host names (host:port) that mean this site.
	 *
	 * @phpstan-param list<string> $alias_hosts
	 */
	public function __construct(
		string $base_url,
		private readonly PageFetcher $fetcher,
		private readonly array $headers = array(),
		private readonly array $alias_hosts = array()
	) {
		$this->base = rtrim( $base_url, '/' ) . '/';
	}

	/**
	 * Base URL with a trailing slash.
	 */
	public function base_url(): string {
		return $this->base;
	}

	/**
	 * Absolute URL for a path relative to the base, or the same-site absolute URL.
	 *
	 * @param string $path Path or URL.
	 */
	public function url( string $path ): string {
		if ( preg_match( '#^https?://#i', $path ) ) {
			return $path;
		}
		return $this->base . ltrim( $path, '/' );
	}

	/**
	 * Fetches a URL of the site (cached, within the time budget).
	 *
	 * @param string                $path_or_url Path or absolute URL.
	 * @param array<string, string> $headers     Extra headers.
	 */
	public function fetch( string $path_or_url, array $headers = array() ): PageResponse {
		$url     = $this->url( $path_or_url );
		$headers = array_merge( $this->headers, $headers );
		$key     = $url . "\n" . serialize( $headers ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- Cache key only.

		if ( isset( $this->cache[ $key ] ) ) {
			return $this->cache[ $key ];
		}
		if ( $this->budget_exhausted() ) {
			return PageResponse::failed( self::SKIPPED_ERROR );
		}

		$response            = $this->fetcher->fetch( $url, $headers, self::TIMEOUT );
		$this->elapsed_ms   += max( 0, $response->elapsed_ms );
		$this->cache[ $key ] = $response;

		return $response;
	}

	/**
	 * Sample pages with their (normal) responses.
	 *
	 * @return list<array{url: string, response: PageResponse}>
	 */
	public function page_responses(): array {
		$list = array();
		foreach ( $this->pages() as $url ) {
			$list[] = array(
				'url'      => $url,
				'response' => $this->fetch( $url ),
			);
		}
		return $list;
	}

	/**
	 * Home page response.
	 */
	public function home(): PageResponse {
		return $this->fetch( $this->base );
	}

	/**
	 * Parsed robots.txt.
	 */
	public function robots(): Robots {
		return Robots::from_response( $this->fetch( 'robots.txt' ) );
	}

	/**
	 * Whether the 60-second budget is used up.
	 */
	public function budget_exhausted(): bool {
		return $this->elapsed_ms + self::TIMEOUT * 1000 > self::BUDGET_MS;
	}

	/**
	 * Milliseconds spent on requests.
	 */
	public function elapsed_ms(): int {
		return $this->elapsed_ms;
	}

	/**
	 * Sample pages: home, then links in the main navigation, then sitemap entries.
	 * Same-site URLs only, without duplicates, at most MAX_PAGES.
	 *
	 * @return list<string>
	 */
	public function pages(): array {
		if ( null !== $this->pages ) {
			return $this->pages;
		}

		$pages = array( $this->base );
		$home  = $this->home();
		if ( $home->ok() ) {
			foreach ( ( new Html( $home->body ) )->nav_links() as $href ) {
				$this->add_page( $pages, $href );
			}
		}
		foreach ( $this->sitemap_urls() as $loc ) {
			$this->add_page( $pages, $loc );
		}

		$this->pages = $pages;
		return $pages;
	}

	/**
	 * Adds a same-site page URL (normalized, no fragment, no duplicates) while room is left.
	 *
	 * @param string[] $pages Pages.
	 * @param string   $href  Link.
	 *
	 * @phpstan-param list<string> $pages
	 */
	private function add_page( array &$pages, string $href ): void {
		if ( count( $pages ) >= self::MAX_PAGES ) {
			return;
		}
		$url = $this->same_site( $href );
		if ( null !== $url && ! in_array( $url, $pages, true ) ) {
			$pages[] = $url;
		}
	}

	/**
	 * Same-site absolute URL for a link, or null for other sites, anchors and non-HTTP links.
	 *
	 * @param string $href Link.
	 */
	public function same_site( string $href ): ?string {
		$href = trim( (string) preg_replace( '/#.*$/', '', trim( $href ) ) );
		if ( '' === $href || preg_match( '#^(mailto|tel|javascript|data):#i', $href ) ) {
			return null;
		}
		if ( str_starts_with( $href, '//' ) ) {
			$href = (string) parse_url( $this->base, PHP_URL_SCHEME ) . ':' . $href; // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- Platform-neutral core.
		}
		if ( ! preg_match( '#^https?://#i', $href ) ) {
			$origin = (string) preg_replace( '#^(https?://[^/]+).*$#i', '$1', $this->base );
			return str_starts_with( $href, '/' ) ? $origin . $href : $this->base . $href;
		}

		$host = self::host_port( $href );
		if ( self::host_port( $this->base ) === $host ) {
			return $href;
		}
		if ( in_array( $host, $this->alias_hosts, true ) ) {
			$origin = (string) preg_replace( '#^(https?://[^/]+).*$#i', '$1', $this->base );
			return $origin . (string) preg_replace( '#^https?://[^/]+#i', '', $href );
		}
		return null;
	}

	/**
	 * First URLs from the sitemap (robots.txt "Sitemap:" lines, then /wp-sitemap.xml, then /sitemap.xml).
	 * A sitemap index is followed to its first child sitemap.
	 *
	 * @return list<string>
	 */
	private function sitemap_urls(): array {
		$candidates = array_merge( $this->robots()->sitemaps(), array( 'wp-sitemap.xml', 'sitemap.xml' ) );
		foreach ( $candidates as $candidate ) {
			$url = $this->same_site( $candidate );
			if ( null === $url ) {
				continue;
			}
			$response = $this->fetch( $url );
			if ( ! $response->ok() ) {
				continue;
			}
			$locs = self::locs( $response->body );
			if ( str_contains( $response->body, '<sitemapindex' ) && array() !== $locs ) {
				$child = $this->same_site( $locs[0] );
				$locs  = null === $child ? array() : self::locs( $this->fetch( $child )->body );
			}
			if ( array() !== $locs ) {
				return $locs;
			}
		}
		return array();
	}

	/**
	 * <loc> values of a sitemap document.
	 *
	 * @param string $xml Sitemap XML.
	 * @return list<string>
	 */
	private static function locs( string $xml ): array {
		preg_match_all( '#<loc>\s*([^<\s]+)\s*</loc>#i', $xml, $matches );
		return array_map( static fn( string $loc ): string => html_entity_decode( $loc, ENT_QUOTES | ENT_XML1 ), $matches[1] );
	}

	/**
	 * Lower-case host[:port] of a URL.
	 *
	 * @param string $url URL.
	 */
	private static function host_port( string $url ): string {
		$host = strtolower( (string) parse_url( $url, PHP_URL_HOST ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- Platform-neutral core.
		$port = parse_url( $url, PHP_URL_PORT ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- Platform-neutral core.
		return null === $port ? $host : $host . ':' . $port;
	}
}
