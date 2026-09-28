<?php
/**
 * "Is this quantity available, and in how many days?"
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\Core\Catalog\Query;

use AIHazirSite\Core\Catalog\Listing;
use AIHazirSite\Core\Catalog\ListingType;
use AIHazirSite\Core\Catalog\ListingValidator;
use AIHazirSite\Core\Templates\Freshness;
use AIHazirSite\Core\Templates\Template;

/**
 * Answers yes / no / unknown with Turkish reasons, from the listing's own data only:
 * - offer: asked quantity against the stock, or, when the template has an inventory field (e.g.
 *   remaining tour places), against that field only (1.6.1: empty → unknown, the general quantity is
 *   not used; a stale value gives "unknown"); unknown stock → unknown;
 * - supply: made to order, so the quantity is not a limit;
 * - lead time against the asked days; no lead time given → unknown;
 * - demand, missing or expired listing → no.
 * Any "no" makes the answer no; otherwise any "unknown" makes it unknown.
 *
 * @phpstan-type Answer array{answer: string, reasons: list<string>, listing_id: int|null, available_quantity: string|null, unit: string, lead_time_days: int|null}
 */
final class Availability {

	public const YES     = 'yes';
	public const NO      = 'no';
	public const UNKNOWN = 'unknown';

	/**
	 * The answer.
	 *
	 * @param Listing|null $listing  Current listing (null: missing or expired).
	 * @param Template     $template The listing's template.
	 * @param string|null  $quantity Asked quantity (decimal string), or null.
	 * @param int|null     $days     Needed within this many days, or null.
	 * @param string       $now      ISO 8601 date-time (freshness).
	 * @return array{answer: string, reasons: list<string>, listing_id: int|null, available_quantity: string|null, unit: string, lead_time_days: int|null}
	 */
	public static function check( ?Listing $listing, Template $template, ?string $quantity, ?int $days, string $now ): array {
		if ( null === $listing ) {
			return self::result( self::NO, array( 'İlan bulunamadı veya geçerlilik süresi doldu.' ), null, null, '', null );
		}
		if ( ListingType::DEMAND === $listing->type ) {
			return self::result( self::NO, array( 'Bu bir alım ilanı: firma bu ürünü arıyor, satmıyor.' ), $listing->id, null, $listing->unit, null );
		}

		$answers = array();
		$reasons = array();
		$stock   = $listing->quantity;
		$unit    = $listing->unit;
		$stale   = false;
		$label   = null;
		foreach ( $template->fields as $field ) {
			if ( 'inventoryLevel' === ( $field->schema['property'] ?? '' ) ) {
				// 1.6.1: the template's own availability field (e.g. a tour's remaining places) is the only
				// source; the general quantity (e.g. a tour's total) is not a fallback for it.
				$label = $field->label;
				$stock = $listing->attributes[ $field->name ] ?? null;
				$unit  = '' === $field->unit ? $field->label : $field->unit;
				$stale = null !== $stock && Freshness::is_stale( $field, $listing, $now );
			}
		}

		if ( null !== $quantity ) {
			if ( ListingType::SUPPLY === $listing->type ) {
				$answers[] = self::YES;
				$reasons[] = 'Siparişe göre üretilir; miktar sınırı yok.';
			} elseif ( null === $stock ) {
				$answers[] = self::UNKNOWN;
				$reasons[] = null === $label ? 'Stok miktarı belirtilmemiş; firmaya sorun.' : sprintf( '%s belirtilmemiş; firmaya sorun.', $label );
			} elseif ( $stale ) {
				$answers[] = self::UNKNOWN;
				$reasons[] = null === $label
					? sprintf( 'Son bilinen miktar %s %s, ancak doğrulanmadı (son güncelleme %s).', $stock, $unit, (string) $listing->updated_at )
					: sprintf( '%s son olarak %s bildirildi, ancak doğrulanmadı (son güncelleme %s).', $label, $stock, (string) $listing->updated_at );
			} elseif ( ListingValidator::compare( $quantity, $stock ) <= 0 ) {
				$answers[] = self::YES;
				$reasons[] = null === $label ? trim( sprintf( 'Stokta %s %s var; istenen %s.', $stock, $unit, $quantity ) ) : sprintf( '%s: %s; istenen %s.', $label, $stock, $quantity );
			} else {
				$answers[] = self::NO;
				$reasons[] = null === $label ? trim( sprintf( 'Stokta %s %s var; istenen %s karşılanamıyor.', $stock, $unit, $quantity ) ) : sprintf( '%s: %s; istenen %s karşılanamıyor.', $label, $stock, $quantity );
			}
		}

		if ( null !== $days ) {
			if ( null === $listing->lead_time_days ) {
				$answers[] = self::UNKNOWN;
				$reasons[] = 'Teslim süresi belirtilmemiş; firmaya sorun.';
			} elseif ( $listing->lead_time_days <= $days ) {
				$answers[] = self::YES;
				$reasons[] = sprintf( 'Teslim süresi %d gün; istenen %d gün içinde.', $listing->lead_time_days, $days );
			} else {
				$answers[] = self::NO;
				$reasons[] = sprintf( 'Teslim süresi %d gün; istenen %d günü aşıyor.', $listing->lead_time_days, $days );
			}
		}

		if ( array() === $answers ) {
			$answers[] = self::YES;
			$reasons[] = 'İlan geçerli.' . ( null === $listing->lead_time_days ? '' : sprintf( ' Teslim süresi %d gün.', $listing->lead_time_days ) );
		}

		$answer = in_array( self::NO, $answers, true ) ? self::NO : ( in_array( self::UNKNOWN, $answers, true ) ? self::UNKNOWN : self::YES );
		return self::result( $answer, $reasons, $listing->id, $stale ? null : $stock, $unit, $listing->lead_time_days );
	}

	/**
	 * Result array.
	 *
	 * @param string      $answer   Answer.
	 * @param string[]    $reasons  Reasons.
	 * @param int|null    $id       Listing id.
	 * @param string|null $quantity Available quantity.
	 * @param string      $unit     Unit.
	 * @param int|null    $days     Lead time.
	 *
	 * @phpstan-param list<string> $reasons
	 * @return array{answer: string, reasons: list<string>, listing_id: int|null, available_quantity: string|null, unit: string, lead_time_days: int|null}
	 */
	private static function result( string $answer, array $reasons, ?int $id, ?string $quantity, string $unit, ?int $days ): array {
		return array(
			'answer'             => $answer,
			'reasons'            => $reasons,
			'listing_id'         => $id,
			'available_quantity' => $quantity,
			'unit'               => $unit,
			'lead_time_days'     => $days,
		);
	}
}
