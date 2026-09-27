<?php
/**
 * Inquiry input validation.
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\Core\Inquiry;

use AIHazirSite\Core\Catalog\Listing;
use AIHazirSite\Core\Catalog\Query\ListingSearch;

/**
 * Turns raw input into clean values or field errors (Turkish). A referral request
 * (yönlendirme talebi, the only kind for fee-less professional services such as law
 * firms) may be about anything except fees: expertise, case types, references…
 */
final class InquiryValidator {

	public const MESSAGE_MIN = 10;
	public const MESSAGE_MAX = 4000;

	/**
	 * Words that ask about fees (folded, matched as parts of words).
	 */
	public const FEE_TERMS = array( 'ücret', 'ucret', 'fiyat', 'tarife', 'maliyet', 'kaç para', 'kac para', 'avans', 'masraf', 'fee', 'price', 'pricing', 'cost' );

	/**
	 * Validates.
	 *
	 * @param array<string, mixed>          $input   Raw input.
	 * @param string[]                      $kinds   Kinds allowed on this site.
	 * @param callable(int): (Listing|null) $listing Current listing by id (null when missing or expired).
	 * @return array{0: array{kind: string, listing: Listing|null, subject: string, message: string, contact: InquiryContact}|null, 1: array<string, string>}
	 *
	 * @phpstan-param list<string> $kinds
	 */
	public function validate( array $input, array $kinds, callable $listing ): array {
		$text   = static fn( string $k, int $max ): string => isset( $input[ $k ] ) && is_scalar( $input[ $k ] ) ? mb_substr( trim( (string) preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', (string) $input[ $k ] ) ), 0, $max ) : '';
		$errors = array();

		$kind = $text( 'kind', 32 );
		$kind = '' === $kind ? ( $kinds[0] ?? '' ) : $kind;
		if ( ! in_array( $kind, $kinds, true ) ) {
			$errors['kind'] = 'Bu sitede geçerli talep türleri: ' . implode( ', ', $kinds ) . '.';
		}

		$found = null;
		if ( isset( $input['listing_id'] ) && '' !== $input['listing_id'] ) {
			$id    = is_numeric( $input['listing_id'] ) ? (int) $input['listing_id'] : 0;
			$found = $id > 0 ? $listing( $id ) : null;
			if ( null === $found ) {
				$errors['listing_id'] = 'İlan bulunamadı veya süresi doldu.';
			}
		}

		$subject = $text( 'subject', 200 );
		$message = $text( 'message', self::MESSAGE_MAX + 1 );
		if ( mb_strlen( $message ) < self::MESSAGE_MIN ) {
			$errors['message'] = sprintf( 'Mesaj en az %d karakter olmalı.', self::MESSAGE_MIN );
		} elseif ( mb_strlen( $message ) > self::MESSAGE_MAX ) {
			$errors['message'] = sprintf( 'Mesaj en fazla %d karakter olabilir.', self::MESSAGE_MAX );
		}

		if ( Inquiry::KIND_REFERRAL === $kind && self::mentions_fee( $subject . ' ' . $message ) ) {
			$errors['message'] = 'Bu kanaldan ücret sorulamaz ve ücret bilgisi verilmez. Uzmanlık alanı, dava türü, referans işler gibi diğer konuları yazabilirsiniz; ücret için doğrudan büro ile görüşün.';
		}

		$raw     = is_array( $input['contact'] ?? null ) ? $input['contact'] : array();
		$contact = new InquiryContact(
			mb_substr( trim( is_scalar( $raw['name'] ?? null ) ? (string) $raw['name'] : '' ), 0, 100 ),
			mb_substr( trim( is_scalar( $raw['company'] ?? null ) ? (string) $raw['company'] : '' ), 0, 150 ),
			strtolower( trim( is_scalar( $raw['email'] ?? null ) ? (string) $raw['email'] : '' ) ),
			trim( is_scalar( $raw['phone'] ?? null ) ? (string) $raw['phone'] : '' )
		);
		if ( '' !== $contact->email && false === filter_var( $contact->email, FILTER_VALIDATE_EMAIL ) ) {
			$errors['contact.email'] = 'Geçerli bir e-posta adresi girin.';
		}
		if ( '' !== $contact->phone && ( ! preg_match( '/^\+?[\d\s().\/-]+$/', $contact->phone ) || strlen( (string) preg_replace( '/\D/', '', $contact->phone ) ) < 7 ) ) {
			$errors['contact.phone'] = 'Telefon yalnızca rakam, boşluk ve + ( ) - içerebilir; en az 7 rakam.';
		}
		if ( '' === $contact->email && '' === $contact->phone ) {
			$errors['contact'] = 'Size dönülebilmesi için e-posta veya telefon gerekli.';
		}

		if ( array() !== $errors ) {
			return array( null, $errors );
		}
		return array(
			array(
				'kind'    => $kind,
				'listing' => $found,
				'subject' => $subject,
				'message' => $message,
				'contact' => $contact,
			),
			array(),
		);
	}

	/**
	 * Whether the text asks about fees.
	 *
	 * @param string $text Text.
	 */
	public static function mentions_fee( string $text ): bool {
		$folded = ListingSearch::fold( $text );
		foreach ( self::FEE_TERMS as $term ) {
			if ( str_contains( $folded, ListingSearch::fold( $term ) ) ) {
				return true;
			}
		}
		return false;
	}
}
