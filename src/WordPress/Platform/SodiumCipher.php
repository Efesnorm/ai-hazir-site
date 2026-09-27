<?php
/**
 * Encryption at rest for personal data.
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\WordPress\Platform;

/**
 * Uses libsodium secretbox (XSalsa20-Poly1305; bundled with PHP 7.2+, and with WordPress as sodium_compat).
 * The key is derived from the site's own secret salt, so a database dump alone does not reveal the
 * contact data. If the salts are changed, earlier values can no longer be decrypted (decrypt() → null).
 */
final class SodiumCipher {

	/**
	 * Encrypts a string (base64 of nonce + ciphertext).
	 *
	 * @param string $plain Plain text.
	 */
	public static function encrypt( string $plain ): string {
		$nonce = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		return base64_encode( $nonce . sodium_crypto_secretbox( $plain, $nonce, self::key() ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Binary ciphertext stored as text.
	}

	/**
	 * Decrypts; null when the value was not made with this site's key.
	 *
	 * @param string $stored Stored value.
	 */
	public static function decrypt( string $stored ): ?string {
		$raw = base64_decode( $stored, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Binary ciphertext stored as text.
		if ( false === $raw || strlen( $raw ) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ) {
			return null;
		}
		try {
			$plain = sodium_crypto_secretbox_open( substr( $raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ), substr( $raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ), self::key() );
		} catch ( \SodiumException $e ) {
			return null;
		}
		return false === $plain ? null : $plain;
	}

	/**
	 * 32-byte key derived from the site's auth salt.
	 */
	private static function key(): string {
		return sodium_crypto_generichash( 'aihs-inquiry-contact|' . wp_salt( 'auth' ), '', SODIUM_CRYPTO_SECRETBOX_KEYBYTES );
	}
}
