<?php
/**
 * Tests for the access policy and robots.txt rules (snapshot tests).
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\Tests\Unit\Access;

use AIHazirSite\Core\Access\BotPolicy;
use AIHazirSite\Core\Access\PolicyStore;
use AIHazirSite\Core\Access\Presets;
use AIHazirSite\Core\Access\RobotsRules;
use AIHazirSite\Core\Compliance\Robots;
use AIHazirSite\Core\Measurement\Registry;
use AIHazirSite\Tests\Support\MemorySettings;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * Snapshot files live in tests/Snapshots/robots/; a changed output fails the test until the
 * snapshot is reviewed and updated on purpose.
 *
 * @covers \AIHazirSite\Core\Access\BotPolicy
 * @covers \AIHazirSite\Core\Access\Presets
 * @covers \AIHazirSite\Core\Access\RobotsRules
 * @covers \AIHazirSite\Core\Access\PolicyStore
 */
final class RobotsRulesTest extends TestCase {

	/**
	 * WordPress's default virtual robots.txt with the core sitemap line.
	 */
	public const WP_DEFAULT = "User-agent: *\nDisallow: /wp-admin/\nAllow: /wp-admin/admin-ajax.php\n\nSitemap: https://ornek.com/wp-sitemap.xml\n";

	/**
	 * Rules as the WordPress adapter configures them.
	 */
	private static function rules(): RobotsRules {
		return new RobotsRules( array( 'Disallow: /wp-admin/', 'Allow: /wp-admin/admin-ajax.php' ) );
	}

	/**
	 * Snapshot content.
	 *
	 * @param string $name File name without extension.
	 */
	private static function snapshot( string $name ): string {
		return (string) file_get_contents( dirname( __DIR__, 2 ) . '/Snapshots/robots/' . $name . '.txt' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
	}

	/**
	 * Mixed policy: exact expected output (hand-written).
	 */
	public function test_mixed_policy_exact_output(): void {
		$policy = ( new BotPolicy() )->with( 'gptbot', BotPolicy::DISALLOW )->with( 'perplexitybot', BotPolicy::ALLOW );

		$this->assertSame(
			self::WP_DEFAULT
			. "\n# BEGIN AI Hazir Site - AI bot erisimi\n"
			. "User-agent: GPTBot\nDisallow: /\n\n"
			. "User-agent: PerplexityBot\nDisallow: /wp-admin/\nAllow: /wp-admin/admin-ajax.php\n"
			. "# END AI Hazir Site\n",
			self::rules()->apply( self::WP_DEFAULT, $policy, Registry::bots() )
		);
	}

	/**
	 * Presets.
	 *
	 * @return array<string, array{string}>
	 */
	public static function presets(): array {
		return array(
			Presets::ALLOW_ALL       => array( Presets::ALLOW_ALL ),
			Presets::SEARCH_AND_USER => array( Presets::SEARCH_AND_USER ),
			Presets::BLOCK_TRAINING  => array( Presets::BLOCK_TRAINING ),
		);
	}

	/**
	 * Each preset produces exactly its snapshot.
	 *
	 * @dataProvider presets
	 *
	 * @param string $preset Preset.
	 */
	public function test_preset_snapshots( string $preset ): void {
		$output = self::rules()->apply( self::WP_DEFAULT, Presets::policy( $preset, Registry::bots() ), Registry::bots() );

		$this->assertSame( self::snapshot( $preset ), $output );
	}

	/**
	 * The robots.txt produced by each preset means what the preset says (RFC 9309 matching).
	 */
	public function test_presets_mean_what_they_say(): void {
		$bots = Registry::bots();
		foreach ( Presets::ALL as $preset ) {
			$robots = new Robots( self::rules()->apply( self::WP_DEFAULT, Presets::policy( $preset, $bots ), $bots ) );
			foreach ( $bots as $bot ) {
				$training = 'training' === $bot->category;
				$expected = Presets::ALLOW_ALL === $preset || ! $training;
				$this->assertSame( $expected, $robots->allows( $bot->name, '/urunler/' ), "{$preset} / {$bot->name}" );
				$this->assertFalse( $robots->allows( $bot->name, '/wp-admin/' ), 'Admin stays closed for every bot.' );
			}
		}
	}

	/**
	 * All-default writes nothing; re-applying is idempotent; changing the policy replaces the block;
	 * lines added by others before or after the block are kept; removing restores the original.
	 */
	public function test_block_is_replaced_not_duplicated(): void {
		$rules = self::rules();
		$bots  = Registry::bots();

		$this->assertSame( self::WP_DEFAULT, $rules->apply( self::WP_DEFAULT, new BotPolicy(), $bots ) );

		$training = Presets::policy( Presets::BLOCK_TRAINING, $bots );
		$once     = $rules->apply( self::WP_DEFAULT, $training, $bots );
		$this->assertSame( $once, $rules->apply( $once, $training, $bots ) );
		$this->assertSame( 1, substr_count( $once, RobotsRules::BEGIN ) );

		$with_other = $once . "\n# Başka eklenti\nUser-agent: SomeBot\nDisallow: /tmp/\n";
		$replaced   = $rules->apply( $with_other, ( new BotPolicy() )->with( 'ccbot', BotPolicy::DISALLOW ), $bots );
		$this->assertStringContainsString( "# Başka eklenti\nUser-agent: SomeBot\nDisallow: /tmp/", $replaced );
		$this->assertStringStartsWith( self::WP_DEFAULT, $replaced );
		$this->assertSame( 1, substr_count( $replaced, 'User-agent: CCBot' ) );
		$this->assertStringNotContainsString( 'User-agent: GPTBot', $replaced );

		$this->assertSame( self::WP_DEFAULT, RobotsRules::remove( $once ) );
		$this->assertSame( self::WP_DEFAULT, $rules->apply( $once, new BotPolicy(), $bots ), 'Back to default removes the block.' );
	}

	/**
	 * Policy value object and storage.
	 */
	public function test_policy_and_store(): void {
		$policy = BotPolicy::from_array( array( 'gptbot' => 'disallow', 'ccbot' => 'default', 'claudebot' => 'banana', 'uydurma' => 'allow' ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		$this->assertSame( array( 'gptbot' => 'disallow', 'uydurma' => 'allow' ), $policy->to_array() ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		$this->assertTrue( $policy->is_disallowed( 'gptbot' ) );
		$this->assertSame( BotPolicy::DEFAULT, $policy->mode( 'claudebot' ) );

		$settings = new MemorySettings();
		$saved    = ( new PolicyStore( $settings ) )->save( $policy, Registry::bots() );
		$this->assertSame( array( 'gptbot' => 'disallow' ), $saved->to_array(), 'Unknown bots are dropped.' );
		$this->assertEquals( $saved, ( new PolicyStore( $settings ) )->get() );
		$this->assertFalse( $settings->autoload[ PolicyStore::OPTION ] );
	}

	/**
	 * Unknown presets are rejected.
	 */
	public function test_unknown_preset(): void {
		$this->expectException( InvalidArgumentException::class );
		Presets::policy( 'hepsi', Registry::bots() );
	}
}
