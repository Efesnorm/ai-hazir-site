<?php
/**
 * The "AI Hazır" badge and its verification page.
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\WordPress\Report;

use AIHazirSite\Adapters\Report\BadgeSvg;
use AIHazirSite\Core\Compliance\Badge;
use AIHazirSite\Core\Compliance\ScoreReport;
use AIHazirSite\Core\Features;
use AIHazirSite\WordPress\Compliance\Admin\CompliancePage;
use AIHazirSite\WordPress\Compliance\ComplianceModule;
use AIHazirSite\WordPress\Module;
use AIHazirSite\WordPress\Platform\RewriteRules;
use AIHazirSite\WordPress\Platform\PageCache;

/**
 * While `compliance_report` is on: the `[aihs_rozet]` shortcode and the `ai-hazir-site/rozet`
 * block (both server-rendered, empty below the threshold), and the site's own verification page
 * /ai-hazir-dogrulama/ that the badge links to (site, score, scan date, per-check status).
 * The shortcode is always registered so a switched-off badge renders nothing instead of its tag.
 */
final class BadgeModule implements Module {

	public const QUERY_VAR = 'aihs_verify';
	public const SLUG      = 'ai-hazir-dogrulama';
	public const REWRITE   = '^ai-hazir-dogrulama/?$';
	public const SHORTCODE = 'aihs_rozet';

	/**
	 * Registers hooks.
	 */
	public function register(): void {
		add_shortcode( self::SHORTCODE, array( self::class, 'render' ) );
		add_action( 'init', array( self::class, 'rewrite' ), 20 );
		if ( ! Features::is_enabled( Features::COMPLIANCE_REPORT ) ) {
			return;
		}
		add_action( 'init', array( self::class, 'register_block' ) );
		add_filter( 'query_vars', array( self::class, 'query_vars' ) );
		add_action( 'template_redirect', array( self::class, 'maybe_render_page' ) );
	}

	/**
	 * Removes the rewrite rule on deactivation.
	 */
	public function deactivate(): void {
		RewriteRules::remove_and_flush( self::REWRITE );
	}

	/**
	 * Registers the block from blocks/rozet/block.json.
	 */
	public static function register_block(): void {
		register_block_type(
			dirname( __DIR__, 3 ) . '/blocks/rozet',
			array( 'render_callback' => array( self::class, 'render' ) )
		);
	}

	/**
	 * Adds (feature on) or drops (feature off) the page rule; flushes only when it changes.
	 */
	public static function rewrite(): void {
		$enabled = Features::is_enabled( Features::COMPLIANCE_REPORT );
		if ( $enabled ) {
			add_rewrite_rule( self::REWRITE, 'index.php?' . self::QUERY_VAR . '=1', 'top' );
		}
		$rules   = get_option( 'rewrite_rules' );
		$present = is_array( $rules ) && isset( $rules[ self::REWRITE ] );
		if ( $present !== $enabled ) {
			flush_rewrite_rules( false );
		}
	}

	/**
	 * Registers the query var.
	 *
	 * @param string[] $vars Query vars.
	 * @return string[]
	 */
	public static function query_vars( array $vars ): array {
		$vars[] = self::QUERY_VAR;
		return $vars;
	}

	/**
	 * Verification page URL.
	 */
	public static function url(): string {
		return home_url( '/' . self::SLUG . '/' );
	}

	/**
	 * Score threshold (filterable).
	 */
	public static function threshold(): int {
		return (int) apply_filters( 'aihs_badge_threshold', Badge::DEFAULT_THRESHOLD );
	}

