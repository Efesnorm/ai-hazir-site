<?php
/**
 * Advanced standards (A2A agent card) check.
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\Core\Compliance\Checks;

use AIHazirSite\Core\Compliance\Check;
use AIHazirSite\Core\Compliance\CheckResult;
use AIHazirSite\Core\Compliance\Site;

/**
 * An A2A Agent Card is published at /.well-known/agent-card.json with the required
 * AgentCard fields (A2A Protocol Specification 1.0.0, §4.4.1, §8.2, §14.3).
 */
final class AdvancedCheck implements Check {

	public const PATH            = '.well-known/agent-card.json';
	public const REQUIRED_FIELDS = array( 'name', 'description', 'supportedInterfaces', 'version', 'capabilities', 'defaultInputModes', 'defaultOutputModes', 'skills' );

	/**
	 * Id.
	 */
	public function id(): string {
		return 'advanced';
	}

	/**
	 * Weight.
	 */
	public function weight(): int {
		return 5;
	}

	/**
	 * 1 for a complete card, 0.5 for a card with missing required fields, 0 for none.
	 *
	 * @param Site $site Site.
	 */
	public function run( Site $site ): CheckResult {
		$response = $site->fetch( self::PATH );
		if ( ! $response->reached() ) {
			return CheckResult::unmeasured( '/' . self::PATH . ' adresine erişilemedi.' );
		}

		$fix  = 'A2A agent kartvizitini /' . self::PATH . ' adresinde yayınlayın (AI Hazır Site A2A modülü).';
		$card = $response->ok() ? json_decode( $response->body, true ) : null;
		if ( ! is_array( $card ) ) {
			return CheckResult::measured( 0.0, array( 'Agent kartviziti yok.' ), $fix );
		}

		$missing = array_values( array_filter( self::REQUIRED_FIELDS, static fn( string $field ): bool => ! array_key_exists( $field, $card ) ) );
		if ( array() === $missing ) {
			return CheckResult::measured( 1.0, array( 'Agent kartviziti var ve zorunlu alanlar tam.' ) );
		}

		return CheckResult::measured( 0.5, array( 'Agent kartvizitinde zorunlu alanlar eksik: ' . implode( ', ', $missing ) . '.' ), $fix );
	}
}
