<?php
/**
 * Deactivation removes our rewrite rules (1.12.1).
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\Tests\Integration;

use AIHazirSite\Core\Features;
use AIHazirSite\WordPress\Lifecycle;
use AIHazirSite\WordPress\Portal\Portal;
use AIHazirSite\WordPress\Portal\PortalModule;
use AIHazirSite\WordPress\Report\BadgeModule;
use AIHazirSite\WordPress\Schema\SchemaModule;
use WP_UnitTestCase;

/**
 * On a live site /ai-katalog/ stayed in the stored rules after deactivation: the rules were flushed while
 * still registered for the request. Now each rule is forgotten first.
 *
 * @covers \AIHazirSite\WordPress\Platform\RewriteRules
 * @covers \AIHazirSite\WordPress\Lifecycle
 */
final class DeactivateRewriteRulesTest extends WP_UnitTestCase {

	/**
	 * Pretty permalinks and every page-serving feature on.
	 */
	public function set_up(): void {
		parent::set_up();
		$this->set_permalink_structure( '/%postname%/' );
		delete_option( Features::OPTION );
		foreach ( array( Features::CATALOG, Features::SCHEMA_OUTPUT, Features::LLMS_TXT, Features::PORTAL_MODE, Features::COMPLIANCE_SCAN, Features::COMPLIANCE_REPORT ) as $feature ) {
			Features::set( $feature, true );
		}
	}

	/**
	 * Stored rules.
	 *
	 * @return array<string, string>
	 */
	private static function stored(): array {
		$rules = get_option( 'rewrite_rules' );
		return is_array( $rules ) ? $rules : array();
	}

	/**
	 * The three rules are stored while on, and gone after deactivation (the plugin's other data stays).
	 */
	public function test_rules_removed_on_deactivation(): void {
		SchemaModule::rewrite();
		BadgeModule::rewrite();
		PortalModule::rewrite();
		flush_rewrite_rules( false );
		foreach ( array( SchemaModule::REWRITE, BadgeModule::REWRITE, Portal::REWRITE ) as $rule ) {
			$this->assertArrayHasKey( $rule, self::stored(), $rule );
		}

		Lifecycle::deactivate();

		foreach ( array( SchemaModule::REWRITE, BadgeModule::REWRITE, Portal::REWRITE ) as $rule ) {
			$this->assertArrayNotHasKey( $rule, self::stored(), $rule );
		}
		$this->assertTrue( Features::is_enabled( Features::CATALOG ), 'Deactivation keeps the settings.' );
	}
}
