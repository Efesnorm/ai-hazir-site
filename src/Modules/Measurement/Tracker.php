<?php
/**
 * Counts AI bot visits and AI-referred human visits.
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\Modules\Measurement;

use AIHazirSite\Core\Features;
use AIHazirSite\Core\Storage\HitStore;
use Throwable;

/**
 * Classifies the request early (`parse_request`, which covers front-end, REST and
 * robots.txt) and writes at most one row at `shutdown`.
 *
 * Nothing about the visitor is stored except the bot/referrer id, the path and
 * the day: no IP address, no user agent, no query string.
 */
final class Tracker {

	/**
	 * Hit captured for this request, written by {@see Tracker::flush()}.
	 *
	 * @var array{kind: string, source_id: string, path: string, bot: Bot|null, ip: string}|null
	 */
	private ?array $pending = null;

	/**
	 * Verifies a bot's identity: fn( Bot $bot, string $ip ): bool.
	 *
	 * @var (callable(Bot, string): bool)|null
	 */
	private $verifier;

	/**
	 * Constructor.
	 *
	 * @param HitStore        $store      Counter storage.
	 * @param Classifier|null $classifier Classifier; loaded from data/ on first use when null.
	 * @param callable|null   $verifier   fn( Bot, string $ip ): bool. Null means "not verified".
	 *
	 * @phpstan-param (callable(Bot, string): bool)|null $verifier
	 */
	public function __construct(
		private readonly HitStore $store,
		private ?Classifier $classifier = null,
		?callable $verifier = null
	) {
		$this->verifier = $verifier;
	}

	/**
	 * `parse_request` callback.
	 */
	public function capture_current_request(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only analytics; only utm_source is read and never stored.
		$this->capture( $_SERVER, $_GET );
	}

	/**
	 * Classifies a request and remembers the hit (nothing is written yet).
	 *
	 * @param array<mixed> $server $_SERVER-like array.
	 * @param array<mixed> $query  $_GET-like array.
	 */
	public function capture( array $server, array $query ): void {
		if ( null !== $this->pending
			|| ! Features::is_enabled( Features::MEASUREMENT )
			|| is_admin()
			|| wp_doing_cron()
			|| wp_doing_ajax()
		) {
			return;
		}

		$path = HitStore::normalize_path( wp_check_invalid_utf8( self::header( $server, 'REQUEST_URI' ) ) );
		$bot  = $this->classifier()->match_bot( self::header( $server, 'HTTP_USER_AGENT' ) );

		if ( null !== $bot ) {
			$ip            = self::header( $server, 'REMOTE_ADDR' );
			$this->pending = array(
				'kind'      => HitStore::KIND_BOT,
				'source_id' => $bot->id,
				'path'      => $path,
				'bot'       => $bot,
				'ip'        => false === filter_var( $ip, FILTER_VALIDATE_IP ) ? '' : $ip,
			);
			return;
		}

		$utm      = isset( $query['utm_source'] ) && is_string( $query['utm_source'] ) ? sanitize_text_field( wp_unslash( $query['utm_source'] ) ) : '';
		$referrer = $this->classifier()->match_referrer( self::header( $server, 'HTTP_REFERER' ), $utm );

		if ( null !== $referrer ) {
			$this->pending = array(
				'kind'      => HitStore::KIND_REFERRAL,
				'source_id' => $referrer->id,
				'path'      => $path,
				'bot'       => null,
				'ip'        => '',
			);
		}
	}

	/**
	 * `shutdown` callback.
	 */
	public function on_shutdown(): void {
		$this->flush();
	}

	/**
	 * Writes the captured hit, if any.
	 *
	 * @return bool True when a row was written.
	 */
	public function flush(): bool {
		if ( null === $this->pending ) {
			return false;
		}

		$hit           = $this->pending;
		$this->pending = null;

		return $this->store->increment(
			current_time( 'Y-m-d' ),
			$hit['kind'],
			$hit['source_id'],
			$hit['path'],
			null !== $hit['bot'] && $this->verify( $hit['bot'], $hit['ip'] )
		);
	}

	/**
	 * Captures and writes in one step (used by tests and benchmarks).
	 *
	 * @param array<mixed> $server $_SERVER-like array.
	 * @param array<mixed> $query  $_GET-like array.
	 */
	public function handle( array $server, array $query = array() ): bool {
		$this->capture( $server, $query );
		return $this->flush();
	}

	/**
	 * Runs the verifier; any failure counts as "not verified".
	 *
	 * @param Bot    $bot Bot.
	 * @param string $ip  Client IP.
	 */
	private function verify( Bot $bot, string $ip ): bool {
		if ( null === $this->verifier || '' === $ip ) {
			return false;
		}
		try {
			return (bool) ( $this->verifier )( $bot, $ip );
		} catch ( Throwable ) {
			return false;
		}
	}

	/**
	 * Classifier, loaded on first use.
	 */
	private function classifier(): Classifier {
		if ( null === $this->classifier ) {
			$this->classifier = Classifier::from_data();
		}
		return $this->classifier;
	}

	/**
	 * Unslashed string value from a $_SERVER-like array.
	 *
	 * @param array<mixed> $server $_SERVER-like array.
	 * @param string       $key    Key.
	 */
	private static function header( array $server, string $key ): string {
		return isset( $server[ $key ] ) && is_string( $server[ $key ] ) ? (string) wp_unslash( $server[ $key ] ) : '';
	}
}
