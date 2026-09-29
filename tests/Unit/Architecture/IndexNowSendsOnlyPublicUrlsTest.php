<?php
/**
 * Architecture: IndexNow sends only the public catalog URLs to the IndexNow endpoint (1.10.0).
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\Tests\Unit\Architecture;

use PHPUnit\Framework\TestCase;

/**
 * - IndexNowService posts once, to its fixed endpoint, only inside submit(), which takes the feature state;
 * - the only caller builds the URL list from the public sitemap list (CatalogSitemap::urls()) and passes
 *   the feature state.
 *
 * @coversNothing
 */
final class IndexNowSendsOnlyPublicUrlsTest extends TestCase {

	private const SERVICE = 'Core/IndexNow/IndexNowService.php';
	private const MODULE  = 'WordPress/IndexNow/IndexNowModule.php';

	/**
	 * Source of a file under src/.
	 *
	 * @param string $relative Path.
	 */
	private static function code( string $relative ): string {
		return (string) file_get_contents( dirname( __DIR__, 3 ) . '/src/' . $relative ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
	}

	/**
	 * One POST, to the endpoint, inside submit( bool $enabled, ... ).
	 */
	public function test_service_posts_once_to_the_endpoint(): void {
		$code = self::code( self::SERVICE );
		$this->assertSame( 1, substr_count( $code, '->post_json(' ) );
		$this->assertStringContainsString( '->post_json( self::ENDPOINT,', $code );
		$this->assertStringContainsString( "public const ENDPOINT     = 'https://api.indexnow.org/indexnow';", $code );
		$start = strpos( $code, 'public function submit( bool $enabled,' );
		$this->assertNotFalse( $start );
		$this->assertGreaterThan( $start, strpos( $code, '->post_json(' ) );
		$this->assertStringContainsString( '! $enabled ||', $code );
	}

	/**
	 * The only caller: public sitemap URLs and the real feature state.
	 */
	public function test_caller_sends_public_urls(): void {
		$root    = dirname( __DIR__, 3 ) . '/src/';
		$callers = array();
		foreach ( new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $root ) ) as $file ) {
			if ( 'php' !== $file->getExtension() ) {
				continue;
			}
			$code = (string) file_get_contents( $file->getPathname() ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			// IndexNow's submit() (the quote box has a submit() of its own).
			if ( str_contains( $code, 'IndexNowService' ) && str_contains( $code, 'service()->submit(' ) ) {
				$callers[] = str_replace( '\\', '/', substr( $file->getPathname(), strlen( $root ) ) );
			}
		}
		$this->assertSame( array( self::MODULE ), $callers );

		$code = self::code( self::MODULE );
		$this->assertStringContainsString( "array_column( CatalogSitemap::urls(), 'loc' )", $code );
		$this->assertStringContainsString( 'Features::is_enabled( Features::INDEXNOW ),', $code );
	}
}
