<?php
/**
 * Parsing and matching of robots.txt files (RFC 9309).
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\Core\Compliance;

use AIHazirSite\Core\Contracts\PageResponse;

/**
 * Implements the matching rules of RFC 9309:
 * - user-agent product tokens match case-insensitively; all matching groups are combined (§2.2.1);
 * - with no matching group the "*" group applies (§2.2.1);
 * - the longest matching rule wins, allow wins a tie; "*" and "$" are supported (§2.2.2, §2.2.3);
 * - 4xx robots.txt → everything allowed (§2.3.1.3); 5xx or unreachable → everything disallowed (§2.3.1.4).
 */
final class Robots {

	/**
	 * Groups: user agents and rules.
	 *
	 * @var list<array{agents: list<string>, rules: list<array{allow: bool, path: string}>}>
	 */
	private array $groups = array();

	/**
	 * Sitemap URLs.
	 *
	 * @var list<string>
	 */
	private array $sitemaps = array();

	/**
	 * Constructor.
	 *
	 * @param string    $content  robots.txt content.
	 * @param bool|null $blanket  true = allow all, false = disallow all, null = use content.
	 */
	public function __construct( string $content = '', private readonly ?bool $blanket = null ) {
		$this->parse( $content );
	}

	/**
	 * Interprets a fetched robots.txt according to its status.
	 *
	 * @param PageResponse $response Response of /robots.txt.
	 */
	public static function from_response( PageResponse $response ): self {
		if ( $response->ok() ) {
			return new self( $response->body );
		}
		if ( $response->status >= 400 && $response->status < 500 ) {
			return new self( '', true );
		}
		return new self( '', false );
	}

	/**
	 * Whether a crawler with the product token may fetch the path.
	 *
	 * @param string $token Product token, e.g. GPTBot.
	 * @param string $path  Path (with query).
	 */
	public function allows( string $token, string $path = '/' ): bool {
		if ( null !== $this->blanket ) {
			return $this->blanket;
		}

		$rules = $this->rules_for( strtolower( $token ) );
		$best  = null;
		foreach ( $rules as $rule ) {
			if ( '' === $rule['path'] || ! self::matches( $rule['path'], $path ) ) {
				continue;
			}
			$length = strlen( $rule['path'] );
			if ( null === $best || $length > $best['length'] || ( $length === $best['length'] && $rule['allow'] ) ) {
				$best = array(
					'length' => $length,
					'allow'  => $rule['allow'],
				);
			}
		}

		return null === $best || $best['allow'];
	}

	/**
	 * Sitemap URLs listed in the file.
	 *
	 * @return list<string>
	 */
	public function sitemaps(): array {
		return $this->sitemaps;
	}

	/**
	 * Rules of all groups matching the token, or of the "*" group.
	 *
	 * @param string $token Lower-case token.
	 * @return list<array{allow: bool, path: string}>
	 */
	private function rules_for( string $token ): array {
		$specific = array();
		$wildcard = array();
		foreach ( $this->groups as $group ) {
			if ( in_array( $token, $group['agents'], true ) ) {
				array_push( $specific, ...$group['rules'] );
			} elseif ( in_array( '*', $group['agents'], true ) ) {
				array_push( $wildcard, ...$group['rules'] );
			}
		}
		return array() !== $specific ? $specific : $wildcard;
	}

	/**
	 * Parses the file into groups.
	 *
	 * @param string $content robots.txt content.
	 */
	private function parse( string $content ): void {
		$current = null;
		$lines   = preg_split( '/\r\n|\r|\n/', $content );
		foreach ( is_array( $lines ) ? $lines : array() as $line ) {
			$line = trim( (string) preg_replace( '/#.*$/', '', $line ) );
			if ( ! str_contains( $line, ':' ) ) {
				continue;
			}
			[ $key, $value ] = array_map( 'trim', explode( ':', $line, 2 ) );
			$key             = strtolower( $key );

			if ( 'sitemap' === $key ) {
				$this->sitemaps[] = $value;
			} elseif ( 'user-agent' === $key ) {
				if ( null === $current || array() !== $this->groups[ $current ]['rules'] ) {
					$this->groups[] = array(
						'agents' => array(),
						'rules'  => array(),
					);
					$current        = count( $this->groups ) - 1;
				}
				$this->groups[ $current ]['agents'][] = strtolower( $value );
			} elseif ( ( 'allow' === $key || 'disallow' === $key ) && null !== $current ) {
				$this->groups[ $current ]['rules'][] = array(
					'allow' => 'allow' === $key,
					'path'  => $value,
				);
			}
		}
	}

	/**
	 * Whether a rule path matches (supports "*" and a trailing "$").
	 *
	 * @param string $pattern Rule path.
	 * @param string $path    Request path.
	 */
	private static function matches( string $pattern, string $path ): bool {
		$anchored = str_ends_with( $pattern, '$' );
		$pattern  = $anchored ? substr( $pattern, 0, -1 ) : $pattern;
		$regex    = '#^' . str_replace( '\*', '.*', preg_quote( $pattern, '#' ) ) . ( $anchored ? '$' : '' ) . '#';

		return 1 === preg_match( $regex, $path );
	}
}
