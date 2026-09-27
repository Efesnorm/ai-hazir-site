<?php
/**
 * Tools → AI Uyum admin page.
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\WordPress\Compliance\Admin;

use AIHazirSite\Core\Compliance\CheckResult;
use AIHazirSite\Core\Compliance\ScoreReport;
use AIHazirSite\WordPress\Compliance\ComplianceModule;

/**
 * "Scan now" button, score, per-check results (biggest gain first) and comparison with earlier scans.
 */
final class CompliancePage {

	public const SLUG       = 'aihs-compliance';
	public const CAPABILITY = 'manage_options';
	public const ACTION     = 'aihs_run_scan';

	/**
	 * Registers admin hooks.
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_page' ) );
		add_action( 'admin_post_' . self::ACTION, array( $this, 'run_scan' ) );
	}

	/**
	 * Adds the page under Tools.
	 */
	public function add_page(): void {
		add_management_page( __( 'AI Uyum', 'ai-hazir-site' ), __( 'AI Uyum', 'ai-hazir-site' ), self::CAPABILITY, self::SLUG, array( $this, 'render' ) );
	}

	/**
	 * Check labels.
	 *
	 * @return array<string, string>
	 */
	public static function labels(): array {
		return array(
			'structured_data'   => __( 'Yapılandırılmış veri', 'ai-hazir-site' ),
			'readability'       => __( 'İçerik okunabilirliği', 'ai-hazir-site' ),
			'machine_interface' => __( 'Makine arayüzü (REST / MCP)', 'ai-hazir-site' ),
			'bot_access'        => __( 'AI bot erişimi', 'ai-hazir-site' ),
			'llms_txt'          => __( 'llms.txt', 'ai-hazir-site' ),
			'freshness'         => __( 'Tazelik', 'ai-hazir-site' ),
			'advanced'          => __( 'İleri standartlar (A2A kartviziti)', 'ai-hazir-site' ),
		);
	}

	/**
	 * Renders the page.
	 */
	public function render(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return;
		}

		$scans = ComplianceModule::store()->all();

		echo '<div class="wrap"><h1>' . esc_html__( 'AI Uyum', 'ai-hazir-site' ) . '</h1>';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display-only flag set by our own redirect.
		if ( isset( $_GET['scanned'] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Tarama tamamlandı.', 'ai-hazir-site' ) . '</p></div>';
		}

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="' . esc_attr( self::ACTION ) . '">';
		wp_nonce_field( self::ACTION );
		submit_button( __( 'Şimdi tara', 'ai-hazir-site' ), 'primary', 'submit', false );
		echo ' <span class="description">' . esc_html__( 'Tarama sitenin kendi sayfalarını çeker; en fazla 60 saniye sürer.', 'ai-hazir-site' ) . '</span>';
		echo '</form>';

		if ( array() === $scans ) {
			echo '<p>' . esc_html__( 'Henüz tarama yapılmadı.', 'ai-hazir-site' ) . '</p></div>';
			return;
		}

		echo self::render_report( $scans[0], $scans[1] ?? null ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in render_report().
		echo self::render_history( $scans ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in render_history().
		echo '</div>';
	}

	/**
	 * Score and per-check table, biggest gain first, compared with the previous scan.
	 *
	 * @param ScoreReport      $report   Latest report.
	 * @param ScoreReport|null $previous Previous report.
	 */
	public static function render_report( ScoreReport $report, ?ScoreReport $previous = null ): string {
		$score  = $report->score();
		$labels = self::labels();

		$html  = '<h2>' . esc_html__( 'AI uyum puanı', 'ai-hazir-site' ) . ': <span id="aihs-score">' . esc_html( null === $score ? '–' : $score . '/100' ) . '</span>';
		$prior = null === $previous ? null : $previous->score();
		if ( null !== $score && null !== $prior ) {
			$delta = $score - $prior;
			$html .= ' <small id="aihs-delta">(' . esc_html( sprintf( '%s%d', $delta > 0 ? '+' : '', $delta ) ) . ')</small>';
		}
		$html .= '</h2>';

		if ( 0 === $report->measured_weight() ) {
			$html .= '<div class="notice notice-warning inline" id="aihs-unreachable"><p>' . esc_html__( 'Site kendi adresine erişemedi, hiçbir kontrol ölçülemedi. Barındırma firmanız WordPress\'in kendine istek atmasını (loopback) engelliyor olabilir; Araçlar → Site Sağlığı ekranındaki "loopback" sonucuna bakın.', 'ai-hazir-site' ) . '</p></div>';
		}

		$html .= '<p>' . esc_html(
			sprintf(
				/* translators: 1: date, 2: measured weight, 3: seconds, 4: scoring version. */
				__( 'Tarih: %1$s · Ölçülen ağırlık: %2$d/100 · Süre: %3$s sn · Puanlama sürümü: %4$d', 'ai-hazir-site' ),
				wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) strtotime( $report->scanned_at ) ),
				$report->measured_weight(),
				number_format_i18n( $report->elapsed_ms / 1000, 1 ),
				$report->score_version
			)
		) . '</p>';

		$html .= '<table class="widefat striped" id="aihs-checks"><thead><tr>'
			. '<th>' . esc_html__( 'Kontrol', 'ai-hazir-site' ) . '</th>'
			. '<th>' . esc_html__( 'Puan', 'ai-hazir-site' ) . '</th>'
			. '<th>' . esc_html__( 'Kazanılabilir', 'ai-hazir-site' ) . '</th>'
			. '<th>' . esc_html__( 'Önceki tarama', 'ai-hazir-site' ) . '</th>'
			. '<th>' . esc_html__( 'Bulgular', 'ai-hazir-site' ) . '</th>'
			. '<th>' . esc_html__( 'Nasıl düzeltilir', 'ai-hazir-site' ) . '</th>'
			. '</tr></thead><tbody>';

		foreach ( $report->by_gain() as $row ) {
			$before = null === $previous ? null : $previous->result( $row['id'] );
			$points = null === $row['ratio'] ? __( 'ölçülemedi', 'ai-hazir-site' ) : self::number( $row['points'] ) . '/' . $row['weight'];
			$prior  = null === $before || null === $before['ratio'] ? '–' : self::number( $before['points'] ) . '/' . $before['weight'];

			$html .= '<tr data-check="' . esc_attr( $row['id'] ) . '" class="aihs-level-' . esc_attr( null === $row['ratio'] ? 'unmeasured' : $row['level'] ) . '">'
				. '<td><strong>' . esc_html( $labels[ $row['id'] ] ?? $row['id'] ) . '</strong></td>'
				. '<td>' . esc_html( $points ) . '</td>'
				. '<td>' . esc_html( $row['gain'] > 0 ? '+' . self::number( $row['gain'] ) : '–' ) . '</td>'
				. '<td>' . esc_html( $prior ) . '</td>'
				. '<td>' . self::findings( $row['findings'] ) . '</td>'
				. '<td>' . esc_html( $row['fix'] ) . '</td>'
				. '</tr>';
		}

		return $html . '</tbody></table>';
	}

