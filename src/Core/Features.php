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
	 * Portal mode: many businesses on one site (1.2.0).
	 */
	public const PORTAL_MODE = 'portal_mode';

	/**
	 * Updates from our update server, canary channels, rollback (1.3.0).
	 */
	public const REMOTE_UPDATES = 'remote_updates';

	/**
	 * Weekly summary to the report panel; also needs the site owner's explicit consent (1.3.0).
	 */
	public const TELEMETRY = 'telemetry';

	/**
	 * Need ↔ offer/supply matching (1.5.0).
	 */
	public const MATCHING = 'matching';

	/**
	 * A2A Agent Card and agent endpoint (1.6.0).
	 */
	public const A2A = 'a2a';

	/**
	 * The home page announces our AI resources (llms.txt, REST API) with standard links (1.7.0).
	 */
	public const DISCOVERY = 'discovery';

	/**
	 * Page-cache plugins do not serve cached pages to AI bots (WP Rocket, LiteSpeed Cache) (1.8.0).
	 */
	public const BOT_CACHE_BYPASS = 'bot_cache_bypass';

	/**
	 * The AI catalog in the site's XML sitemap (WordPress core, Rank Math, Yoast SEO) (1.8.0).
	 */
	public const CATALOG_SITEMAP = 'catalog_sitemap';

	/**
	 * Changed catalog pages are announced to search engines with IndexNow (1.10.0).
	 */
	public const INDEXNOW = 'indexnow';

	/**
	 * A LiteSpeed server's own page cache does not serve AI bots (.htaccess rule, no-store headers) (1.14.0).
	 */
	public const LITESPEED_SERVER_BYPASS = 'litespeed_server_bypass';

	/**
	 * Requests carrying the "AIHazirSite-Test/" User-Agent token are counted apart from real traffic (1.17.0).
	 */
	public const MEASUREMENT_TEST_FILTER = 'measurement_test_filter';

	/**
	 * Portal network: sites of one owner verify each other (mother/member) and say so to AI (1.20.0).
	 */
	public const PORTAL_NETWORK = 'portal_network';

	/**
	 * Security software screen: Imunify preset lock, Wordfence allow-list for network sites, guidance (1.21.0).
	 */
	public const SECURITY_INTEGRATIONS = 'security_integrations';

	/**
	 * Sector templates (0.8.0).
	 */
	public const TEMPLATES = 'templates';

	/**
	 * Approved exceptions to "disabled by default", each with a CHANGELOG rationale.
	 *
	 * - measurement: the "before" baseline must be collected from the moment the
	 *   plugin is installed; it stores only aggregated, non-personal counters.
	 * - measurement_test_filter (1.17.0): affects only requests carrying our own test token, never real
	 *   traffic; switched off by default it would let our tests blur the baseline it protects.
	 */
	public const DEFAULT_ON = array( self::MEASUREMENT, self::MEASUREMENT_TEST_FILTER );

	/**
	 * Features that need ALL of the listed features (1.9.0). Used by the settings screen, which only
	 * lets a feature be turned on when these are on and off when nothing on needs it. Runtime behavior
	 * does not depend on this table.
	 */
	public const REQUIRES = array(
		self::TEMPLATES         => array( self::CATALOG ),
		self::SCHEMA_OUTPUT     => array( self::CATALOG ),
		self::LLMS_TXT          => array( self::CATALOG ),
		self::REST_API          => array( self::CATALOG ),
		self::ABILITIES         => array( self::CATALOG ),
		self::INQUIRIES         => array( self::CATALOG ),
		self::MULTILINGUAL      => array( self::CATALOG ),
		self::PORTAL_MODE       => array( self::CATALOG ),
		self::MATCHING          => array( self::CATALOG ),
		self::A2A               => array( self::CATALOG ),
		self::MCP               => array( self::ABILITIES ),
		self::PORTAL_NETWORK    => array( self::REST_API ),
		self::COMPLIANCE_REPORT => array( self::COMPLIANCE_SCAN ),
	);

	/**
	 * Features that need AT LEAST ONE of the listed features (1.9.0).
	 */
	public const REQUIRES_ANY = array(
		self::DISCOVERY       => array( self::LLMS_TXT, self::REST_API ),
		self::CATALOG_SITEMAP => array( self::SCHEMA_OUTPUT, self::LLMS_TXT ),
		self::INDEXNOW        => array( self::SCHEMA_OUTPUT, self::LLMS_TXT ),
	);

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
			self::MEASUREMENT             => true,
			self::COMPLIANCE_SCAN         => false,
			self::CATALOG                 => false,
			self::BOT_ACCESS              => false,
			self::SCHEMA_OUTPUT           => false,
			self::LLMS_TXT                => false,
			self::TEMPLATES               => false,
			self::COMPLIANCE_WIZARD       => false,
			self::REST_API                => false,
			self::ABILITIES               => false,
			self::MCP                     => false,
			self::INQUIRIES               => false,
			self::COMPLIANCE_REPORT       => false,
			self::MULTILINGUAL            => false,
			self::PORTAL_MODE             => false,
			self::REMOTE_UPDATES          => false,
			self::TELEMETRY               => false,
			self::MATCHING                => false,
			self::A2A                     => false,
			self::DISCOVERY               => false,
			self::BOT_CACHE_BYPASS        => false,
			self::CATALOG_SITEMAP         => false,
			self::INDEXNOW                => false,
			self::LITESPEED_SERVER_BYPASS => false,
			self::MEASUREMENT_TEST_FILTER => true,
			self::PORTAL_NETWORK          => false,
			self::SECURITY_INTEGRATIONS   => false,
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
	 * What must be turned on before a feature can be: every missing "all" requirement, and the "any"
	 * list when none of it is on.
	 *
	 * @param string $key Feature key.
	 * @return array{all: list<string>, any: list<string>}
	 */
	public static function missing_requirements( string $key ): array {
		$all = array();
		foreach ( self::REQUIRES[ $key ] ?? array() as $required ) {
			if ( ! self::is_enabled( $required ) ) {
				$all[] = $required;
			}
		}
		$any = array();
		foreach ( self::REQUIRES_ANY[ $key ] ?? array() as $candidate ) {
			if ( self::is_enabled( $candidate ) ) {
				$any = array();
				break;
			}
			$any[] = $candidate;
		}
		return array(
			'all' => $all,
			'any' => $any,
		);
	}

	/**
	 * Features that are on and would lose a requirement if this one were turned off.
	 *
	 * @param string $key Feature key.
	 * @return list<string>
	 */
	public static function enabled_dependents( string $key ): array {
		$dependents = array();
		foreach ( self::REQUIRES as $dependent => $requirements ) {
			if ( in_array( $key, $requirements, true ) && self::is_enabled( $dependent ) ) {
				$dependents[] = $dependent;
			}
		}
		foreach ( self::REQUIRES_ANY as $dependent => $candidates ) {
			if ( ! in_array( $key, $candidates, true ) || ! self::is_enabled( $dependent ) ) {
				continue;
			}
			$others = array_filter( $candidates, static fn( string $candidate ): bool => $candidate !== $key && self::is_enabled( $candidate ) );
			if ( array() === $others ) {
				$dependents[] = $dependent;
			}
		}
		return $dependents;
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
