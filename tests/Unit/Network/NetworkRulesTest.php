<?php
/**
 * Portal network rules (1.20.0).
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\Tests\Unit\Network;

use AIHazirSite\Adapters\Llms\LlmsTxtBuilder;
use AIHazirSite\Adapters\Schema\NetworkSchema;
use AIHazirSite\Adapters\Schema\SchemaValidator;
use AIHazirSite\Core\Network\NetworkCheck;
use AIHazirSite\Core\Network\NetworkSettings;
use AIHazirSite\Core\Network\NetworkView;
use AIHazirSite\Tests\Support\SchemaFixtures as F;
use PHPUnit\Framework\TestCase;

/**
 * Settings, mutual consent, what is published, Schema.org and llms.txt output.
 *
 * @covers \AIHazirSite\Core\Network\NetworkSettings
 * @covers \AIHazirSite\Core\Network\NetworkCheck
 * @covers \AIHazirSite\Core\Network\NetworkView
 * @covers \AIHazirSite\Adapters\Schema\NetworkSchema
 * @covers \AIHazirSite\Adapters\Llms\LlmsTxtBuilder
 */
final class NetworkRulesTest extends TestCase {

	private const MOTHER = 'https://www.makedonya.org.tr/';
	private const KOSOVA = 'https://www.kosova.org.tr/';
	private const NOW    = 1790000000;

	/**
	 * Eleven member addresses (the first network's size).
	 *
	 * @return list<string>
	 */
	private static function eleven(): array {
		$list = array();
		foreach ( array( 'kosova', 'yunanistan', 'arnavutluk', 'sirbistan', 'bosna', 'karadag', 'hirvatistan', 'slovenya', 'bulgaristan', 'romanya', 'macaristan' ) as $country ) {
			$list[] = 'https://www.' . $country . '.org.tr/';
		}
		return $list;
	}

	/**
	 * Addresses are normalized; only https site addresses pass.
	 */
	public function test_normalize_url(): void {
		$this->assertSame( 'https://www.kosova.org.tr/', NetworkSettings::normalize_url( ' HTTPS://WWW.Kosova.org.tr ' ) );
		$this->assertSame( 'https://ornek.com/tr/', NetworkSettings::normalize_url( 'https://ornek.com/tr' ) );
		foreach ( array( 'http://ornek.com/', 'ftp://ornek.com', 'https://localhost/', 'https://ornek.com/?a=1', 'https://u:p@ornek.com/', 'javascript:alert(1)', '' ) as $bad ) {
			$this->assertNull( NetworkSettings::normalize_url( $bad ), $bad );
		}
	}

	/**
	 * Mother input: name required, invalid lines reported, duplicates and itself dropped, at most 25.
	 */
	public function test_mother_input(): void {
		[ $settings, $errors ] = NetworkSettings::from_input(
			array(
				'role'    => 'mother',
				'name'    => " Balkan\nPortalları ",
				'members' => implode( "\n", array_merge( self::eleven(), array( 'http://eski.com/', self::KOSOVA, self::MOTHER ) ) ),
			),
			self::MOTHER
		);
		$this->assertSame( 'mother', $settings->role );
		$this->assertSame( 'Balkan Portalları', $settings->name );
		$this->assertSame( self::MOTHER, $settings->mother );
		$this->assertSame( self::eleven(), $settings->members );
		$this->assertCount( 1, $errors );

		[ $none, $errors ] = NetworkSettings::from_input( array( 'role' => 'mother' ), self::MOTHER );
		$this->assertContains( 'Ağ adı gerekli.', $errors );

		$many = array();
		for ( $i = 0; $i < 30; $i++ ) {
			$many[] = 'https://site' . $i . '.example/';
		}
		[ $capped, $errors ] = NetworkSettings::from_input(
			array(
				'role'    => 'mother',
				'name'    => 'Ağ',
				'members' => implode( "\n", $many ),
			),
			self::MOTHER
		);
		$this->assertCount( NetworkSettings::MAX_MEMBERS, $capped->members );
		$this->assertNotSame( array(), $errors );
	}

	/**
	 * Member input: a valid mother other than itself.
	 */
	public function test_member_input(): void {
		[ $settings ] = NetworkSettings::from_input(
			array(
				'role'   => 'member',
				'mother' => 'https://www.makedonya.org.tr',
			),
			self::KOSOVA
		);
		$this->assertSame( 'member', $settings->role );
		$this->assertSame( self::MOTHER, $settings->mother );

		[ $own_url, $errors ] = NetworkSettings::from_input(
			array(
				'role'   => 'member',
				'mother' => self::KOSOVA,
			),
			self::KOSOVA
		);
		$this->assertSame( 'none', $own_url->role );
		$this->assertNotSame( array(), $errors );

		$this->assertSame( 'none', NetworkSettings::from_array( array( 'role' => 'x' ) )->role );
		$this->assertSame( $settings->to_array(), NetworkSettings::from_array( $settings->to_array() )->to_array() );
	}

