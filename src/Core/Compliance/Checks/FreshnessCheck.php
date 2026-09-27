<?php
/**
 * Freshness check.
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\Core\Compliance\Checks;

use AIHazirSite\Core\Compliance\Check;
use AIHazirSite\Core\Compliance\CheckResult;
use AIHazirSite\Core\Compliance\Html;
use AIHazirSite\Core\Compliance\Site;

/**
 * Structured data states when it was updated or until when it is valid.
 */
final class FreshnessCheck implements Check {

	public const DATE_PROPERTIES = array( 'dateModified', 'priceValidUntil', 'validThrough' );

	/**
	 * Id.
	 */
	public function id(): string {
		return 'freshness';
	}

	/**
	 * Weight.
	 */
	public function weight(): int {
		return 10;
	}

	/**
	 * Share of pages with JSON-LD whose data carries one of DATE_PROPERTIES (0 when no page has JSON-LD).
	 *
	 * @param Site $site Site.
	 */
	public function run( Site $site ): CheckResult {
		$reached   = 0;
		$with_data = 0;
		$dated     = 0;

		foreach ( $site->page_responses() as $page ) {
			if ( ! $page['response']->ok() ) {
				continue;
			}
			++$reached;
			$items = ( new Html( $page['response']->body ) )->json_ld()['items'];
			if ( array() === $items ) {
				continue;
			}
			++$with_data;
			if ( self::has_date( $items ) ) {
				++$dated;
			}
		}

		if ( 0 === $reached ) {
			return CheckResult::unmeasured( 'Örnek sayfalara erişilemedi.' );
		}

		$fix = 'Yapılandırılmış veride dateModified, ilanlarda priceValidUntil veya validThrough tarihi verin.';
		if ( 0 === $with_data ) {
			return CheckResult::measured( 0.0, array( 'Tarih taşıyabilecek yapılandırılmış veri yok.' ), $fix );
		}

		return CheckResult::measured(
			$dated / $with_data,
			array( sprintf( 'Yapılandırılmış verisi olan %d sayfanın %d tanesinde güncelleme veya geçerlilik tarihi var.', $with_data, $dated ) ),
			$fix
		);
	}

	/**
	 * Whether any object (at any depth) has a date property.
	 *
	 * @param array<mixed> $data JSON-LD data.
	 */
	private static function has_date( array $data ): bool {
		foreach ( $data as $key => $value ) {
			if ( in_array( $key, self::DATE_PROPERTIES, true ) && is_string( $value ) && '' !== $value ) {
				return true;
			}
			if ( is_array( $value ) && self::has_date( $value ) ) {
				return true;
			}
		}
		return false;
	}
}
