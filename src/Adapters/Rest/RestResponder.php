<?php
/**
 * REST response bodies from catalog data.
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\Adapters\Rest;

use AIHazirSite\Core\Catalog\CompanyProfile;
use AIHazirSite\Core\Catalog\Listing;
use AIHazirSite\Core\Catalog\ListingValidity;
use AIHazirSite\Core\Catalog\Query\CatalogQuery;
use AIHazirSite\Core\Catalog\Query\ListingSearch;
use AIHazirSite\Core\Portal\Business;
use AIHazirSite\Core\Templates\Freshness;
use AIHazirSite\Core\Templates\Template;
use AIHazirSite\Core\Templates\TemplateField;
use AIHazirSite\Core\Templates\TemplateRegistry;

/**
 * Platform-neutral producer of the aihs/v1 bodies (shapes in RestSchemas). Only current listings
 * (ListingValidity); no price key where the template forbids prices; template fields with their
 * label and unit; a short-lived value past its freshness limit is marked verified: false.
 */
final class RestResponder {

	/**
	 * Constructor.
	 *
	 * @param string                $site_url    Site home URL.
	 * @param string                $catalog_url AI catalog page URL (listing anchors: #ilan-{id}).
	 * @param TemplateRegistry|null $templates   Sector templates; null = every listing is "general".
	 */
	public function __construct(
		private readonly string $site_url,
		private readonly string $catalog_url,
		private readonly ?TemplateRegistry $templates = null
	) {
	}

	/**
	 * GET /profile.
	 *
	 * @param CompanyProfile $profile    Profile.
	 * @param string|null    $updated_at Last save.
	 * @return array<string, mixed>
	 */
	public function profile( CompanyProfile $profile, ?string $updated_at ): array {
		return array(
			'name'           => $profile->name,
			'sector'         => $profile->sector,
			'country'        => $profile->country,
			'languages'      => $profile->languages,
			'contact_email'  => $profile->contact_email,
			'contact_phone'  => $profile->contact_phone,
			'certifications' => $profile->certifications,
			'template'       => $profile->template,
			'url'            => $this->site_url,
			'catalog_url'    => $this->catalog_url,
			'updated_at'     => $updated_at,
			'valid_until'    => null,
		);
	}

	/**
	 * Adds the multilingual keys to a body (1.1.0): `language`, and `translation` markers on the
	 * profile or on each listing (by id). Used only while multilingual output is active.
	 *
	 * @param array<string, mixed>                                                                              $body     Body from profile(), listings() or listing().
	 * @param string                                                                                            $language Answer language.
	 * @param array<int|string, array{language: string, missing: list<string>, fallback_language: string|null}> $markers  Listing id (or 'profile') → marker.
	 * @return array<string, mixed>
	 */
	public static function translated( array $body, string $language, array $markers ): array {
		$body['language'] = $language;
		if ( isset( $body['items'] ) && is_array( $body['items'] ) ) {
			foreach ( $body['items'] as $i => $item ) {
				if ( is_array( $item ) && isset( $markers[ (int) ( $item['id'] ?? 0 ) ] ) ) {
					$body['items'][ $i ]['translation'] = $markers[ (int) $item['id'] ];
				}
			}
		} elseif ( isset( $body['id'], $markers[ (int) $body['id'] ] ) ) {
			$body['translation'] = $markers[ (int) $body['id'] ];
		} elseif ( isset( $markers['profile'] ) ) {
			$body['translation'] = $markers['profile'];
		}
		return $body;
	}

	/**
	 * Adds each listing's business (1.2.0 portal mode): `business {id, slug, name, url}`, or null
	 * for the portal's own listings.
	 *
	 * @param array<string, mixed>                                                $body   Body from listings() or listing().
	 * @param array<int, array{id: int, slug: string, name: string, url: string}> $owners Listing id → business reference.
	 * @return array<string, mixed>
	 */
	public static function with_business( array $body, array $owners ): array {
		if ( isset( $body['items'] ) && is_array( $body['items'] ) ) {
			foreach ( $body['items'] as $i => $item ) {
				if ( is_array( $item ) ) {
					$body['items'][ $i ]['business'] = $owners[ (int) ( $item['id'] ?? 0 ) ] ?? null;
				}
			}
		} elseif ( isset( $body['id'] ) ) {
			$body['business'] = $owners[ (int) $body['id'] ] ?? null;
		}
		return $body;
	}

	/**
	 * GET /businesses (1.2.0 portal mode).
	 *
	 * @param Business[] $businesses Businesses.
	 * @param callable   $url_of     fn( Business ): string, the business catalog page.
	 * @return array<string, mixed>
	 *
	 * @phpstan-param list<Business> $businesses
	 * @phpstan-param callable(Business): string $url_of
	 */
	public function businesses( array $businesses, callable $url_of ): array {
		$dates = array_filter( array_map( static fn( Business $b ): ?string => $b->updated_at, $businesses ) );
		rsort( $dates );
		return array(
			'items'       => array_map(
				static fn( Business $b ): array => array(
					'id'             => (int) $b->id,
					'slug'           => $b->slug,
					'name'           => $b->profile->name,
					'sector'         => $b->profile->sector,
					'country'        => $b->profile->country,
					'languages'      => $b->profile->languages,
					'certifications' => $b->profile->certifications,
					'contact_email'  => $b->profile->contact_email,
					'contact_phone'  => $b->profile->contact_phone,
					'catalog_url'    => $url_of( $b ),
				),
				$businesses
			),
			'updated_at'  => $dates[0] ?? null,
			'valid_until' => null,
		);
	}

