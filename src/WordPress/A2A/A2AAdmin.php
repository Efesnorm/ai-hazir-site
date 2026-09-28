<?php
/**
 * Approve and send an A2A quote request to a matched partner agent.
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\WordPress\A2A;

use AIHazirSite\Core\Features;
use AIHazirSite\WordPress\Catalog\Admin\CatalogAdmin;
use AIHazirSite\WordPress\Matching\MatchingAdmin;
use AIHazirSite\WordPress\Matching\MatchingModule;

/**
 * Step 1 (page): the exact JSON-RPC message is shown; it is kept server side under a one-time
 * token. Step 2 (POST, capability + nonce): exactly that message becomes an ApprovedRequest and is
 * sent. Only for partner listings that are in the matches of one of our needs.
 */
final class A2AAdmin {

	public const SLUG    = 'aihs-a2a-send';
	public const APPROVE = 'aihs_a2a_approve';
	public const PENDING = 'aihs_a2a_pending_';

	/**
	 * Registers hooks.
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_menu' ), 30 );
		add_action( 'admin_post_' . self::APPROVE, array( $this, 'on_approve' ) );
	}

	/**
	 * Hidden page (reached from Eşleşmeler).
	 */
	public function add_menu(): void {
		add_submenu_page( 'options.php', __( 'A2A ile teklif iste', 'ai-hazir-site' ), __( 'A2A ile teklif iste', 'ai-hazir-site' ), CatalogAdmin::CAPABILITY, self::SLUG, array( $this, 'render' ) );
	}

	/**
	 * Link from a match row, or '' when A2A sending is not possible for it.
	 *
	 * @param int    $need_id Need.
	 * @param string $partner Partner REST base ('' = local).
	 * @param int    $id      Partner listing id.
	 */
	public static function link( int $need_id, string $partner, int $id ): string {
		if ( '' === $partner || ! Features::is_enabled( Features::A2A ) ) {
			return '';
		}
		return add_query_arg(
			array(
				'page'      => self::SLUG,
				'need'      => $need_id,
				'partner'   => rawurlencode( $partner ),
				'candidate' => $id,
			),
			admin_url( 'admin.php' )
		);
	}

