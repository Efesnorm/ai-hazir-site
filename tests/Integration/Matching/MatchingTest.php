<?php
/**
 * Matching on a WordPress site with partner sites.
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\Tests\Integration\Matching;

use AIHazirSite\Core\Catalog\ListingType;
use AIHazirSite\Core\Features;
use AIHazirSite\WordPress\Catalog\Admin\FormState;
use AIHazirSite\WordPress\Catalog\CatalogModule;
use AIHazirSite\WordPress\Matching\MatchingAdmin;
use AIHazirSite\WordPress\Matching\MatchingModule;
use AIHazirSite\WordPress\Uninstaller;
use WP_UnitTestCase;
use WPDieException;

/**
 * Local + partner candidates; an unreachable partner does not stop local matching.
 *
 * @covers \AIHazirSite\WordPress\Matching\MatchingModule
 * @covers \AIHazirSite\WordPress\Matching\MatchingAdmin
 */
final class MatchingTest extends WP_UnitTestCase {

	private const PARTNER = 'https://fabrika.example/wp-json/aihs/v1/';
	private const CLOSED  = 'https://kapali.example/wp-json/aihs/v1/';

	/**
	 * Outgoing request URLs.
	 *
	 * @var list<string>
	 */
	private array $requests = array();

	/**
	 * Features on, admin, partner answers mocked.
	 */
	public function set_up(): void {
		parent::set_up();
		delete_option( Features::OPTION );
		delete_option( MatchingModule::OPTION );
		foreach ( array( self::PARTNER, self::CLOSED ) as $base ) {
			foreach ( array( 'offer', 'supply' ) as $type ) {
				delete_transient( MatchingModule::CACHE . md5( $base . 'listings?type=' . $type . '&per_page=50' ) );
			}
		}
		Features::set( Features::CATALOG, true );
		Features::set( Features::MATCHING, true );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$requests = &$this->requests;
		add_filter(
			'pre_http_request',
			static function ( $preempt, array $args, string $url ) use ( &$requests ) {
				$requests[] = $url;
				if ( str_starts_with( $url, self::CLOSED ) ) {
					return new \WP_Error( 'http_request_failed', 'Bağlantı reddedildi.' );
				}
				$items = str_contains( $url, 'type=offer' ) ? array(
					array(
						'id'             => 7,
						'type'           => 'offer',
						'title'          => 'Fabrika NYY 3x2,5',
						'category'       => 'Kablo',
						'url'            => 'https://fabrika.example/ai-katalog/#ilan-7',
						'quantity'       => array(
							'value' => '1000',
							'unit'  => 'm',
						),
						'region'         => 'Marmara',
						'lead_time_days' => 3,
						'template'       => 'general',
						'attributes'     => array(),
						'description'    => '<script>alert(1)</script>',
					),
				) : array();
				return array(
					'headers'  => array(),
					'body'     => (string) wp_json_encode( array( 'items' => $items ) ),
					'response' => array(
						'code'    => 200,
						'message' => 'OK',
					),
					'cookies'  => array(),
					'filename' => null,
				);
			},
			10,
			3
		);
	}

	/**
	 * Clears the nonce.
	 */
	public function tear_down(): void {
		unset( $_REQUEST['_wpnonce'] );
		parent::tear_down();
	}

	/**
	 * Saves a listing.
	 *
	 * @param array<string, mixed> $input Input.
	 */
	private static function save( array $input ): int {
		$result = CatalogModule::service()->save_listing( $input );
		self::assertTrue( $result->is_valid(), implode( ' | ', $result->errors ) );
		return (int) $result->listing()?->id;
	}

	/**
	 * Settings through the form (https partners only), then matching with a closed partner.
	 */
	public function test_local_and_partner_matches(): void {
		$need  = self::save(
			array(
				'type'           => ListingType::DEMAND,
				'title'          => 'NYY kablo aranıyor',
				'category'       => 'Kablo',
				'quantity'       => '2000',
				'unit'           => 'm',
				'region'         => 'Marmara',
				'lead_time_days' => '10',
			)
		);
		$local = self::save(
			array(
				'type'           => ListingType::SUPPLY,
				'title'          => 'Siparişe NYY üretimi',
				'category'       => 'Kablo',
				'quantity'       => '5000',
				'unit'           => 'm',
				'region'         => 'Marmara',
				'lead_time_days' => '7',
			)
		);
		self::save(
			array(
				'type'     => ListingType::OFFER,
				'title'    => 'LED armatür',
				'category' => 'Aydınlatma',
			)
		);

		$_REQUEST['_wpnonce'] = wp_create_nonce( MatchingAdmin::SAVE );
		MatchingAdmin::handle_save(
			array(
				'weights'  => array(
					'attributes' => '1',
					'quantity'   => '1',
					'lead_time'  => '1',
					'price'      => '1',
				),
				'partners' => self::PARTNER . "\n" . self::CLOSED . "\nhttp://guvensiz.example/\n",
			)
		);
		$this->assertStringContainsString( 'guvensiz', FormState::take()['errors']['partners'] );
		$this->assertSame( array( self::PARTNER, self::CLOSED ), MatchingModule::settings()['partners'] );

		$need_listing = MatchingModule::need( $need );
		$this->assertNotNull( $need_listing );
		$result = MatchingModule::matches( $need_listing );

		$this->assertSame( array( self::CLOSED ), $result['unreachable'], 'The closed partner is reported…' );
		$keys = array_map( static fn( array $m ): string => $m['candidate']->key(), $result['matches'] );
		$this->assertSame( array( '#' . $local, self::PARTNER . '#7' ), $keys, '…and local matching goes on (5000 m covers the need; the partner 1000 m).' );
		$this->assertCount( 1, $result['excluded'], 'The lighting offer fails the category filter.' );

		$html = MatchingAdmin::page( $need, FormState::take() );
		$this->assertStringContainsString( 'class="aihs-unreachable"', $html );
		$this->assertStringContainsString( 'href="https://fabrika.example/ai-katalog/#ilan-7"', $html );
		$this->assertStringNotContainsString( '<script>alert(1)</script>', $html, 'Partner data is escaped.' );
		$this->assertStringContainsString( 'Miktar karşılama', $html );

		// Answers are cached for an hour: a second run asks nobody.
		$before = count( $this->requests );
		MatchingModule::matches( $need_listing );
		$this->assertSame( $before, count( $this->requests ) );

		$this->assertNull( MatchingModule::need( $local ), 'Only needs can be matched.' );
		$this->assertContains( MatchingModule::OPTION, Uninstaller::options() );

		unset( $_REQUEST['_wpnonce'] );
		$this->expectException( WPDieException::class );
		MatchingAdmin::handle_save( array() );
	}
}
