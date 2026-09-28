<?php
/**
 * The llms.txt text for the company and its catalog.
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\Adapters\Llms;

use AIHazirSite\Core\Catalog\CompanyProfile;
use AIHazirSite\Core\Catalog\Listing;
use AIHazirSite\Core\Catalog\ListingType;
use AIHazirSite\Core\Catalog\ListingValidity;
use AIHazirSite\Core\Templates\Freshness;
use AIHazirSite\Core\Templates\Template;
use AIHazirSite\Core\Templates\TemplateRegistry;

/**
 * Follows the format proposed at llmstxt.org: an H1 with the site name (the only required
 * part), a blockquote summary, non-heading details, then H2 sections of file lists
 * ("- [name](url): notes"); the "Optional" section holds links an agent may skip.
 * Current listings only (ListingValidity); empty sections are left out. UTF-8, LF, no BOM.
 * Sector templates (0.8.0): template fields marked `llms` are listed with their label and unit,
 * prices are left out where the template forbids them, and stale short-lived values are marked.
 */
final class LlmsTxtBuilder {

	/**
	 * Default (Turkish) texts; the platform passes translated ones.
	 */
	public const LABELS = array(
		ListingType::OFFER  => 'Satılanlar',
		ListingType::DEMAND => 'Arananlar',
		ListingType::SUPPLY => 'Tedarik edilebilenler',
		'summary'           => '%s: sattığı, aradığı ve tedarik edebildiği ürünlerin güncel özeti.',
		'country'           => 'Ülke',
		'languages'         => 'Diller',
		'certifications'    => 'Sertifikalar',
		'updated'           => 'Son güncelleme',
		'category'          => 'Kategori',
		'quantity'          => 'Miktar',
		'price'             => 'Fiyat',
		'budget'            => 'Bütçe',
		'region'            => 'Bölge',
		'lead_time'         => 'Teslim süresi',
		'days'              => '%d gün',
		'valid_until'       => 'Geçerlilik',
		'attributes'        => 'Özellikler',
		'contact'           => 'İletişim',
		'website'           => 'Web sitesi',
		'email'             => 'E-posta',
		'phone'             => 'Telefon',
		'catalog'           => 'AI Katalog',
		'catalog_note'      => 'Tüm geçerli ilanların sade HTML listesi',
		'api'               => 'AI Katalog API (JSON)',
		'api_note'          => 'Geçerli ilanlar, sayfalı; şema: %s',
		'api_templates'     => 'Şablon alan tanımları (JSON)',
		'unverified'        => '%1$s (doğrulanmadı, son güncelleme %2$s)',
		'language'          => 'Dil',
		'untranslated'      => 'çevirisi yok, %1$s dilinde: %2$s',
		'alternate'         => 'Bu metin %s dilinde',
		'field_title'       => 'başlık',
		'field_description' => 'açıklama',
		'field_category'    => 'kategori',
		'field_region'      => 'bölge',
		'field_sector'      => 'sektör',
	);

	/**
	 * Merged texts.
	 *
	 * @var array<string, string>
	 */
	private readonly array $labels;

	/**
	 * Language context of the text being built (1.1.0), null for single-language output.
	 *
	 * @var array{language: string, fallback: string, missing: array<int|string, list<string>>, alternates: array<string, string>}|null
	 */
	private ?array $i18n = null;

	/**
	 * Constructor.
	 *
	 * @param string                $site_url    Site home URL.
	 * @param string                $catalog_url AI catalog page URL (listing anchors: #ilan-{id}).
	 * @param string                $site_name   Used when the profile has no name.
	 * @param array<string, string> $labels      Translated texts (keys of self::LABELS).
	 * @param TemplateRegistry|null $templates   Sector templates; null = every listing is "general".
	 * @param string                $api_url     REST API base URL with trailing slash ('' when the API is off).
	 */
	public function __construct(
		private readonly string $site_url,
		private readonly string $catalog_url,
		private readonly string $site_name,
		array $labels = array(),
		private readonly ?TemplateRegistry $templates = null,
		private readonly string $api_url = ''
	) {
		$this->labels = array_merge( self::LABELS, $labels );
	}

