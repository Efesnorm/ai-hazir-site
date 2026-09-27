<?php
/**
 * Tests for Wizard and WizardJournal.
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\Tests\Unit\Compliance\Wizard;

use AIHazirSite\Core\Access\PolicyStore;
use AIHazirSite\Core\Catalog\CatalogService;
use AIHazirSite\Core\Catalog\CompanyProfile;
use AIHazirSite\Core\Compliance\ScoreReport;
use AIHazirSite\Core\Compliance\Wizard\FixStep;
use AIHazirSite\Core\Compliance\Wizard\Wizard;
use AIHazirSite\Core\Compliance\Wizard\WizardJournal;
use AIHazirSite\Core\Features;
use AIHazirSite\Tests\Support\FixedClock;
use AIHazirSite\Tests\Support\MemoryListingRepository;
use AIHazirSite\Tests\Support\MemoryProfileRepository;
use AIHazirSite\Tests\Support\MemorySettings;
use PHPUnit\Framework\TestCase;

/**
 * Apply and undo, with in-memory storage.
 *
 * @covers \AIHazirSite\Core\Compliance\Wizard\Wizard
 * @covers \AIHazirSite\Core\Compliance\Wizard\WizardJournal
 * @covers \AIHazirSite\Core\Features
 */
final class WizardTest extends TestCase {

	/**
	 * Settings.
	 *
	 * @var MemorySettings
	 */
	private MemorySettings $settings;

	/**
	 * Listings.
	 *
	 * @var MemoryListingRepository
	 */
	private MemoryListingRepository $listings;

	/**
	 * Profiles.
	 *
	 * @var MemoryProfileRepository
	 */
	private MemoryProfileRepository $profiles;

	/**
	 * Wizard under test.
	 *
	 * @var Wizard
	 */
	private Wizard $wizard;

	/**
	 * Fresh storage.
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->settings = new MemorySettings();
		$this->listings = new MemoryListingRepository();
		$this->profiles = new MemoryProfileRepository();
		Features::use_settings( $this->settings );
		$this->wizard = new Wizard( $this->settings, new CatalogService( $this->listings, $this->profiles, new FixedClock() ), new WizardJournal( $this->settings ), new FixedClock() );
	}

	/**
	 * Everything the site state consists of, except the wizard's own journal.
	 *
	 * @return array<string, mixed>
	 */
	private function snapshot(): array {
		$values = $this->settings->values;
		unset( $values[ WizardJournal::OPTION ] );
		return array(
			'options'  => $values,
			'profile'  => $this->profiles->profile?->to_array(),
			'updated'  => $this->profiles->updated_at(),
			'listings' => $this->listings->ids(),
		);
	}

	/**
	 * Valid short-form input per step.
	 *
	 * @return array<string, array<string, string>>
	 */
	public static function inputs(): array {
		return array(
			FixStep::SCAN          => array(),
			FixStep::PROFILE       => array(
				'name'          => 'Örnek Kablo A.Ş.',
				'sector'        => 'Kablo',
				'country'       => 'tr',
				'contact_email' => 'satis@ornek.com.tr',
			),
			FixStep::FIRST_LISTING => array(
				'type'  => 'offer',
				'title' => 'NYY kablo',
			),
			FixStep::SCHEMA        => array(),
			FixStep::LLMS          => array(),
			FixStep::BOTS          => array( 'preset' => 'search_and_user' ),
		);
	}

	/**
	 * Every step changes the site and its undo returns it exactly to the earlier state.
	 */
	public function test_each_step_undo_restores(): void {
		$this->settings->set( Features::OPTION, array( Features::MEASUREMENT => true ), true );
		$this->settings->set( PolicyStore::OPTION, array( 'gptbot' => 'disallow' ) );

		foreach ( self::inputs() as $step => $input ) {
			$before = $this->snapshot();
			$this->assertSame( array(), $this->wizard->apply( $step, $input ), $step );
			$this->assertNotSame( $before, $this->snapshot(), $step . ' changes something.' );
			$this->assertNull( $this->wizard->undo( $step ) );
			$this->assertSame( $before, $this->snapshot(), $step . ' undo restores.' );
		}
	}

