<?php
/**
 * Maps a request to an AI bot or an AI referrer.
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\Core\Measurement;

/**
 * Matching is a single compiled regex for bots and a host comparison for referrers,
 * so ordinary browser traffic costs one preg_match.
 */
final class Classifier {

	/**
	 * Combined bot regex, built on first use.
	 *
	 * @var string|null
	 */
	private ?string $regex = null;

	/**
	 * Constructor.
	 *
	 * @param Bot[]      $bots      Bots.
	 * @param Referrer[] $referrers Referrers.
	 *
	 * @phpstan-param list<Bot>      $bots
	 * @phpstan-param list<Referrer> $referrers
	 */
	public function __construct(
		private readonly array $bots,
		private readonly array $referrers
	) {
	}

	/**
	 * Classifier with the bundled data files.
	 */
	public static function from_data(): self {
		return new self( Registry::bots(), Registry::referrers() );
	}

	/**
	 * Bot whose pattern matches the user agent, if any.
	 *
	 * @param string $user_agent User-Agent header.
	 */
	public function match_bot( string $user_agent ): ?Bot {
		if ( '' === $user_agent || array() === $this->bots ) {
			return null;
		}

		if ( null === $this->regex ) {
			$parts = array();
			foreach ( $this->bots as $index => $bot ) {
				$parts[] = '(?<b' . $index . '>' . $bot->ua_pattern . ')';
			}
			$this->regex = '~' . implode( '|', $parts ) . '~i';
		}

		if ( 1 !== preg_match( $this->regex, $user_agent, $matches, PREG_UNMATCHED_AS_NULL ) ) {
			return null;
		}

		foreach ( $this->bots as $index => $bot ) {
			if ( isset( $matches[ 'b' . $index ] ) ) {
				return $bot;
			}
		}

		return null;
	}

	/**
	 * AI platform that sent a human visitor, if any.
	 *
	 * @param string $referer    Referer header.
	 * @param string $utm_source Value of the utm_source query parameter.
	 */
	public function match_referrer( string $referer, string $utm_source ): ?Referrer {
		$host = '' === $referer ? '' : (string) parse_url( $referer, PHP_URL_HOST ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- Platform-neutral core; PHP 8.1 parse_url is reliable.
		$utm  = strtolower( trim( $utm_source ) );

		foreach ( $this->referrers as $referrer ) {
			if ( ( '' !== $host && $referrer->matches_host( $host ) ) || ( '' !== $utm && $referrer->matches_host( $utm ) ) ) {
				return $referrer;
			}
		}

		return null;
	}

	/**
	 * Bot by id.
	 *
	 * @param string $id Bot id.
	 */
	public function bot( string $id ): ?Bot {
		foreach ( $this->bots as $bot ) {
			if ( $bot->id === $id ) {
				return $bot;
			}
		}
		return null;
	}

	/**
	 * All bots.
	 *
	 * @return list<Bot>
	 */
	public function bots(): array {
		return $this->bots;
	}

	/**
	 * All referrers.
	 *
	 * @return list<Referrer>
	 */
	public function referrers(): array {
		return $this->referrers;
	}
}
