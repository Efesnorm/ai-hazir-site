<?php
/**
 * Shared setup for the compliance wizard integration tests.
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\Tests\Integration\Compliance;

use AIHazirSite\Core\Compliance\Wizard\FixStep;
use AIHazirSite\Core\Features;
use AIHazirSite\WordPress\Access\AccessModule;
use AIHazirSite\WordPress\Compliance\Wizard\WizardModule;
use AIHazirSite\WordPress\Compliance\Wizard\WizardPage;
use AIHazirSite\WordPress\Llms\LlmsModule;
use AIHazirSite\WordPress\Schema\CatalogPage;
use AIHazirSite\WordPress\Schema\SchemaModule;
use WP_UnitTestCase;

/**
 * An empty site (no profile, no listings, only the wizard on) whose scans are answered with
 * the site's real outputs, so a scan sees what the wizard changed.
 */
abstract class WizardTestCase extends WP_UnitTestCase {

	/**
	 * Page under test.
	 *
	 * @var WizardPage
	 */
	protected WizardPage $page;

	/**
	 * Empty site, administrator, wizard on, scans served locally.
	 */
	public function set_up(): void {
		parent::set_up();
		foreach ( array( Features::OPTION, 'aihs_scans', 'aihs_wizard', 'aihs_profile', 'aihs_profile_updated', 'aihs_bot_policy', 'aihs_llms_cache', 'aihs_schema_cache', 'aihs_schema_error' ) as $option ) {
			delete_option( $option );
		}
		Features::set( Features::COMPLIANCE_WIZARD, true );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$this->page = new WizardPage();
		add_filter( 'pre_http_request', array( self::class, 'loopback' ), 10, 3 );
	}

	/**
	 * Clears request globals.
	 */
	public function tear_down(): void {
		unset( $_REQUEST['_wpnonce'] );
		parent::tear_down();
	}

	/**
	 * Answers the scanner's requests with what the site would serve right now.
	 *
	 * @param mixed  $pre  Short-circuit value.
	 * @param array  $args Request arguments.
	 * @param string $url  URL.
	 * @return array<string, mixed>
	 */
	public static function loopback( mixed $pre, array $args, string $url ): array {
		$path = (string) wp_parse_url( $url, PHP_URL_PATH );
		$body = null;
		$type = 'text/html; charset=utf-8';

		if ( '/' === $path || '' === $path ) {
			$head = Features::is_enabled( Features::SCHEMA_OUTPUT ) ? SchemaModule::script( SchemaModule::home_document() ) : '';
			$nav  = SchemaModule::catalog_enabled() ? '<nav><a href="' . esc_url( SchemaModule::catalog_url() ) . '">AI Katalog</a></nav>' : '';
			$body = '<!doctype html><html lang="tr"><head><title>Örnek</title>' . $head . '</head><body><main><h1>Örnek</h1><p>Hoş geldiniz.</p></main>' . $nav . '</body></html>';
		} elseif ( '/ai-katalog/' === $path && SchemaModule::catalog_enabled() ) {
			$body = CatalogPage::render_html();
		} elseif ( '/llms.txt' === $path && Features::is_enabled( Features::LLMS_TXT ) ) {
			$body = LlmsModule::response( '/llms.txt' );
			$type = 'text/plain; charset=utf-8';
		} elseif ( '/robots.txt' === $path ) {
			$body = AccessModule::filter_robots( AccessModule::virtual_robots() );
			$type = 'text/plain; charset=utf-8';
		}

		return array(
			'headers'  => array( 'content-type' => $type ),
			'cookies'  => array(),
			'body'     => $body ?? 'yok',
			'response' => array(
				'code'    => null === $body ? 404 : 200,
				'message' => '',
			),
		);
	}

	/**
	 * Short-form input per step.
	 *
	 * @return array<string, array<string, string>>
	 */
	public static function inputs(): array {
		return array(
			FixStep::PROFILE       => array(
				'name'          => 'Örnek Kablo A.Ş.',
				'sector'        => 'Kablo',
				'country'       => 'TR',
				'contact_email' => 'satis@ornek.com.tr',
			),
			FixStep::FIRST_LISTING => array(
				'type'        => 'offer',
				'title'       => 'NYY enerji kablosu',
				'description' => 'Stoktan teslim.',
				'price_min'   => '42,50',
				'currency'    => 'TRY',
			),
			FixStep::BOTS          => array( 'preset' => 'allow_all' ),
		);
	}

	/**
	 * Applies a step through the admin handler (valid nonce).
	 *
	 * @param string $step Step id.
	 */
	protected function apply( string $step ): string {
		$_REQUEST['_wpnonce'] = wp_create_nonce( WizardPage::APPLY . '_' . $step );
		return $this->page->handle_apply( array_merge( array( 'step' => $step ), self::inputs()[ $step ] ?? array() ) );
	}

	/**
	 * Undoes a step through the admin handler (valid nonce).
	 *
	 * @param string $step Step id.
	 */
	protected function undo( string $step ): string {
		$_REQUEST['_wpnonce'] = wp_create_nonce( WizardPage::UNDO . '_' . $step );
		return $this->page->handle_undo( array( 'step' => $step ) );
	}

	/**
	 * Applies every suggested step until none is left; returns the applied step ids.
	 *
	 * @return list<string>
	 */
	protected function run_wizard(): array {
		$applied = array();
		for ( $round = 0; $round < 8; $round++ ) {
			$actions = WizardModule::plan()['actions'];
			if ( array() === $actions ) {
				break;
			}
			$step = $actions[0]->id;
			$this->assertStringContainsString( 'applied=' . $step, $this->apply( $step ), $step );
			$applied[] = $step;
		}
		return $applied;
	}

	/**
	 * Scans again through the finish handler.
	 */
	protected function finish(): void {
		$_REQUEST['_wpnonce'] = wp_create_nonce( WizardPage::FINISH );
		$this->assertStringContainsString( 'finished=1', $this->page->handle_finish() );
	}
}
