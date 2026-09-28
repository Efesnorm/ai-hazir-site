<?php
/**
 * Architecture: no outgoing A2A request without a person's approval (1.6.0).
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\Tests\Unit\Architecture;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * An ApprovedRequest is created only by the approval handler, and only that handler calls
 * A2AOutbox::send_approved(); the outbox's POST happens only inside send_approved().
 *
 * @coversNothing
 */
final class A2ASendsOnlyWithApprovalTest extends TestCase {

	private const HANDLER = 'WordPress/A2A/A2AAdmin.php';
	private const OUTBOX  = 'WordPress/A2A/A2AOutbox.php';

	/**
	 * Creation and sending only in the approval handler.
	 */
	public function test_only_the_approval_handler_sends(): void {
		$root       = dirname( __DIR__, 3 ) . '/src/';
		$violations = array();
		foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root ) ) as $file ) {
			if ( 'php' !== $file->getExtension() ) {
				continue;
			}
			$relative = str_replace( '\\', '/', substr( $file->getPathname(), strlen( $root ) ) );
			$code     = (string) file_get_contents( $file->getPathname() ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			if ( self::HANDLER !== $relative && 1 === preg_match( '/new\s+\\\\?(?:[A-Za-z_\\\\]+\\\\)?ApprovedRequest\s*\(/', $code ) ) {
				$violations[] = "{$relative}: new ApprovedRequest()";
			}
			if ( self::HANDLER !== $relative && isset( CatalogWritesOnlyThroughServiceTest::calls( $code, true )['send_approved'] ) ) {
				$violations[] = "{$relative}: send_approved()";
			}
		}
		$this->assertSame( array(), $violations, "A2A sending outside the approval handler:\n" . implode( "\n", $violations ) );
	}

	/**
	 * In the outbox, the POST is inside send_approved( ApprovedRequest ) and nowhere else.
	 */
	public function test_outbox_posts_only_approved(): void {
		$code = (string) file_get_contents( dirname( __DIR__, 3 ) . '/src/' . self::OUTBOX ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$this->assertSame( 1, substr_count( $code, 'wp_safe_remote_post(' ) );
		$start = strpos( $code, 'public static function send_approved( ApprovedRequest $approved )' );
		$this->assertNotFalse( $start );
		$this->assertGreaterThan( $start, strpos( $code, 'wp_safe_remote_post(' ) );
		$this->assertStringNotContainsString( 'wp_remote_post(', $code );
		$this->assertStringNotContainsString( 'wp_remote_request(', $code );
	}
}
