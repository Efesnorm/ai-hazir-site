<?php
/**
 * Counts AI bot visits and AI-referred human visits.
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\Core\Measurement;

use AIHazirSite\Core\Contracts\Clock;
use AIHazirSite\Core\Contracts\HitRepository;
use AIHazirSite\Core\Features;
use Throwable;

/**
 * Classifies a request early ({@see Tracker::capture()}) and writes at most one
 * row later ({@see Tracker::flush()}). The platform adapter decides which
 * requests reach capture() (e.g. never admin, cron or AJAX requests).
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
	 * @param HitRepository   $hits       Counter storage.
	 * @param Clock           $clock      Provides the day.
	 * @param Classifier|null $classifier Classifier; loaded from data/ on first use when null.
	 * @param callable|null   $verifier   fn( Bot, string $ip ): bool. Null means "not verified".
	 *
	 * @phpstan-param (callable(Bot, string): bool)|null $verifier
	 */
	public function __construct(
		private readonly HitRepository $hits,
		private readonly Clock $clock,
		private ?Classifier $classifier = null,
		?callable $verifier = null
	) {
		$this->verifier = $verifier;
	}

	/**
	 * Classifies a request and remembers the hit (nothing is written yet).
	 * Only the first countable request is kept until flush().
	 *
	 * @param Request $request Request.
	 */
	public function capture( Request $request ): void {
		if ( null !== $this->pending || ! Features::is_enabled( Features::MEASUREMENT ) ) {
			return;
		}

		$path = Hit::normalize_path( $request->uri );
		$bot  = $this->classifier()->match_bot( $request->user_agent );

		if ( null !== $bot ) {
			$this->pending = array(
				'kind'      => Hit::KIND_BOT,
				'source_id' => $bot->id,
				'path'      => $path,
				'bot'       => $bot,
				'ip'        => false === filter_var( $request->ip, FILTER_VALIDATE_IP ) ? '' : $request->ip,
			);
			return;
		}

		$referrer = $this->classifier()->match_referrer( $request->referer, $request->utm_source );
		if ( null !== $referrer ) {
			$this->pending = array(
				'kind'      => Hit::KIND_REFERRAL,
				'source_id' => $referrer->id,
				'path'      => $path,
				'bot'       => null,
				'ip'        => '',
			);
		}
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

		return $this->hits->increment(
			$this->clock->today(),
			$hit['kind'],
			$hit['source_id'],
			$hit['path'],
			null !== $hit['bot'] && $this->verify( $hit['bot'], $hit['ip'] )
		);
	}

	/**
	 * Captures and writes in one step.
	 *
	 * @param Request $request Request.
	 */
	public function handle( Request $request ): bool {
		$this->capture( $request );
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
}
