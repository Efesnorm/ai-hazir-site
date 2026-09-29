<?php
/**
 * LiteSpeed server cache (without the LiteSpeed Cache plugin): no cached pages for AI bots.
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\WordPress\Integrations;

use AIHazirSite\Core\Features;
use AIHazirSite\Core\Integrations\BotTokens;
use AIHazirSite\Core\Integrations\UserAgentRewriteRule;
use AIHazirSite\Core\Measurement\Classifier;

/**
 * On a LiteSpeed server whose host caches pages without the LiteSpeed Cache plugin (found on intekarglobal.com),
 * AI bot requests were answered from the server cache and never reached WordPress (1.14.0):
 * - the rule of UserAgentRewriteRule goes at the top of the "WordPress" block of .htaccess through the core
 *   `mod_rewrite_rules` filter, written by WordPress's own save_mod_rewrite_rules() (before WordPress's [L] rules,
 *   where it would never run);
 * - responses to requests recognised as AI bots carry Cache-Control: private, no-store (RFC 9111) and
 *   X-LiteSpeed-Cache-Control: no-cache, so a cache layer that honours them does not store the bot's copy.
 * Turning off removes the rule the same way.
 */
final class LiteSpeedServerBypass {

	public const OPTION = 'aihs_litespeed_server';

	/**
	 * Whether the web server is LiteSpeed (filterable for tests and unusual setups).
	 */
	public static function detected(): bool {
		$software = isset( $_SERVER['SERVER_SOFTWARE'] ) && is_string( $_SERVER['SERVER_SOFTWARE'] ) ? sanitize_text_field( wp_unslash( $_SERVER['SERVER_SOFTWARE'] ) ) : '';
		return (bool) apply_filters( 'aihs_is_litespeed_server', false !== stripos( $software, 'litespeed' ) );
	}

	/**
	 * Hooks while the integration is on.
	 */
	public static function hook(): void {
		add_filter( 'mod_rewrite_rules', array( self::class, 'rules' ) );
		add_action( 'parse_request', array( self::class, 'bot_headers' ), 1 );
	}

	/**
	 * The rule lines for the AI bot list.
	 *
	 * @return list<string>
	 */
	public static function lines(): array {
		return UserAgentRewriteRule::lines( BotTokens::all() );
	}

	/**
	 * `mod_rewrite_rules`: our rule first in the WordPress block.
	 *
	 * @param mixed $rules WordPress's rules.
	 */
	public static function rules( mixed $rules ): string {
		return implode( "\n", self::lines() ) . "\n\n" . (string) $rules;
	}

	/**
	 * `parse_request`: no-store headers for AI bots (before our own pages are served at priority 20).
	 */
	public static function bot_headers(): void {
		$agent = isset( $_SERVER['HTTP_USER_AGENT'] ) && is_string( $_SERVER['HTTP_USER_AGENT'] ) ? (string) wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Only matched against the bot patterns.
		if ( headers_sent() || '' === $agent || null === Classifier::from_data()->match_bot( $agent ) ) {
			return;
		}
		header( 'Cache-Control: private, no-store' );
		header( 'X-LiteSpeed-Cache-Control: no-cache' );
	}

	/**
	 * Turns the rule on or off and rewrites .htaccess with WordPress's own function; the result is kept.
	 *
	 * @param bool $on New state.
	 * @return bool Whether .htaccess was written.
	 */
	public static function apply( bool $on ): bool {
		if ( $on ) {
			add_filter( 'mod_rewrite_rules', array( self::class, 'rules' ) );
		} else {
			remove_filter( 'mod_rewrite_rules', array( self::class, 'rules' ) );
		}
		if ( ! function_exists( 'save_mod_rewrite_rules' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
			require_once ABSPATH . 'wp-admin/includes/misc.php';
		}
		$written = (bool) save_mod_rewrite_rules();
		update_option(
			self::OPTION,
			array(
				'on'      => $on,
				'written' => $written,
				'at'      => time(),
			),
			false
		);
		return $written;
	}

	/**
	 * The last write: on/off, whether .htaccess was written, when; null before the first.
	 *
	 * @return array{on: bool, written: bool, at: int}|null
	 */
	public static function last(): ?array {
		$last = get_option( self::OPTION );
		return is_array( $last ) && isset( $last['on'], $last['written'], $last['at'] )
			? array(
				'on'      => (bool) $last['on'],
				'written' => (bool) $last['written'],
				'at'      => (int) $last['at'],
			)
			: null;
	}

	/**
	 * Whether the stored .htaccess currently contains our rule.
	 */
	public static function in_htaccess(): bool {
		if ( ! function_exists( 'get_home_path' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		$file = get_home_path() . '.htaccess';
		return is_readable( $file ) && str_contains( (string) file_get_contents( $file ), UserAgentRewriteRule::FIRST_LINE ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local file.
	}

	/**
	 * Whether the integration is on.
	 */
	public static function enabled(): bool {
		return Features::is_enabled( Features::LITESPEED_SERVER_BYPASS );
	}
}