	/**
	 * GET /listings: current listings passing the filters, one page.
	 *
	 * @param Listing[]                   $listings All listings.
	 * @param ListingsQuery|ListingSearch $query Filters and paging (REST parameters or core criteria).
	 * @param string                      $today    Y-m-d.
	 * @param string                      $now      ISO 8601 date-time.
	 * @return array<string, mixed>
	 *
	 * @phpstan-param list<Listing> $listings
	 */
	public function listings( array $listings, ListingsQuery|ListingSearch $query, string $today, string $now ): array {
		$search   = $query instanceof ListingsQuery ? $query->search() : $query;
		$selected = CatalogQuery::select( $listings, $search, $today );

		return array(
			'items'       => array_map( fn( Listing $l ): array => $this->item( $l, $today, $now ), $selected['items'] ),
			'page'        => $search->page,
			'per_page'    => $search->per_page,
			'total'       => $selected['total'],
			'total_pages' => (int) ceil( $selected['total'] / max( 1, $search->per_page ) ),
			'updated_at'  => $selected['updated_at'],
			'valid_until' => null,
		);
	}

	/**
	 * GET /listings/{id}: the listing, or null when it is not current.
	 *
	 * @param Listing $listing Listing.
	 * @param string  $today   Y-m-d.
	 * @param string  $now     ISO 8601 date-time.
	 * @return array<string, mixed>|null
	 */
	public function listing( Listing $listing, string $today, string $now ): ?array {
		return ListingValidity::is_current( $listing, $today ) ? $this->item( $listing, $today, $now ) : null;
	}

	/**
	 * GET /templates: definitions of the given templates.
	 *
	 * @param Template[] $templates Templates in use.
	 * @return array<string, mixed>
	 *
	 * @phpstan-param list<Template> $templates
	 */
	public function templates( array $templates ): array {
		$items = array();
		foreach ( $templates as $template ) {
			$items[] = array(
				'id'            => $template->id,
				'version'       => $template->version,
				'name'          => $template->name,
				'description'   => $template->description,
				'item_type'     => $template->item_type,
				'price_allowed' => $template->price,
				'fields'        => array_map(
					static fn( TemplateField $f ): array => array(
						'name'        => $f->name,
						'label'       => $f->label,
						'type'        => $f->type,
						'unit'        => $f->unit,
						'unit_code'   => $f->unit_code,
						'required'    => $f->required,
						'allowed'     => $f->allowed,
						'help'        => $f->help,
						'fresh_hours' => $f->fresh_hours,
					),
					$template->fields
				),
			);
		}
		return array(
			'items'       => $items,
			'updated_at'  => null,
			'valid_until' => null,
		);
	}

	/**
	 * Templates used by the profile and the current listings, "general" first.
	 *
	 * @param CompanyProfile $profile  Profile.
	 * @param Listing[]      $listings All listings.
	 * @param string         $today    Y-m-d.
	 * @return list<Template>
	 *
	 * @phpstan-param list<Listing> $listings
	 */
	public function used_templates( CompanyProfile $profile, array $listings, string $today ): array {
		$ids = array( Template::GENERAL => true );
		if ( null !== $this->templates ) {
			$ids[ $this->templates->get( $profile->template )->id ] = true;
			foreach ( $listings as $listing ) {
				if ( ListingValidity::is_current( $listing, $today ) ) {
					$ids[ $this->templates->get( $listing->template )->id ] = true;
				}
			}
		}
		return array_map( fn( string $id ): Template => $this->template_by_id( $id ), array_keys( $ids ) );
	}

	/**
	 * One listing.
	 *
	 * @param Listing $listing Listing.
	 * @param string  $today   Y-m-d.
	 * @param string  $now     ISO 8601 date-time.
	 * @return array<string, mixed>
	 */
	private function item( Listing $listing, string $today, string $now ): array {
		$template = $this->template_by_id( $listing->template );
		$item     = array(
			'id'             => (int) $listing->id,
			'type'           => $listing->type,
			'title'          => $listing->title,
			'description'    => $listing->description,
			'category'       => $listing->category,
			'url'            => $this->catalog_url . ( null === $listing->id ? '' : '#ilan-' . $listing->id ),
			'quantity'       => null === $listing->quantity ? null : array(
				'value' => $listing->quantity,
				'unit'  => $listing->unit,
			),
			'price'          => null === $listing->price_min && null === $listing->price_max ? null : array(
				'min'      => $listing->price_min,
				'max'      => $listing->price_max,
				'currency' => $listing->currency,
			),
			'region'         => $listing->region,
			'lead_time_days' => $listing->lead_time_days,
			'valid_until'    => ListingValidity::valid_until( $listing, $today ),
			'updated_at'     => $listing->updated_at,
			'template'       => $template->id,
			'attributes'     => array(),
		);
		if ( ! $template->price ) {
			unset( $item['price'] );
		}

		foreach ( $listing->attributes as $name => $value ) {
			$field                = $template->field( $name );
			$stale                = null !== $field && Freshness::is_stale( $field, $listing, $now );
			$item['attributes'][] = array(
				'name'         => $name,
				'label'        => $field->label ?? $name,
				'value'        => $value,
				'unit'         => $field->unit ?? '',
				'verified'     => ! $stale,
				'confirmed_at' => null !== $field && null !== $field->fresh_hours ? $listing->updated_at : null,
			);
		}
		return $item;
	}

	/**
	 * Template by id ("general" without a registry or for an unknown id).
	 *
	 * @param string $id Id.
	 */
	private function template_by_id( string $id ): Template {
		return null === $this->templates ? Template::general() : $this->templates->get( $id );
	}
}
