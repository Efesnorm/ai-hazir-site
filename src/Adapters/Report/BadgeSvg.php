<?php
/**
 * The "AI Hazır" badge image.
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\Adapters\Report;

/**
 * An inline SVG (no external request, scales, readable by screen readers through role="img" and
 * aria-label). All text is escaped here.
 */
final class BadgeSvg {

	/**
	 * The SVG.
	 *
	 * @param string $title Badge text (e.g. "AI Hazır").
	 * @param int    $score Score 0–100.
	 * @param string $date  Scan date as shown.
	 * @param string $label Accessible description.
	 */
	public static function render( string $title, int $score, string $date, string $label ): string {
		$esc = static fn( string $text ): string => htmlspecialchars( $text, ENT_QUOTES | ENT_XML1, 'UTF-8' );
		return '<svg xmlns="http://www.w3.org/2000/svg" width="180" height="56" viewBox="0 0 180 56" role="img" aria-label="' . $esc( $label ) . '">'
			. '<title>' . $esc( $label ) . '</title>'
			. '<rect width="180" height="56" rx="8" fill="#0b5d3b"/>'
			. '<rect x="118" width="62" height="56" rx="8" fill="#12824f"/>'
			. '<text x="12" y="26" fill="#ffffff" font-family="Arial, Helvetica, sans-serif" font-size="16" font-weight="700">' . $esc( $title ) . '</text>'
			. '<text x="12" y="44" fill="#d7f5e6" font-family="Arial, Helvetica, sans-serif" font-size="11">' . $esc( $date ) . '</text>'
			. '<text x="149" y="36" fill="#ffffff" font-family="Arial, Helvetica, sans-serif" font-size="20" font-weight="700" text-anchor="middle">' . max( 0, min( 100, $score ) ) . '</text>'
			. '</svg>';
	}
}
