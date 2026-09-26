<?php
/**
 * AI bot definition.
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\Core\Measurement;

/**
 * One entry of data/ai-bots.json.
 */
final class Bot {

	public const CATEGORIES = array( 'training', 'search', 'user_agent' );
	public const VERIFY     = array( 'none', 'ip_ranges', 'rdns' );

	/**
	 * Constructor.
	 *
	 * @param string $id            Stable identifier stored in the database.
	 * @param string $name          Display name.
	 * @param string $operator      Company operating the bot.
	 * @param string $ua_pattern    Case-insensitive regex fragment (no delimiters).
	 * @param string $category      One of self::CATEGORIES.
	 * @param string $verify        One of self::VERIFY.
	 * @param string $verify_source IP list URL (ip_ranges) or comma-separated host suffixes (rdns).
	 * @param string $docs_url      Official documentation URL.
	 */
	public function __construct(
		public readonly string $id,
		public readonly string $name,
		public readonly string $operator,
		public readonly string $ua_pattern,
		public readonly string $category,
		public readonly string $verify,
		public readonly string $verify_source,
		public readonly string $docs_url
	) {
	}

	/**
	 * Builds a bot from a decoded JSON record, or null when the record is invalid.
	 *
	 * @param mixed $data Decoded JSON record.
	 */
	public static function from_array( mixed $data ): ?self {
		if ( ! is_array( $data ) ) {
			return null;
		}

		$fields = array();
		foreach ( array( 'id', 'name', 'operator', 'ua_pattern', 'category', 'verify', 'verify_source', 'docs_url' ) as $key ) {
			if ( ! isset( $data[ $key ] ) || ! is_string( $data[ $key ] ) ) {
				return null;
			}
			$fields[ $key ] = $data[ $key ];
		}

		if ( ! preg_match( '/^[a-z0-9-]{1,64}$/', $fields['id'] )
			|| '' === $fields['ua_pattern']
			|| false === @preg_match( '~' . $fields['ua_pattern'] . '~i', '' ) // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Invalid patterns are rejected, not reported.
			|| ! in_array( $fields['category'], self::CATEGORIES, true )
			|| ! in_array( $fields['verify'], self::VERIFY, true )
			|| ( 'none' !== $fields['verify'] && '' === $fields['verify_source'] )
		) {
			return null;
		}

		return new self(
			$fields['id'],
			$fields['name'],
			$fields['operator'],
			$fields['ua_pattern'],
			$fields['category'],
			$fields['verify'],
			$fields['verify_source'],
			$fields['docs_url']
		);
	}
}
