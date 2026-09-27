<?php
/**
 * Stored llms.txt text.
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\Adapters\Llms;

use AIHazirSite\Core\Contracts\Settings;

/**
 * The text is rebuilt only when its input (profile, listings, today, URLs, texts) changes;
 * otherwise the stored text is served. Storage is written only on a rebuild, so normal
 * requests do not write to the database. "Today" is part of the input, so a listing that
 * expires drops out the next day without any other change.
 */
final class LlmsCache {

	public const OPTION = 'aihs_llms_cache';

	/**
	 * Constructor.
	 *
	 * @param Settings $settings Storage.
	 */
	public function __construct( private readonly Settings $settings ) {
	}

	/**
	 * The text for this input: stored when the input is unchanged, else built and stored.
	 *
	 * @param array<mixed> $input Everything the text depends on.
	 * @param callable     $build Builds the text.
	 *
	 * @phpstan-param callable(): string $build
	 */
	public function text( array $input, callable $build ): string {
		$hash   = md5( (string) json_encode( $input ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Platform-neutral adapter; hash only.
		$stored = $this->settings->get( self::OPTION, array() );
		if ( is_array( $stored ) && ( $stored['hash'] ?? null ) === $hash && is_string( $stored['text'] ?? null ) ) {
			return $stored['text'];
		}

		$text = $build();
		$this->settings->set(
			self::OPTION,
			array(
				'hash' => $hash,
				'text' => $text,
			),
			false
		);
		return $text;
	}
}
