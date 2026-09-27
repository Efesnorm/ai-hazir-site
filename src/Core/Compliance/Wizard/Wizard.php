<?php
/**
 * Applies and undoes wizard steps.
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\Core\Compliance\Wizard;

use AIHazirSite\Core\Access\PolicyStore;
use AIHazirSite\Core\Access\Presets;
use AIHazirSite\Core\Catalog\CatalogService;
use AIHazirSite\Core\Catalog\CompanyProfile;
use AIHazirSite\Core\Contracts\Clock;
use AIHazirSite\Core\Contracts\Settings;
use AIHazirSite\Core\Features;
use AIHazirSite\Core\Measurement\Registry;

/**
 * Changes only the plugin's own settings and data: feature keys (Features), the company
 * profile and the first listing (CatalogService), the bot access policy (PolicyStore, an
 * `aihs_` option). Before each step it records the values it replaces (WizardJournal);
 * undo puts exactly those back. Theme files, other plugins' settings, WordPress settings
 * and physical files are never touched: those gaps are manual steps (StepPlanner).
 */
final class Wizard {

	/**
	 * Steps that must be undone before the key step can be undone.
	 */
	public const DEPENDENTS = array(
		FixStep::PROFILE       => array( FixStep::FIRST_LISTING, FixStep::SCHEMA ),
		FixStep::FIRST_LISTING => array( FixStep::SCHEMA ),
	);

	/**
	 * Profile fields the wizard's short form sets.
	 */
	public const PROFILE_FIELDS = array( 'name', 'sector', 'country', 'contact_email' );

	/**
	 * Listing fields the wizard's short form sets.
	 */
	public const LISTING_FIELDS = array( 'type', 'title', 'description', 'price_min', 'currency' );

	/**
	 * Constructor.
	 *
	 * @param Settings       $settings Storage of the plugin's options.
	 * @param CatalogService $catalog  Catalog writes.
	 * @param WizardJournal  $journal  Journal.
	 * @param Clock          $clock    Time of each step.
	 */
	public function __construct(
		private readonly Settings $settings,
		private readonly CatalogService $catalog,
		private readonly WizardJournal $journal,
		private readonly Clock $clock
	) {
	}

	/**
	 * Applies a step; returns field errors (nothing is changed when there are errors).
	 *
	 * @param string               $step  Step id (FixStep::ACTIONS).
	 * @param array<string, mixed> $input Short-form input (profile, first listing, bot preset).
	 * @return array<string, string>
	 */
	public function apply( string $step, array $input = array() ): array {
		if ( ! in_array( $step, FixStep::ACTIONS, true ) ) {
			return array( 'step' => 'Bilinmeyen adım.' );
		}
		if ( isset( $this->journal->applied()[ $step ] ) ) {
			return array( 'step' => 'Bu adım zaten uygulandı.' );
		}

		$changes = array();
		switch ( $step ) {
			case FixStep::SCAN:
				$changes[] = $this->feature( Features::COMPLIANCE_SCAN );
				break;

			case FixStep::PROFILE:
				[ $profile, $updated ] = $this->catalog->profile_state();
				$data                  = ( $profile ?? new CompanyProfile() )->to_array();
				foreach ( self::PROFILE_FIELDS as $field ) {
					$data[ $field ] = isset( $input[ $field ] ) && is_scalar( $input[ $field ] ) ? (string) $input[ $field ] : '';
				}
				unset( $data['template'] );
				$result = $this->catalog->save_profile( $data );
				if ( ! $result->is_valid() ) {
					return $result->errors;
				}
				$changes[] = array(
					'type'    => 'profile',
					'profile' => $profile?->to_array(),
					'updated' => $updated,
				);
				$changes[] = $this->feature( Features::CATALOG );
				break;

			case FixStep::FIRST_LISTING:
				$data = array();
				foreach ( self::LISTING_FIELDS as $field ) {
					$data[ $field ] = isset( $input[ $field ] ) && is_scalar( $input[ $field ] ) ? (string) $input[ $field ] : '';
				}
				$result = $this->catalog->save_listing( $data );
				$id     = $result->listing()?->id;
				if ( ! $result->is_valid() || null === $id ) {
					return $result->errors;
				}
				$changes[] = array(
					'type' => 'listing',
					'id'   => $id,
				);
				$changes[] = $this->feature( Features::CATALOG );
				break;

			case FixStep::SCHEMA:
				$changes[] = $this->feature( Features::SCHEMA_OUTPUT );
				break;

			case FixStep::LLMS:
				$changes[] = $this->feature( Features::LLMS_TXT );
				break;

			case FixStep::BOTS:
				$preset = isset( $input['preset'] ) && is_string( $input['preset'] ) && '' !== $input['preset'] ? $input['preset'] : Presets::ALLOW_ALL;
				if ( ! in_array( $preset, Presets::ALL, true ) ) {
					return array( 'preset' => 'Geçersiz hazır ayar.' );
				}
				$marker    = new \stdClass();
				$before    = $this->settings->get( PolicyStore::OPTION, $marker );
				$changes[] = array(
					'type'  => 'policy',
					'had'   => $before !== $marker,
					'value' => $before === $marker ? null : $before,
				);
				$bots      = Registry::bots();
				( new PolicyStore( $this->settings ) )->save( Presets::policy( $preset, $bots ), $bots );
				$changes[] = $this->feature( Features::BOT_ACCESS );
				break;
		}

		$this->journal->record( $step, gmdate( 'Y-m-d\TH:i:s\Z', $this->clock->now() ), $changes );
		return array();
	}

