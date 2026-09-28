<?php
/**
 * The weekly summary a site may send to the report panel.
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\Core\Telemetry;

use AIHazirSite\Core\Compliance\ScoreReport;

/**
 * Totals only (data minimisation): no records, addresses, e-mails, IPs, listing texts or URLs.
 * FIELDS is the complete list; the consent screen shows exactly these keys.
 */
final class TelemetrySummary {

	/**
	 * Every key that is sent (besides site_id and sent_at added by the service).
	 */
	public const FIELDS = array( 'score', 'score_version', 'ai_bot_reads_7d', 'ai_bot_reads_verified_7d', 'inquiries_7d', 'plugin_version', 'wp_version' );

	/**
	 * The summary.
	 *
	 * @param ScoreReport|null $latest         Latest compliance scan, or null.
	 * @param int              $bot_reads      AI bot reads in the last 7 days.
	 * @param int              $verified_reads Verified ones among them.
	 * @param int              $inquiries      Inquiries in the last 7 days.
	 * @param string           $plugin_version Plugin version.
	 * @param string           $wp_version     Platform version.
	 * @return array{score: int|null, score_version: int|null, ai_bot_reads_7d: int, ai_bot_reads_verified_7d: int, inquiries_7d: int, plugin_version: string, wp_version: string}
	 */
	public static function build( ?ScoreReport $latest, int $bot_reads, int $verified_reads, int $inquiries, string $plugin_version, string $wp_version ): array {
		return array(
			'score'                    => $latest?->score(),
			'score_version'            => $latest?->score_version,
			'ai_bot_reads_7d'          => max( 0, $bot_reads ),
			'ai_bot_reads_verified_7d' => max( 0, min( $verified_reads, $bot_reads ) ),
			'inquiries_7d'             => max( 0, $inquiries ),
			'plugin_version'           => $plugin_version,
			'wp_version'               => $wp_version,
		);
	}
}
