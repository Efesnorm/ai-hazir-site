<?php
/**
 * The compliance wizard end to end.
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\Tests\Integration\Compliance;

use AIHazirSite\Core\Features;
use AIHazirSite\WordPress\Catalog\Admin\FormState;
use AIHazirSite\WordPress\Catalog\WpListingRepository;
use AIHazirSite\WordPress\Compliance\ComplianceModule;
use AIHazirSite\WordPress\Compliance\Wizard\WizardModule;
use AIHazirSite\WordPress\Compliance\Wizard\WizardPage;
use WPDieException;

/**
 * Score increase, undo, suggestion and access control.
 *
 * @covers \AIHazirSite\WordPress\Compliance\Wizard\WizardPage
 * @covers \AIHazirSite\WordPress\Compliance\Wizard\WizardModule
 * @covers \AIHazirSite\Core\Compliance\Wizard\Wizard
 */
final class WizardFlowTest extends WizardTestCase {

	/**
	 * Empty site whose robots.txt blocks every bot (another plugin adds "Disallow: /" for all):
	 * after the wizard the score is measurably higher.
	 */
	public function test_score_increases_on_empty_site(): void {
		add_filter( 'robots_txt', static fn( $output ): string => $output . "\nUser-agent: *\nDisallow: /\n", 50 );

		$this->assertSame( array( 'scan' ), array_map( static fn( $s ): string => $s->id, WizardModule::plan()['actions'] ) );
		$applied = $this->run_wizard();
		$this->assertSame( array( 'scan', 'profile', 'first_listing', 'schema', 'llms', 'bots' ), $applied );

		$this->finish();
		$before = WizardModule::journal()->baseline();
		$after  = ComplianceModule::store()->latest();
		$this->assertNotNull( $before );
		$this->assertNotNull( $after );
		$this->assertGreaterThan( (int) $before->score() + 20, (int) $after->score(), sprintf( 'Önce %d, sonra %d.', (int) $before->score(), (int) $after->score() ) );
		foreach ( array( 'structured_data', 'freshness', 'llms_txt', 'bot_access' ) as $check ) {
			$this->assertGreaterThan( (float) $before->result( $check )['ratio'], (float) $after->result( $check )['ratio'], $check );
		}

		$html = WizardPage::render_html( array( 'errors' => array(), 'input' => array(), 'warnings' => array() ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		$this->assertStringContainsString( '<th id="aihs-score-before">' . $before->score() . '</th><th id="aihs-score-after">' . $after->score() . '</th>', $html );
		$this->assertStringContainsString( 'id="aihs-wizard-manual"', $html, 'Remaining gaps are explained.' );
		$this->assertStringContainsString( 'id="aihs-wizard-no-actions"', $html );
	}

	/**
	 * Each step undone right after it was applied returns the plugin data to the earlier state;
	 * all steps undone in reverse order return the site to its start.
	 */
	public function test_each_undo_restores(): void {
		add_filter( 'robots_txt', static fn( $output ): string => $output . "\nUser-agent: *\nDisallow: /\n", 50 );
		$start = self::plugin_state();
		$steps = array();

		for ( $round = 0; $round < 8; $round++ ) {
			$actions = WizardModule::plan()['actions'];
			if ( array() === $actions ) {
				break;
			}
			$step   = $actions[0]->id;
			$before = self::plugin_state();
			$this->apply( $step );
			$this->assertNotSame( $before, self::plugin_state(), $step . ' changes something.' );
			$this->assertStringContainsString( 'undone=' . $step, $this->undo( $step ) );
			$this->assertSame( $before, self::plugin_state(), $step . ' undo restores.' );
			$this->apply( $step );
			$steps[] = $step;
		}
		$this->assertCount( 6, $steps );

		foreach ( array_reverse( $steps ) as $step ) {
			$this->assertStringContainsString( 'undone=' . $step, $this->undo( $step ), $step );
		}
		$this->assertSame( $start, self::plugin_state() );
		$this->assertSame( array(), ( new WpListingRepository() )->ids() );
	}

	/**
	 * A bot blocked by name by another plugin: our setting cannot lift it, so it is a manual step.
	 */
	public function test_named_block_is_manual(): void {
		add_filter( 'robots_txt', static fn( $output ): string => $output . "\nUser-agent: GPTBot\nDisallow: /\n", 50 );
		$this->apply( 'scan' );

		$plan = WizardModule::plan();
		$this->assertNotContains( 'bots', array_map( static fn( $s ): string => $s->id, $plan['actions'] ) );
		$this->assertContains( 'manual_bot_access', array_map( static fn( $s ): string => $s->id, $plan['manual'] ) );
	}

	/**
	 * A prerequisite cannot be undone while its dependent is applied; the page says why.
	 */
	public function test_undo_order(): void {
		$this->run_wizard();
		$this->assertStringNotContainsString( 'undone=', $this->undo( 'profile' ) );
		$this->assertStringContainsString( 'first_listing', (string) FormState::take()['errors']['undo'] );

		$html = WizardPage::render_html( array( 'errors' => array(), 'input' => array(), 'warnings' => array() ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		$this->assertStringContainsString( 'disabled', $html );
	}

	/**
	 * Invalid profile input: nothing applied, errors and values carried back to the form.
	 */
	public function test_invalid_input(): void {
		$this->apply( 'scan' );
		$_REQUEST['_wpnonce'] = wp_create_nonce( WizardPage::APPLY . '_profile' );
		$this->page->handle_apply(
			array(
				'step'          => 'profile',
				'name'          => '',
				'contact_email' => 'satis@ornek.com.tr',
			)
		);

		$state = FormState::take();
		$this->assertArrayHasKey( 'name', $state['errors'] );
		$this->assertArrayNotHasKey( 'profile', WizardModule::journal()->applied() );
		$this->assertFalse( Features::is_enabled( Features::CATALOG ) );
		$this->assertStringContainsString( 'value="satis@ornek.com.tr"', WizardPage::render_html( $state ) );
	}

	/**
	 * The first-run suggestion shows until the wizard is started or dismissed.
	 */
	public function test_suggestion(): void {
		ob_start();
		WizardPage::suggestion();
		$this->assertStringContainsString( 'id="aihs-wizard-suggestion"', (string) ob_get_clean() );

		$_REQUEST['_wpnonce'] = wp_create_nonce( WizardPage::DISMISS );
		$this->page->handle_dismiss();
		ob_start();
		WizardPage::suggestion();
		$this->assertSame( '', (string) ob_get_clean() );
	}

	/**
	 * Without the capability or a valid nonce nothing is applied.
	 */
	public function test_access_control(): void {
		$_REQUEST['_wpnonce'] = 'gecersiz';
		try {
			$this->page->handle_apply( array( 'step' => 'scan' ) );
			$this->fail( 'Invalid nonce must be rejected.' );
		} catch ( WPDieException $e ) {
			$this->assertFalse( Features::is_enabled( Features::COMPLIANCE_SCAN ) );
		}

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$_REQUEST['_wpnonce'] = wp_create_nonce( WizardPage::APPLY . '_scan' );
		$this->expectException( WPDieException::class );
		$this->page->handle_apply( array( 'step' => 'scan' ) );
	}

	/**
	 * Plugin data that the wizard may change (its own journal, the scan history and output caches aside).
	 *
	 * @return array<string, mixed>
	 */
	public static function plugin_state(): array {
		global $wpdb;
		$rows    = $wpdb->get_results( "SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE 'aihs\\_%' ORDER BY option_name", ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$options = array();
		foreach ( (array) $rows as $row ) {
			if ( ! in_array( $row['option_name'], array( 'aihs_wizard', 'aihs_scans', 'aihs_first_scan', 'aihs_llms_cache', 'aihs_schema_cache', 'aihs_schema_error' ), true ) ) {
				$options[ $row['option_name'] ] = maybe_unserialize( $row['option_value'] );
			}
		}
		$listings = array();
		foreach ( ( new WpListingRepository() )->ids() as $id ) {
			$listings[] = ( new WpListingRepository() )->find( $id )?->title;
		}
		return array(
			'options'  => $options,
			'listings' => $listings,
		);
	}
}
