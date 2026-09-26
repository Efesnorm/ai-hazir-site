<?php
/**
 * Tests for the bundled data files and Registry.
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\Tests\Unit\Measurement;

use AIHazirSite\Core\Measurement\Bot;
use AIHazirSite\Core\Measurement\Registry;
use AIHazirSite\Tests\Unit\UnitTestCase;

/**
 * Registry unit tests.
 *
 * @covers \AIHazirSite\Core\Measurement\Registry
 * @covers \AIHazirSite\Core\Measurement\Bot
 * @covers \AIHazirSite\Core\Measurement\Referrer
 */
final class RegistryTest extends UnitTestCase {

	/**
	 * Every record in data/ai-bots.json is valid and the required bots are present.
	 */
	public function test_bundled_bots_are_complete_and_valid(): void {
		$raw  = json_decode( (string) file_get_contents( Registry::data_dir() . '/ai-bots.json' ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$bots = Registry::bots();

		$this->assertCount( count( $raw['bots'] ), $bots, 'An invalid record was skipped.' );

		$ids = array_map( static fn( Bot $bot ): string => $bot->id, $bots );
		$this->assertSame( array_unique( $ids ), $ids, 'Bot ids must be unique.' );
		$required = array( 'gptbot', 'oai-searchbot', 'chatgpt-user', 'claudebot', 'claude-user', 'claude-searchbot', 'perplexitybot', 'perplexity-user', 'ccbot', 'bytespider', 'amazonbot', 'meta-externalagent' );
		$this->assertSame( array(), array_values( array_diff( $required, $ids ) ), 'Required bots are missing.' );

		foreach ( $bots as $bot ) {
			if ( 'ip_ranges' === $bot->verify ) {
				$this->assertStringStartsWith( 'https://', $bot->verify_source, $bot->id );
			}
		}
	}

	/**
	 * Every referrer in data/ai-referrers.json is valid.
	 */
	public function test_bundled_referrers_are_complete(): void {
		$domains = array_map( static fn( $r ): string => $r->domain, Registry::referrers() );

		$this->assertSame( array( 'chatgpt.com', 'perplexity.ai', 'claude.ai', 'gemini.google.com', 'copilot.microsoft.com' ), $domains );
	}

	/**
	 * Invalid records are skipped, valid ones kept.
	 */
	public function test_invalid_records_are_skipped(): void {
		$file = tempnam( sys_get_temp_dir(), 'aihs' );
		$this->assertIsString( $file );
		file_put_contents( // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			$file,
			(string) json_encode( // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- No WordPress in unit tests.
				array(
					'bots' => array(
						array(
							'id'            => 'ok',
							'name'          => 'Ok',
							'operator'      => 'X',
							'ua_pattern'    => 'OkBot',
							'category'      => 'search',
							'verify'        => 'none',
							'verify_source' => '',
							'docs_url'      => '',
						),
						array(
							'id'            => 'bad-regex',
							'name'          => 'B',
							'operator'      => 'X',
							'ua_pattern'    => '(',
							'category'      => 'search',
							'verify'        => 'none',
							'verify_source' => '',
							'docs_url'      => '',
						),
						array(
							'id'            => 'bad-category',
							'name'          => 'B',
							'operator'      => 'X',
							'ua_pattern'    => 'B',
							'category'      => 'other',
							'verify'        => 'none',
							'verify_source' => '',
							'docs_url'      => '',
						),
						array(
							'id'            => 'no-source',
							'name'          => 'B',
							'operator'      => 'X',
							'ua_pattern'    => 'B',
							'category'      => 'search',
							'verify'        => 'ip_ranges',
							'verify_source' => '',
							'docs_url'      => '',
						),
						'not an object',
					),
				)
			)
		);

		$bots = Registry::bots( $file );
		unlink( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink

		$this->assertCount( 1, $bots );
		$this->assertSame( 'ok', $bots[0]->id );
	}

	/**
	 * A missing file yields empty lists instead of an error.
	 */
	public function test_missing_file_is_empty(): void {
		$this->assertSame( array(), Registry::bots( '/nonexistent/ai-bots.json' ) );
		$this->assertSame( array(), Registry::referrers( '/nonexistent/ai-referrers.json' ) );
	}
}