	/**
	 * All steps in order, then undone in reverse: back to an empty site; the profile keeps the earlier save time.
	 */
	public function test_all_steps_and_reverse_undo(): void {
		$this->profiles->restore_profile( new CompanyProfile( 'Eski Ad', 'Eski', 'DE', array( 'de' ) ), '2026-01-01T00:00:00Z' );
		$before = $this->snapshot();

		foreach ( self::inputs() as $step => $input ) {
			$this->assertSame( array(), $this->wizard->apply( $step, $input ), $step );
		}
		$this->assertSame( 'Örnek Kablo A.Ş.', $this->profiles->get()->name );
		$this->assertSame( array( 'de' ), $this->profiles->get()->languages, 'Fields outside the short form are kept.' );
		foreach ( array( Features::COMPLIANCE_SCAN, Features::CATALOG, Features::SCHEMA_OUTPUT, Features::LLMS_TXT, Features::BOT_ACCESS ) as $key ) {
			$this->assertTrue( Features::is_enabled( $key ), $key );
		}
		$this->assertSame( 'disallow', ( new PolicyStore( $this->settings ) )->get()->to_array()['gptbot'] ?? null, 'search_and_user blocks training bots.' );

		foreach ( array_reverse( array_keys( self::inputs() ) ) as $step ) {
			$this->assertNull( $this->wizard->undo( $step ), $step );
		}
		$this->assertSame( $before, $this->snapshot() );
		$this->assertSame( array(), ( new WizardJournal( $this->settings ) )->applied() );
	}

	/**
	 * Invalid input changes nothing; a step applies once; prerequisites are undone last.
	 */
	public function test_refusals(): void {
		$before = $this->snapshot();
		$this->assertArrayHasKey( 'name', $this->wizard->apply( FixStep::PROFILE, array( 'name' => '' ) ) );
		$this->assertArrayHasKey( 'title', $this->wizard->apply( FixStep::FIRST_LISTING, array( 'type' => 'offer' ) ) );
		$this->assertArrayHasKey( 'preset', $this->wizard->apply( FixStep::BOTS, array( 'preset' => 'hepsini_engelle' ) ) );
		$this->assertArrayHasKey( 'step', $this->wizard->apply( 'tema_duzenle' ) );
		$this->assertSame( $before, $this->snapshot() );

		$this->wizard->apply( FixStep::PROFILE, self::inputs()[ FixStep::PROFILE ] );
		$this->assertSame( array( 'step' => 'Bu adım zaten uygulandı.' ), $this->wizard->apply( FixStep::PROFILE, self::inputs()[ FixStep::PROFILE ] ) );
		$this->wizard->apply( FixStep::FIRST_LISTING, self::inputs()[ FixStep::FIRST_LISTING ] );

		$this->assertStringContainsString( 'first_listing', (string) $this->wizard->undo( FixStep::PROFILE ) );
		$this->assertNull( $this->wizard->undo_blocker( FixStep::FIRST_LISTING ) );
		$this->assertSame( 'Bu adım uygulanmamış.', $this->wizard->undo( FixStep::SCHEMA ) );
	}

	/**
	 * The journal keeps the first baseline and the dismissal.
	 */
	public function test_journal(): void {
		$journal = new WizardJournal( $this->settings );
		$this->assertFalse( $journal->started() );

		$journal->set_baseline( new ScoreReport( '2026-09-27T10:00:00Z', 2, array() ) );
		$journal->set_baseline( new ScoreReport( '2026-09-27T11:00:00Z', 2, array() ) );
		$this->assertSame( '2026-09-27T10:00:00Z', $journal->baseline()?->scanned_at );
		$this->assertTrue( $journal->started() );

		$this->assertFalse( $journal->dismissed() );
		$journal->dismiss();
		$this->assertTrue( $journal->dismissed() );
	}

	/**
	 * Features::restore puts back the exact earlier override (or its absence).
	 */
	public function test_feature_restore(): void {
		$this->assertNull( Features::stored( Features::LLMS_TXT ) );
		Features::set( Features::LLMS_TXT, true );
		Features::restore( Features::LLMS_TXT, null );
		$this->assertArrayNotHasKey( Features::OPTION, $this->settings->values, 'No override left: option removed.' );

		Features::set( Features::MEASUREMENT, false );
		Features::set( Features::LLMS_TXT, true );
		Features::restore( Features::LLMS_TXT, false );
		$this->assertSame(
			array(
				Features::MEASUREMENT => false,
				Features::LLMS_TXT    => false,
			),
			$this->settings->values[ Features::OPTION ]
		);
	}
}