	/**
	 * The llms.txt text.
	 *
	 * @param CompanyProfile $profile       Company profile.
	 * @param Listing[]      $listings      All listings (expired ones are skipped).
	 * @param string         $today         Y-m-d.
	 * @param string         $date_modified Last change of the data (ISO 8601), '' when unknown.
	 * @param string|null    $now           ISO 8601 date-time for freshness (default: start of today).
	 * @param array|null     $i18n          Language context (1.1.0): language, fallback (default language),
	 *                                      missing (listing id or 'profile' → fields shown in the fallback
	 *                                      language), alternates (language → URL). Null = unchanged output.
	 *
	 * @phpstan-param list<Listing> $listings
	 * @phpstan-param array{language: string, fallback: string, missing: array<int|string, list<string>>, alternates: array<string, string>}|null $i18n
	 */
	public function build( CompanyProfile $profile, array $listings, string $today, string $date_modified, ?string $now = null, ?array $i18n = null ): string {
		$this->i18n = $i18n;
		$name       = self::inline( '' === $profile->name ? $this->site_name : $profile->name );
		$blocks     = array(
			'# ' . $name,
			'> ' . sprintf( $this->labels['summary'], $name . ( '' !== $profile->sector ? ' (' . self::inline( $profile->sector ) . ')' : '' ) ),
		);

		$details = array_filter(
			array(
				'language'       => null === $i18n ? '' : $i18n['language'] . $this->untranslated( 'profile' ),
				'country'        => $profile->country,
				'languages'      => implode( ', ', $profile->languages ),
				'certifications' => implode( ', ', $profile->certifications ),
				'updated'        => substr( $date_modified, 0, 10 ),
			),
			static fn( string $value ): bool => '' !== $value
		);
		if ( array() !== $details ) {
			$blocks[] = implode( "\n", array_map( fn( string $key, string $value ): string => '- ' . $this->labels[ $key ] . ': ' . self::inline( $value ), array_keys( $details ), $details ) );
		}

		foreach ( ListingType::ALL as $type ) {
			$lines = array();
			foreach ( $listings as $listing ) {
				if ( $type === $listing->type && ListingValidity::is_current( $listing, $today ) ) {
					$lines[] = $this->listing_line( $listing, $today, $now );
				}
			}
			if ( array() !== $lines ) {
				$blocks[] = '## ' . $this->labels[ $type ] . "\n\n" . implode( "\n", $lines );
			}
		}

		$contact  = array_filter(
			array(
				'email' => $profile->contact_email,
				'phone' => $profile->contact_phone,
			),
			static fn( string $value ): bool => '' !== $value
		);
		$blocks[] = '## ' . $this->labels['contact'] . "\n\n" . self::link( $this->labels['website'], $this->site_url, $this->pairs( $contact ) );
		$optional = array( self::link( $this->labels['catalog'], $this->catalog_url, $this->labels['catalog_note'] ) );
		if ( '' !== $this->api_url ) {
			$optional[] = self::link( $this->labels['api'], $this->api_url . 'listings', sprintf( $this->labels['api_note'], $this->api_url . 'schema/listings' ) );
			$optional[] = self::link( $this->labels['api_templates'], $this->api_url . 'templates', '' );
		}
		foreach ( null === $i18n ? array() : $i18n['alternates'] as $language => $url ) {
			if ( $language !== $i18n['language'] ) {
				$optional[] = self::link( 'llms.txt (' . $language . ')', $url, sprintf( $this->labels['alternate'], $language ) );
			}
		}
		$blocks[] = "## Optional\n\n" . implode( "\n", $optional );

		return implode( "\n\n", $blocks ) . "\n";
	}

	/**
	 * One listing as a file-list item pointing at its anchor on the catalog page.
	 *
	 * @param Listing     $listing Listing.
	 * @param string      $today   Y-m-d.
	 * @param string|null $now     ISO 8601 date-time.
	 */
	private function listing_line( Listing $listing, string $today, ?string $now ): string {
		$details = array();
		foreach ( $this->details( $listing, $today, $now ) as $label => $value ) {
			$details[] = $label . ': ' . $value;
		}

		$text = '' !== $listing->description ? rtrim( self::inline( $listing->description ), '.' ) . '. ' : '';
		$url  = $this->catalog_url . ( null === $listing->id ? '' : '#ilan-' . $listing->id );

		return self::link( $listing->title, $url, $text . implode( '; ', $details ) . $this->untranslated( $listing->id ?? 0 ) );
	}

