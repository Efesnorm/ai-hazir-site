<?php
/**
 * Input and output schemas of the aihs/ abilities.
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\Adapters\Abilities;

use AIHazirSite\Adapters\Rest\RestSchemas;
use AIHazirSite\Core\Catalog\ListingType;
use AIHazirSite\Core\Catalog\Nace;
use AIHazirSite\Core\Catalog\Query\Availability;
use AIHazirSite\Core\Catalog\Query\ListingSearch;

/**
 * Outputs are the REST bodies (RestSchemas), so REST and MCP answer the same question the same way.
 * Inputs are closed objects (additionalProperties false); descriptions guide AI agents.
 */
final class AbilitySchemas {

	/**
	 * Ability names → [input schema, output schema]. With published languages (1.1.0, two or more)
	 * the catalog reads take an optional `lang` and their outputs carry the translation markers.
	 *
	 * In portal mode (1.2.0, $businesses not null) listings carry their business, the search takes a
	 * `business` slug and `aihs/list-businesses` lists the businesses.
	 *
	 * @param string[]      $languages  Published languages, default first; empty = single-language output.
	 * @param string[]|null $businesses Business slugs in portal mode; null = not a portal.
	 * @return array<string, array{input: array<string, mixed>, output: array<string, mixed>}>
	 *
	 * @phpstan-param list<string> $languages
	 * @phpstan-param list<string>|null $businesses
	 */
	public static function all( array $languages = array(), ?array $businesses = null ): array {
		$all = self::languages( self::base(), $languages );
		if ( null === $businesses ) {
			return $all;
		}
		$business = array(
			'type'        => 'string',
			'description' => 'Yalnızca bu işletmenin ilanları (aihs/list-businesses sonucundaki slug). Verilmezse tüm işletmelerde arar.',
		);
		if ( array() !== $businesses ) {
			$business['enum'] = $businesses;
		}
		$all['aihs/search-listings']['input']['properties']['business'] = $business;
		$all['aihs/search-listings']['output']                          = RestSchemas::with_portal( 'listings', $all['aihs/search-listings']['output'] );
		$all['aihs/get-listing']['output']                              = RestSchemas::with_portal( 'listing', $all['aihs/get-listing']['output'] );
		$all['aihs/list-businesses']                                    = array(
			'input'  => self::input( array() ),
			'output' => self::strip( RestSchemas::with_portal( 'businesses', array() ) ),
		);
		return $all;
	}

	/**
	 * Sibling suggestions (1.22.0): the search takes `network` and may answer with `network_suggestions`.
	 *
	 * @param array<string, array{input: array<string, mixed>, output: array<string, mixed>}> $all Schemas from all().
	 * @return array<string, array{input: array<string, mixed>, output: array<string, mixed>}>
	 */
	public static function with_suggestions( array $all ): array {
		$all['aihs/search-listings']['input']['properties']['network'] = array(
			'type'        => 'boolean',
			'default'     => false,
			'description' => 'true: bu sitede sonuç olsa da doğrulanmış kardeş portallardan öneri (network_suggestions) iste. Sonuç yoksa öneriler kendiliğinden gelir.',
		);
		$all['aihs/search-listings']['output']                         = RestSchemas::with_suggestions( $all['aihs/search-listings']['output'] );
		return $all;
	}

	/**
	 * "Ağda yayınla" (1.26.0): listings carry `network_share`; the search may answer with `network_listings`.
	 *
	 * @param array<string, array{input: array<string, mixed>, output: array<string, mixed>}> $all Schemas from all().
	 * @return array<string, array{input: array<string, mixed>, output: array<string, mixed>}>
	 */
	public static function with_share( array $all ): array {
		$all['aihs/search-listings']['output'] = RestSchemas::with_share( 'listings', $all['aihs/search-listings']['output'] );
		$all['aihs/get-listing']['output']     = RestSchemas::with_share( 'listing', $all['aihs/get-listing']['output'] );
		return $all;
	}

	/**
	 * Adds the multilingual input and output keys (1.1.0) when two or more languages are published.
	 *
	 * @param array<string, array{input: array<string, mixed>, output: array<string, mixed>}> $all       Schemas.
	 * @param string[]                                                                        $languages Published languages.
	 * @return array<string, array{input: array<string, mixed>, output: array<string, mixed>}>
	 *
	 * @phpstan-param list<string> $languages
	 */
	private static function languages( array $all, array $languages ): array {
		if ( count( $languages ) < 2 ) {
			return $all;
		}
		$lang = array(
			'type'        => 'string',
			'enum'        => $languages,
			'description' => 'Cevap dili (ISO 639-1). Çevirisi olmayan alanlar ' . $languages[0] . ' dilinde döner ve translation.missing içinde listelenir.',
		);
		foreach ( array( 'aihs/get-profile', 'aihs/search-listings', 'aihs/get-listing' ) as $name ) {
			$all[ $name ]['input']['properties']           = array_merge( $all[ $name ]['input']['properties'] ?? array(), array( 'lang' => $lang ) );
			$all[ $name ]['input']['additionalProperties'] = false;
		}
		$all['aihs/get-profile']['output']     = self::strip( RestSchemas::get( 'profile', true ) );
		$all['aihs/search-listings']['output'] = self::strip( RestSchemas::get( 'listings', true ) );
		$all['aihs/get-listing']['output']     = RestSchemas::with_languages( 'listing', RestSchemas::listing() );
		return $all;
	}