	/**
	 * Consent from both sides; every missing part is refused on its own.
	 */
	public function test_mutual_consent(): void {
		$member = static fn( string $mother, string $role = 'member' ): ?array => NetworkCheck::document(
			array(
				'role'    => $role,
				'name'    => '',
				'mother'  => $mother,
				'members' => array(),
			)
		);
		$this->assertSame( NetworkCheck::VERIFIED, NetworkCheck::member_status( self::MOTHER, $member( self::MOTHER ) ) );
		$this->assertSame( NetworkCheck::OTHER_MOTHER, NetworkCheck::member_status( self::MOTHER, $member( 'https://baska.example/' ) ) );
		$this->assertSame( NetworkCheck::NOT_DECLARED, NetworkCheck::member_status( self::MOTHER, $member( '', 'none' ) ) );
		$this->assertSame( NetworkCheck::UNREACHABLE, NetworkCheck::member_status( self::MOTHER, null ) );
		$this->assertNull( NetworkCheck::document( array( 'role' => 'admin' ) ) );

		$mother = static fn( array $members, string $role = 'mother', string $own_url = self::MOTHER ): ?array => NetworkCheck::document(
			array(
				'role'    => $role,
				'name'    => 'Balkan Portalları',
				'mother'  => $own_url,
				'members' => $members,
			)
		);
		$listed = array(
			'url'      => self::KOSOVA,
			'name'     => 'Kosova',
			'country'  => 'XK',
			'verified' => true,
		);
		$this->assertSame( NetworkCheck::VERIFIED, NetworkCheck::self_status( self::KOSOVA, self::MOTHER, $mother( array( $listed ) ) ) );
		$this->assertSame( NetworkCheck::NOT_LISTED, NetworkCheck::self_status( self::KOSOVA, self::MOTHER, $mother( array() ) ) );
		$this->assertSame( NetworkCheck::NOT_MOTHER, NetworkCheck::self_status( self::KOSOVA, self::MOTHER, $mother( array( $listed ), 'member' ) ) );
		$this->assertSame( NetworkCheck::NOT_MOTHER, NetworkCheck::self_status( self::KOSOVA, self::MOTHER, $mother( array( $listed ), 'mother', 'https://baska.example/' ) ) );

		$this->assertSame( NetworkCheck::VERIFIED, NetworkCheck::effective( NetworkCheck::UNREACHABLE, self::NOW - 3600, self::NOW ), '24-hour grace.' );
		$this->assertSame( NetworkCheck::UNREACHABLE, NetworkCheck::effective( NetworkCheck::UNREACHABLE, self::NOW - 90000, self::NOW ) );
	}

	/**
	 * Remote texts are data: tags, control characters and long values are cleaned.
	 */
	public function test_remote_text_cleaned(): void {
		$doc = NetworkCheck::document(
			array(
				'role'    => 'mother',
				'name'    => "<script>x</script>Ağ\u{0007} " . str_repeat( 'a', 300 ),
				'mother'  => self::MOTHER,
				'members' => array(
					array(
						'url'      => 'javascript:alert(1)',
						'name'     => 'Kötü',
						'country'  => 'XK',
						'verified' => true,
					),
					array(
						'url'      => self::KOSOVA,
						'name'     => '<b>Kosova</b>',
						'country'  => 'kosova',
						'verified' => 'yes',
					),
				),
			)
		);
		$this->assertNotNull( $doc );
		$this->assertStringNotContainsString( '<', $doc['name'] );
		$this->assertLessThanOrEqual( NetworkSettings::NAME_MAX, mb_strlen( $doc['name'] ) );
		$this->assertCount( 1, $doc['members'] );
		$this->assertSame( 'Kosova', $doc['members'][0]['name'] );
		$this->assertSame( '', $doc['members'][0]['country'] );
		$this->assertFalse( $doc['members'][0]['verified'] );
	}

	/**
	 * Mother view: only verified members are published; its /network lists itself and them.
	 */
	public function test_mother_view(): void {
		$members  = self::eleven();
		$settings = new NetworkSettings( 'mother', 'Balkan Portalları', self::MOTHER, $members );
		$state    = array( 'members' => array() );
		foreach ( $members as $i => $url ) {
			$state['members'][ $url ] = array(
				'status'      => 0 === $i % 2 ? NetworkCheck::VERIFIED : NetworkCheck::NOT_DECLARED,
				'name'        => 'Portal ' . $i,
				'country'     => 'XK',
				'verified_at' => 0 === $i % 2 ? self::NOW : null,
			);
		}
		$view = new NetworkView( $settings, $state, self::MOTHER, 'Makedonya', 'MK', self::NOW );

		$this->assertTrue( $view->active() );
		$this->assertCount( 6, $view->siblings() );
		$doc = $view->document();
		$this->assertSame( 'mother', $doc['role'] );
		$this->assertSame( self::MOTHER, $doc['mother'] );
		$this->assertCount( 7, $doc['members'] );
		$this->assertSame( self::MOTHER, $doc['members'][0]['url'] );

		$alone = new NetworkView( $settings, array(), self::MOTHER, 'Makedonya', 'MK', self::NOW );
		$this->assertFalse( $alone->active(), 'No verified member yet: nothing published.' );
	}