	/**
	 * " (çevirisi yok, tr dilinde: açıklama, bölge)" for a record with fallback fields, else ''.
	 *
	 * @param int|string $key Listing id or 'profile'.
	 */
	private function untranslated( int|string $key ): string {
		$missing = $this->i18n['missing'][ $key ] ?? array();
		if ( null === $this->i18n || array() === $missing ) {
			return '';
		}
		$names = array_map( fn( string $field ): string => $this->labels[ 'field_' . $field ] ?? $field, $missing );
		return ' (' . sprintf( $this->labels['untranslated'], $this->i18n['fallback'], implode( ', ', $names ) ) . ')';
	}

	/**
	 * A listing's details as label → single-line value (also shown on the catalog page).
	 *
	 * @param Listing     $listing Listing.
	 * @param string      $today   Y-m-d.
	 * @param string|null $now     ISO 8601 date-time for freshness (default: start of today).
	 * @return array<string, string>
	 */
	public function details( Listing $listing, string $today, ?string $now = null ): array {
		$template = null === $this->templates ? Template::general() : $this->templates->get( $listing->template );
		$notes    = array();
		if ( '' !== $listing->category ) {
			$notes['category'] = $listing->category;
		}
		if ( null !== $listing->quantity ) {
			$notes['quantity'] = trim( $listing->quantity . ' ' . $listing->unit );
		}
		if ( null !== $listing->price_min && $template->price ) {
			$range = null === $listing->price_max || $listing->price_max === $listing->price_min ? $listing->price_min : $listing->price_min . '–' . $listing->price_max;
			$notes[ ListingType::DEMAND === $listing->type ? 'budget' : 'price' ] = trim( $range . ' ' . $listing->currency );
		}
		if ( '' !== $listing->region ) {
			$notes['region'] = $listing->region;
		}
		if ( null !== $listing->lead_time_days ) {
			$notes['lead_time'] = sprintf( $this->labels['days'], $listing->lead_time_days );
		}
		$notes['valid_until'] = ListingValidity::valid_until( $listing, $today );

		$fields = array();
		$extras = array();
		foreach ( $listing->attributes as $key => $value ) {
			$field = $template->field( $key );
			if ( null === $field ) {
				$extras[ $key ] = $value;
			} elseif ( $field->llms ) {
				$shown = trim( $value . ' ' . $field->unit );
				if ( Freshness::is_stale( $field, $listing, $now ?? $today . 'T00:00:00Z' ) ) {
					$shown = sprintf( $this->labels['unverified'], $shown, self::moment( (string) $listing->updated_at ) );
				}
				$fields[ $field->label ] = $shown;
			}
		}
		if ( array() !== $extras ) {
			$notes['attributes'] = implode( ', ', array_map( static fn( string $k, string $v ): string => $k . ' ' . $v, array_keys( $extras ), $extras ) );
		}

		$details = array();
		foreach ( $notes as $key => $value ) {
			if ( 'valid_until' === $key ) {
				foreach ( $fields as $label => $shown ) {
					$details[ self::inline( $label ) ] = self::inline( $shown );
				}
			}
			$details[ $this->labels[ $key ] ] = self::inline( $value );
		}
		return $details;
	}

	/**
	 * "2026-09-25 10:00 UTC" from an ISO 8601 UTC time.
	 *
	 * @param string $iso ISO 8601.
	 */
	private static function moment( string $iso ): string {
		$time = strtotime( $iso );
		return false === $time ? $iso : gmdate( 'Y-m-d H:i', $time ) . ' UTC';
	}

	/**
	 * "Label: value; Label: value".
	 *
	 * @param array<string, string> $values Label key → value.
	 */
	private function pairs( array $values ): string {
		return implode( '; ', array_map( fn( string $key, string $value ): string => $this->labels[ $key ] . ': ' . self::inline( $value ), array_keys( $values ), $values ) );
	}

	/**
	 * "- [name](url): notes" (notes optional).
	 *
	 * @param string $name  Link text.
	 * @param string $url   URL.
	 * @param string $notes Notes.
	 */
	private static function link( string $name, string $url, string $notes ): string {
		$name = str_replace( array( '\\', '[', ']' ), array( '\\\\', '\\[', '\\]' ), self::inline( $name ) );
		$url  = str_replace( array( ' ', '(', ')' ), array( '%20', '%28', '%29' ), $url );
		return '- [' . $name . '](' . $url . ')' . ( '' !== $notes ? ': ' . $notes : '' );
	}

	/**
	 * Single-line text: whitespace (including line breaks) collapsed.
	 *
	 * @param string $text Text.
	 */
	private static function inline( string $text ): string {
		return trim( (string) preg_replace( '/\s+/u', ' ', $text ) );
	}
}
