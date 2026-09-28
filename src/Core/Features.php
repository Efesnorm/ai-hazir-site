<?php
/**
 * Feature flags.
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\Core;

use AIHazirSite\Core\Contracts\Settings;
use LogicException;

/**
 * Every user-facing feature is tied to a key here and ships disabled by default,
 * except the keys listed in {@see Features::DEFAULT_ON}.
 *
 * Stored values live in the `aihs_features` option as `array<string, bool>`.
 * Keys that are not declared in {@see Features::defaults()} are always disabled,
 * even if the option contains them.
 */
final class Features {

	/**
	 * Option name that stores feature flag overrides.
	 */
	public const OPTION = 'aihs_features';

	/**
	 * AI bot and referral measurement (0.2.0).
	 */
	public const MEASUREMENT = 'measurement';

	/**
	 * AI compliance scan and score (0.3.0).
	 */
	public const COMPLIANCE_SCAN = 'compliance_scan';

	/**
	 * AI catalog: listings and company profile (0.4.0).
	 */
	public const CATALOG = 'catalog';

	/**
	 * AI bot access rules in robots.txt (0.5.0).
	 */
	public const BOT_ACCESS = 'bot_access';

	/**
	 * Schema.org JSON-LD output (0.6.0).
	 */
	public const SCHEMA_OUTPUT = 'schema_output';

	/**
	 * The llms.txt file and the AI catalog page (0.7.0).
	 */
	public const LLMS_TXT = 'llms_txt';

	/**
	 * Compliance wizard (0.9.0).
	 */
	public const COMPLIANCE_WIZARD = 'compliance_wizard';

	/**
	 * Read-only REST API (0.10.0).
	 */
	public const REST_API = 'rest_api';

	/**
	 * Catalog abilities (WordPress Abilities API) (0.11.0).
	 */
	public const ABILITIES = 'abilities';

	/**
	 * MCP server over the catalog abilities (0.11.0).
	 */
	public const MCP = 'mcp';

	/**
	 * Inquiry box (0.12.0).
	 */
	public const INQUIRIES = 'inquiries';

	/**
	 * Compliance report and badge (1.0.0).
	 */
	public const COMPLIANCE_REPORT = 'compliance_report';

	/**
	 * Multilingual catalog output (1.1.0).
	 */
	public const MULTILINGUAL = 'multilingual';

	/**
	 * Sector templates (0.8.0).
	 */
	public const TEMPLATES = 'templates';

	/**
	 * Approved exceptions to "disabled by default", each with a CHANGELOG rationale.
	 *
	 * - measurement: the "before" baseline must be collected from the moment the
	 *   plugin is installed; it stores only aggregated, non-personal counters.
	 */
	public const DEFAULT_ON = array( self::MEASUREMENT );

	/**
	 * Storage, set once by the platform at boot.
	 *
	 * @var Settings|null
	 */
	private static ?Settings $settings = null;

	/**
	 * Sets where feature states are stored (called by the platform adapter at boot).
	 *
	 * @param Settings $settings Settings.
	 */
	public static function use_settings( Settings $settings ): void {
		self::$settings = $settings;
	}

	/**
	 * Declared feature keys and their default state.
	 *
	 * @return array<string, bool>
	 */
	public static function defaults(): array {
		return array(
			self::MEASUREMENT       => true,
			self::COMPLIANCE_SCAN   => false,
			self::CATALOG           => false,
			self::BOT_ACCESS        => false,
			self::SCHEMA_OUTPUT     => false,
			self::LLMS_TXT          => false,
			self::TEMPLATES         => false,
			self::COMPLIANCE_WIZARD => false,
			self::REST_API          => false,
			self::ABILITIES         => false,
			self::MCP               => false,
			self::INQUIRIES         => false,
			self::COMPLIANCE_REPORT => false,
			self::MULTILINGUAL      => false,
		);
	}

	/**
	 * Whether a feature is enabled.
	 *
	 * @param string $key Feature key.
	 */
	public static function is_enabled( string $key ): bool {
		$defaults = self::defaults();
		if ( ! array_key_exists( $key, $defaults ) ) {
			return false;
		}

		$stored = self::settings()->get( self::OPTION, array() );
		if ( is_array( $stored ) && array_key_exists( $key, $stored ) ) {
			return (bool) $stored[ $key ];
		}

		return $defaults[ $key ];
	}

	/**
	 * Turns a declared feature on or off.
	 *
	 * @param string $key     Feature key.
	 * @param bool   $enabled New state.
	 * @return bool False when the key is not declared.
	 */
	public static function set( string $key, bool $enabled ): bool {
		if ( ! array_key_exists( $key, self::defaults() ) ) {
			return false;
		}

		$stored         = self::settings()->get( self::OPTION, array() );
		$stored         = is_array( $stored ) ? $stored : array();
		$stored[ $key ] = $enabled;
		self::settings()->set( self::OPTION, $stored, true );

		return true;
	}

	/**
	 * The stored override of a feature (null when the default applies).
	 *
	 * @param string $key Feature key.
	 */
	public static function stored( string $key ): ?bool {
		$stored = self::settings()->get( self::OPTION, array() );
		return is_array( $stored ) && array_key_exists( $key, $stored ) ? (bool) $stored[ $key ] : null;
	}

	/**
	 * Puts back an override read earlier with stored(); null removes it (the default applies again).
	 * The option itself is removed when no override is left.
	 *
	 * @param string    $key    Feature key.
	 * @param bool|null $stored Earlier override.
	 */
	public static function restore( string $key, ?bool $stored ): void {
		$overrides = self::settings()->get( self::OPTION, array() );
		$overrides = is_array( $overrides ) ? $overrides : array();
		if ( null === $stored ) {
			unset( $overrides[ $key ] );
		} else {
			$overrides[ $key ] = $stored;
		}
		if ( array() === $overrides ) {
			self::settings()->delete( self::OPTION );
			return;
		}
		self::settings()->set( self::OPTION, $overrides, true );
	}

	/**
	 * Configured storage.
	 *
	 * @throws LogicException When use_settings() was not called.
	 */
	private static function settings(): Settings {
		if ( null === self::$settings ) {
			throw new LogicException( 'Features::use_settings() must be called before use.' );
		}
		return self::$settings;
	}
}
