<?php
/**
 * The robots_txt filter inside WordPress.
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\Tests\Integration\Access;

use AIHazirSite\Core\Access\BotPolicy;
use AIHazirSite\Core\Access\Presets;
use AIHazirSite\Core\Access\RobotsRules;
use AIHazirSite\Core\Compliance\Robots;
use AIHazirSite\Core\Features;
use AIHazirSite\Core\Measurement\Registry;
use AIHazirSite\WordPress\Access\AccessModule;
use WP_UnitTestCase;

/**
 * Robots filter integration tests using WordPress's real do_robots() output.
 *
 * @covers \AIHazirSite\WordPress\Access\AccessModule
 */
final class RobotsFilterTest extends WP_UnitTestCase {

	/**
	 * Physical robots.txt created by a test, removed afterwards.
	 *
	 * @var string|null
	 */
	private ?string $physical = null;

	/**
	 * Clean state: feature off, no policy, no module filter.
	 */
	public function set_up(): void {
		parent::set_up();
		delete_option( Features::OPTION );
		delete_option( 'aihs_bot_policy' );
		remove_filter( 'robots_txt', array( AccessModule::class, 'filter_robots' ), AccessModule::FILTER_PRIORITY );
	}

	/**
	 * Removes a test robots.txt.
	 */
	public function tear_down(): void {
		if ( null !== $this->physical && file_exists( $this->physical ) ) {
			unlink( $this->physical ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
		}
		parent::tear_down();
	}

	/**
	 * What /robots.txt returns: WordPress's own do_robots().
	 */
	private static function served(): string {
		// WordPress 6.9's do_robots() calls header() without checking headers_sent(); in the test
		// runner output has already started. Only that warning is ignored; any other still fails.
		set_error_handler( // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler
			static fn( int $errno, string $message ): bool => str_contains( $message, 'Cannot modify header information' ),
			E_WARNING
		);
		try {
			ob_start();
			do_robots();
			return (string) ob_get_clean();
		} finally {
			restore_error_handler();
		}
	}

	/**
	 * Turns the feature on, stores a policy and registers the module as on a new request.
	 *
	 * @param BotPolicy $policy Policy.
	 */
	private function enable( BotPolicy $policy ): void {
		Features::set( Features::BOT_ACCESS, true );
		AccessModule::store()->save( $policy, Registry::bots() );
		( new AccessModule() )->register();
	}

	/**
	 * Off by default: robots.txt is WordPress's own output.
	 */
	public function test_feature_off_adds_nothing(): void {
		$before = self::served();
		( new AccessModule() )->register();

		$this->assertFalse( Features::is_enabled( Features::BOT_ACCESS ) );
		$this->assertSame( $before, self::served() );
		$this->assertStringNotContainsString( RobotsRules::BEGIN, $before );
	}

	/**
	 * Existing rules and other plugins' lines (before and after us) are kept; our block is added.
	 */
	public function test_existing_rules_and_other_plugins_are_kept(): void {
		add_filter( 'robots_txt', static fn( string $o ): string => $o . "Disallow: /ozel/\n", 10 );
		add_filter( 'robots_txt', static fn( string $o ): string => $o . "\n# SEO eklentisi\nUser-agent: Googlebot\nAllow: /\n", 200 );
		$without = self::served();

		$this->enable( Presets::policy( Presets::BLOCK_TRAINING, Registry::bots() ) );
		$with = self::served();

		$this->assertSame( $without, RobotsRules::remove( $with ), 'Removing our block gives back exactly the previous output.' );
		$this->assertStringStartsWith( "User-agent: *\nDisallow: /wp-admin/\nAllow: /wp-admin/admin-ajax.php\n", $with );
		$this->assertStringContainsString( "Disallow: /ozel/\n", $with );
		$this->assertLessThan( strpos( $with, RobotsRules::BEGIN ), strpos( $with, 'Disallow: /ozel/' ), 'Earlier filters come before us.' );
		$this->assertStringContainsString( "# SEO eklentisi\nUser-agent: Googlebot\nAllow: /\n", $with );
		$this->assertLessThan( strpos( $with, '# SEO eklentisi' ), strpos( $with, RobotsRules::BEGIN ), 'Later filters still come after us.' );

		$robots = new Robots( $with );
		$this->assertFalse( $robots->allows( 'GPTBot', '/urunler/' ) );
		$this->assertTrue( $robots->allows( 'PerplexityBot', '/urunler/' ) );
		$this->assertFalse( $robots->allows( 'PerplexityBot', '/ozel/' ), 'Default bots still follow the site rules.' );
	}

	/**
	 * Changing the policy changes robots.txt; turning the feature off restores the original.
	 */
	public function test_policy_change_and_feature_off(): void {
		$original = self::served();

		$this->enable( ( new BotPolicy() )->with( 'ccbot', BotPolicy::DISALLOW ) );
		$this->assertStringContainsString( "User-agent: CCBot\nDisallow: /", self::served() );

		AccessModule::store()->save( ( new BotPolicy() )->with( 'claudebot', BotPolicy::ALLOW ), Registry::bots() );
		$changed = self::served();
		$this->assertStringNotContainsString( 'CCBot', $changed );
		$this->assertStringContainsString( "User-agent: ClaudeBot\nDisallow: /wp-admin/\nAllow: /wp-admin/admin-ajax.php", $changed );

		Features::set( Features::BOT_ACCESS, false );
		$this->assertSame( $original, self::served(), 'Feature off: robots.txt is back to the original.' );
		$this->assertSame( array( 'claudebot' => 'allow' ), AccessModule::store()->get()->to_array(), 'Policy is kept.' );
	}

	/**
	 * A physical robots.txt is detected and never modified.
	 */
	public function test_physical_file_is_detected_and_untouched(): void {
		$this->assertNull( AccessModule::physical_file() );

		$path = trailingslashit( get_home_path() ) . 'robots.txt';
		if ( file_exists( $path ) || ! is_writable( dirname( $path ) ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_writable -- Test setup.
			$this->markTestSkipped( 'Cannot create a temporary robots.txt in the site root.' );
		}
		$content        = "User-agent: *\nDisallow: /elle-yazilmis/\n";
		$this->physical = $path;
		file_put_contents( $path, $content ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents

		$this->enable( Presets::policy( Presets::BLOCK_TRAINING, Registry::bots() ) );
		self::served();

		$this->assertSame( $path, AccessModule::physical_file() );
		$this->assertSame( $content, file_get_contents( $path ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
	}
}
