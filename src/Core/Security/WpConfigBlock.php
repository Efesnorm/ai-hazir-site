<?php
/**
 * Our marked block in wp-config.php (1.21.0).
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\Core\Security;

use ParseError;

/**
 * Pure text transforms of the configuration file: our lines live only between our two marker comments, right after the
 * opening `<?php` (before ABSPATH and wp-settings.php, as page-cache plugins do for WP_CACHE). Nothing outside the block
 * is ever changed. A result that is not valid PHP, or a constant the site already defines itself, means "do not write".
 */
final class WpConfigBlock {

	public const BEGIN = '# BEGIN AI Hazir Site';
	public const END   = '# END AI Hazir Site';

	/**
	 * Whether our block is present.
	 *
	 * @param string $source File content.
	 */
	public static function present( string $source ): bool {
		return str_contains( $source, self::BEGIN ) && str_contains( $source, self::END );
	}

	/**
	 * The file without our block (unchanged when there is none).
	 *
	 * @param string $source File content.
	 */
	public static function remove( string $source ): string {
		$pattern = '/\R?' . preg_quote( self::BEGIN, '/' ) . '.*?' . preg_quote( self::END, '/' ) . '[^\S\r\n]*/s';
		return (string) preg_replace( $pattern, '', $source, 1 );
	}

	/**
	 * Whether the site defines a constant itself, outside our block.
	 *
	 * @param string $source   File content.
	 * @param string $constant Constant name.
	 */
	public static function defined_outside( string $source, string $constant ): bool {
		return 1 === preg_match( '/define\s*\(\s*[\'"]' . preg_quote( $constant, '/' ) . '[\'"]/i', self::remove( $source ) );
	}

	/**
	 * The file with our block holding these lines (replacing an earlier block), or null when it must not be written:
	 * no opening `<?php`, or the result is not valid PHP.
	 *
	 * @param string   $source File content.
	 * @param string[] $lines  PHP lines for the block.
	 *
	 * @phpstan-param list<string> $lines
	 */
	public static function with( string $source, array $lines ): ?string {
		$clean = self::remove( $source );
		if ( 1 !== preg_match( '/^\s*<\?php[^\S\r\n]*\R/', $clean, $match ) ) {
			return null;
		}
		$eol    = str_contains( $source, "\r\n" ) ? "\r\n" : "\n";
		$block  = self::BEGIN . $eol . implode( $eol, $lines ) . $eol . self::END . $eol;
		$result = $match[0] . $block . substr( $clean, strlen( $match[0] ) );
		return self::valid( $result ) ? $result : null;
	}

	/**
	 * Whether a PHP source parses (PHP's own tokenizer in parse mode; nothing is executed).
	 *
	 * @param string $source PHP source.
	 */
	public static function valid( string $source ): bool {
		try {
			$tokens = token_get_all( $source, TOKEN_PARSE );
			return array() !== $tokens;
		} catch ( ParseError $e ) {
			return false;
		}
	}
}
