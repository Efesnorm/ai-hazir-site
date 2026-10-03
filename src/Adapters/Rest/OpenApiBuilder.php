<?php
/**
 * OpenAPI 3.1 description of the AI Katalog REST API (1.18.0).
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\Adapters\Rest;

use AIHazirSite\Core\Catalog\ListingType;

/**
 * Platform-neutral producer of one OpenAPI 3.1.0 document (https://spec.openapis.org/oas/v3.1.0) for the
 * endpoints that are on. Response shapes come from RestSchemas and the inquiry schemas are passed in by the
 * platform (the abilities' own schemas), so the contract has a single source; OpenAPI 3.1 schemas are JSON Schema 2020-12, which reads our draft-04 subset
 * (type, enum, pattern, required, properties, items, minimum…) unchanged, so only the `$schema` keyword is dropped.
 * Served as the RFC 8631 "service-desc" of the site.
 */
final class OpenApiBuilder {

	public const VERSION = '3.1.0';

	/**
	 * Media type registered with IANA for OpenAPI documents.
	 */
	public const MEDIA_TYPE = 'application/vnd.oai.openapi+json';

	/**
	 * The document.
	 *
	 * @param string               $title         API title (company or site name).
	 * @param string               $version       Plugin version.
	 * @param string               $server        REST base URL without trailing slash (…/wp-json/aihs/v1).
	 * @param bool                 $multilingual  Answers in several languages (?lang=, Accept-Language).
	 * @param bool                 $portal        Portal mode: /businesses and listing owners.
	 * @param array<string, mixed> $inquiry_request POST /inquiries body schema; empty when the inquiry box is off.
	 * @param array<string, mixed> $inquiry_receipt Its 201 answer schema.
	 * @param bool                 $network         Portal network (1.20.0): GET /network.
	 * @return array<string, mixed>
	 */
	public static function build( string $title, string $version, string $server, bool $multilingual, bool $portal, array $inquiry_request = array(), array $inquiry_receipt = array(), bool $network = false ): array {
		$schema = static function ( string $name ) use ( $multilingual, $portal ): array {
			$schema = RestSchemas::get( $name, $multilingual );
			return self::plain( $portal ? RestSchemas::with_portal( $name, $schema ) : $schema );
		};

		$schemas = array(
			'Profile'   => $schema( 'profile' ),
			'Listings'  => $schema( 'listings' ),
			'Listing'   => $schema( 'listing' ),
			'Templates' => $schema( 'templates' ),
			'Error'     => array(
				'type'       => 'object',
				'properties' => array(
					'code'    => array( 'type' => 'string' ),
					'message' => array( 'type' => 'string' ),
					'data'    => array( 'type' => 'object' ),
				),
				'required'   => array( 'code', 'message' ),
			),
		);

		$read  = static fn( string $summary, string $id, string $schema_ref, array $parameters = array(), array $more = array() ): array => array(
			'get' => array(
				'summary'     => $summary,
				'operationId' => $id,
				'parameters'  => array_merge( $parameters, $multilingual ? array( array( '$ref' => '#/components/parameters/lang' ) ) : array() ),
				'responses'   => array(
					'200' => array(
						'description' => $summary,
						'content'     => array( 'application/json' => array( 'schema' => array( '$ref' => '#/components/schemas/' . $schema_ref ) ) ),
					),
					'304' => array( '$ref' => '#/components/responses/NotModified' ),
					'429' => array( '$ref' => '#/components/responses/TooManyRequests' ),
				) + $more,
			),
		);
		$query = static fn( string $name, array $schema, string $description ): array => array(
			'name'        => $name,
			'in'          => 'query',
			'required'    => false,
			'description' => $description,
			'schema'      => $schema,
		);

		$listing_params = array(
			$query(
				'type',
				array(
					'type' => 'string',
					'enum' => ListingType::ALL,
				),
				'offer = satılan, demand = aranan, supply = tedarik edilebilen.'
			),
			$query( 'category', array( 'type' => 'string' ), 'Kategori (büyük/küçük harf ve Türkçe karakter duyarsız).' ),
			$query( 'region', array( 'type' => 'string' ), 'Bölge.' ),
			$query(
				'page',
				array(
					'type'    => 'integer',
					'minimum' => 1,
					'default' => 1,
				),
				'Sayfa.'
			),
			$query(
				'per_page',
				array(
					'type'    => 'integer',
					'minimum' => 1,
					'maximum' => ListingsQuery::MAX_PER_PAGE,
					'default' => ListingsQuery::DEFAULT_PER_PAGE,
				),
				'Sayfa başına ilan.'
			),
		);
		if ( $portal ) {
			$listing_params[] = $query( 'business', array( 'type' => 'string' ), 'Yalnızca bu işletmenin ilanları (işletme kısa adı).' );
		}

		$schema_names = array(
			'type' => 'string',
			'enum' => RestSchemas::NAMES,
		);
		$paths        = array(
			'/profile'       => $read( 'Firma profili', 'getProfile', 'Profile' ),
			'/listings'      => $read( 'Geçerli ilanlar (sayfalı)', 'listListings', 'Listings', $listing_params, array( '400' => array( '$ref' => '#/components/responses/BadRequest' ) ) ),
			'/listings/{id}' => $read(
				'Tek ilan',
				'getListing',
				'Listing',
				array(
					array(
						'name'     => 'id',
						'in'       => 'path',
						'required' => true,
						'schema'   => array(
							'type'    => 'integer',
							'minimum' => 1,
						),
					),
				),
				array( '404' => array( '$ref' => '#/components/responses/NotFound' ) )
			),
			'/templates'     => $read( 'Sektör şablonlarının alan tanımları', 'listTemplates', 'Templates' ),
			'/schema/{name}' => array(
				'get' => array(
					'summary'     => 'Bir yanıtın JSON Şeması',
					'operationId' => 'getSchema',
					'parameters'  => array(
						array(
							'name'     => 'name',
							'in'       => 'path',
							'required' => true,
							'schema'   => $schema_names,
						),
					),
					'responses'   => array(
						'200' => array(
							'description' => 'JSON Şeması',
							'content'     => array( 'application/json' => array( 'schema' => array( 'type' => 'object' ) ) ),
						),
					),
				),
			),
			'/openapi.json'  => array(
				'get' => array(
					'summary'     => 'Bu belge',
					'operationId' => 'getOpenApi',
					'responses'   => array(
						'200' => array(
							'description' => 'OpenAPI 3.1 belgesi',
							'content'     => array( self::MEDIA_TYPE => array( 'schema' => array( 'type' => 'object' ) ) ),
						),
					),
				),
			),
		);

		if ( $network ) {
			$schemas['Network'] = self::network_schema();
			$paths['/network']  = $read( 'Portal ağı: ağ adı, anne site ve karşılıklı doğrulanmış siteler', 'getNetwork', 'Network' );
		}

		if ( $portal ) {
			$schemas['Businesses']       = self::plain( RestSchemas::with_portal( 'businesses', array() ) );
			$paths['/businesses']        = $read( 'İşletmeler', 'listBusinesses', 'Businesses' );
			$paths['/schema/businesses'] = array(
				'get' => array(
					'summary'     => 'İşletmeler yanıtının JSON Şeması',
					'operationId' => 'getBusinessesSchema',
					'responses'   => array(
						'200' => array(
							'description' => 'JSON Şeması',
							'content'     => array( 'application/json' => array( 'schema' => array( 'type' => 'object' ) ) ),
						),
					),
				),
			);
		}

		if ( array() !== $inquiry_request ) {
			$schemas['InquiryRequest'] = $inquiry_request;
			$schemas['InquiryReceipt'] = $inquiry_receipt;
			$paths['/inquiries']       = array(
				'post' => array(
					'summary'     => 'Firmaya teklif isteği veya talep bırak',
					'description' => 'Otomatik onay ve otomatik yanıt yoktur; firma talebi inceleyip verilen iletişim bilgisinden döner.',
					'operationId' => 'createInquiry',
					'requestBody' => array(
						'required' => true,
						'content'  => array( 'application/json' => array( 'schema' => array( '$ref' => '#/components/schemas/InquiryRequest' ) ) ),
					),
					'responses'   => array(
						'201' => array(
							'description' => 'Talep alındı',
							'content'     => array( 'application/json' => array( 'schema' => array( '$ref' => '#/components/schemas/InquiryReceipt' ) ) ),
						),
						'400' => array( '$ref' => '#/components/responses/BadRequest' ),
						'429' => array( '$ref' => '#/components/responses/TooManyRequests' ),
					),
				),
			);
		}

		$components = array(
			'schemas'   => $schemas,
			'responses' => array(
				'NotModified'     => array( 'description' => 'Değişmedi (If-None-Match ile gönderilen ETag güncel).' ),
				'BadRequest'      => array(
					'description' => 'Geçersiz parametre veya gövde.',
					'content'     => array( 'application/json' => array( 'schema' => array( '$ref' => '#/components/schemas/Error' ) ) ),
				),
				'NotFound'        => array(
					'description' => 'Bulunamadı veya süresi doldu.',
					'content'     => array( 'application/json' => array( 'schema' => array( '$ref' => '#/components/schemas/Error' ) ) ),
				),
				'TooManyRequests' => array(
					'description' => 'İstemci başına istek sınırı aşıldı.',
					'headers'     => array(
						'Retry-After' => array(
							'description' => 'Kaç saniye sonra yeniden denenebilir.',
							'schema'      => array( 'type' => 'integer' ),
						),
					),
				),
			),
		);
		if ( $multilingual ) {
			$components['parameters'] = array(
				'lang' => array(
					'name'        => 'lang',
					'in'          => 'query',
					'required'    => false,
					'description' => 'Yanıt dili (ör. en); verilmezse Accept-Language başlığı kullanılır.',
					'schema'      => array( 'type' => 'string' ),
				),
			);
		}

		return array(
			'openapi'    => self::VERSION,
			'info'       => array(
				'title'       => '' === trim( $title ) ? 'AI Katalog API' : $title . ' – AI Katalog API',
				'version'     => $version,
				'description' => 'Salt okunur AI Katalog: firma profili, geçerli ilanlar (satılan, aranan, tedarik edilebilen) ve sektör şablonları. Tutarlar yuvarlama olmasın diye metin olarak verilir ("42.50"). Yanıtlarda ETag, Last-Modified ve Cache-Control bulunur.',
			),
			'servers'    => array( array( 'url' => $server ) ),
			'security'   => array(),
			'paths'      => $paths,
			'components' => $components,
		);
	}

	/**
	 * GET /network answer (1.20.0).
	 *
	 * @return array<string, mixed>
	 */
	public static function network_schema(): array {
		$string = array( 'type' => 'string' );
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'role'    => array(
					'type' => 'string',
					'enum' => array( 'none', 'mother', 'member' ),
				),
				'name'    => $string,
				'mother'  => $string,
				'members' => array(
					'type'  => 'array',
					'items' => array(
						'type'                 => 'object',
						'properties'           => array(
							'url'      => $string,
							'name'     => $string,
							'country'  => array(
								'type'    => 'string',
								'pattern' => '^([A-Z]{2})?$',
							),
							'verified' => array( 'type' => 'boolean' ),
						),
						'required'             => array( 'url', 'name', 'country', 'verified' ),
						'additionalProperties' => false,
					),
				),
			),
			'required'             => array( 'role', 'name', 'mother', 'members' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * A RestSchemas schema without its draft-04 `$schema` keyword (the OpenAPI 3.1 dialect applies).
	 *
	 * @param array<string, mixed> $schema Schema.
	 * @return array<string, mixed>
	 */
	private static function plain( array $schema ): array {
		unset( $schema['$schema'] );
		return $schema;
	}
}
