<?php
/**
 * Architecture: portal data is written only through PortalService (1.2.0).
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\Tests\Unit\Architecture;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Business and listing-link writes are called from PortalService only, so a business user can
 * never reach another business's data by another path.
 *
 * @coversNothing
 */
final class PortalWritesOnlyThroughServiceTest extends TestCase {

	/**
	 * Write method → files allowed to call it.
	 */
	private const METHOD_CALLS = array(
		'store_business'         => array( 'Core/Portal/PortalService.php' ),
		'remove_business'        => array( 'Core/Portal/PortalService.php' ),
		'store_listing_business' => array( 'Core/Portal/PortalService.php' ),
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

		$this->assertSame( array(), $violations, "Portal writes outside PortalService:\n" . implode( "\n", $violations ) );
	}
}
