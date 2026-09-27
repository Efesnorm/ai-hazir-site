<?php
/**
 * Snapshot and rule tests for llms.txt.
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\Tests\Unit\Llms;

use AIHazirSite\Adapters\Llms\LlmsTxtBuilder;
use AIHazirSite\Core\Catalog\CompanyProfile;
use AIHazirSite\Core\Catalog\Listing;
use AIHazirSite\Tests\Support\SchemaFixtures as F;
use PHPUnit\Framework\TestCase;

/**
 * The snapshot lives in tests/Snapshots/llms/; a changed output fails until reviewed on purpose.
 *
 * @covers \AIHazirSite\Adapters\Llms\LlmsTxtBuilder
 */
final class LlmsTxtBuilderTest extends TestCase {

	private const CATALOG_URL = 'https://ornek.com/ai-katalog/';

	/**
	 * Builder over the fixture site.
	 */
	private static function builder(): LlmsTxtBuilder {
		return new LlmsTxtBuilder( F::SITE_URL, self::CATALOG_URL, 'Örnek Site' );
	}

	/**
	 * Every fixture listing (expired ones included).
	 *
	 * @return list<Listing>
	 */
	private static function listings(): array {
		return array( F::offer(), F::supply(), F::demand(), F::expired(), F::stale(), F::unpriced() );
	}

	/**
	 * Full text equals the reviewed snapshot.
	 */
	public function test_snapshot(): void {
		$text = self::builder()->build( F::profile(), self::listings(), F::TODAY, '2026-09-22T08:00:00Z' );

		$this->assertSame( (string) file_get_contents( dirname( __DIR__, 2 ) . '/Snapshots/llms/llms.txt' ), $text ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
	}

	/**
	 * The format's required H1 comes first, followed by the blockquote summary; LF only, no BOM, valid UTF-8.
	 */
	public function test_format(): void {
		$text  = self::builder()->build( F::profile(), self::listings(), F::TODAY, '' );
		$lines = explode( "\n", $text );

		$this->assertSame( '# Örnek Kablo A.Ş.', $lines[0] );
		$this->assertStringStartsWith( '> ', $lines[2] );
		$this->assertStringNotContainsString( "\r", $text );
		$this->assertStringStartsNotWith( "\xEF\xBB\xBF", $text );
		$this->assertTrue( mb_check_encoding( $text, 'UTF-8' ) );
		$this->assertStringEndsWith( "\n", $text );
		$this->assertStringEndsNotWith( "\n\n", $text );
		$this->assertStringNotContainsString( 'Son güncelleme', $text, 'No date line when the date is unknown.' );
	}

	/**
	 * Expired listings (own date or the 90-day default) are left out; empty sections too.
	 */
	public function test_expired_and_empty_sections_left_out(): void {
		$text = self::builder()->build( F::profile(), array( F::expired(), F::stale(), F::unpriced() ), F::TODAY, '' );

		$this->assertStringNotContainsString( 'Eski kampanya', $text );
		$this->assertStringNotContainsString( 'Güncellenmeyen ilan', $text );
		$this->assertStringContainsString( '- [Fiyat sorunuz](https://ornek.com/ai-katalog/#ilan-16): Geçerlilik: 2026-12-24', $text );
		$this->assertStringNotContainsString( '## Arananlar', $text );
		$this->assertStringNotContainsString( '## Tedarik edilebilenler', $text );
	}

	/**
	 * Markdown in user text cannot break the structure; an empty profile falls back to the site name.
	 */
	public function test_user_text_is_neutralized(): void {
		$listing = new Listing( 20, 'offer', "Kablo [özel]\n# Başlık", "Satır 1\n\n## Satır 2", '', null, '', null, null, '', '', null, '2026-12-31', '2026-09-25T00:00:00Z' );
		$text    = self::builder()->build( new CompanyProfile(), array( $listing ), F::TODAY, '' );

		$this->assertStringStartsWith( "# Örnek Site\n", $text );
		$this->assertStringContainsString( '- [Kablo \[özel\] # Başlık](https://ornek.com/ai-katalog/#ilan-20): Satır 1 ## Satır 2. Geçerlilik: 2026-12-31', $text );
		$this->assertSame( 1, preg_match_all( '/^# /m', $text ) );
		$this->assertSame( array( '## Satılanlar', '## İletişim', '## Optional' ), array_values( array_filter( explode( "\n", $text ), static fn( string $l ): bool => str_starts_with( $l, '## ' ) ) ) );
	}

	/**
	 * Translated labels replace the defaults.
	 */
	public function test_labels(): void {
		$builder = new LlmsTxtBuilder( F::SITE_URL, self::CATALOG_URL, '', array( 'offer' => 'Offers' ) );

		$this->assertStringContainsString( "## Offers\n", $builder->build( F::profile(), array( F::offer() ), F::TODAY, '' ) );
	}
}