	/**
	 * Earlier scans.
	 *
	 * @param ScoreReport[] $scans Reports, newest first.
	 */
	public static function render_history( array $scans ): string {
		$html = '<h2>' . esc_html__( 'Önceki taramalar', 'ai-hazir-site' ) . '</h2><table class="widefat striped" id="aihs-history"><thead><tr><th>'
			. esc_html__( 'Tarih', 'ai-hazir-site' ) . '</th><th>' . esc_html__( 'Puan', 'ai-hazir-site' ) . '</th></tr></thead><tbody>';
		foreach ( $scans as $scan ) {
			$score = $scan->score();
			$html .= '<tr><td>' . esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) strtotime( $scan->scanned_at ) ) ) . '</td><td>'
				. esc_html( null === $score ? '–' : (string) $score ) . '</td></tr>';
		}
		return $html . '</tbody></table>';
	}

	/**
	 * `admin_post_aihs_run_scan`: runs a scan and returns to the page.
	 */
	public function run_scan(): void {
		$this->authorize();

		if ( function_exists( 'set_time_limit' ) ) {
			set_time_limit( 90 ); // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged -- The scan may take up to 60 s.
		}
		ComplianceModule::run();

		wp_safe_redirect( add_query_arg( array( 'page' => self::SLUG, 'scanned' => 1 ), admin_url( 'tools.php' ) ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		exit;
	}

	/**
	 * Capability and nonce check; dies on failure.
	 */
	public function authorize(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'Bu işlem için yetkiniz yok.', 'ai-hazir-site' ), 403 );
		}
		check_admin_referer( self::ACTION );
	}

	/**
	 * Findings as an escaped list.
	 *
	 * @param string[] $findings Findings.
	 */
	private static function findings( array $findings ): string {
		if ( array() === $findings ) {
			return '';
		}
		return '<ul>' . implode( '', array_map( static fn( string $f ): string => '<li>' . esc_html( $f ) . '</li>', $findings ) ) . '</ul>';
	}

	/**
	 * Localized number without trailing zeros.
	 *
	 * @param float $value Value.
	 */
	private static function number( float $value ): string {
		return number_format_i18n( $value, floor( $value ) === $value ? 0 : 1 );
	}
}
