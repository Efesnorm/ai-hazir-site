<?php
/**
 * Our marked block in wp-config.php (1.21.0).
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\Tests\Unit\Security;

use AIHazirSite\Core\Security\WpConfigBlock;
use PHPUnit\Framework\TestCase;

/**
 * Nothing outside the block changes; idempotent; refuses to produce invalid PHP.
 *
 * @covers \AIHazirSite\Core\Security\WpConfigBlock
 */
final class WpConfigBlockTest extends TestCase {

	private const CONFIG = "<?php\n/** DB */\ndefine( 'DB_NAME', 'wp' );\n\$table_prefix = 'wp_';\nif ( ! defined( 'ABSPATH' ) ) {\n\tdefine( 'ABSPATH', __DIR__ . '/' );\n}\nrequire_once ABSPATH . 'wp-settings.php';\n";

	private const LINE = "define( 'IMUNIFY_AI_BOT_PROTECTION_PRESET', 'balanced' );";

	/**
	 * Added right after <?php, before ABSPATH; removed back to the exact original.
	 */
	public function test_add_and_remove(): void {
		$with = WpConfigBlock::with( self::CONFIG, array( self::LINE ) );
		$this->assertNotNull( $with );
		$this->assertStringStartsWith( "<?php\n" . WpConfigBlock::BEGIN . "\n" . self::LINE . "\n" . WpConfigBlock::END . "\n/** DB */", $with );
		$this->assertLessThan( strpos( $with, 'ABSPATH' ), strpos( $with, self::LINE ) );
		$this->assertTrue( WpConfigBlock::present( $with ) );
		$this->assertTrue( WpConfigBlock::valid( $with ) );
		$this->assertSame( self::CONFIG, WpConfigBlock::remove( $with ) );
	}

	/**
	 * Writing twice keeps one block; Windows line endings are kept.
	 */
	public function test_idempotent_and_line_endings(): void {
		$once  = (string) WpConfigBlock::with( self::CONFIG, array( self::LINE ) );
		$twice = (string) WpConfigBlock::with( $once, array( self::LINE ) );
		$this->assertSame( $once, $twice );
		$this->assertSame( 1, substr_count( $twice, WpConfigBlock::BEGIN ) );

		$crlf = str_replace( "\n", "\r\n", self::CONFIG );
		$with = (string) WpConfigBlock::with( $crlf, array( self::LINE ) );
		$this->assertStringContainsString( WpConfigBlock::BEGIN . "\r\n" . self::LINE . "\r\n", $with );
		$this->assertSame( $crlf, WpConfigBlock::remove( $with ) );
	}

	/**
	 * A constant the site sets itself is detected (outside our block only).
	 */
	public function test_defined_outside(): void {
		$own = str_replace( "<?php\n", "<?php\ndefine( \"IMUNIFY_AI_BOT_PROTECTION_PRESET\", 'strict' );\n", self::CONFIG );
		$this->assertTrue( WpConfigBlock::defined_outside( $own, 'IMUNIFY_AI_BOT_PROTECTION_PRESET' ) );
		$ours = (string) WpConfigBlock::with( self::CONFIG, array( self::LINE ) );
		$this->assertFalse( WpConfigBlock::defined_outside( $ours, 'IMUNIFY_AI_BOT_PROTECTION_PRESET' ) );
	}

	/**
	 * No opening tag, or a result that would not parse: nothing to write.
	 */
	public function test_refuses(): void {
		$this->assertNull( WpConfigBlock::with( "define( 'A', 1 );\n", array( self::LINE ) ) );
		$this->assertNull( WpConfigBlock::with( self::CONFIG, array( "define( 'BROKEN'" ) ) );
		$this->assertFalse( WpConfigBlock::valid( "<?php\nif ( {\n" ) );
		$this->assertSame( self::CONFIG, WpConfigBlock::remove( self::CONFIG ), 'No block: unchanged.' );
	}
}