	/**
	 * Why a step cannot be undone now (a dependent step is still applied), or null.
	 *
	 * @param string $step Step id.
	 */
	public function undo_blocker( string $step ): ?string {
		$applied = $this->journal->applied();
		if ( ! isset( $applied[ $step ] ) ) {
			return 'Bu adım uygulanmamış.';
		}
		foreach ( self::DEPENDENTS[ $step ] ?? array() as $dependent ) {
			if ( isset( $applied[ $dependent ] ) ) {
				return 'Önce buna bağlı adımı geri alın: ' . $dependent . '.';
			}
		}
		return null;
	}

	/**
	 * Puts back the values a step replaced; returns an error or null.
	 *
	 * @param string $step Step id.
	 */
	public function undo( string $step ): ?string {
		$blocker = $this->undo_blocker( $step );
		if ( null !== $blocker ) {
			return $blocker;
		}

		foreach ( array_reverse( $this->journal->applied()[ $step ]['changes'] ) as $change ) {
			switch ( $change['type'] ?? '' ) {
				case 'feature':
					Features::restore( (string) $change['key'], isset( $change['stored'] ) ? (bool) $change['stored'] : null );
					break;
				case 'profile':
					$this->catalog->revert_profile(
						is_array( $change['profile'] ?? null ) ? CompanyProfile::from_array( $change['profile'] ) : null,
						isset( $change['updated'] ) ? (string) $change['updated'] : null
					);
					break;
				case 'listing':
					$this->catalog->delete_listing( (int) $change['id'] );
					break;
				case 'policy':
					if ( true === ( $change['had'] ?? false ) ) {
						$this->settings->set( PolicyStore::OPTION, $change['value'], false );
					} else {
						$this->settings->delete( PolicyStore::OPTION );
					}
					break;
			}
		}

		$this->journal->forget( $step );
		return null;
	}

	/**
	 * Turns a feature on and returns how to put back its earlier state.
	 *
	 * @param string $key Feature key.
	 * @return array<string, mixed>
	 */
	private function feature( string $key ): array {
		$change = array(
			'type'   => 'feature',
			'key'    => $key,
			'stored' => Features::stored( $key ),
		);
		Features::set( $key, true );
		return $change;
	}
}
