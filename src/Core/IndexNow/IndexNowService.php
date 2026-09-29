<?php
/**
 * IndexNow: telling search engines that our public pages changed.
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\Core\IndexNow;

use AIHazirSite\Core\Contracts\Clock;
use AIHazirSite\Core\Contracts\HttpStatusPoster;
use AIHazirSite\Core\Contracts\Settings;

/**
 * The IndexNow protocol (https://www.indexnow.org/documentation) (1.10.0):
 * - key: 8–128 characters of a-z, A-Z, 0-9 and "-"; ours is 32 random hexadecimal characters, served
 *   as /{key}.txt at the site root, and always named in `keyLocation` (a site may have several keys,
 *   e.g. Rank Math's own);
 * - one POST of {host, key, keyLocation, urlList} to api.indexnow.org, shared with every participating
 *   search engine.
 * Only URLs on the site's own host are sent (no personal data), only while the feature is on, and at
 * most once an hour. The last result is kept for the settings screen.
 */
final class IndexNowService {

	public const OPTION       = 'aihs_indexnow';
	public const ENDPOINT     = 'https://api.indexnow.org/indexnow';
	public const MIN_INTERVAL = 3600;
	public const MAX_URLS     = 10000;

	/**
	 * Constructor.
	 *
	 * @param Settings         $settings Storage (key, last result).
	 * @param HttpStatusPoster $poster   Outgoing POST.
	 * @param Clock            $clock    Clock.
	 */
	public function __construct(
		private readonly Settings $settings,
		private readonly HttpStatusPoster $poster,
		private readonly Clock $clock
	) {
	}

	/**
	 * Whether a key has the protocol's form.
	 *
	 * @param string $key Key.
	 */
	public static function valid_key( string $key ): bool {
		return 1 === preg_match( '/^[a-zA-Z0-9-]{8,128}$/', $key );
	}

	/**
	 * The stored key ('' when none yet).
	 */
	public function key(): string {
		$state = $this->state();
		return isset( $state['key'] ) && is_string( $state['key'] ) && self::valid_key( $state['key'] ) ? $state['key'] : '';
	}

	/**
	 * The stored key, created on first use.
	 */
	public function ensure_key(): string {
		$key = $this->key();
		if ( '' === $key ) {
			$key          = bin2hex( random_bytes( 16 ) );
			$state        = $this->state();
			$state['key'] = $key;
			$this->settings->set( self::OPTION, $state );
		}
		return $key;
	}

	/**
	 * The last submission: time (Unix), HTTP status (0 = no answer), number of URLs; null when none yet.
	 *
	 * @return array{at: int, status: int, count: int}|null
	 */
	public function last(): ?array {
		$last = $this->state()['last'] ?? null;
		if ( ! is_array( $last ) || ! isset( $last['at'], $last['status'], $last['count'] ) ) {
			return null;
		}
		return array(
			'at'     => (int) $last['at'],
			'status' => (int) $last['status'],
			'count'  => (int) $last['count'],
		);
	}

	/**
	 * Earliest time (Unix) the next submission may be sent.
	 */
	public function next_allowed(): int {
		$last = $this->last();
		return null === $last ? 0 : $last['at'] + self::MIN_INTERVAL;
	}

	/**
	 * The request body.
	 *
	 * @param string   $host         Site host.
	 * @param string   $key          Key.
	 * @param string   $key_location Key file URL.
	 * @param string[] $urls         URLs.
	 */
	public static function payload( string $host, string $key, string $key_location, array $urls ): string {
		return (string) json_encode( // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Platform-neutral core.
			array(
				'host'        => $host,
				'key'         => $key,
				'keyLocation' => $key_location,
				'urlList'     => $urls,
			),
			JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
		);
	}

	/**
	 * Sends the URLs; null when nothing was sent (off, no key, no URL on the host, or within the hour).
	 *
	 * @param bool     $enabled      Feature state.
	 * @param string   $host         Site host.
	 * @param string   $key_location Key file URL (on the host).
	 * @param string[] $urls         Public URLs.
	 * @return array{at: int, status: int, count: int}|null
	 */
	public function submit( bool $enabled, string $host, string $key_location, array $urls ): ?array {
		$key  = $this->key();
		$urls = array_slice( array_values( array_unique( array_filter( $urls, static fn( string $url ): bool => self::on_host( $url, $host ) ) ) ), 0, self::MAX_URLS );
		if ( ! $enabled || '' === $key || '' === $host || array() === $urls || ! self::on_host( $key_location, $host ) || $this->clock->now() < $this->next_allowed() ) {
			return null;
		}

		$status        = $this->poster->post_json( self::ENDPOINT, self::payload( $host, $key, $key_location, $urls ) );
		$last          = array(
			'at'     => $this->clock->now(),
			'status' => $status,
			'count'  => count( $urls ),
		);
		$state         = $this->state();
		$state['last'] = $last;
		$this->settings->set( self::OPTION, $state );
		return $last;
	}

	/**
	 * What an answer means (Turkish, for the site owner).
	 *
	 * @param int $status HTTP status (0 = no answer).
	 */
	public static function meaning( int $status ): string {
		return match ( $status ) {
			200     => 'Tamam: adresler alındı.',
			202     => 'Kabul edildi: anahtar doğrulaması sürüyor.',
			400     => 'Biçim hatası.',
			403     => 'Arama motoru anahtar dosyasına ulaşamadı; barındırmanın bot korumasını kontrol edin.',
			422     => 'Adresler siteyle ya da anahtarla uyuşmuyor.',
			429     => 'Çok sık istek; bir süre sonra yeniden denenir.',
			0       => 'Yanıt alınamadı (bağlantı hatası).',
			default => 'Beklenmeyen yanıt.',
		};
	}

	/**
	 * Whether a URL is https or http on the host.
	 *
	 * @param string $url  URL.
	 * @param string $host Host.
	 */
	private static function on_host( string $url, string $host ): bool {
		$parts = parse_url( $url ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- Platform-neutral core.
		return is_array( $parts ) && in_array( $parts['scheme'] ?? '', array( 'https', 'http' ), true ) && strtolower( $parts['host'] ?? '' ) === strtolower( $host );
	}

	/**
	 * Stored state.
	 *
	 * @return array<string, mixed>
	 */
	private function state(): array {
		$state = $this->settings->get( self::OPTION, array() );
		return is_array( $state ) ? $state : array();
	}
}
