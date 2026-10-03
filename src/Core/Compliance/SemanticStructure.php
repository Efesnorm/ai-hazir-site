<?php
/**
 * Semantic structure advice for the compliance scan (1.19.0).
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\Core\Compliance;

/**
 * Whether a page's language, title, main content and headings can be told apart by a machine. Each rule follows a
 * published standard:
 * - page language `<html lang>`: WCAG 2.2 SC 3.1.1 (A);
 * - page title `<title>`: WCAG 2.2 SC 2.4.2 (A);
 * - one main content landmark (`<main>` or role="main"): HTML Standard `main` element, WCAG SC 1.3.1 / 2.4.1;
 * - one `<h1>` and no skipped heading levels: W3C WAI headings tutorial, WCAG SC 1.3.1.
 * Advice only: it is shown apart from the score and never changes it (the score stays comparable).
 */
final class SemanticStructure {

	public const FIX = 'Bunlar temanın ve sayfa oluşturucunun işidir; eklenti temaya dokunmaz. Ana içerik alanını <main> olarak işaretleyen ve sayfa dilini (<html lang>) veren bir tema ya da ayar kullanın; her sayfada tek bir <h1> olsun ve başlık seviyeleri sırayla insin (h2, sonra h3).';

	/**
	 * Findings for one page ("<url>: …"), empty when the structure is fine.
	 *
	 * @param string $url  Page URL.
	 * @param string $html Page HTML.
	 * @return list<string>
	 */
	public static function page( string $url, string $html ): array {
		$doc      = new Html( $html );
		$findings = array();

		if ( '' === $doc->html_lang() ) {
			$findings[] = 'Sayfanın dili belirtilmemiş (<html lang>).';
		}
		if ( '' === $doc->title() ) {
			$findings[] = 'Sayfa başlığı (<title>) yok.';
		}

		$main = $doc->main_count();
		if ( 0 === $main ) {
			$findings[] = 'Ana içerik <main> ile işaretlenmemiş; agentlar menü, altbilgi ve içeriği ayıramaz.';
		} elseif ( $main > 1 ) {
			$findings[] = sprintf( '%d ana içerik alanı (<main>) var; bir tane olmalı.', $main );
		}

		$levels = $doc->heading_levels();
		$h1     = count( array_keys( $levels, 1, true ) );
		if ( 0 === $h1 ) {
			$findings[] = 'Ana başlık (<h1>) yok.';
		} elseif ( $h1 > 1 ) {
			$findings[] = sprintf( '%d tane <h1> var; bir tane olmalı.', $h1 );
		}
		$previous = 0;
		foreach ( $levels as $level ) {
			if ( $previous > 0 && $level > $previous + 1 ) {
				$findings[] = sprintf( 'Başlık seviyesi atlanıyor: <h%d> sonrasında <h%d>.', $previous, $level );
				break;
			}
			$previous = $level;
		}

		return array_map( static fn( string $finding ): string => $url . ': ' . $finding, $findings );
	}

	/**
	 * Findings for every sample page that answered.
	 *
	 * @param Site $site Site.
	 * @return list<string>
	 */
	public static function site( Site $site ): array {
		$findings = array();
		foreach ( $site->page_responses() as $page ) {
			if ( $page['response']->ok() ) {
				array_push( $findings, ...self::page( $page['url'], $page['response']->body ) );
			}
		}
		return $findings;
	}
}
