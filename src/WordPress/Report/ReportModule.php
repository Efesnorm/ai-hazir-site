<?php
/**
 * Compliance report and badge (U4) – WordPress wiring.
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\WordPress\Report;

use AIHazirSite\Core\Features;
use AIHazirSite\WordPress\Module;

/**
 * While `compliance_report` is on: Tools → AI Uyum Raporu (HTML and PDF).
 */
final class ReportModule implements Module {

	public const SLUG       = 'aihs-report';
	public const CAPABILITY = 'manage_options';
	public const PDF        = 'aihs_report_pdf';

	/**
	 * Registers hooks.
	 */
	public function register(): void {
		if ( ! Features::is_enabled( Features::COMPLIANCE_REPORT ) ) {
			return;
		}
		if ( is_admin() ) {
			add_action( 'admin_menu', array( self::class, 'add_page' ) );
			add_action( 'admin_post_' . self::PDF, array( self::class, 'download' ) );
		}
	}

	/**
	 * Nothing to clean up.
	 */
	public function deactivate(): void {
	}

	/**
	 * Adds the page under Tools.
	 */
	public static function add_page(): void {
		add_management_page( __( 'AI Uyum Raporu', 'ai-hazir-site' ), __( 'AI Uyum Raporu', 'ai-hazir-site' ), self::CAPABILITY, self::SLUG, array( self::class, 'render' ) );
	}

	/**
	 * Renders the page.
	 */
	public static function render(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'Bu sayfaya erişim yetkiniz yok.', 'ai-hazir-site' ), 403 );
		}
		$link = wp_nonce_url( admin_url( 'admin-post.php?action=' . self::PDF ), self::PDF );
		echo '<div class="wrap"><p><a class="button button-primary" href="' . esc_url( $link ) . '">' . esc_html__( 'PDF indir', 'ai-hazir-site' ) . '</a></p>'
			. ComplianceReportView::html( ComplianceReportView::data() ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in html().
			. '</div>';
	}

	/**
	 * `admin_post_aihs_report_pdf`: streams the PDF.
	 */
	public static function download(): void {
		self::authorize();
		$pdf = ComplianceReportView::pdf( ComplianceReportView::data() );
		nocache_headers();
		header( 'Content-Type: application/pdf' );
		header( 'Content-Disposition: attachment; filename="ai-uyum-raporu-' . gmdate( 'Y-m-d' ) . '.pdf"' );
		header( 'Content-Length: ' . strlen( $pdf ) );
		echo $pdf; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Binary PDF, not HTML.
		exit;
	}

	/**
	 * Capability and nonce check; dies on failure.
	 */
	public static function authorize(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'Bu işlem için yetkiniz yok.', 'ai-hazir-site' ), 403 );
		}
		check_admin_referer( self::PDF );
	}
}