	/**
	 * Single-language schemas.
	 *
	 * @return array<string, array{input: array<string, mixed>, output: array<string, mixed>}>
	 */
	private static function base(): array {
		return array(
			'aihs/get-profile'        => array(
				'input'  => self::input( array() ),
				'output' => self::strip( RestSchemas::profile() ),
			),
			'aihs/search-listings'    => array(
				'input'  => self::input(
					array(
						'type'       => array(
							'type'        => 'string',
							'enum'        => ListingType::ALL,
							'description' => 'offer = firmanın sattığı, demand = firmanın aradığı, supply = siparişe üretip tedarik edebildiği.',
						),
						'category'   => array(
							'type'        => 'string',
							'description' => 'Kategori, tam eşleşme (büyük/küçük harf duyarsız).',
						),
						'region'     => array(
							'type'        => 'string',
							'description' => 'Bölge, tam eşleşme.',
						),
						'keyword'    => array(
							'type'        => 'string',
							'description' => 'Başlık, açıklama, kategori, bölge veya özellik değerlerinde geçen kelime (ör. "3x2,5").',
						),
						'attributes' => array(
							'type'                 => 'object',
							'description'          => 'Şablon alanı → değer (eşitlik). Alan adları aihs/get-profile ve REST /templates yanıtında.',
							'additionalProperties' => array( 'type' => 'string' ),
						),
						'page'       => array(
							'type'    => 'integer',
							'minimum' => 1,
							'default' => 1,
						),
						'per_page'   => array(
							'type'    => 'integer',
							'minimum' => 1,
							'maximum' => ListingSearch::MAX_PER_PAGE,
							'default' => ListingSearch::DEFAULT_PER_PAGE,
						),
						'sector'     => array(
							'type'        => 'string',
							'enum'        => array_keys( Nace::SECTIONS ),
							'description' => 'Faaliyet alanı: NACE Rev. 2.1 bölüm harfi (ör. H = ulaştırma ve depolama, I = konaklama ve yiyecek hizmetleri). İşletmenin (portalda) veya firmanın bölümüne göre süzer.',
						),
					)
				),
				'output' => self::strip( RestSchemas::listings() ),
			),
			'aihs/get-listing'        => array(
				'input'  => self::input( array( 'id' => self::id() ), array( 'id' ) ),
				'output' => RestSchemas::listing(),
			),
			'aihs/check-availability' => array(
				'input'  => self::input(
					array(
						'id'          => self::id(),
						'quantity'    => array(
							'type'        => 'number',
							'minimum'     => 0,
							'description' => 'İstenen miktar, ilanın biriminde (ör. metre).',
						),
						'within_days' => array(
							'type'        => 'integer',
							'minimum'     => 0,
							'description' => 'En geç kaç gün içinde gerekli.',
						),
					),
					array( 'id' )
				),
				'output' => array(
					'type'                 => 'object',
					'properties'           => array(
						'answer'             => array(
							'type'        => 'string',
							'enum'        => array( Availability::YES, Availability::NO, Availability::UNKNOWN ),
							'description' => 'yes = karşılanabilir, no = karşılanamaz, unknown = ilanda bilgi yok (firmaya sorun).',
						),
						'reasons'            => array(
							'type'  => 'array',
							'items' => array( 'type' => 'string' ),
						),
						'listing_id'         => array( 'type' => array( 'integer', 'null' ) ),
						'available_quantity' => array(
							'type'    => array( 'string', 'null' ),
							'pattern' => RestSchemas::DECIMAL,
						),
						'unit'               => array( 'type' => 'string' ),
						'lead_time_days'     => array( 'type' => array( 'integer', 'null' ) ),
					),
					'required'             => array( 'answer', 'reasons', 'listing_id', 'available_quantity', 'unit', 'lead_time_days' ),
					'additionalProperties' => false,
				),
			),
		);
	}

	/**
	 * Closed input object.
	 *
	 * @param array<string, array<string, mixed>> $properties Properties.
	 * @param string[]                            $required   Required names.
	 * @return array<string, mixed>
	 */
	private static function input( array $properties, array $required = array() ): array {
		// No "properties" key at all when empty: an empty PHP array would be encoded as [] (not an object).
		$schema = array( 'type' => 'object' );
		if ( array() !== $properties ) {
			$schema['properties'] = $properties;
		}
		$schema['additionalProperties'] = false;
		if ( array() !== $required ) {
			$schema['required'] = $required;
		}
		return $schema;
	}

	/**
	 * Listing id.
	 *
	 * @return array<string, mixed>
	 */
	private static function id(): array {
		return array(
			'type'        => 'integer',
			'minimum'     => 1,
			'description' => 'İlan kimliği (aihs/search-listings sonucundaki id).',
		);
	}

	/**
	 * A REST document schema without its $schema and title keys.
	 *
	 * @param array<string, mixed> $schema Schema.
	 * @return array<string, mixed>
	 */
	private static function strip( array $schema ): array {
		unset( $schema['$schema'], $schema['title'] );
		return $schema;
	}
}
