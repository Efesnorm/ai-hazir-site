<?php
/**
 * Rule-based spam score of an inquiry.
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\Core\Security;

use AIHazirSite\Core\Catalog\Listing;
use AIHazirSite\Core\Catalog\Query\ListingSearch;

/**
 * 0–100 from explainable rules (reasons are Turkish, shown to the site owner):
 * - many inquiries from the same client in a short time,
 * - empty or meaningless text (few letters, few different letters, long repeated characters,
 *   mostly symbols, all capitals),
 * - many links,
 * - no word in common with the listing it is about.
 * At or above the threshold the inquiry goes to quarantine; it is never deleted or answered automatically.
 */
final class SpamScorer {

	public const DEFAULT_THRESHOLD = 50;

	/**
	 * Score and reasons.
	 *
	 * @param string       $subject Subject.
	 * @param string       $message Message.
	 * @param Listing|null $listing Listing the inquiry is about.
	 * @param int          $recent  Inquiries of the same client in the last 10 minutes.
	 * @return array{score: int, reasons: list<string>}
	 */
	public function score( string $subject, string $message, ?Listing $listing, int $recent ): array {
		$text    = trim( $subject . ' ' . $message );
		$score   = 0;
		$reasons = array();
		$add     = static function ( int $points, string $reason ) use ( &$score, &$reasons ): void {
			$score    += $points;
			$reasons[] = $reason;
		};

		if ( $recent >= 3 ) {
			$add( 50, sprintf( 'Aynı istemciden son 10 dakikada %d talep.', $recent ) );
		} elseif ( $recent >= 1 ) {
			$add( 10, 'Aynı istemciden kısa süre önce başka bir talep.' );
		}

		$letters = (int) preg_match_all( '/\p{L}/u', $text );
		$length  = max( 1, mb_strlen( (string) preg_replace( '/\s+/u', '', $text ) ) );
		if ( $letters < 10 ) {
			$add( 50, 'İçerik boş ya da anlamsız (çok az harf).' );
		} elseif ( $letters / $length < 0.5 ) {
			$add( 20, 'İçeriğin çoğu harf değil.' );
		}
		if ( preg_match( '/(.)\1{5,}/u', $text ) ) {
			$add( 20, 'Aynı karakter art arda tekrarlanıyor.' );
		}
		preg_match_all( '/\p{L}/u', mb_strtolower( $text ), $letter_list );
		if ( $letters >= 10 && count( array_unique( $letter_list[0] ) ) < 5 ) {
			$add( 40, 'Çok az farklı harf kullanılmış.' );
		}
		$words = (string) preg_replace( '#(https?://|www\.)\S+#i', '', $text );
		if ( $letters >= 20 && mb_strtoupper( $words ) === $words && mb_strtolower( $words ) !== $words ) {
			$add( 20, 'Tamamı büyük harf.' );
		}

		$links = (int) preg_match_all( '#(https?://|www\.)#i', $text );
		if ( $links >= 3 ) {
			$add( 60, sprintf( '%d bağlantı içeriyor.', $links ) );
		} elseif ( 2 === $links ) {
			$add( 30, '2 bağlantı içeriyor.' );
		} elseif ( 1 === $links ) {
			$add( 10, 'Bağlantı içeriyor.' );
		}

		if ( null !== $listing && ! self::related( $text, $listing ) ) {
			$add( 25, 'İlanla ortak kelime yok.' );
		}

		return array(
			'score'   => min( 100, $score ),
			'reasons' => $reasons,
		);
	}

	/**
	 * Whether the text shares a word (first 5 letters, 4+ letter words) with the listing.
	 *
	 * @param string  $text    Text.
	 * @param Listing $listing Listing.
	 */
	private static function related( string $text, Listing $listing ): bool {
		$stems         = static function ( string $value ): array {
			preg_match_all( '/[\p{L}\p{N}]{4,}/u', ListingSearch::fold( $value ), $m );
			return array_unique( array_map( static fn( string $w ): string => mb_substr( $w, 0, 5 ), $m[0] ) );
		};
		$listing_words = $stems( implode( ' ', array_merge( array( $listing->title, $listing->category, $listing->description ), array_values( $listing->attributes ) ) ) );
		return array() !== array_intersect( $stems( $text ), $listing_words );
	}
}
