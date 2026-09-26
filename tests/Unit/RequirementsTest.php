<?php
/**
 * Tests for Requirements.
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\Tests\Unit;

use AIHazirSite\Core\Requirements;
use Brain\Monkey\Functions;

/**
 * Requirements unit tests.
 *
 * @covers \AIHazirSite\Core\Requirements
 */
final class RequirementsTest extends UnitTestCase {

	/**
	 * Supported versions boot the plugin and add no notice.
	 */
	public function test_supported_versions_boot_plugin(): void {
		$requirements = new Requirements( '8.1.0', '6.9' );
		$booted       = false;

		$result = $requirements->run(
			static function () use ( &$booted ): void {
				$booted = true;
			}
		);

		$this->assertTrue( $requirements->are_met() );
		$this->assertTrue( $result );
		$this->assertTrue( $booted );
		$this->assertFalse( has_action( 'admin_notices', array( $requirements, 'render_notice' ) ) );
	}

	/**
	 * PHP 8.0 shows an admin notice and the plugin does not boot.
	 */
	public function test_php_80_shows_notice_and_does_not_boot(): void {
		$requirements = new Requirements( '8.0.30', '6.9' );
		$booted       = false;

		$result = $requirements->run(
			static function () use ( &$booted ): void {
				$booted = true;
			}
		);

		$this->assertFalse( $requirements->is_php_supported() );
		$this->assertFalse( $result );
		$this->assertFalse( $booted );
		$this->assertNotFalse( has_action( 'admin_notices', array( $requirements, 'render_notice' ) ) );

		Functions\stubTranslationFunctions();
		Functions\stubEscapeFunctions();

		ob_start();
		$requirements->render_notice();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'notice-error', $html );
		$this->assertStringContainsString( 'PHP 8.1', $html );
		$this->assertStringContainsString( '8.0.30', $html );
	}

	/**
	 * An old WordPress version is rejected too.
	 */
	public function test_old_wordpress_is_rejected(): void {
		$requirements = new Requirements( '8.3.0', '6.8.2' );

		Functions\stubTranslationFunctions();

		$this->assertTrue( $requirements->is_php_supported() );
		$this->assertFalse( $requirements->is_wp_supported() );
		$this->assertCount( 1, $requirements->errors() );
	}

	/**
	 * The notice prints nothing when requirements are met.
	 */
	public function test_notice_is_empty_when_met(): void {
		$requirements = new Requirements( '8.3.0', '7.0' );

		ob_start();
		$requirements->render_notice();

		$this->assertSame( '', ob_get_clean() );
	}
}
