<?php
/**
 * AgentCard contract check.
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\Adapters\A2A;

/**
 * Checks a card against the A2A 1.0.0 AgentCard definition (a2a.proto: REQUIRED fields and their
 * types, proto3 JSON names). Used before publishing our card (a half or old-format card is never
 * published) and for partner cards before sending to them.
 */
final class AgentCardValidator {

	/**
	 * Required AgentCard fields (a2a.proto, field_behavior REQUIRED).
	 */
	public const REQUIRED = array( 'name', 'description', 'supportedInterfaces', 'version', 'capabilities', 'defaultInputModes', 'defaultOutputModes', 'skills' );

	/**
	 * Problems of a card ([] = valid).
	 *
	 * @param mixed $card Decoded card.
	 * @return list<string>
	 */
	public static function errors( mixed $card ): array {
		if ( ! is_array( $card ) || array_is_list( $card ) ) {
			return array( 'Kartvizit bir JSON nesnesi değil.' );
		}
		$errors = array();
		foreach ( self::REQUIRED as $field ) {
			if ( ! array_key_exists( $field, $card ) ) {
				$errors[] = "{$field} eksik.";
			}
		}
		foreach ( array( 'name', 'description', 'version' ) as $field ) {
			if ( isset( $card[ $field ] ) && ( ! is_string( $card[ $field ] ) || '' === $card[ $field ] ) ) {
				$errors[] = "{$field} boş olmayan metin olmalı.";
			}
		}
		if ( isset( $card['capabilities'] ) && ( ! is_array( $card['capabilities'] ) || ( array() !== $card['capabilities'] && array_is_list( $card['capabilities'] ) ) ) ) {
			$errors[] = 'capabilities nesne olmalı.';
		}
		foreach ( array( 'defaultInputModes', 'defaultOutputModes' ) as $field ) {
			if ( isset( $card[ $field ] ) && ! self::strings( $card[ $field ], true ) ) {
				$errors[] = "{$field} boş olmayan metin listesi olmalı.";
			}
		}
		$interfaces = $card['supportedInterfaces'] ?? null;
		if ( isset( $card['supportedInterfaces'] ) && ( ! is_array( $interfaces ) || ! array_is_list( $interfaces ) || array() === $interfaces ) ) {
			$errors[] = 'supportedInterfaces boş olmayan liste olmalı.';
		} elseif ( is_array( $interfaces ) ) {
			foreach ( $interfaces as $i => $interface ) {
				foreach ( array( 'url', 'protocolBinding', 'protocolVersion' ) as $field ) {
					if ( ! is_array( $interface ) || ! is_string( $interface[ $field ] ?? null ) || '' === $interface[ $field ] ) {
						$errors[] = "supportedInterfaces[{$i}].{$field} eksik.";
					}
				}
			}
		}
		$skills = $card['skills'] ?? null;
		if ( isset( $card['skills'] ) && ( ! is_array( $skills ) || ! array_is_list( $skills ) ) ) {
			$errors[] = 'skills liste olmalı.';
		} elseif ( is_array( $skills ) ) {
			foreach ( $skills as $i => $skill ) {
				foreach ( array( 'id', 'name', 'description' ) as $field ) {
					if ( ! is_array( $skill ) || ! is_string( $skill[ $field ] ?? null ) || '' === $skill[ $field ] ) {
						$errors[] = "skills[{$i}].{$field} eksik.";
					}
				}
				if ( ! is_array( $skill ) || ! self::strings( $skill['tags'] ?? null, true ) ) {
					$errors[] = "skills[{$i}].tags boş olmayan metin listesi olmalı.";
				}
			}
		}
		if ( isset( $card['provider'] ) && ( ! is_array( $card['provider'] ) || ! is_string( $card['provider']['url'] ?? null ) || ! is_string( $card['provider']['organization'] ?? null ) ) ) {
			$errors[] = 'provider için url ve organization zorunlu.';
		}
		return $errors;
	}

	/**
	 * The JSON-RPC 1.0 interface URL of a valid card, or null.
	 *
	 * @param mixed $card Decoded card.
	 */
	public static function jsonrpc_url( mixed $card ): ?string {
		if ( array() !== self::errors( $card ) || ! is_array( $card ) ) {
			return null;
		}
		foreach ( $card['supportedInterfaces'] as $interface ) {
			if ( AgentCardBuilder::BINDING === $interface['protocolBinding'] && str_starts_with( (string) $interface['protocolVersion'], '1.' ) && str_starts_with( $interface['url'], 'https://' ) ) {
				return $interface['url'];
			}
		}
		return null;
	}

	/**
	 * Whether a value is a list of strings.
	 *
	 * @param mixed $value     Value.
	 * @param bool  $non_empty Must have at least one item.
	 */
	private static function strings( mixed $value, bool $non_empty ): bool {
		return is_array( $value ) && array_is_list( $value ) && ( ! $non_empty || array() !== $value ) && array() === array_filter( $value, static fn( $v ): bool => ! is_string( $v ) );
	}
}
