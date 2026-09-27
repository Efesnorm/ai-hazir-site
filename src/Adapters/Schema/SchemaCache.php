<?php
/**
 * Last valid JSON-LD per page.
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\Adapters\Schema;

use AIHazirSite\Core\Contracts\Settings;

/**
 * Invalid output is never published: the last valid document of the same page is served
 * instead and the error is recorded for an admin notice. Storage is written only when
 * something changed, so normal page views do not write to the database.
 */
final class SchemaCache {

	public const OPTION       = 'aihs_schema_cache';
	public const ERROR_OPTION = 'aihs_schema_error';

	/**
	 * Constructor.
	 *
	 * @param Settings        $settings  Storage.
	 * @param SchemaValidator $validator Validator.
	 */
	public function __construct(
		private readonly Settings $settings,
		private readonly SchemaValidator $validator = new SchemaValidator()
	) {
	}

	/**
	 * Document to publish for a page: the new one when valid, else the last valid one (or null).
	 *
	 * @param string               $page     Page key, e.g. "home", "catalog".
	 * @param array<string, mixed> $document Freshly built JSON-LD.
	 * @param string               $now      ISO 8601 time (for the error record).
	 * @return array<string, mixed>|null
	 */
	public function publish( string $page, array $document, string $now ): ?array {
		$result = $this->validator->validate( $document );
		$cache  = $this->cache();
		$errors = $this->errors();

		if ( array() === $result['errors'] ) {
			$hash = md5( (string) json_encode( $document ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Platform-neutral adapter; hash only.
			if ( ( $cache[ $page ]['hash'] ?? '' ) !== $hash ) {
				$cache[ $page ] = array(
					'hash'     => $hash,
					'document' => $document,
				);
				$this->settings->set( self::OPTION, $cache, false );
			}
			if ( isset( $errors[ $page ] ) ) {
				unset( $errors[ $page ] );
				$this->settings->set( self::ERROR_OPTION, $errors, false );
			}
			return $document;
		}

		if ( ( $errors[ $page ]['errors'] ?? null ) !== $result['errors'] ) {
			$errors[ $page ] = array(
				'errors' => $result['errors'],
				'at'     => $now,
			);
			$this->settings->set( self::ERROR_OPTION, $errors, false );
		}

		$last = $cache[ $page ]['document'] ?? null;
		return is_array( $last ) ? $last : null;
	}

	/**
	 * Recorded errors per page.
	 *
	 * @return array<string, array{errors: list<string>, at: string}>
	 */
	public function errors(): array {
		$stored = $this->settings->get( self::ERROR_OPTION, array() );
		$errors = array();
		foreach ( is_array( $stored ) ? $stored : array() as $page => $record ) {
			if ( is_array( $record ) && isset( $record['errors'] ) && is_array( $record['errors'] ) ) {
				$errors[ (string) $page ] = array(
					'errors' => array_values( array_map( 'strval', $record['errors'] ) ),
					'at'     => (string) ( $record['at'] ?? '' ),
				);
			}
		}
		return $errors;
	}

	/**
	 * Cached documents per page.
	 *
	 * @return array<string, array{hash: string, document: array<string, mixed>}>
	 */
	private function cache(): array {
		$stored = $this->settings->get( self::OPTION, array() );
		$cache  = array();
		foreach ( is_array( $stored ) ? $stored : array() as $page => $record ) {
			if ( is_array( $record ) && isset( $record['hash'], $record['document'] ) && is_array( $record['document'] ) ) {
				$cache[ (string) $page ] = array(
					'hash'     => (string) $record['hash'],
					'document' => $record['document'],
				);
			}
		}
		return $cache;
	}
}
