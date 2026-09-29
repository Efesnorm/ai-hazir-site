<?php
/**
 * Recommended feature sets by site type.
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\Core\Setup;

use AIHazirSite\Core\Features;

/**
 * The PRD pilot network table ("AI'a açılacak", "yazma izni") as feature sets (1.11.0). Each list is in
 * requirement order (Features::REQUIRES / REQUIRES_ANY), so turning them on one by one never meets a
 * missing requirement. The quote box is never in a profile (personal data: it is turned on on its own
 * screen after the privacy notice); matching neither (only for sites with needs).
 */
final class SetupProfiles {

	public const PRODUCT = 'urun';
	public const EXPORT  = 'ihracat';
	public const TOUR    = 'tur';
	public const PORTAL  = 'portal';
	public const SERVICE = 'hizmet';

	/**
	 * Read-only base: measured, understandable and queryable by AI; nothing written to the site.
	 */
	private const READ = array(
		Features::MEASUREMENT,
		Features::COMPLIANCE_SCAN,
		Features::COMPLIANCE_REPORT,
		Features::BOT_ACCESS,
		Features::CATALOG,
		Features::TEMPLATES,
		Features::SCHEMA_OUTPUT,
		Features::LLMS_TXT,
		Features::REST_API,
		Features::ABILITIES,
		Features::MCP,
		Features::DISCOVERY,
		Features::BOT_CACHE_BYPASS,
		Features::CATALOG_SITEMAP,
		Features::INDEXNOW,
	);

	/**
	 * Profiles: id → features in requirement order.
	 *
	 * @return array<string, list<string>>
	 */
	public static function all(): array {
		$agent = array_merge( self::READ, array( Features::A2A ) );
		return array(
			self::PRODUCT => $agent,
			self::EXPORT  => array_merge( $agent, array( Features::MULTILINGUAL ) ),
			self::TOUR    => $agent,
			self::PORTAL  => array_merge( $agent, array( Features::PORTAL_MODE ) ),
			self::SERVICE => self::READ,
		);
	}

	/**
	 * The features a profile would turn on now (those already on are left out), in order.
	 *
	 * @param string $profile Profile id.
	 * @return list<string> Empty for an unknown profile.
	 */
	public static function plan( string $profile ): array {
		$plan = array();
		foreach ( self::all()[ $profile ] ?? array() as $key ) {
			if ( ! Features::is_enabled( $key ) ) {
				$plan[] = $key;
			}
		}
		return $plan;
	}
}
