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
	 * GET /listings: current listings passing the filters, one page.
	 *
	 * @param Listing[]     $listings All listings.
	 * @param ListingsQuery $query    Filters and paging.
	 * @param string        $today    Y-m-d.
	 * @param string        $now      ISO 8601 date-time.
	 * @return array<string, mixed>
	 *
	 * @phpstan-param list<Listing> $listings
	 */
	public function listings( array $listings, ListingsQuery $query, string $today, string $now ): array {
		$current = array_values( array_filter( $listings, static fn( Listing $l ): bool => ListingValidity::is_current( $l, $today ) ) );
		$matches = array_values( array_filter( $current, array( $query, 'matches' ) ) );
		usort( $matches, static fn( Listing $a, Listing $b ): int => array( (string) $b->updated_at, (int) $b->id ) <=> array( (string) $a->updated_at, (int) $a->id ) );

		$dates = array_filter( array_map( static fn( Listing $l ): ?string => $l->updated_at, $current ) );
		rsort( $dates );
		$total = count( $matches );

		return array(
			'items'       => array_map( fn( Listing $l ): array => $this->item( $l, $today, $now ), array_slice( $matches, ( $query->page - 1 ) * $query->per_page, $query->per_page ) ),
			'page'        => $query->page,
			'per_page'    => $query->per_page,
			'total'       => $total,
			'total_pages' => (int) ceil( $total / $query->per_page ),
			'updated_at'  => $dates[0] ?? null,
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
