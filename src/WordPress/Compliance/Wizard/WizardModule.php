<?php
/**
 * Compliance wizard (U3) – WordPress wiring.
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\WordPress\Compliance\Wizard;

use AIHazirSite\Core\Access\Presets;
use AIHazirSite\Core\Compliance\Robots;
use AIHazirSite\Core\Compliance\Wizard\SiteState;
use AIHazirSite\Core\Compliance\Wizard\StepPlanner;
use AIHazirSite\Core\Compliance\Wizard\Wizard;
use AIHazirSite\Core\Compliance\Wizard\WizardJournal;
use AIHazirSite\Core\Features;
use AIHazirSite\Core\Measurement\Registry;
use AIHazirSite\WordPress\Access\AccessModule;
use AIHazirSite\WordPress\Catalog\CatalogModule;
use AIHazirSite\WordPress\Catalog\WpListingRepository;
use AIHazirSite\WordPress\Catalog\WpProfileRepository;
use AIHazirSite\WordPress\Compliance\ComplianceModule;
use AIHazirSite\WordPress\Llms\LlmsModule;
use AIHazirSite\WordPress\Module;
use AIHazirSite\WordPress\Platform\WpClock;
use AIHazirSite\WordPress\Platform\WpSettings;

/**
 * Registers Tools → AI Uyum Sihirbazı and the first-run suggestion while `compliance_wizard` is on.
 * The wizard itself (core) only changes the plugin's own settings and data.
 */
final class WizardModule implements Module {

	/**
	 * Registers hooks.
	 */
	public function register(): void {
		if ( ! Features::is_enabled( Features::COMPLIANCE_WIZARD ) || ! is_admin() ) {
			return;
		}
		( new WizardPage() )->register();
	}

	/**
	 * Nothing scheduled.
	 */
	public function deactivate(): void {
	}

	/**
	 * The wizard.
	 */
	public static function wizard(): Wizard {
		return new Wizard( new WpSettings(), CatalogModule::service(), self::journal(), new WpClock() );
	}

	/**
	 * The journal.
	 */
	public static function journal(): WizardJournal {
		return new WizardJournal( new WpSettings() );
	}

	/**
	 * Steps for the latest scan and the current site.
	 *
	 * @return array{actions: list<\AIHazirSite\Core\Compliance\Wizard\FixStep>, manual: list<\AIHazirSite\Core\Compliance\Wizard\FixStep>}
	 */
	public static function plan(): array {
		$plan    = ( new StepPlanner() )->plan( ComplianceModule::store()->latest(), self::state() );
		$applied = self::journal()->applied();

		$plan['actions'] = array_values( array_filter( $plan['actions'], static fn( $step ): bool => ! isset( $applied[ $step->id ] ) ) );
		return $plan;
	}

	/**
	 * What the planner needs to know about this site (read only).
	 */
	public static function state(): SiteState {
		$features = array();
		foreach ( array_keys( Features::defaults() ) as $key ) {
			$features[ $key ] = Features::is_enabled( $key );
		}

		// The robots.txt as it will be served from the next request on (our block follows the setting),
		// and as it would be with every AI bot allowed by our setting (read only: nothing is saved).
		$bots    = Registry::bots();
		$output  = AccessModule::virtual_robots();
		$robots  = new Robots( AccessModule::filter_robots( $output ) );
		$allowed = new Robots( AccessModule::rules()->apply( $output, Presets::policy( Presets::ALLOW_ALL, $bots ), $bots ) );
		$blocked = false;
		$fixable = true;
		foreach ( $bots as $bot ) {
			if ( ! $robots->allows( $bot->name, '/' ) ) {
				$blocked = true;
				$fixable = $fixable && $allowed->allows( $bot->name, '/' );
			}
		}

		$updated = ( new WpProfileRepository() )->updated_at();

		return new SiteState(
			$features,
			null !== $updated,
			count( ( new WpListingRepository() )->ids() ),
			null !== AccessModule::physical_file(),
			null !== LlmsModule::physical_file(),
			'0' !== (string) get_option( 'blog_public', '1' ),
			$blocked,
			$fixable
		);
	}
}
