<?php
/**
 * AI Hazır Site → Eşleşmeler.
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\WordPress\Matching;

use AIHazirSite\WordPress\Admin\AdminMenu;
use AIHazirSite\Core\Catalog\ListingType;
use AIHazirSite\Core\Matching\MatchWeights;
use AIHazirSite\WordPress\A2A\A2AAdmin;
use AIHazirSite\WordPress\Catalog\Admin\CatalogAdmin;
use AIHazirSite\WordPress\Catalog\Admin\FormState;

/**
 * Pick a need; see the candidates by score with the points of every criterion. Settings: weights
 * and partner sites. Partner data is untrusted and escaped like everything else.
 */
final class MatchingAdmin {

	public const SLUG = 'aihs-matching';
	public const SAVE = 'aihs_save_matching';

	/**
	 * Registers hooks.
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_menu' ), 20 );
		add_action( 'admin_post_' . self::SAVE, array( $this, 'on_save' ) );
	}

	/**
	 * Submenu under AI Katalog.
	 */
	public function add_menu(): void {
		AdminMenu::add( __( 'Eşleşmeler', 'ai-hazir-site' ), CatalogAdmin::CAPABILITY, self::SLUG, array( $this, 'render' ) );
	}

	/**
	 * Page URL.
	 *
	 * @param array<string, string|int> $args Extra query args.
	 */
	public static function url( array $args = array() ): string {
		return AdminMenu::url( self::SLUG, $args );
	}

