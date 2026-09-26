<?php
/**
 * Loads bot and referrer lists from the data/ folder.
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\Modules\Measurement;

/**
 * Reads data/ai-bots.json and data/ai-referrers.json. Invalid records are skipped
 * so a bad edit never breaks page loads.
 */
final class Registry {

	/**
	 * Default data directory.
	 */
	public static function data_dir(): string {
		return dirname( __DIR__, 3 ) . '/data';
	}

	/**
	 * Loads bots.
	 *
	 * @param string|null $file JSON file; defaults to data/ai-bots.json.
	 * @return list<Bot>
	 */
	public static function bots( ?string $file = null ): array {
		$bots = array();
		foreach ( self::records( $file ?? self::data_dir() . '/ai-bots.json', 'bots' ) as $record ) {
			$bot = Bot::from_array( $record );
			if ( null !== $bot ) {
				$bots[] = $bot;
			}
		}
		return $bots;
	}

	/**
	 * Loads referrers.
	 *
	 * @param string|null $file JSON file; defaults to data/ai-referrers.json.
	 * @return list<Referrer>
	 */
	public static function referrers( ?string $file = null ): array {
		$referrers = array();
		foreach ( self::records( $file ?? self::data_dir() . '/ai-referrers.json', 'referrers' ) as $record ) {
			$referrer = Referrer::from_array( $record );
			if ( null !== $referrer ) {
				$referrers[] = $referrer;
			}
		}
		return $referrers;
	}

	/**
	 * Raw records under `$key`, or an empty list when the file is missing or invalid.
	 *
	 * @param string $file JSON file.
	 * @param string $key  Top-level key.
	 * @return array<mixed>
	 */
	private static function records( string $file, string $key ): array {
		if ( ! is_readable( $file ) ) {
			return array();
		}
		$json = file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local plugin data file.
		$data = is_string( $json ) ? json_decode( $json, true ) : null;

		return is_array( $data ) && isset( $data[ $key ] ) && is_array( $data[ $key ] ) ? $data[ $key ] : array();
	}
}
