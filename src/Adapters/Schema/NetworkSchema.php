<?php
/**
 * Portal network in Schema.org (1.20.0).
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\Adapters\Schema;

/**
 * The network as an umbrella Organization (schema.org parentOrganization / subOrganization): every site's Organization
 * names the network as its parent; the mother's home page also carries the network node with its sub-organizations.
 * The network node's @id is "<mother URL>#network".
 */
final class NetworkSchema {

	/**
	 * The network node's @id.
	 *
	 * @param string $mother Mother site URL.
	 */
	public static function id( string $mother ): string {
		return $mother . '#network';
	}

	/**
	 * Reference to the network, for parentOrganization.
	 *
	 * @param string $name   Network name.
	 * @param string $mother Mother site URL.
	 * @return array<string, string>
	 */
	public static function parent( string $name, string $mother ): array {
		return array(
			'@type' => 'Organization',
			'@id'   => self::id( $mother ),
			'name'  => $name,
			'url'   => $mother,
		);
	}

	/**
	 * Home page graph with the network: parentOrganization on our Organization; on the mother, the network node too.
	 *
	 * @param array<string, mixed>                                         $document Home document (@graph).
	 * @param string                                                       $name     Network name.
	 * @param string                                                       $mother   Mother site URL.
	 * @param string                                                       $own_url     This site's URL.
	 * @param string                                                       $self_name This site's name.
	 * @param list<array{url: string, name: string, country: string}>|null $members  Verified sites (mother only), else null.
	 * @return array<string, mixed>
	 */
	public static function home( array $document, string $name, string $mother, string $own_url, string $self_name, ?array $members ): array {
		if ( ! is_array( $document['@graph'] ?? null ) ) {
			return $document;
		}
		foreach ( $document['@graph'] as $i => $node ) {
			$id = $own_url . '#organization';
			if ( is_array( $node ) && 'Organization' === ( $node['@type'] ?? null ) && ( $node['@id'] ?? null ) === $id ) {
				$document['@graph'][ $i ]['parentOrganization'] = self::parent( $name, $mother );
			}
		}
		if ( null !== $members ) {
			$subs = array();
			foreach ( array_merge(
				array(
					array(
						'url'  => $own_url,
						'name' => $self_name,
					),
				),
				$members
			) as $site ) {
				$subs[] = array(
					'@type' => 'Organization',
					'@id'   => $site['url'] . '#organization',
					'name'  => '' !== $site['name'] ? $site['name'] : self::host( $site['url'] ),
					'url'   => $site['url'],
				);
			}
			$document['@graph'][] = self::parent( $name, $mother ) + array( 'subOrganization' => $subs );
		}
		return $document;
	}

	/**
	 * Host of a URL (a name for a site that has none).
	 *
	 * @param string $url URL.
	 */
	private static function host( string $url ): string {
		return (string) preg_replace( '#^https?://([^/]+).*$#', '$1', $url );
	}

	/**
	 * AI catalog feed with the network on its publisher (also when an SEO plugin owns the home Organization).
	 *
	 * @param array<string, mixed> $document Catalog document.
	 * @param string               $name     Network name.
	 * @param string               $mother   Mother site URL.
	 * @return array<string, mixed>
	 */
	public static function catalog( array $document, string $name, string $mother ): array {
		if ( is_array( $document['publisher'] ?? null ) ) {
			$document['publisher']['parentOrganization'] = self::parent( $name, $mother );
		}
		return $document;
	}
}
