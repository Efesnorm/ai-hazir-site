<?php
/**
 * Architecture: our public outputs tell page caches not to store them (1.6.1).
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\Tests\Unit\Architecture;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Every response we serve ourselves (status_header( 200 ) then exit: /ai-katalog/, business pages,
 * /llms.txt, the verification page, the A2A card) calls PageCache::exclude() right before it.
 * A live site with WP Rocket kept serving a stale /ai-katalog/ without it.
 *
 * @coversNothing
 */
final class PublicPagesNotCachedTest extends TestCase {

	/**
	 * PageCache::exclude() on the line before each status_header( 200 ).
	 */
	public function test_every_served_page_excludes_page_caches(): void {
		$root       = dirname( __DIR__, 3 ) . '/src/';
		$served     = array();
		$violations = array();
		foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root ) ) as $file ) {
			if ( 'php' !== $file->getExtension() ) {
				continue;
			}
			$relative = str_replace( '\\', '/', substr( $file->getPathname(), strlen( $root ) ) );
			$lines    = array_map( 'trim', (array) file( $file->getPathname() ) );
			foreach ( $lines as $i => $line ) {
				if ( str_starts_with( $line, 'status_header( 200 )' ) ) {
					$served[] = $relative;
					if ( 'PageCache::exclude();' !== ( $lines[ $i - 1 ] ?? '' ) ) {
						$violations[] = $relative . ':' . ( $i + 1 );
					}
				}
			}
		}

		$this->assertSame( array(), $violations, "Served without PageCache::exclude():\n" . implode( "\n", $violations ) );
		sort( $served );
		$this->assertSame(
			array( 'WordPress/A2A/A2AModule.php', 'WordPress/IndexNow/IndexNowModule.php', 'WordPress/Integrations/CatalogSitemap.php', 'WordPress/Llms/LlmsModule.php', 'WordPress/Portal/BusinessPage.php', 'WordPress/Report/BadgeModule.php', 'WordPress/Schema/CatalogPage.php' ),
			$served
		);
	}
}
