<?php
/**
 * Guards ADR-001: src/Core must not depend on WordPress (or any platform).
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\Tests\Unit\Architecture;

use PhpToken;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Scans src/Core for global function calls, constants and classes that PHP itself
 * does not provide. Anything else (get_option(), $wpdb, WP_Error, ABSPATH, ...)
 * belongs in a platform adapter such as src/WordPress.
 *
 * @coversNothing
 */
final class CoreIsPlatformNeutralTest extends TestCase {

	/**
	 * Tokens after which an identifier is a member, declaration or instantiation, not a global use.
	 */
	private const MEMBER_CONTEXT = array( T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION, T_CONST, T_NAMESPACE, T_USE, T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM, T_CASE );

	/**
	 * Every file under src/Core is platform-neutral.
	 */
	public function test_core_has_no_platform_dependencies(): void {
		$root       = dirname( __DIR__, 3 ) . '/src/Core';
		$violations = array();
		$files      = 0;

		foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root ) ) as $file ) {
			if ( 'php' !== $file->getExtension() ) {
				continue;
			}
			++$files;
			foreach ( self::violations( (string) file_get_contents( $file->getPathname() ) ) as $violation ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
				$violations[] = substr( $file->getPathname(), strlen( $root ) + 1 ) . ': ' . $violation;
			}
		}

		$this->assertGreaterThan( 15, $files, 'src/Core was scanned.' );
		$this->assertSame( array(), $violations, "Platform code in src/Core:\n" . implode( "\n", $violations ) );
	}

	/**
	 * Samples the scanner must reject.
	 *
	 * @return array<string, array{string, list<string>}>
	 */
	public static function forbidden(): array {
		return array(
			'WordPress function'         => array( '<?php $a = get_option( "x" );', array( 'get_option()' ) ),
			'fully qualified function'   => array( '<?php $a = \\wp_remote_get( "x" );', array( 'wp_remote_get()' ) ),
			'global $wpdb'               => array( '<?php function f() { global $wpdb; return $wpdb->prefix; }', array( 'global', '$wpdb', '$wpdb' ) ),
			'WordPress class'            => array( '<?php $e = new \\WP_Error( "x" );', array( 'WP_Error' ) ),
			'WordPress class in type'    => array( '<?php function f( WP_Post $p ) {}', array( 'WP_Post' ) ),
			'WordPress constant'         => array( '<?php $t = DAY_IN_SECONDS; $p = ABSPATH;', array( 'DAY_IN_SECONDS', 'ABSPATH' ) ),
			'some other platform helper' => array( '<?php shopify_helper();', array( 'shopify_helper()' ) ),
		);
	}

	/**
	 * The scanner catches platform dependencies.
	 *
	 * @dataProvider forbidden
	 *
	 * @param string   $code     PHP code.
	 * @param string[] $expected Violations.
	 */
	public function test_scanner_rejects_platform_code( string $code, array $expected ): void {
		$this->assertSame( $expected, self::violations( $code ) );
	}

	/**
	 * Plain PHP is accepted.
	 */
	public function test_scanner_accepts_plain_php(): void {
		$code = <<<'PHP'
<?php
namespace AIHazirSite\Core\Example;

use AIHazirSite\Core\Contracts\Settings;
use Throwable;

final class Example {
	public const MAX = 3;
	private const NAMES = array( 'a' );

	public function run( Settings $settings ): int {
		try {
			$value = $settings->get( 'x', PHP_INT_MAX );
			$other = self::MAX + strlen( (string) $value ) + ENT_QUOTES;
			$list  = array_map( static fn( int $i ): int => $i * 2, array( 1, 2 ) );
			return max( $other, count( $list ), Example::MAX, $this->get_option() );
		} catch ( Throwable ) {
			return match ( true ) { default => 0 };
		}
	}

	private function get_option(): int {
		return filter_var( '1', FILTER_VALIDATE_INT ) ?: 0;
	}
}
PHP;

		$this->assertSame( array(), self::violations( $code ) );
	}

	/**
	 * Platform dependencies found in PHP code.
	 *
	 * @param string $code PHP code.
	 * @return list<string>
	 */
	public static function violations( string $code ): array {
		static $internal_functions = null, $internal_constants = null;
		if ( null === $internal_functions ) {
			$internal_functions = array_flip( get_defined_functions()['internal'] );
			$internal_constants = array();
			foreach ( get_defined_constants( true ) as $category => $constants ) {
				if ( 'user' !== $category ) {
					$internal_constants += $constants;
				}
			}
		}

		$tokens = array_values(
			array_filter(
				PhpToken::tokenize( $code ),
				static fn( PhpToken $t ): bool => ! $t->is( array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT, T_OPEN_TAG, T_INLINE_HTML ) )
			)
		);

		$violations = array();
		foreach ( $tokens as $i => $token ) {
			if ( $token->is( T_GLOBAL ) ) {
				$violations[] = 'global';
				continue;
			}
			if ( $token->is( T_VARIABLE ) && '$wpdb' === $token->text ) {
				$violations[] = '$wpdb';
				continue;
			}
			if ( ! $token->is( array( T_STRING, T_NAME_FULLY_QUALIFIED ) ) ) {
				continue;
			}

			$previous = $tokens[ $i - 1 ] ?? null;
			$next     = $tokens[ $i + 1 ] ?? null;
			$name     = ltrim( $token->text, '\\' );

			if ( null !== $previous && $previous->is( self::MEMBER_CONTEXT ) ) {
				continue;
			}

			if ( str_starts_with( $name, 'WP_' ) || 'wpdb' === $name ) {
				$violations[] = $name;
			} elseif ( null !== $next && '(' === $next->text && ! ( null !== $previous && $previous->is( T_NEW ) ) ) {
				if ( ! isset( $internal_functions[ strtolower( $name ) ] ) ) {
					$violations[] = $name . '()';
				}
			} elseif ( preg_match( '/^[A-Z][A-Z0-9_]*$/', $name ) && ! isset( $internal_constants[ $name ] ) && ! in_array( strtolower( $name ), array( 'true', 'false', 'null' ), true ) ) {
				$is_class = null !== $next && $next->is( T_DOUBLE_COLON );
				if ( ! $is_class ) {
					$violations[] = $name;
				}
			}
		}

		return $violations;
	}
}
