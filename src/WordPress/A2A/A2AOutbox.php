<?php
/**
 * Outgoing A2A messages to partner agents.
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\WordPress\A2A;

use AIHazirSite\Adapters\A2A\A2ASkills;
use AIHazirSite\Adapters\A2A\AgentCardBuilder;
use AIHazirSite\Adapters\A2A\AgentCardValidator;
use AIHazirSite\Adapters\A2A\JsonRpcServer;
use AIHazirSite\Core\Catalog\Listing;
use AIHazirSite\Core\Inquiry\Inquiry;
use AIHazirSite\Core\Security\AuditLog;
use AIHazirSite\WordPress\Catalog\WpProfileRepository;
use AIHazirSite\WordPress\Matching\MatchingModule;
use AIHazirSite\WordPress\Platform\WpHttpClient;
use AIHazirSite\WordPress\Platform\WpSecret;

/**
 * Only to agents of sites in the partner list (matching settings), only after a match, only with
 * an ApprovedRequest. The partner's card is read from its own host and validated; its JSON-RPC
 * endpoint must be on the same host. Every send and its answer go to the audit log.
 */
final class A2AOutbox {

	/**
	 * The partner's agent endpoint, or null (not a partner, no valid card, other host).
	 *
	 * @param string $partner Partner REST base.
	 */
	public static function endpoint( string $partner ): ?string {
		if ( ! in_array( $partner, MatchingModule::settings()['partners'], true ) ) {
			return null;
		}
		$host = wp_parse_url( $partner, PHP_URL_HOST );
		if ( ! is_string( $host ) || '' === $host ) {
			return null;
		}
		$body = ( new WpHttpClient() )->get( 'https://' . $host . '/' . A2AModule::CARD_PATH );
		$url  = null === $body ? null : AgentCardValidator::jsonrpc_url( json_decode( $body, true ) );
		return null !== $url && wp_parse_url( $url, PHP_URL_HOST ) === $host ? $url : null;
	}

	/**
	 * The quote request for a matched partner listing (shown to the user before approval).
	 *
	 * @param Listing $need      Our need.
	 * @param Listing $candidate Partner listing.
	 * @return array<string, mixed>
	 */
	public static function quote_request( Listing $need, Listing $candidate ): array {
		$profile = ( new WpProfileRepository() )->get();
		$amount  = null === $need->quantity ? '' : ' ' . $need->quantity . ' ' . $need->unit;
		$lead    = null === $need->lead_time_days ? '' : sprintf( /* translators: %d: days. */ __( ' En geç %d gün içinde teslim.', 'ai-hazir-site' ), $need->lead_time_days );
		return JsonRpcServer::send_message(
			wp_generate_uuid4(),
			array(
				'skill'      => A2ASkills::QUOTE,
				'kind'       => Inquiry::KIND_QUOTE_REQUEST,
				'listing_id' => (int) $candidate->id,
				'subject'    => $need->title,
				/* translators: 1: listing title, 2: amount, 3: lead time sentence. */
				'message'    => trim( sprintf( __( '"%1$s" ilanınız için%2$s teklif rica ederiz.%3$s', 'ai-hazir-site' ), $candidate->title, $amount, $lead ) ),
				'contact'    => array(
					'name'    => $profile->name,
					'company' => $profile->name,
					'email'   => $profile->contact_email,
					'phone'   => $profile->contact_phone,
				),
			)
		);
	}

	/**
	 * Sends an approved request; returns the decoded answer or the reason it failed.
	 *
	 * @param ApprovedRequest $approved Approved request.
	 * @return array{ok: bool, answer: array<string, mixed>|null, error: string}
	 */
	public static function send_approved( ApprovedRequest $approved ): array {
		$hash     = ( new WpSecret() )->hmac( 'a2a-out|' . (string) wp_parse_url( $approved->partner, PHP_URL_HOST ) );
		$audit    = A2AModule::audit();
		$response = wp_safe_remote_post(
			$approved->endpoint,
			array(
				'timeout'    => 15,
				'user-agent' => 'AI Hazir Site/' . AIHS_VERSION,
				'headers'    => array(
					'Content-Type' => 'application/json',
					'A2A-Version'  => AgentCardBuilder::PROTOCOL_VERSION,
				),
				'body'       => (string) wp_json_encode( $approved->request ),
			)
		);
		$code     = is_wp_error( $response ) ? 0 : (int) wp_remote_retrieve_response_code( $response );
		$answer   = is_wp_error( $response ) ? null : json_decode( wp_remote_retrieve_body( $response ), true );
		$answer   = is_array( $answer ) ? $answer : null;
		$state    = is_string( $answer['result']['task']['status']['state'] ?? null ) ? $answer['result']['task']['status']['state'] : '';
		$ok       = 200 === $code && null !== $answer && ! isset( $answer['error'] );
		$audit->record( Inquiry::CHANNEL_A2A, $hash, 'send', $ok ? AuditLog::OUTCOME_ACCEPTED : AuditLog::OUTCOME_INVALID, null, 'user=' . $approved->user_id . ';http=' . $code . ';state=' . $state );
		return array(
			'ok'     => $ok,
			'answer' => $answer,
			'error'  => $ok ? '' : ( is_wp_error( $response ) ? $response->get_error_message() : 'HTTP ' . $code . ( isset( $answer['error']['message'] ) && is_string( $answer['error']['message'] ) ? ': ' . $answer['error']['message'] : '' ) ),
		);
	}
}
