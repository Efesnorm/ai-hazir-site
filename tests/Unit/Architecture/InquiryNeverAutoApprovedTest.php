<?php
/**
 * No path approves an inquiry automatically; inquiries are written only through InquiryService.
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\Tests\Unit\Architecture;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Scans src/:
 * - the repository's write methods are called only by InquiryService;
 * - InquiryService::set_status() is called only by the admin screen (a person);
 * - the approved status (constant or literal) appears only in the Inquiry model, the service's
 *   status list and the admin screen.
 *
 * @coversNothing
 */
final class InquiryNeverAutoApprovedTest extends TestCase {

	private const SERVICE = 'Core/Inquiry/InquiryService.php';
	private const ADMIN   = 'WordPress/Inquiry/Admin/InquiryAdmin.php';

	/**
	 * Method → files allowed to call it.
	 */
	private const METHOD_CALLS = array(
		'store_inquiry'  => array( self::SERVICE ),
		'change_status'  => array( self::SERVICE ),
		'remove_inquiry' => array( self::SERVICE ),
		'set_status'     => array( self::ADMIN ),
	);

	/**
	 * Files that may name the approved status.
	 */
	private const APPROVED_FILES = array( 'Core/Inquiry/Inquiry.php', self::ADMIN );

	/**
	 * Scans.
	 */
	public function test_no_automatic_approval(): void {
		$root       = dirname( __DIR__, 3 ) . '/src/';
		$violations = array();
		foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root ) ) as $file ) {
			if ( 'php' !== $file->getExtension() ) {
				continue;
			}
			$relative = str_replace( '\\', '/', substr( $file->getPathname(), strlen( $root ) ) );
			$code     = (string) file_get_contents( $file->getPathname() ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

			foreach ( CatalogWritesOnlyThroughServiceTest::calls( $code, true ) as $method => $count ) {
				if ( isset( self::METHOD_CALLS[ $method ] ) && ! in_array( $relative, self::METHOD_CALLS[ $method ], true ) ) {
					$violations[] = $relative . ': ->' . $method . '()';
				}
			}
			if ( ! in_array( $relative, self::APPROVED_FILES, true ) && ( str_contains( $code, 'STATUS_APPROVED' ) || preg_match( "/['\"]approved['\"]/", $code ) ) ) {
				$violations[] = $relative . ': approved';
			}
		}

		$this->assertSame( array(), $violations );
	}
}
