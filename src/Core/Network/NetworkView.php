<?php
/**
 * What this site publishes about its network (1.20.0).
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\Core\Network;

/**
 * Built from the settings and the stored check results. Only verified (mutually consented) sites are published.
 *
 * State shape (stored by the platform):
 * - members:    url → {status, name, country, checked_at, verified_at} (the mother's checks of its members);
 * - self:       {status, checked_at, verified_at} (a member's check of itself at its mother);
 * - mother_doc: the mother's last /network answer (a member's cache), or null;
 * - seen:       url → observed server IP of a verified site that called us (for allow-lists, 1.21.0).
 *
 * @phpstan-import-type Document from NetworkCheck
 * @phpstan-type Site array{url: string, name: string, country: string}
 */
final class NetworkView {

	/**
	 * Constructor.
	 *
	 * @param NetworkSettings      $settings Settings.
	 * @param array<string, mixed> $state    Stored state.
	 * @param string               $own_url     This site's URL (normalized).
	 * @param string               $name     This site's name.
	 * @param string               $country  This site's country code ('' if unknown).
	 * @param int                  $now      Now.
	 */
	public function __construct(
		private readonly NetworkSettings $settings,
		private readonly array $state,
		private readonly string $own_url,
		private readonly string $name,
		private readonly string $country,
		private readonly int $now
	) {
	}

	/**
	 * Status of a member as seen by this mother (with the 24-hour grace).
	 *
	 * @param string $url Member URL.
	 */
	public function member_status( string $url ): string {
		$row = is_array( $this->state['members'][ $url ] ?? null ) ? $this->state['members'][ $url ] : array();
		return NetworkCheck::effective( (string) ( $row['status'] ?? NetworkCheck::UNREACHABLE ), isset( $row['verified_at'] ) ? (int) $row['verified_at'] : null, $this->now );
	}

	/**
	 * Status of this member at its mother (with the 24-hour grace).
	 */
	public function self_status(): string {
		$row = is_array( $this->state['self'] ?? null ) ? $this->state['self'] : array();
		return NetworkCheck::effective( (string) ( $row['status'] ?? NetworkCheck::UNREACHABLE ), isset( $row['verified_at'] ) ? (int) $row['verified_at'] : null, $this->now );
	}

	/**
	 * Whether this site is part of a verified network now.
	 */
	public function active(): bool {
		return '' !== $this->network_name() && array() !== $this->siblings();
	}

	/**
	 * Network name ('' when not in a verified network).
	 */
	public function network_name(): string {
		if ( NetworkSettings::ROLE_MOTHER === $this->settings->role ) {
			return $this->settings->name;
		}
		if ( NetworkSettings::ROLE_MEMBER === $this->settings->role && NetworkCheck::VERIFIED === $this->self_status() ) {
			return (string) ( $this->mother_document()['name'] ?? '' );
		}
		return '';
	}

	/**
	 * The mother's URL ('' when not in a network).
	 */
	public function mother(): string {
		return match ( $this->settings->role ) {
			NetworkSettings::ROLE_MOTHER => $this->own_url,
			NetworkSettings::ROLE_MEMBER => $this->settings->mother,
			default                      => '',
		};
	}

	/**
	 * The other verified sites of the network (this site left out).
	 *
	 * @return list<array{url: string, name: string, country: string}>
	 */
	public function siblings(): array {
		$sites = array();
		if ( NetworkSettings::ROLE_MOTHER === $this->settings->role ) {
			foreach ( $this->settings->members as $url ) {
				if ( NetworkCheck::VERIFIED === $this->member_status( $url ) ) {
					$row     = is_array( $this->state['members'][ $url ] ?? null ) ? $this->state['members'][ $url ] : array();
					$sites[] = array(
						'url'     => $url,
						'name'    => NetworkCheck::text( $row['name'] ?? '', 100 ),
						'country' => NetworkCheck::country( $row['country'] ?? '' ),
					);
				}
			}
		} elseif ( NetworkSettings::ROLE_MEMBER === $this->settings->role && NetworkCheck::VERIFIED === $this->self_status() ) {
			foreach ( $this->mother_document()['members'] ?? array() as $member ) {
				if ( $member['verified'] && $member['url'] !== $this->own_url ) {
					$sites[] = array(
						'url'     => $member['url'],
						'name'    => $member['name'],
						'country' => $member['country'],
					);
				}
			}
		}
		return $sites;
	}

	/**
	 * This site's own /network answer.
	 *
	 * @return array{role: string, name: string, mother: string, members: list<array{url: string, name: string, country: string, verified: bool}>}
	 */
	public function document(): array {
		if ( NetworkSettings::ROLE_NONE === $this->settings->role ) {
			return array(
				'role'    => NetworkSettings::ROLE_NONE,
				'name'    => '',
				'mother'  => '',
				'members' => array(),
			);
		}
		$members = array();
		if ( NetworkSettings::ROLE_MOTHER === $this->settings->role ) {
			// The mother is a site of the network too; members find themselves in this list once verified.
			$members[] = array(
				'url'      => $this->own_url,
				'name'     => NetworkCheck::text( $this->name, 100 ),
				'country'  => NetworkCheck::country( $this->country ),
				'verified' => true,
			);
		}
		foreach ( $this->siblings() as $site ) {
			$members[] = $site + array( 'verified' => true );
		}
		if ( NetworkSettings::ROLE_MEMBER === $this->settings->role && array() !== $members ) {
			$members[] = array(
				'url'      => $this->own_url,
				'name'     => NetworkCheck::text( $this->name, 100 ),
				'country'  => NetworkCheck::country( $this->country ),
				'verified' => true,
			);
		}
		return array(
			'role'    => $this->settings->role,
			'name'    => $this->network_name(),
			'mother'  => $this->mother(),
			'members' => $members,
		);
	}

	/**
	 * The mother's cached answer (member side).
	 *
	 * @return array|null
	 *
	 * @phpstan-return Document|null
	 */
	private function mother_document(): ?array {
		return NetworkCheck::document( $this->state['mother_doc'] ?? null );
	}
}
