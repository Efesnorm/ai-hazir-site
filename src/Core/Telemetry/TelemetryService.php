<?php
/**
 * Consent and sending of the report panel summary.
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\Core\Telemetry;

use AIHazirSite\Core\Contracts\Clock;
use AIHazirSite\Core\Contracts\HttpPoster;
use AIHazirSite\Core\Contracts\Settings;

/**
 * Nothing is sent unless the feature is on, an endpoint is configured AND the site owner gave
 * consent to the current notice. Consent is stored with its time, the notice version and a
 * random site id (not derived from the site address); revoking deletes all three.
 */
final class TelemetryService {

	public const OPTION = 'aihs_telemetry_consent';

	/**
	 * Version of the consent notice; a new notice (e.g. more fields) needs new consent.
	 */
	public const NOTICE_VERSION = 1;

	/**
	 * Constructor.
	 *
	 * @param Settings   $settings Storage.
	 * @param HttpPoster $poster   Outgoing POST.
	 * @param Clock      $clock    Time.
	 */
	public function __construct(
		private readonly Settings $settings,
		private readonly HttpPoster $poster,
		private readonly Clock $clock
	) {
	}

	/**
	 * Valid consent for the current notice, or null.
	 *
	 * @return array{given_at: string, notice_version: int, site_id: string}|null
	 */
	public function consent(): ?array {
		$stored = $this->settings->get( self::OPTION, null );
		if ( ! is_array( $stored ) || self::NOTICE_VERSION !== ( $stored['notice_version'] ?? null ) || ! is_string( $stored['site_id'] ?? null ) || ! is_string( $stored['given_at'] ?? null ) ) {
			return null;
		}
		return array(
			'given_at'       => $stored['given_at'],
			'notice_version' => self::NOTICE_VERSION,
			'site_id'        => $stored['site_id'],
		);
	}

	/**
	 * Records consent to the current notice.
	 */
	public function give_consent(): void {
		$this->settings->set(
			self::OPTION,
			array(
				'given_at'       => gmdate( 'Y-m-d\TH:i:s\Z', $this->clock->now() ),
				'notice_version' => self::NOTICE_VERSION,
				'site_id'        => bin2hex( random_bytes( 16 ) ),
			),
			false
		);
	}

	/**
	 * Withdraws consent (sending stops at once).
	 */
	public function revoke(): void {
		$this->settings->delete( self::OPTION );
	}

	/**
	 * The exact body that would be sent.
	 *
	 * @param array<string, mixed> $summary TelemetrySummary::build() result.
	 * @return array<string, mixed>
	 */
	public function payload( array $summary ): array {
		$consent = $this->consent();
		return array_merge(
			array(
				'site_id' => $consent['site_id'] ?? '',
				'sent_at' => gmdate( 'Y-m-d\TH:i:s\Z', $this->clock->now() ),
			),
			array_intersect_key( $summary, array_flip( TelemetrySummary::FIELDS ) )
		);
	}

	/**
	 * Sends the summary when allowed; returns whether it was sent.
	 *
	 * @param bool                 $enabled  Whether the telemetry feature is on.
	 * @param string               $endpoint Report panel URL ('' = none configured).
	 * @param array<string, mixed> $summary  TelemetrySummary::build() result.
	 */
	public function send( bool $enabled, string $endpoint, array $summary ): bool {
		if ( ! $enabled || ! str_starts_with( $endpoint, 'https://' ) || null === $this->consent() ) {
			return false;
		}
		return $this->poster->post( $endpoint, (string) json_encode( $this->payload( $summary ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Platform-neutral core.
	}
}
