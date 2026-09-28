<?php
/**
 * Architecture: catalog translations are written only through CatalogService (1.1.0).
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\Tests\Unit\Architecture;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * The translation repositories' write methods are called from CatalogService only, so every
 * translation is validated (language, field, length) before it is stored.
 *
 * @coversNothing
 */
final class TranslationWritesOnlyThroughServiceTest extends TestCase {

	/**
	 * Write method → files allowed to call it.
	 */
	private const METHOD_CALLS = array(
		'store_translations'         => array( 'Core/Catalog/CatalogService.php' ),
		'store_profile_translations' => array( 'Core/Catalog/CatalogService.php' ),
	);

	/**
	 * No other file calls the write methods.
	 */
	public function test_only_the_service_writes(): void {
		$root       = dirname( __DIR__, 3 ) . '/src/';
		$violations = array();

		foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root ) ) as $file ) {
			if ( 'php' !== $file->getExtension() ) {
				continue;
			}
			$relative = str_replace( '\\', '/', substr( $file->getPathname(), strlen( $root ) ) );
			$code     = (string) file_get_contents( $file->getPathname() ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

			foreach ( CatalogWritesOnlyThroughServiceTest::calls( $code, true ) as $name => $count ) {
				if ( isset( self::METHOD_CALLS[ $name ] ) && ! in_array( $relative, self::METHOD_CALLS[ $name ], true ) ) {
					$violations[] = "{$relative}: ->{$name}()";
				}
			}
		}

		$this->assertSame( array(), $violations, "Translation writes outside CatalogService:\n" . implode( "\n", $violations ) );
	}
}
