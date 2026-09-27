<?php
/**
 * The inquiry channels: MCP ability and REST endpoint over one service.
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\WordPress\Inquiry;

use AIHazirSite\Adapters\Abilities\InquirySchemas;
use AIHazirSite\Core\Features;
use AIHazirSite\Core\Inquiry\Inquiry;
use AIHazirSite\Core\Inquiry\InquiryResult;
use AIHazirSite\Core\Templates\TemplateRegistry;
use AIHazirSite\WordPress\Abilities\AbilitiesModule;
use AIHazirSite\WordPress\Catalog\WpProfileRepository;
use AIHazirSite\WordPress\Rest\RestModule;
use AIHazirSite\WordPress\Templates\TemplatesModule;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * A site whose profile template forbids prices (the `service` template: e.g. a law firm) takes only
 * referral requests: `aihs/submit-inquiry` is not registered at all; `aihs/request-referral` is.
 * Every other site gets `aihs/submit-inquiry`. REST `POST /aihs/v1/inquiries` follows the same rule.
 * Answers disclose a reference only, never the status (a spammer learns nothing about quarantine).
 */
final class InquiryChannels {

	/**
	 * Kinds this site accepts.
	 *
	 * @return list<string>
	 */
	public static function kinds(): array {
		return self::referral_only() ? array( Inquiry::KIND_REFERRAL ) : array( Inquiry::KIND_QUOTE_REQUEST, Inquiry::KIND_OFFER, Inquiry::KIND_REFERRAL );
	}

	/**
	 * Whether the site's own template forbids prices (read even while templates are switched off,
	 * so a law firm never gets an offer channel by accident).
	 */
	public static function referral_only(): bool {
		$registry = TemplatesModule::registry() ?? new TemplateRegistry( array( TemplateRegistry::data_dir() ) );
		return ! $registry->get( ( new WpProfileRepository() )->get()->template )->price;
	}

	/**
	 * The one inquiry ability of this site.
	 */
	public static function ability_name(): string {
		return self::referral_only() ? InquirySchemas::REFERRAL : InquirySchemas::SUBMIT;
	}

	/**
	 * Whether the MCP / abilities channel is on.
	 */
	public static function abilities_enabled(): bool {
		return Features::is_enabled( Features::INQUIRIES ) && Features::is_enabled( Features::ABILITIES );
	}

	/**
	 * Whether the REST channel is on.
	 */
	public static function rest_enabled(): bool {
		return Features::is_enabled( Features::INQUIRIES ) && Features::is_enabled( Features::REST_API );
	}

	/**
	 * `wp_abilities_api_init`: registers this site's inquiry ability.
	 */
	public static function register_ability(): void {
		$referral = self::referral_only();
		wp_register_ability(
			self::ability_name(),
			array(
				'label'               => $referral ? __( 'Yönlendirme talebi bırak', 'ai-hazir-site' ) : __( 'Teklif veya talep bırak', 'ai-hazir-site' ),
				'description'         => $referral
					? __( 'Büroya iletilmek üzere bir yönlendirme/iletişim talebi bırakır. Uzmanlık alanı, dava türleri, referans işler gibi her konu yazılabilir; ücret sorulamaz ve ücret bilgisi verilmez. Otomatik yanıt gönderilmez; büro talebi inceleyip size döner.', 'ai-hazir-site' )
					: __( 'Firmaya teklif isteği, teklif veya iletişim talebi bırakır. Hiçbir talep otomatik onaylanmaz ve otomatik yanıt gönderilmez; firma talebi inceleyip size döner.', 'ai-hazir-site' ),
				'category'            => AbilitiesModule::CATEGORY,
				'input_schema'        => InquirySchemas::input( self::kinds() ),
				'output_schema'       => InquirySchemas::output(),
				'execute_callback'    => array( self::class, 'execute' ),
				'permission_callback' => '__return_true',
				'meta'                => array(
					'annotations'  => array(
						'readonly'    => false,
						'destructive' => false,
						'idempotent'  => false,
					),
					'show_in_rest' => false,
				),
			)
		);
	}

	/**
	 * Ability execution (MCP or other callers; the source is an AI agent).
	 *
	 * @param mixed $input Validated input.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function execute( mixed $input = null ): array|WP_Error {
		$result = self::submit( is_array( $input ) ? $input : array(), Inquiry::CHANNEL_MCP, Inquiry::SOURCE_AI );
		if ( ! $result->is_stored() ) {
			return new WP_Error( 'aihs_inquiry_' . $result->outcome, implode( ' ', $result->errors ), array( 'status' => null === $result->retry_after ? 400 : 429 ) );
		}
		return self::receipt( $result );
	}

	/**
	 * `rest_api_init`: POST /aihs/v1/inquiries.
	 */
	public static function register_route(): void {
		register_rest_route(
			RestModule::NAMESPACE,
			'/inquiries',
			array(
				'methods'             => 'POST',
				'callback'            => array( self::class, 'post' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * POST /inquiries.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public static function post( WP_REST_Request $request ): WP_REST_Response {
		$params = (array) $request->get_json_params();
		$params = array() !== $params ? $params : $request->get_body_params();
		$source = isset( $params['source'] ) && in_array( $params['source'], array( Inquiry::SOURCE_AI, Inquiry::SOURCE_HUMAN ), true ) ? (string) $params['source'] : Inquiry::SOURCE_UNKNOWN;
		unset( $params['source'], $params['status'] );

		$result = self::submit( $params, Inquiry::CHANNEL_REST, $source );
		if ( $result->is_stored() ) {
			return new WP_REST_Response( self::receipt( $result ), 201 );
		}
		$status   = null === $result->retry_after ? 400 : 429;
		$response = new WP_REST_Response(
			array(
				'code'    => 'aihs_inquiry_' . $result->outcome,
				'message' => implode( ' ', $result->errors ),
				'data'    => array(
					'status' => $status,
					'errors' => $result->errors,
				),
			),
			$status
		);
		if ( null !== $result->retry_after ) {
			$response->header( 'Retry-After', (string) $result->retry_after );
		}
		return $response;
	}

	/**
	 * Submits through the single write point.
	 *
	 * @param array<string, mixed> $input   Input.
	 * @param string               $channel Channel.
	 * @param string               $source  Source.
	 */
	public static function submit( array $input, string $channel, string $source ): InquiryResult {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) && is_string( $_SERVER['REMOTE_ADDR'] ) ? (string) filter_var( wp_unslash( $_SERVER['REMOTE_ADDR'] ), FILTER_VALIDATE_IP ) : '';
		return InquiryModule::service()->submit( $input, $channel, $source, $ip, self::kinds() );
	}

	/**
	 * What the submitter gets back.
	 *
	 * @param InquiryResult $result Stored result.
	 * @return array{received: true, reference: int, message: string}
	 */
	private static function receipt( InquiryResult $result ): array {
		return array(
			'received'  => true,
			'reference' => (int) $result->inquiry?->id,
			'message'   => __( 'Talebiniz firmaya iletildi. Otomatik yanıt gönderilmez; firma inceleyip verdiğiniz iletişim bilgisinden size dönecek.', 'ai-hazir-site' ),
		);
	}
}
