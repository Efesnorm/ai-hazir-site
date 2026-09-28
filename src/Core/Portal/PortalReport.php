<?php
/**
 * Per-business AI visibility figures.
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\Core\Portal;

use AIHazirSite\Core\Inquiry\Inquiry;

/**
 * For each business: AI bot reads of its catalog page, its listings and the inquiries about them.
 * The portal row (business 0) takes listings and inquiries of no business, so the rows always
 * add up to the totals.
 */
final class PortalReport {

	/**
	 * Rows by business id (0 = the portal itself) and totals.
	 *
	 * @param Business[]           $businesses       Businesses.
	 * @param array<int, int|null> $listing_business Listing id → business id (null = portal).
	 * @param array<string, int>   $page_hits        Path → AI bot reads in the period.
	 * @param Inquiry[]            $inquiries        Inquiries in the period.
	 * @param callable             $path_of          fn( Business ): string, the business page path.
	 * @return array{rows: array<int, array{name: string, slug: string, page_hits: int, listings: int, inquiries: int}>, totals: array{page_hits: int, listings: int, inquiries: int}}
	 *
	 * @phpstan-param list<Business> $businesses
	 * @phpstan-param list<Inquiry> $inquiries
	 * @phpstan-param callable(Business): string $path_of
	 */
	public static function build( array $businesses, array $listing_business, array $page_hits, array $inquiries, callable $path_of ): array {
		$rows = array(
			0 => array(
				'name'      => '',
				'slug'      => '',
				'page_hits' => 0,
				'listings'  => 0,
				'inquiries' => 0,
			),
		);
		foreach ( $businesses as $business ) {
			$rows[ (int) $business->id ] = array(
				'name'      => $business->profile->name,
				'slug'      => $business->slug,
				'page_hits' => $page_hits[ $path_of( $business ) ] ?? 0,
				'listings'  => 0,
				'inquiries' => 0,
			);
		}
		$owner = static fn( ?int $listing_id ): int => null !== $listing_id && isset( $rows[ (int) ( $listing_business[ $listing_id ] ?? 0 ) ] ) ? (int) ( $listing_business[ $listing_id ] ?? 0 ) : 0;
		foreach ( array_keys( $listing_business ) as $listing_id ) {
			++$rows[ $owner( $listing_id ) ]['listings'];
		}
		foreach ( $inquiries as $inquiry ) {
			++$rows[ $owner( $inquiry->listing_id ) ]['inquiries'];
		}

		return array(
			'rows'   => $rows,
			'totals' => array(
				'page_hits' => array_sum( array_column( $rows, 'page_hits' ) ),
				'listings'  => count( $listing_business ),
				'inquiries' => count( $inquiries ),
			),
		);
	}
}
