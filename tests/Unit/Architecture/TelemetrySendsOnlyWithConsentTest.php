<?php
/**
 * Architecture: outgoing POSTs only from TelemetryService (1.3.0).
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\Tests\Unit\Architecture;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Data can leave the site only through TelemetryService::send(), which checks the feature, the
 * endpoint and the owner's consent first. No other file calls ->post( or WordPress's POST functions.
 * 1.10.0 (approved): IndexNowService is the second allowed sender; it sends only the public catalog
 * URLs while `indexnow` is on (IndexNowSendsOnlyPublicUrlsTest), through ->post_json( (HttpStatusPoster),
 * which this test also covers.
 *
 * @coversNothing
 */
final class TelemetrySendsOnlyWithConsentTest extends TestCase {

	/**
	 * Outgoing POST functions of WordPress.
	 */
	private const WP_POSTS = array( 'wp_remote_post', 'wp_safe_remote_post' );

	/**
	 * Allowed callers.
	 */
	private const POSTERS = array( 'Core/Telemetry/TelemetryService.php', 'Core/IndexNow/IndexNowService.php' );

	/**
	 * The only HttpPoster implementation, and the A2A outbox (1.6.0: sends only ApprovedRequest, guarded
	 * by A2ASendsOnlyWithApprovalTest).
	 */
	private const WP_POSTERS = array( 'WordPress/Platform/WpHttpPoster.php', 'WordPress/A2A/A2AOutbox.php' );

	/**
	 * No other file posts.
	 */
	public function test_only_the_service_posts(): void {
		$root       = dirname( __DIR__, 3 ) . '/src/';
		$violations = array();

		foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root ) ) as $file ) {
			if ( 'php' !== $file->getExtension() ) {
				continue;
			}
			$relative = str_replace( '\\', '/', substr( $file->getPathname(), strlen( $root ) ) );
			$code     = (string) file_get_contents( $file->getPathname() ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

			foreach ( array( 'post', 'post_json' ) as $method ) {
				if ( isset( CatalogWritesOnlyThroughServiceTest::calls( $code, true )[ $method ] ) && ! in_array( $relative, self::POSTERS, true ) ) {
					$violations[] = "{$relative}: ->{$method}()";
				}
			}
			foreach ( CatalogWritesOnlyThroughServiceTest::calls( $code, false ) as $name => $count ) {
				if ( in_array( $name, self::WP_POSTS, true ) && ! in_array( $relative, self::WP_POSTERS, true ) ) {
					$violations[] = "{$relative}: {$name}()";
				}
			}
		}

		$this->assertSame( array(), $violations, "Outgoing POST outside TelemetryService:\n" . implode( "\n", $violations ) );
	}
}
