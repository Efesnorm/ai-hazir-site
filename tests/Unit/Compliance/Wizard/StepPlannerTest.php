<?php
/**
 * Tests for StepPlanner.
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\Tests\Unit\Compliance\Wizard;

use AIHazirSite\Core\Compliance\ScoreReport;
use AIHazirSite\Core\Compliance\Wizard\FixStep;
use AIHazirSite\Core\Compliance\Wizard\SiteState;
use AIHazirSite\Core\Compliance\Wizard\StepPlanner;
use AIHazirSite\Core\Features;
use PHPUnit\Framework\TestCase;

/**
 * Findings → steps, ordered by expected gain.
 *
 * @covers \AIHazirSite\Core\Compliance\Wizard\StepPlanner
 * @covers \AIHazirSite\Core\Compliance\Wizard\FixStep
 * @covers \AIHazirSite\Core\Compliance\Wizard\SiteState
 */
final class StepPlannerTest extends TestCase {

	/**
	 * A report with the given ratio per check (null = not measured).
	 *
	 * @param array<string, float|null> $ratios Check id → ratio.
	 */
	private static function report( array $ratios ): ScoreReport {
		$weights = array(
			'structured_data'   => 20,
			'readability'       => 20,
			'machine_interface' => 20,
			'bot_access'        => 15,
			'llms_txt'          => 10,
			'freshness'         => 10,
			'advanced'          => 5,
		);
		$rows    = array();
		foreach ( $weights as $id => $weight ) {
			$ratio  = array_key_exists( $id, $ratios ) ? $ratios[ $id ] : 1.0;
			$points = null === $ratio ? 0.0 : round( $weight * $ratio, 2 );
			$rows[] = array(
				'id'       => $id,
				'weight'   => $weight,
				'ratio'    => $ratio,
				'points'   => $points,
				'gain'     => null === $ratio ? 0.0 : round( $weight - $points, 2 ),
				'level'    => 'info',
				'findings' => array(),
				'fix'      => '',
			);
		}
		return new ScoreReport( '2026-09-27T12:00:00Z', 2, $rows );
	}

	/**
	 * Site state with the scan on plus the given features.
	 *
	 * @param array<string, mixed> $overrides Constructor overrides.
	 */
	private static function state( array $overrides = array() ): SiteState {
		$features = array_merge( array( Features::COMPLIANCE_SCAN => true ), $overrides['features'] ?? array() );
		return new SiteState(
			$features,
			$overrides['has_profile'] ?? false,
			$overrides['listings'] ?? 0,
			$overrides['physical_robots'] ?? false,
			$overrides['physical_llms'] ?? false,
			$overrides['search_visible'] ?? true,
			$overrides['robots_blocks_ai'] ?? false
		);
	}

	/**
	 * Ids and gains of steps.
	 *
	 * @param FixStep[] $steps Steps.
	 * @return list<array{string, float}>
	 *
	 * @phpstan-param list<FixStep> $steps
	 */
	private static function ids( array $steps ): array {
		return array_map( static fn( FixStep $s ): array => array( $s->id, $s->gain ), $steps );
	}

	/**
	 * Without a scan (or with the scan off) the only step is the scan.
	 */
	public function test_first_step_is_scan(): void {
		$this->assertSame( array( array( 'scan', 0.0 ) ), self::ids( ( new StepPlanner() )->plan( null, self::state() )['actions'] ) );
		$this->assertSame( array( array( 'scan', 0.0 ) ), self::ids( ( new StepPlanner() )->plan( self::report( array() ), new SiteState() )['actions'] ) );
	}

	/**
	 * Empty site: actions by gain, prerequisites first; manual steps separately by gain.
	 */
	public function test_order_by_expected_gain(): void {
		$plan = ( new StepPlanner() )->plan(
			self::report(
				array(
					'structured_data'   => 0.0,
					'freshness'         => 0.0,
					'llms_txt'          => 0.0,
					'bot_access'        => 0.5,
					'readability'       => 0.6,
					'machine_interface' => 0.25,
					'advanced'          => 0.0,
				)
			),
			self::state( array( 'robots_blocks_ai' => true ) )
		);

		$this->assertSame(
			array( array( 'profile', 0.0 ), array( 'first_listing', 0.0 ), array( 'schema', 30.0 ), array( 'llms', 10.0 ), array( 'bots', 7.5 ) ),
			self::ids( $plan['actions'] )
		);
		$this->assertSame( array( 'profile', 'first_listing' ), $plan['actions'][2]->requires );
		$this->assertSame( array( 'profile' ), $plan['actions'][1]->requires );
		$this->assertSame(
			array( array( 'manual_machine_interface', 15.0 ), array( 'manual_readability', 8.0 ), array( 'manual_advanced', 5.0 ) ),
			self::ids( $plan['manual'] )
		);
		foreach ( $plan['manual'] as $step ) {
			$this->assertTrue( $step->manual );
		}
	}

	/**
	 * A bigger gain elsewhere comes before the schema chain.
	 */
	public function test_bigger_gain_first(): void {
		$plan = ( new StepPlanner() )->plan(
			self::report(
				array(
					'freshness'  => 0.5,
					'llms_txt'   => 0.0,
					'bot_access' => 0.0,
				)
			),
			self::state(
				array(
					'has_profile'      => true,
					'listings'         => 2,
					'robots_blocks_ai' => true,
				)
			)
		);

		$this->assertSame( array( array( 'bots', 15.0 ), array( 'llms', 10.0 ), array( 'schema', 5.0 ) ), self::ids( $plan['actions'] ) );
		$this->assertSame( array(), $plan['actions'][2]->requires, 'Profile and a listing already exist.' );
	}

	/**
	 * Gaps the plugin cannot fix become instructions; unmeasured checks show their whole weight as "at most".
	 */
	public function test_manual_cases(): void {
		$report = self::report(
			array(
				'structured_data' => 0.5,
				'llms_txt'        => null,
				'bot_access'      => 0.0,
			)
		);

		$plan = ( new StepPlanner() )->plan(
			$report,
			self::state(
				array(
					'features'         => array(
						Features::SCHEMA_OUTPUT => true,
						Features::LLMS_TXT      => true,
					),
					'physical_robots'  => true,
					'robots_blocks_ai' => true,
				)
			)
		);
		$this->assertSame( array(), $plan['actions'] );
		$this->assertSame( array( array( 'manual_bot_access', 15.0 ), array( 'manual_llms_txt', 10.0 ), array( 'manual_structured_data', 10.0 ) ), self::ids( $plan['manual'] ) );
		$this->assertTrue( $plan['manual'][1]->gain_is_max );
		$this->assertStringContainsString( 'robots.txt dosyası', $plan['manual'][0]->title );

		$hidden = ( new StepPlanner() )->plan( $report, self::state( array( 'search_visible' => false ) ) );
		$this->assertStringContainsString( 'Ayarlar → Okuma', $hidden['manual'][0]->description );

		$llms = ( new StepPlanner() )->plan( self::report( array( 'llms_txt' => 0.0 ) ), self::state( array( 'physical_llms' => true ) ) );
		$this->assertSame( array(), $llms['actions'], 'A physical llms.txt is never replaced.' );
		$this->assertSame( 'manual_llms_txt', $llms['manual'][0]->id );
	}

	/**
	 * A complete site has nothing to do.
	 */
	public function test_nothing_to_gain(): void {
		$this->assertSame(
			array(
				'actions' => array(),
				'manual'  => array(),
			),
			( new StepPlanner() )->plan( self::report( array() ), self::state() )
		);
	}
}
