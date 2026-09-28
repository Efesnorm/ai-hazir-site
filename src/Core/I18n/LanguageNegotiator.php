<?php
/**
 * Which language to answer in.
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\Core\I18n;

/**
 * Picks the answer language: an explicit parameter wins, then the Accept-Language header
 * (RFC 9110 §12.5.4: quality values, "*" and q=0), matched by primary subtag (RFC 4647 §3.4
 * lookup: "en-GB" → "en"), else the default language.
 */
final class LanguageNegotiator {

	/**
	 * Constructor.
	 *
	 * @param LanguageSettings $settings Published languages.
	 */
	public function __construct( private readonly LanguageSettings $settings ) {
	}

	/**
	 * The language to answer in.
	 *
	 * @param string|null $requested       Explicit choice (e.g. ?lang=en), or null.
	 * @param string      $accept_language Accept-Language header value ('' when absent).
	 */
	public function negotiate( ?string $requested, string $accept_language = '' ): string {
		if ( null !== $requested && '' !== $requested ) {
			$code = LanguageSettings::normalise( $requested );
			if ( null !== $code && $this->settings->has( $code ) ) {
				return $code;
			}
		}
		foreach ( self::ranges( $accept_language ) as $range ) {
			if ( '*' === $range ) {
				return $this->settings->default;
			}
			$code = LanguageSettings::normalise( $range );
			if ( null !== $code && $this->settings->has( $code ) ) {
				return $code;
			}
		}
		return $this->settings->default;
	}

	/**
	 * Language ranges of an Accept-Language value, highest quality first (equal quality keeps
	 * header order); ranges with q=0 ("not acceptable") are left out.
	 *
	 * @param string $header Header value.
	 * @return list<string>
	 */
	public static function ranges( string $header ): array {
		$ranges = array();
		foreach ( explode( ',', $header ) as $position => $part ) {
			$pieces = array_map( 'trim', explode( ';', $part ) );
			$range  = $pieces[0];
			if ( '' === $range || 1 !== preg_match( '/^(\*|[A-Za-z]{1,8}(-[A-Za-z0-9]{1,8})*)$/', $range ) ) {
				continue;
			}
			$quality = 1.0;
			foreach ( array_slice( $pieces, 1 ) as $parameter ) {
				if ( 1 === preg_match( '/^q\s*=\s*(0(\.\d{0,3})?|1(\.0{0,3})?)$/i', $parameter, $m ) ) {
					$quality = (float) $m[1];
				}
			}
			if ( $quality > 0.0 ) {
				$ranges[] = array( $range, $quality, $position );
			}
		}
		usort( $ranges, static fn( array $a, array $b ): int => array( $b[1], $a[2] ) <=> array( $a[1], $b[2] ) );
		return array_map( static fn( array $r ): string => $r[0], $ranges );
	}
}
