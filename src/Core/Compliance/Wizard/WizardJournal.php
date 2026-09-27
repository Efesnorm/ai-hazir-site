<?php
/**
 * What the wizard did, so each step can be undone.
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\Core\Compliance\Wizard;

use AIHazirSite\Core\Compliance\ScoreReport;
use AIHazirSite\Core\Contracts\Settings;

/**
 * Stored in the `aihs_wizard` option: per applied step the time and the earlier values it
 * replaced (changes), the score before the wizard (baseline) and whether the suggestion was
 * dismissed. Written only when something changes.
 *
 * @phpstan-type Change array<string, mixed>
 * @phpstan-type Entry array{at: string, changes: list<Change>}
 */
final class WizardJournal {

	public const OPTION = 'aihs_wizard';

	/**
	 * Constructor.
	 *
	 * @param Settings $settings Storage.
	 */
	public function __construct( private readonly Settings $settings ) {
	}

	/**
	 * Applied steps in the order they were applied.
	 *
	 * @return array<string, array{at: string, changes: list<array<string, mixed>>}>
	 */
	public function applied(): array {
		$applied = array();
		foreach ( (array) ( $this->data()['applied'] ?? array() ) as $step => $entry ) {
			if ( is_array( $entry ) && is_array( $entry['changes'] ?? null ) ) {
				$applied[ (string) $step ] = array(
					'at'      => (string) ( $entry['at'] ?? '' ),
					'changes' => array_values( array_filter( $entry['changes'], 'is_array' ) ),
				);
			}
		}
		return $applied;
	}

	/**
	 * Records an applied step.
	 *
	 * @param string                     $step    Step id.
	 * @param string                     $at      ISO 8601 time.
	 * @param list<array<string, mixed>> $changes Earlier values.
	 */
	public function record( string $step, string $at, array $changes ): void {
		$data                     = $this->data();
		$data['applied']          = (array) ( $data['applied'] ?? array() );
		$data['applied'][ $step ] = array(
			'at'      => $at,
			'changes' => $changes,
		);
		$this->settings->set( self::OPTION, $data, false );
	}

	/**
	 * Forgets an undone step.
	 *
	 * @param string $step Step id.
	 */
	public function forget( string $step ): void {
		$data = $this->data();
		if ( ! isset( $data['applied'][ $step ] ) ) {
			return;
		}
		unset( $data['applied'][ $step ] );
		$this->settings->set( self::OPTION, $data, false );
	}

	/**
	 * Score before the wizard, or null.
	 */
	public function baseline(): ?ScoreReport {
		return ScoreReport::from_array( $this->data()['baseline'] ?? null );
	}

	/**
	 * Remembers the score before the wizard (kept once set, until reset).
	 *
	 * @param ScoreReport $report Report.
	 */
	public function set_baseline( ScoreReport $report ): void {
		$data = $this->data();
		if ( isset( $data['baseline'] ) ) {
			return;
		}
		$data['baseline'] = $report->to_array();
		$this->settings->set( self::OPTION, $data, false );
	}

	/**
	 * Whether the wizard was ever started (a baseline or an applied step).
	 */
	public function started(): bool {
		return null !== $this->baseline() || array() !== $this->applied();
	}

	/**
	 * Whether the admin chose not to see the suggestion again.
	 */
	public function dismissed(): bool {
		return true === ( $this->data()['dismissed'] ?? false );
	}

	/**
	 * Hides the suggestion.
	 */
	public function dismiss(): void {
		$data = $this->data();
		if ( true === ( $data['dismissed'] ?? false ) ) {
			return;
		}
		$data['dismissed'] = true;
		$this->settings->set( self::OPTION, $data, false );
	}

	/**
	 * Stored data.
	 *
	 * @return array<string, mixed>
	 */
	private function data(): array {
		$data = $this->settings->get( self::OPTION, array() );
		return is_array( $data ) ? $data : array();
	}
}