	/**
	 * Member view: siblings come from the mother only after this member is verified; it always names its mother.
	 */
	public function test_member_view(): void {
		$settings   = new NetworkSettings( 'member', '', self::MOTHER );
		$mother_doc = array(
			'role'    => 'mother',
			'name'    => 'Balkan Portalları',
			'mother'  => self::MOTHER,
			'members' => array(
				array(
					'url'      => self::MOTHER,
					'name'     => 'Makedonya',
					'country'  => 'MK',
					'verified' => true,
				),
				array(
					'url'      => self::KOSOVA,
					'name'     => 'Kosova',
					'country'  => 'XK',
					'verified' => true,
				),
			),
		);

		$pending = new NetworkView( $settings, array( 'mother_doc' => $mother_doc ), self::KOSOVA, 'Kosova', 'XK', self::NOW );
		$this->assertFalse( $pending->active() );
		$this->assertSame( self::MOTHER, $pending->document()['mother'], 'Declares its mother before verification.' );
		$this->assertSame( array(), $pending->document()['members'] );

		$verified = new NetworkView(
			$settings,
			array(
				'mother_doc' => $mother_doc,
				'self'       => array(
					'status'      => NetworkCheck::VERIFIED,
					'verified_at' => self::NOW,
				),
			),
			self::KOSOVA,
			'Kosova',
			'XK',
			self::NOW
		);
		$this->assertTrue( $verified->active() );
		$this->assertSame( 'Balkan Portalları', $verified->network_name() );
		$this->assertSame( array( self::MOTHER ), array_column( $verified->siblings(), 'url' ) );
	}

	/**
	 * Schema.org: parentOrganization everywhere, the network node with subOrganization on the mother; valid.
	 */
	public function test_schema(): void {
		$home   = array(
			'@context' => 'https://schema.org',
			'@graph'   => array(
				array(
					'@type' => 'Organization',
					'@id'   => self::MOTHER . '#organization',
					'name'  => 'Makedonya',
				),
			),
		);
		$mother = NetworkSchema::home(
			$home,
			'Balkan Portalları',
			self::MOTHER,
			self::MOTHER,
			'Makedonya',
			array(
				array(
					'url'     => self::KOSOVA,
					'name'    => '',
					'country' => 'XK',
				),
			)
		);
		$this->assertSame( self::MOTHER . '#network', $mother['@graph'][0]['parentOrganization']['@id'] );
		$this->assertSame( self::MOTHER . '#network', $mother['@graph'][1]['@id'] );
		$this->assertSame( array( self::MOTHER . '#organization', self::KOSOVA . '#organization' ), array_column( $mother['@graph'][1]['subOrganization'], '@id' ) );
		$this->assertSame( 'www.kosova.org.tr', $mother['@graph'][1]['subOrganization'][1]['name'], 'A site without a name gets its host.' );
		$this->assertSame( array(), ( new SchemaValidator() )->validate( $mother )['errors'] );

		$member = NetworkSchema::home( $home, 'Balkan Portalları', self::MOTHER, self::MOTHER, 'Makedonya', null );
		$this->assertCount( 1, $member['@graph'] );

		$catalog = NetworkSchema::catalog(
			array(
				'publisher' => array(
					'@type' => 'Organization',
					'name'  => 'Kosova',
				),
			),
			'Balkan Portalları',
			self::MOTHER
		);
		$this->assertSame( 'Balkan Portalları', $catalog['publisher']['parentOrganization']['name'] );
	}

	/**
	 * Llms.txt: the siblings section sits before Optional; absent without siblings.
	 */
	public function test_llms_section(): void {
		$builder = new LlmsTxtBuilder(
			F::SITE_URL,
			F::SITE_URL . 'ai-katalog/',
			'Örnek',
			array(),
			null,
			'',
			array(),
			array(
				'name'  => 'Balkan Portalları',
				'sites' => array(
					array(
						'url'     => self::KOSOVA,
						'name'    => 'Kosova Portalı',
						'country' => 'XK',
					),
				),
			)
		);
		$text    = $builder->build( F::profile(), array(), F::TODAY, '' );
		$this->assertStringContainsString( "## Kardeş portallar – Balkan Portalları\n\n- [Kosova Portalı (XK)](" . self::KOSOVA . 'llms.txt): AI Katalog API: ' . self::KOSOVA . "wp-json/aihs/v1/\n\n## Optional", $text );

		$plain = ( new LlmsTxtBuilder( F::SITE_URL, F::SITE_URL . 'ai-katalog/', 'Örnek' ) )->build( F::profile(), array(), F::TODAY, '' );
		$this->assertStringNotContainsString( 'Kardeş portallar', $plain );
	}
}
