<?php
/**
 * Carries form errors and input across the post/redirect/get cycle.
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\WordPress\Catalog\Admin;

/**
 * Per-user, short-lived (60 s) transient; read once.
 */
final class FormState {

	/**
	 * Stores the state for the current user.
	 *
	 * @param array<string, string> $errors   Field errors.
	 * @param array<string, mixed>  $input    Submitted values (already sanitized).
	 * @param string[]              $warnings Warnings.
	 */
	public static function put( array $errors, array $input = array(), array $warnings = array() ): void {
		set_transient(
			self::key(),
			array(
				'errors'   => $errors,
				'input'    => $input,
				'warnings' => array_values( $warnings ),
			),
			60
		);
	}

	/**
	 * Returns and clears the state.
	 *
	 * @return array{errors: array<string, string>, input: array<string, mixed>, warnings: list<string>}
	 */
	public static function take(): array {
		$state = get_transient( self::key() );
		delete_transient( self::key() );

		$state = is_array( $state ) ? $state : array();
		return array(
			'errors'   => is_array( $state['errors'] ?? null ) ? array_map( 'strval', $state['errors'] ) : array(),
			'input'    => is_array( $state['input'] ?? null ) ? $state['input'] : array(),
			'warnings' => is_array( $state['warnings'] ?? null ) ? array_values( array_map( 'strval', $state['warnings'] ) ) : array(),
		);
	}

	/**
	 * Transient name for the current user.
	 */
	private static function key(): string {
		return 'aihs_form_' . get_current_user_id();
	}
}