	/**
	 * Badge HTML, or '' (feature off, no scan, or below the threshold).
	 */
	public static function render(): string {
		$latest = ComplianceModule::store()->latest();
		if ( ! Features::is_enabled( Features::COMPLIANCE_REPORT ) || null === $latest || ! Badge::earned( $latest, self::threshold() ) ) {
			return '';
		}
		$score = (int) $latest->score();
		$date  = wp_date( 'd.m.Y', (int) strtotime( $latest->scanned_at ) );
		/* translators: 1: score, 2: date. */
		$label = sprintf( __( 'AI Hazır rozeti: uyum puanı %1$d/100, tarama tarihi %2$s. Doğrulama sayfasını açar.', 'ai-hazir-site' ), $score, $date );
		return '<a class="aihs-badge" href="' . esc_url( self::url() ) . '">' . BadgeSvg::render( __( 'AI Hazır', 'ai-hazir-site' ), $score, (string) $date, $label ) . '</a>';
	}

	/**
	 * `template_redirect`: the verification page.
	 */
	public static function maybe_render_page(): void {
		if ( ! get_query_var( self::QUERY_VAR ) ) {
			return;
		}
		PageCache::exclude();
		status_header( 200 );
		header( 'Content-Type: text/html; charset=utf-8' );
		echo self::page_html(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in page_html().
		exit;
	}

	/**
	 * Verification page HTML (everything escaped here).
	 */
	public static function page_html(): string {
		$latest = ComplianceModule::store()->latest();
		$site   = (string) get_bloginfo( 'name' );
		$title  = __( 'AI Hazır doğrulaması', 'ai-hazir-site' ) . ' – ' . $site;
		$body   = '<h1>' . esc_html( $title ) . '</h1>';

		if ( null === $latest || null === $latest->score() ) {
			$body .= '<p id="aihs-verify-status">' . esc_html__( 'Bu site için henüz ölçülmüş bir uyum taraması yok.', 'ai-hazir-site' ) . '</p>';
		} else {
			$earned = Badge::earned( $latest, self::threshold() );
			$body  .= '<p id="aihs-verify-status">' . esc_html(
				$earned
					/* translators: 1: score, 2: threshold. */
					? sprintf( __( 'AI Hazır kriteri karşılanıyor: uyum puanı %1$d/100 (eşik %2$d).', 'ai-hazir-site' ), (int) $latest->score(), self::threshold() )
					/* translators: 1: score, 2: threshold. */
					: sprintf( __( 'Şu anda AI Hazır kriteri karşılanmıyor: uyum puanı %1$d/100 (eşik %2$d).', 'ai-hazir-site' ), (int) $latest->score(), self::threshold() )
			) . '</p>';
			$body .= '<p>' . esc_html__( 'Tarama tarihi', 'ai-hazir-site' ) . ': ' . esc_html( wp_date( 'd.m.Y H:i', (int) strtotime( $latest->scanned_at ) ) ) . '</p>';
			$body .= self::checks( $latest );
		}
		$body .= '<p><small>' . esc_html__( 'Bu sayfa sitenin kendi AI uyum taramasının son sonucunu gösterir (AI Hazır Site eklentisi).', 'ai-hazir-site' ) . '</small></p>';

		return '<!doctype html><html lang="' . esc_attr( get_bloginfo( 'language' ) ) . '"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
			. '<title>' . esc_html( $title ) . '</title><link rel="canonical" href="' . esc_url( self::url() ) . '"></head><body><main>' . $body . '</main></body></html>';
	}

	/**
	 * Per-check status list.
	 *
	 * @param ScoreReport $latest Latest scan.
	 */
	private static function checks( ScoreReport $latest ): string {
		$labels = CompliancePage::labels();
		$html   = '<table id="aihs-verify-checks"><tbody>';
		foreach ( $latest->results as $row ) {
			$shown = null === $row['ratio'] ? __( 'ölçülemedi', 'ai-hazir-site' ) : rtrim( rtrim( number_format( $row['points'], 2, ',', '' ), '0' ), ',' ) . ' / ' . $row['weight'];
			$html .= '<tr data-check="' . esc_attr( $row['id'] ) . '"><th>' . esc_html( $labels[ $row['id'] ] ?? $row['id'] ) . '</th><td>' . esc_html( $shown ) . '</td></tr>';
		}
		return $html . '</tbody></table>';
	}
}
