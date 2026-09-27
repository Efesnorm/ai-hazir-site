<?php
/**
 * Badge (shortcode, block) and the verification page.
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\Tests\Integration\Report;

use AIHazirSite\Core\Compliance\ScanStore;
use AIHazirSite\Core\Features;
use AIHazirSite\Tests\Unit\Report\ComplianceReportDataTest;
use AIHazirSite\WordPress\Compliance\ComplianceModule;
use AIHazirSite\WordPress\Report\BadgeModule;
use WP_Block_Type_Registry;
use WP_UnitTestCase;

/**
 * Threshold rule, feature switch, verification page content.
 *
 * @covers \AIHazirSite\WordPress\Report\BadgeModule
 */
final class BadgeTest extends WP_UnitTestCase {

	/**
	 * Clean scans, feature on, pretty permalinks.
	 */
	public function set_up(): void {
		parent::set_up();
		foreach ( array( ScanStore::OPTION, ScanStore::FIRST_OPTION, Features::OPTION ) as $option ) {
			delete_option( $option );
		}
		update_option( 'blogname', 'Örnek Kablo A.Ş.' );
		Features::set( Features::COMPLIANCE_REPORT, true );
		$this->set_permalink_structure( '/%postname%/' );
	}

	/**
	 * Stores a scan with the given score (69 or 70 here: structured_data and llms_txt at 0).
	 *
	 * @param float $readability Readability ratio (1.0 → 70, 0.95 → 69).
	 */
	private function scan( float $readability ): void {
		ComplianceModule::store()->add(
			ComplianceReportDataTest::scan(
				'2026-09-27T10:00:00Z',
				array(
					'structured_data' => 0.0,
					'llms_txt'        => 0.0,
					'readability'     => $readability,
				)
			)
		);
	}

	/**
	 * 69 → nothing; 70 → accessible SVG badge linking to the verification page.
	 */
	public function test_threshold(): void {
		$this->scan( 0.95 );
		$this->assertSame( 69, ComplianceModule::store()->latest()?->score() );
		$this->assertSame( '', do_shortcode( '[aihs_rozet]' ) );

		$this->scan( 1.0 );
		$this->assertSame( 70, ComplianceModule::store()->latest()?->score() );
		$html = do_shortcode( '[aihs_rozet]' );
		$this->assertStringContainsString( '<svg', $html );
		$this->assertStringContainsString( 'role="img"', $html );
		$this->assertStringContainsString( 'href="' . home_url( '/ai-hazir-dogrulama/' ) . '"', $html );
		$this->assertStringContainsString( '70', $html );
		$this->assertStringContainsString( '27.09.2026', $html );
	}

	/**
	 * The `aihs_badge_threshold` filter moves the bar.
	 */
	public function test_threshold_filter(): void {
		$this->scan( 1.0 );
		$raise = static fn(): int => 80;
		add_filter( 'aihs_badge_threshold', $raise );
		$this->assertSame( '', do_shortcode( '[aihs_rozet]' ) );
		remove_filter( 'aihs_badge_threshold', $raise );
		$this->assertNotSame( '', do_shortcode( '[aihs_rozet]' ) );
	}

	/**
	 * Feature off: the shortcode renders nothing (not its tag), no page rule.
	 */
	public function test_feature_off(): void {
		$this->scan( 1.0 );
		Features::set( Features::COMPLIANCE_REPORT, false );
		( new BadgeModule() )->register();
		BadgeModule::rewrite();

		$this->assertSame( '', do_shortcode( '[aihs_rozet]' ) );
		$this->assertArrayNotHasKey( BadgeModule::REWRITE, (array) get_option( 'rewrite_rules' ) );
	}

	/**
	 * No scan yet: nothing.
	 */
	public function test_no_scan(): void {
		$this->assertSame( '', BadgeModule::render() );
	}

	/**
	 * The dynamic block is registered from block.json and renders the same badge.
	 */
	public function test_block(): void {
		$registry = WP_Block_Type_Registry::get_instance();
		if ( $registry->is_registered( 'ai-hazir-site/rozet' ) ) {
			$registry->unregister( 'ai-hazir-site/rozet' );
		}
		BadgeModule::register_block();
		$this->assertTrue( $registry->is_registered( 'ai-hazir-site/rozet' ) );

		$this->scan( 1.0 );
		$this->assertStringContainsString( '<svg', do_blocks( '<!-- wp:ai-hazir-site/rozet /-->' ) );
		$this->scan( 0.95 );
		$this->assertSame( '', trim( do_blocks( '<!-- wp:ai-hazir-site/rozet /-->' ) ) );
	}

	/**
	 * /ai-hazir-dogrulama/ resolves to the page; it shows site, score, date and every check.
	 */
	public function test_verification_page(): void {
		( new BadgeModule() )->register();
		BadgeModule::rewrite();
		$this->assertArrayHasKey( BadgeModule::REWRITE, (array) get_option( 'rewrite_rules' ) );

		$this->go_to( BadgeModule::url() );
		$this->assertSame( '1', get_query_var( BadgeModule::QUERY_VAR ) );

		$this->scan( 1.0 );
		$html = BadgeModule::page_html();
		$this->assertStringContainsString( 'Örnek Kablo A.Ş.', $html );
		$this->assertStringContainsString( 'AI Hazır kriteri karşılanıyor: uyum puanı 70/100 (eşik 70)', $html );
		$this->assertStringContainsString( '27.09.2026', $html );
		$this->assertSame( 7, substr_count( $html, 'data-check=' ) );
		$this->assertStringContainsString( 'data-check="structured_data"', $html );

		$this->scan( 0.95 );
		$this->assertStringContainsString( 'Şu anda AI Hazır kriteri karşılanmıyor: uyum puanı 69/100', BadgeModule::page_html() );
	}

	/**
	 * Without a measured scan the page says so.
	 */
	public function test_verification_page_without_scan(): void {
		$this->assertStringContainsString( 'henüz ölçülmüş bir uyum taraması yok', BadgeModule::page_html() );
	}
}