	/**
	 * Renders the page.
	 */
	public function render(): void {
		if ( ! current_user_can( CatalogAdmin::CAPABILITY ) ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only view selection.
		$need = isset( $_GET['need'] ) ? absint( wp_unslash( $_GET['need'] ) ) : 0;
		echo self::page( $need, FormState::take() ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in page().
	}

	/**
	 * Criterion labels.
	 *
	 * @return array<string, string>
	 */
	public static function labels(): array {
		return array(
			'attributes' => __( 'Özellik uyumu', 'ai-hazir-site' ),
			'quantity'   => __( 'Miktar karşılama', 'ai-hazir-site' ),
			'lead_time'  => __( 'Teslim uyumu', 'ai-hazir-site' ),
			'price'      => __( 'Fiyat örtüşmesi', 'ai-hazir-site' ),
		);
	}

	/**
	 * Page HTML (everything escaped here).
	 *
	 * @param int                                                                                       $need_id Selected need (0 = none).
	 * @param array{errors: array<string, string>, input: array<string, mixed>, warnings: list<string>} $state   Form state.
	 */
	public static function page( int $need_id, array $state ): string {
		$html  = '<div class="wrap"><h1>' . esc_html__( 'Eşleşmeler', 'ai-hazir-site' ) . '</h1><p>' . esc_html__( 'Aranan ilanlarınıza uyan satılan ve tedarik edilebilen ilanlar (bu site ve yetkilendirdiğiniz ortak siteler). Eşleşmeler öneridir; hiçbir teklif veya mesaj otomatik gönderilmez.', 'ai-hazir-site' ) . '</p>';
		$html .= array() === $state['errors'] ? '' : '<div class="notice notice-error"><p>' . esc_html( implode( ' ', $state['errors'] ) ) . '</p></div>';

		$needs = MatchingModule::current( ListingType::DEMAND );
		$html .= '<form method="get" action="' . esc_url( admin_url( 'admin.php' ) ) . '"><input type="hidden" name="page" value="' . esc_attr( self::SLUG ) . '"><label>' . esc_html__( 'Aranan ilan', 'ai-hazir-site' ) . ' <select name="need" id="aihs-need">';
		foreach ( $needs as $need ) {
			$html .= '<option value="' . esc_attr( (string) $need->id ) . '"' . selected( $need_id, $need->id, false ) . '>' . esc_html( $need->title ) . '</option>';
		}
		$html .= '</select></label> ' . get_submit_button( __( 'Eşleştir', 'ai-hazir-site' ), 'secondary', '', false ) . '</form>';

		$need = $need_id > 0 ? MatchingModule::need( $need_id ) : null;
		if ( null !== $need ) {
			$html .= self::results( MatchingModule::matches( $need ), (int) $need->id );
		} elseif ( array() === $needs ) {
			$html .= '<p>' . esc_html__( 'Geçerli aranan ilan yok.', 'ai-hazir-site' ) . '</p>';
		}
		return $html . self::settings_form() . '</div>';
	}

	/**
	 * Result table.
	 *
	 * @param array{matches: list<array{candidate: \AIHazirSite\Core\Matching\Candidate, score: float, breakdown: list<array{criterion: string, weight: float, value: float, points: float}>}>, excluded: array<string, string>, unreachable: list<string>} $result  Result.
	 * @param int                                                                                                                                                                                                                                           $need_id Need (for the A2A link).
	 */
	private static function results( array $result, int $need_id ): string {
		$labels = self::labels();
		$html   = '';
		foreach ( $result['unreachable'] as $partner ) {
			/* translators: %s: partner address. */
			$html .= '<div class="notice notice-warning"><p class="aihs-unreachable">' . esc_html( sprintf( __( 'Ortak siteye erişilemedi, atlandı: %s', 'ai-hazir-site' ), $partner ) ) . '</p></div>';
		}
		$html .= '<table id="aihs-matches" class="widefat striped"><thead><tr><th>' . esc_html__( 'Puan', 'ai-hazir-site' ) . '</th><th>' . esc_html__( 'İlan', 'ai-hazir-site' ) . '</th><th>' . esc_html__( 'Kaynak', 'ai-hazir-site' ) . '</th><th>' . esc_html__( 'Açıklama', 'ai-hazir-site' ) . '</th></tr></thead><tbody>';
		foreach ( $result['matches'] as $match ) {
			$candidate = $match['candidate'];
			$parts     = array();
			foreach ( $match['breakdown'] as $row ) {
				$parts[] = ( $labels[ $row['criterion'] ] ?? $row['criterion'] ) . ' ' . number_format_i18n( $row['points'], 1 ) . '/' . number_format_i18n( 100 * $row['weight'], 1 );
			}
			$title = '' === $candidate->url ? esc_html( $candidate->listing->title ) : '<a href="' . esc_url( $candidate->url ) . '">' . esc_html( $candidate->listing->title ) . '</a>';
			$html .= '<tr data-candidate="' . esc_attr( $candidate->key() ) . '"><td data-value="score">' . esc_html( number_format_i18n( $match['score'], 1 ) ) . '</td><td>' . $title . '</td><td>' . esc_html( '' === $candidate->source ? __( 'Bu site', 'ai-hazir-site' ) : (string) wp_parse_url( $candidate->source, PHP_URL_HOST ) ) . '</td><td>' . esc_html( implode( ' · ', $parts ) ) . self::a2a_link( $need_id, $candidate ) . '</td></tr>';
		}
		$html .= '</tbody></table>';
		if ( array() !== $result['excluded'] ) {
			$html .= '<details><summary>' . esc_html( sprintf( /* translators: %d: count. */ __( 'Kesin şartlara uymayan %d ilan', 'ai-hazir-site' ), count( $result['excluded'] ) ) ) . '</summary><ul id="aihs-excluded">';
			foreach ( $result['excluded'] as $key => $reason ) {
				$html .= '<li data-candidate="' . esc_attr( $key ) . '">' . esc_html( $reason ) . '</li>';
			}
			$html .= '</ul></details>';
		}
		return $html;
	}

	/**
	 * "A2A ile teklif iste" for a partner candidate (1.6.0, only while A2A is on), else ''.
	 *
	 * @param int                                  $need_id   Need.
	 * @param \AIHazirSite\Core\Matching\Candidate $candidate Candidate.
	 */
	private static function a2a_link( int $need_id, \AIHazirSite\Core\Matching\Candidate $candidate ): string {
		$url = A2AAdmin::link( $need_id, $candidate->source, (int) $candidate->listing->id );
		return '' === $url ? '' : ' <a class="button button-small aihs-a2a-link" href="' . esc_url( $url ) . '">' . esc_html__( 'A2A ile teklif iste', 'ai-hazir-site' ) . '</a>';
	}

	/**
	 * Weights and partners.
	 */
	private static function settings_form(): string {
		$settings = MatchingModule::settings();
		$html     = '<h2>' . esc_html__( 'Ayarlar', 'ai-hazir-site' ) . '</h2><form id="aihs-matching-settings" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="' . esc_attr( self::SAVE ) . '">' . wp_nonce_field( self::SAVE, '_wpnonce', true, false ) . '<table class="form-table"><tbody>';
		foreach ( self::labels() as $criterion => $label ) {
			$html .= '<tr><th><label for="aihs-w-' . esc_attr( $criterion ) . '">' . esc_html( $label ) . '</label></th><td><input id="aihs-w-' . esc_attr( $criterion ) . '" name="weights[' . esc_attr( $criterion ) . ']" type="number" min="0" step="0.05" class="small-text" value="' . esc_attr( (string) round( $settings['weights'][ $criterion ] ?? 0.25, 4 ) ) . '"></td></tr>';
		}
		return $html . '<tr><th><label for="aihs-partners">' . esc_html__( 'Ortak siteler (her satıra bir REST adresi, https)', 'ai-hazir-site' ) . '</label></th><td><textarea id="aihs-partners" name="partners" rows="4" class="large-text code" placeholder="https://fabrika.example/wp-json/aihs/v1/">' . esc_textarea( implode( "\n", $settings['partners'] ) ) . '</textarea><p class="description">' . esc_html__( 'Yalnızca yetkilendirdiğiniz ortakları ekleyin; otomatik keşif yapılmaz.', 'ai-hazir-site' ) . '</p></td></tr></tbody></table>' . get_submit_button( __( 'Kaydet', 'ai-hazir-site' ) ) . '</form>';
	}

	/**
	 * `admin_post_aihs_save_matching`.
	 */
	public function on_save(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified in handle_save().
		wp_safe_redirect( self::handle_save( wp_unslash( $_POST ) ) );
		exit;
	}

	/**
	 * Saves the settings; returns where to redirect.
	 *
	 * @param array<mixed> $post Unslashed $_POST.
	 */
	public static function handle_save( array $post ): string {
		CatalogAdmin::authorize( self::SAVE );
		$weights = array();
		foreach ( MatchWeights::CRITERIA as $criterion ) {
			$value                 = is_array( $post['weights'] ?? null ) ? ( $post['weights'][ $criterion ] ?? 0 ) : 0;
			$weights[ $criterion ] = is_numeric( $value ) ? max( 0.0, (float) $value ) : 0.0;
		}
		$partners = array();
		$rejected = array();
		foreach ( (array) preg_split( '/\R/', is_string( $post['partners'] ?? null ) ? $post['partners'] : '' ) as $line ) {
			$line = (string) $line;
			$line = trim( $line );
			if ( '' === $line ) {
				continue;
			}
			$url = esc_url_raw( $line, array( 'https' ) );
			if ( str_starts_with( $url, 'https://' ) ) {
				$partners[] = trailingslashit( $url );
			} else {
				$rejected[] = $line;
			}
		}
		update_option(
			MatchingModule::OPTION,
			array(
				'weights'  => ( new MatchWeights( $weights ) )->weights,
				'partners' => array_values( array_unique( $partners ) ),
			),
			false
		);
		FormState::put( array() === $rejected ? array() : array( 'partners' => __( 'https olmayan adresler kaydedilmedi:', 'ai-hazir-site' ) . ' ' . implode( ', ', $rejected ) ) );
		return self::url();
	}
}
