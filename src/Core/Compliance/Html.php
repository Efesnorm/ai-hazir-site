<?php
/**
 * HTML helpers for compliance checks.
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\Core\Compliance;

use DOMDocument;
use DOMElement;
use DOMXPath;

/**
 * Parses a page the way a crawler without JavaScript sees it.
 */
final class Html {

	/**
	 * Parsed document.
	 *
	 * @var DOMXPath
	 */
	private DOMXPath $xpath;

	/**
	 * Constructor.
	 *
	 * @param string $html HTML source.
	 */
	public function __construct( string $html ) {
		$dom = new DOMDocument();
		if ( '' !== trim( $html ) ) {
			$dom->loadHTML( '<?xml encoding="utf-8"?>' . $html, LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_NONET );
		}
		$this->xpath = new DOMXPath( $dom );
	}

	/**
	 * JSON-LD items (arrays and @graph are flattened) and the number of blocks that failed to parse.
	 *
	 * @return array{items: list<array<string, mixed>>, invalid: int, blocks: int}
	 */
	public function json_ld(): array {
		$items   = array();
		$invalid = 0;
		$blocks  = 0;

		foreach ( $this->query( '//script[translate(@type, "ABCDEFGHIJKLMNOPQRSTUVWXYZ", "abcdefghijklmnopqrstuvwxyz")="application/ld+json"]' ) as $script ) {
			++$blocks;
			$data = json_decode( trim( $script->textContent ), true ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- DOM API.
			if ( ! is_array( $data ) ) {
				++$invalid;
				continue;
			}
			foreach ( self::flatten( $data ) as $item ) {
				$items[] = $item;
			}
		}

		return array(
			'items'   => $items,
			'invalid' => $invalid,
			'blocks'  => $blocks,
		);
	}

	/**
	 * Text a crawler sees without running JavaScript (scripts, styles and templates removed).
	 */
	public function visible_text(): string {
		foreach ( $this->query( '//script|//style|//noscript|//template|//svg' ) as $node ) {
			$node->parentNode?->removeChild( $node ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- DOM API.
		}
		$body = $this->query( '//body' );
		$text = null !== ( $body[0] ?? null ) ? $body[0]->textContent : ''; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- DOM API.

		return trim( (string) preg_replace( '/\s+/u', ' ', $text ) );
	}

	/**
	 * Lower-cased content of the robots meta tags.
	 */
	public function meta_robots(): string {
		$values = array();
		foreach ( $this->query( '//meta[translate(@name, "ABCDEFGHIJKLMNOPQRSTUVWXYZ", "abcdefghijklmnopqrstuvwxyz")="robots"]' ) as $meta ) {
			$values[] = strtolower( $meta->getAttribute( 'content' ) );
		}
		return implode( ', ', $values );
	}

	/**
	 * The `lang` attribute of the root element (1.19.0).
	 */
	public function html_lang(): string {
		$root = $this->query( '/html' );
		return null === ( $root[0] ?? null ) ? '' : trim( $root[0]->getAttribute( 'lang' ) );
	}

	/**
	 * Text of the first <title> (1.19.0).
	 */
	public function title(): string {
		$title = $this->query( '//head/title|//title' );
		return null === ( $title[0] ?? null ) ? '' : trim( (string) preg_replace( '/\s+/u', ' ', $title[0]->textContent ) ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- DOM API.
	}

	/**
	 * Main content landmarks that are not hidden: <main> and role="main" (1.19.0).
	 */
	public function main_count(): int {
		return count( $this->query( '//main[not(@hidden)]|//*[not(self::main)][translate(@role, "MAIN", "main")="main"][not(@hidden)]' ) );
	}

	/**
	 * Heading levels (1–6) in document order (1.19.0).
	 *
	 * @return list<int>
	 */
	public function heading_levels(): array {
		$levels = array();
		foreach ( $this->query( '//h1|//h2|//h3|//h4|//h5|//h6' ) as $heading ) {
			$levels[] = (int) substr( strtolower( $heading->nodeName ), 1 ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- DOM API.
		}
		return $levels;
	}

	/**
	 * Links inside <nav> elements (falls back to <header>).
	 *
	 * @return list<string>
	 */
	public function nav_links(): array {
		$links = $this->hrefs( '//nav//a[@href]' );
		return array() !== $links ? $links : $this->hrefs( '//header//a[@href]' );
	}

	/**
	 * Href of the first <link> with the given rel value.
	 *
	 * @param string $rel Rel value.
	 */
	public function link_rel( string $rel ): string {
		foreach ( $this->query( '//link[@rel][@href]' ) as $link ) {
			$rels = preg_split( '/\s+/', trim( $link->getAttribute( 'rel' ) ) );
			if ( is_array( $rels ) && in_array( $rel, $rels, true ) ) {
				return $link->getAttribute( 'href' );
			}
		}
		return '';
	}

	/**
	 * Hrefs matching an XPath.
	 *
	 * @param string $xpath XPath of <a> elements.
	 * @return list<string>
	 */
	private function hrefs( string $xpath ): array {
		$hrefs = array();
		foreach ( $this->query( $xpath ) as $a ) {
			$hrefs[] = $a->getAttribute( 'href' );
		}
		return $hrefs;
	}

	/**
	 * Elements matching an XPath.
	 *
	 * @param string $xpath XPath.
	 * @return list<DOMElement>
	 */
	private function query( string $xpath ): array {
		$nodes = $this->xpath->query( $xpath );
		$list  = array();
		foreach ( false === $nodes ? array() : $nodes as $node ) {
			if ( $node instanceof DOMElement ) {
				$list[] = $node;
			}
		}
		return $list;
	}

	/**
	 * Flattens JSON-LD lists and @graph into a list of objects.
	 *
	 * @param array<mixed> $data Decoded JSON-LD.
	 * @return list<array<string, mixed>>
	 */
	private static function flatten( array $data ): array {
		if ( array_is_list( $data ) ) {
			$items = array();
			foreach ( $data as $entry ) {
				if ( is_array( $entry ) ) {
					array_push( $items, ...self::flatten( $entry ) );
				}
			}
			return $items;
		}
		if ( isset( $data['@graph'] ) && is_array( $data['@graph'] ) ) {
			return self::flatten( $data['@graph'] );
		}
		return array( $data );
	}
}
