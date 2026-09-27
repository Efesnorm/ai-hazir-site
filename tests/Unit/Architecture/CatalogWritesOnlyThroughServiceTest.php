<?php
/**
 * Catalog data is written only through CatalogService.
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
 * Scans src/ for (1) calls to the repositories' write methods and (2) WordPress post-writing
 * functions, and allows them only in the files that own them.
 *
 * @coversNothing
 */
final class CatalogWritesOnlyThroughServiceTest extends TestCase {

	/**
	 * Repository write methods → files allowed to call them.
	 */
	private const METHOD_CALLS = array(
		'store_listing'   => array( 'Core/Catalog/CatalogService.php' ),
		'remove_listing'  => array( 'Core/Catalog/CatalogService.php' ),
		'store_profile'   => array( 'Core/Catalog/CatalogService.php' ),
		'remove_profile'  => array( 'Core/Catalog/CatalogService.php' ),
		'restore_profile' => array( 'Core/Catalog/CatalogService.php' ),
	);

	/**
	 * WordPress functions that write posts or post meta → files allowed to call them.
	 */
	private const WP_WRITES = array( 'wp_insert_post', 'wp_update_post', 'wp_delete_post', 'wp_trash_post', 'add_post_meta', 'update_post_meta', 'delete_post_meta', 'update_metadata', 'delete_metadata' );

	private const WP_WRITERS = array( 'WordPress/Catalog/WpListingRepository.php' );

	/**
	 * Calls found in PHP code: name → count, for method calls ("->name(") or function calls ("name(").
	 *
	 * @param string $code   PHP code.
	 * @param bool   $method True for method calls, false for function calls.
	 * @return array<string, int>
	 */
	public static function calls( string $code, bool $method ): array {
		$tokens = array_values( array_filter( PhpToken::tokenize( $code ), static fn( PhpToken $t ): bool => ! $t->is( array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ) ) ) );
		$found  = array();
		foreach ( $tokens as $i => $token ) {
			if ( ! $token->is( array( T_STRING, T_NAME_FULLY_QUALIFIED ) ) || '(' !== ( $tokens[ $i + 1 ]->text ?? '' ) ) {
				continue;
			}
			$previous  = $tokens[ $i - 1 ] ?? null;
			$is_method = null !== $previous && $previous->is( array( T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON ) );
			$is_decl   = null !== $previous && $previous->is( T_FUNCTION );
			if ( $is_decl || $is_method !== $method ) {
				continue;
			}
			$name           = strtolower( ltrim( $token->text, '\\' ) );
			$found[ $name ] = ( $found[ $name ] ?? 0 ) + 1;
		}
		return $found;
	}

	/**
	 * No file other than the owners writes catalog data.
	 */
	public function test_only_owners_write(): void {
		$root       = dirname( __DIR__, 3 ) . '/src/';
		$violations = array();

		foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root ) ) as $file ) {
			if ( 'php' !== $file->getExtension() ) {
				continue;
			}
			$relative = str_replace( '\\', '/', substr( $file->getPathname(), strlen( $root ) ) );
			$code     = (string) file_get_contents( $file->getPathname() ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

			foreach ( self::calls( $code, true ) as $name => $count ) {
				if ( isset( self::METHOD_CALLS[ $name ] ) && ! in_array( $relative, self::METHOD_CALLS[ $name ], true ) ) {
					$violations[] = "{$relative}: ->{$name}()";
				}
			}
			foreach ( self::calls( $code, false ) as $name => $count ) {
				if ( in_array( $name, self::WP_WRITES, true ) && ! in_array( $relative, self::WP_WRITERS, true ) ) {
					$violations[] = "{$relative}: {$name}()";
				}
			}
		}

		$this->assertSame( array(), $violations, "Catalog writes outside CatalogService:\n" . implode( "\n", $violations ) );
	}

	/**
	 * The scanner itself recognizes method and function calls, and ignores declarations.
	 */
	public function test_scanner(): void {
		$code = '<?php function store_listing() {} $repo->store_listing( $x ); Foo::remove_listing(1); \\wp_insert_post( array() ); update_post_meta( 1, "a", 2 ); $o->update_post_meta();';

		$this->assertSame(
			array(
				'store_listing'    => 1,
				'remove_listing'   => 1,
				'update_post_meta' => 1,
			),
			self::calls( $code, true )
		);
		$this->assertSame(
			array(
				'wp_insert_post'   => 1,
				'update_post_meta' => 1,
			),
			self::calls( $code, false )
		);
	}
}
