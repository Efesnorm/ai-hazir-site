<?php
/**
 * Readability without JavaScript check.
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
 * Pages give meaningful text without JavaScript, are not "noindex", and contact
 * information is present as text (not only in images or PDFs).
 */
final class ReadabilityCheck implements Check {

	public const MIN_WORDS = 50;

	/**
	 * Id.
	 */
	public function id(): string {
		return 'readability';
	}

	/**
	 * Weight.
	 */
	public function weight(): int {
		return 20;
	}

	/**
	 * Per page 0.8 when readable and indexable; +0.2 when contact info is text somewhere.
	 *
	 * @param Site $site Site.
	 */
	public function run( Site $site ): CheckResult {
		$scores   = array();
		$findings = array();
		$contact  = false;

		foreach ( $site->page_responses() as $page ) {
			$response = $page['response'];
			if ( ! $response->ok() ) {
				continue;
			}
			$html  = new Html( $response->body );
			$text  = $html->visible_text();
			$parts = preg_split( '/\s+/u', $text );
			$words = '' === $text || ! is_array( $parts ) ? 0 : count( $parts );

			$contact = $contact || self::has_contact( $text );

			if ( str_contains( $html->meta_robots(), 'noindex' ) || str_contains( strtolower( $response->header( 'x-robots-tag' ) ), 'noindex' ) ) {
				$scores[]   = 0.0;
				$findings[] = $page['url'] . ': noindex nedeniyle AI ve arama motorları bu sayfayı kullanmaz.';
			} elseif ( $words < self::MIN_WORDS ) {
				$scores[]   = 0.0;
				$findings[] = sprintf( '%s: JavaScript olmadan yalnızca %d kelime okunuyor.', $page['url'], $words );
			} else {
				$scores[] = 0.8;
			}
		}

		if ( array() === $scores ) {
			return CheckResult::unmeasured( 'Örnek sayfalara erişilemedi.' );
		}
		if ( ! $contact ) {
			$findings[] = 'İletişim bilgisi (e-posta veya telefon) metin olarak bulunamadı.';
		}

		return CheckResult::measured(
			array_sum( $scores ) / count( $scores ) + ( $contact ? 0.2 : 0.0 ),
			$findings,
			'Önemli bilgileri (ürün, fiyat, stok, iletişim) resim veya PDF yerine sayfa metni olarak yazın; noindex kullanmayın.'
		);
	}

	/**
	 * Whether text contains an e-mail address or a phone number.
	 *
	 * @param string $text Visible text.
	 */
	private static function has_contact( string $text ): bool {
		if ( preg_match( '/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i', $text ) ) {
			return true;
		}
		if ( preg_match_all( '/\+?\d[\d\s().\/-]{8,}\d/', $text, $matches ) ) {
			foreach ( $matches[0] as $candidate ) {
				if ( strlen( (string) preg_replace( '/\D/', '', $candidate ) ) >= 10 ) {
					return true;
				}
			}
		}
		return false;
	}
}
