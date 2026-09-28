<?php
/**
 * The A2A Agent Card.
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\Adapters\A2A;

use AIHazirSite\Core\Catalog\CompanyProfile;

/**
 * Builds the AgentCard (A2A 1.0.0, proto3 JSON field names) from the company profile and the
 * skills that work on this site. One JSON-RPC interface, no streaming or push notifications.
 */
final class AgentCardBuilder {

	public const PROTOCOL_VERSION = '1.0';
	public const BINDING          = 'JSONRPC';

	/**
	 * The card.
	 *
	 * @param CompanyProfile $profile        Company profile.
	 * @param string         $site_name      Site name (when the profile has no name).
	 * @param string         $site_url       Site home URL.
	 * @param string         $endpoint       JSON-RPC endpoint URL.
	 * @param string         $plugin_version Card version (the plugin version).
	 * @param string[]       $skills         Offered skill ids (A2ASkills::*).
	 * @return array<string, mixed>
	 */
	public static function build( CompanyProfile $profile, string $site_name, string $site_url, string $endpoint, string $plugin_version, array $skills ): array {
		$name        = '' === $profile->name ? $site_name : $profile->name;
		$description = $name . ( '' === $profile->sector ? '' : ' (' . $profile->sector . ')' ) . ' firmasının AI agent\'ı: ilanlarda müsaitlik sorar, firmaya teklif isteği bırakır. Talepler otomatik onaylanmaz; son karar firmadaki insandadır.';
		return array(
			'name'                => $name . ' – AI Katalog agent',
			'description'         => $description,
			'supportedInterfaces' => array(
				array(
					'url'             => $endpoint,
					'protocolBinding' => self::BINDING,
					'protocolVersion' => self::PROTOCOL_VERSION,
				),
			),
			'provider'            => array(
				'organization' => $name,
				'url'          => $site_url,
			),
			'version'             => $plugin_version,
			'capabilities'        => array(
				'streaming'         => false,
				'pushNotifications' => false,
			),
			'defaultInputModes'   => array( 'application/json', 'text/plain' ),
			'defaultOutputModes'  => array( 'application/json' ),
			'skills'              => A2ASkills::definitions( $skills ),
		);
	}
}
