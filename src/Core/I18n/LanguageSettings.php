<?php
/**
 * Catalog languages.
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\Core\I18n;

/**
 * The default language and the list of languages the catalog is published in.
 * Codes are ISO 639-1 (the two-letter BCP 47 primary language subtag), lowercase.
 * The default language is always first in the list.
 */
final class LanguageSettings {

	/**
	 * Most languages a site may list.
	 */
	public const MAX = 20;

	/**
	 * Default language (the language the catalog is entered in).
	 *
	 * @var string
	 */
	public readonly string $default;

	/**
	 * Languages, the default first.
	 *
	 * @var list<string>
	 */
	public readonly array $languages;

	/**
	 * Constructor (codes are normalised; invalid and duplicate codes are dropped).
	 *
	 * @param string   $default_language Default language.
	 * @param string[] $languages        Other languages (the default may be included).
	 *
	 * @phpstan-param list<string> $languages
	 */
	public function __construct( string $default_language, array $languages = array() ) {
		$this->default = $default_language;
		$list          = array( $default_language );
		foreach ( $languages as $code ) {
			$code = self::normalise( $code );
			if ( null !== $code && ! in_array( $code, $list, true ) && count( $list ) < self::MAX ) {
				$list[] = $code;
			}
		}
		$this->languages = $list;
	}

	/**
	 * Settings from stored data; the default comes from $fallback when missing or invalid.
	 *
	 * @param mixed  $data     Stored array {default, languages}.
	 * @param string $fallback Default language when none is stored (e.g. the site language).
	 */
	public static function from_array( mixed $data, string $fallback ): self {
		$data     = is_array( $data ) ? $data : array();
		$default  = self::normalise( is_string( $data['default'] ?? null ) ? $data['default'] : '' ) ?? self::normalise( $fallback ) ?? 'tr';
		$list     = is_array( $data['languages'] ?? null ) ? $data['languages'] : array();
		$filtered = array_values( array_filter( $list, 'is_string' ) );
		return new self( $default, $filtered );
	}

	/**
	 * Plain array for storage.
	 *
	 * @return array{default: string, languages: list<string>}
	 */
	public function to_array(): array {
		return array(
			'default'   => $this->default,
			'languages' => $this->languages,
		);
	}

	/**
	 * Whether more than one language is published (otherwise nothing is localized).
	 */
	public function is_multilingual(): bool {
		return count( $this->languages ) > 1;
	}

	/**
	 * Whether a code is one of the languages.
	 *
	 * @param string $code Code.
	 */
	public function has( string $code ): bool {
		return in_array( $code, $this->languages, true );
	}

	/**
	 * Languages other than the default (the ones translations are entered for).
	 *
	 * @return list<string>
	 */
	public function translated(): array {
		return array_slice( $this->languages, 1 );
	}

	/**
	 * A language code in canonical form, or null when it is not a two-letter code.
	 * A region or script subtag is dropped ("en-GB" → "en", "pt_BR" → "pt").
	 *
	 * @param string $code Code.
	 */
	public static function normalise( string $code ): ?string {
		$primary = strtolower( (string) preg_split( '/[-_]/', trim( $code ) )[0] );
		return 1 === preg_match( '/^[a-z]{2}$/', $primary ) ? $primary : null;
	}
}