	/**
	 * Renders the page.
	 */
	public function render(): void {
		if ( ! current_user_can( CatalogAdmin::CAPABILITY ) ) {
			return;
		}
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only preview; sending needs the nonce.
		$need      = isset( $_GET['need'] ) ? absint( wp_unslash( $_GET['need'] ) ) : 0;
		$partner   = isset( $_GET['partner'] ) && is_string( $_GET['partner'] ) ? esc_url_raw( rawurldecode( sanitize_text_field( wp_unslash( $_GET['partner'] ) ) ), array( 'https' ) ) : '';
		$candidate = isset( $_GET['candidate'] ) ? absint( wp_unslash( $_GET['candidate'] ) ) : 0;
		$result    = isset( $_GET['sent'] ) ? sanitize_key( wp_unslash( $_GET['sent'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		echo self::page( $need, $partner, $candidate, $result ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in page().
	}

	/**
	 * Preview and approval form (everything escaped here).
	 *
	 * @param int    $need_id      Need.
	 * @param string $partner      Partner REST base.
	 * @param int    $candidate_id Partner listing id.
	 * @param string $result       '' | ok | failed (after sending).
	 */
	public static function page( int $need_id, string $partner, int $candidate_id, string $result = '' ): string {
		$html = '<div class="wrap"><h1>' . esc_html__( 'A2A ile teklif iste', 'ai-hazir-site' ) . '</h1>';
		if ( '' !== $result ) {
			$outcome = get_transient( self::PENDING . 'result_' . get_current_user_id() );
			delete_transient( self::PENDING . 'result_' . get_current_user_id() );
			$text  = is_string( $outcome ) ? $outcome : '';
			$html .= '<div id="aihs-a2a-result" class="notice ' . ( 'ok' === $result ? 'notice-success' : 'notice-error' ) . '"><p>' . esc_html( ( 'ok' === $result ? __( 'Gönderildi. Karşı agent yanıtı:', 'ai-hazir-site' ) : __( 'Gönderilemedi:', 'ai-hazir-site' ) ) . ' ' . $text ) . '</p></div>';
			return $html . '<p><a href="' . esc_url( MatchingAdmin::url( array( 'need' => $need_id ) ) ) . '">' . esc_html__( '← Eşleşmeler', 'ai-hazir-site' ) . '</a></p></div>';
		}

		$pair = self::matched( $need_id, $partner, $candidate_id );
		if ( null === $pair ) {
			return $html . '<p id="aihs-a2a-error">' . esc_html__( 'Bu ilan, aranan ilanınızın eşleşmeleri arasında değil ya da ortak site listesinde yok.', 'ai-hazir-site' ) . '</p></div>';
		}
		$endpoint = A2AOutbox::endpoint( $partner );
		if ( null === $endpoint ) {
			return $html . '<p id="aihs-a2a-error">' . esc_html__( 'Ortak sitenin geçerli bir A2A kartviziti yok (/.well-known/agent-card.json); gönderim yapılamaz.', 'ai-hazir-site' ) . '</p></div>';
		}

		$request = A2AOutbox::quote_request( $pair[0], $pair[1] );
		$token   = wp_generate_password( 20, false );
		set_transient(
			self::PENDING . get_current_user_id() . '_' . $token,
			array(
				'partner'  => $partner,
				'endpoint' => $endpoint,
				'request'  => $request,
				'need'     => $need_id,
			),
			15 * MINUTE_IN_SECONDS
		);

		return $html . '<p>' . esc_html__( 'Aşağıdaki mesaj yalnızca siz onaylarsanız ortak sitenin agent\'ına gönderilir. Karşı tarafta da talep insan onayı bekler.', 'ai-hazir-site' ) . '</p>'
			/* translators: %s: endpoint URL. */
			. '<p>' . esc_html( sprintf( __( 'Alıcı: %s', 'ai-hazir-site' ), $endpoint ) ) . '</p>'
			. '<pre id="aihs-a2a-preview">' . esc_html( (string) wp_json_encode( $request, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) ) . '</pre>'
			. '<form id="aihs-a2a-approve" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="' . esc_attr( self::APPROVE ) . '"><input type="hidden" name="token" value="' . esc_attr( $token ) . '">' . wp_nonce_field( self::APPROVE, '_wpnonce', true, false )
			. get_submit_button( __( 'Onaylıyorum, gönder', 'ai-hazir-site' ) ) . '</form></div>';
	}

	/**
	 * Need and partner listing when the listing is among the need's matches, else null.
	 *
	 * @param int    $need_id      Need.
	 * @param string $partner      Partner REST base.
	 * @param int    $candidate_id Partner listing id.
	 * @return array{0: \AIHazirSite\Core\Catalog\Listing, 1: \AIHazirSite\Core\Catalog\Listing}|null
	 */
	private static function matched( int $need_id, string $partner, int $candidate_id ): ?array {
		$need = MatchingModule::need( $need_id );
		if ( null === $need || ! in_array( $partner, MatchingModule::settings()['partners'], true ) ) {
			return null;
		}
		foreach ( MatchingModule::matches( $need )['matches'] as $match ) {
			if ( $partner . '#' . $candidate_id === $match['candidate']->key() ) {
				return array( $need, $match['candidate']->listing );
			}
		}
		return null;
	}

	/**
	 * `admin_post_aihs_a2a_approve`.
	 */
	public function on_approve(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified in handle_approve().
		wp_safe_redirect( self::handle_approve( wp_unslash( $_POST ) ) );
		exit;
	}

	/**
	 * Sends the previewed message after approval; returns where to redirect.
	 *
	 * @param array<mixed> $post Unslashed $_POST.
	 */
	public static function handle_approve( array $post ): string {
		CatalogAdmin::authorize( self::APPROVE );
		$token   = is_string( $post['token'] ?? null ) ? preg_replace( '/[^A-Za-z0-9]/', '', $post['token'] ) : '';
		$key     = self::PENDING . get_current_user_id() . '_' . $token;
		$pending = get_transient( $key );
		delete_transient( $key );
		if ( ! is_array( $pending ) || ! in_array( $pending['partner'] ?? '', MatchingModule::settings()['partners'], true ) || ! is_array( $pending['request'] ?? null ) || ! is_string( $pending['endpoint'] ?? null ) ) {
			wp_die( esc_html__( 'Onay süresi doldu veya geçersiz; önizlemeyi yeniden açın.', 'ai-hazir-site' ), 400 );
		}
		$sent = A2AOutbox::send_approved( new ApprovedRequest( (string) $pending['partner'], $pending['endpoint'], $pending['request'], get_current_user_id() ) );
		set_transient( self::PENDING . 'result_' . get_current_user_id(), $sent['ok'] ? (string) ( $sent['answer']['result']['task']['status']['message']['parts'][0]['text'] ?? '' ) : $sent['error'], 5 * MINUTE_IN_SECONDS );
		return add_query_arg(
			array(
				'page' => self::SLUG,
				'need' => (int) ( $pending['need'] ?? 0 ),
				'sent' => $sent['ok'] ? 'ok' : 'failed',
			),
			admin_url( 'admin.php' )
		);
	}
}
