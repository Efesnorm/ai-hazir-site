<?php
/**
 * Tests for ProfileValidator and CompanyProfile.
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\Tests\Unit\Catalog;

use AIHazirSite\Core\Catalog\CompanyProfile;
use AIHazirSite\Core\Catalog\Listing;
use AIHazirSite\Core\Catalog\ProfileValidator;
use PHPUnit\Framework\TestCase;

/**
 * Profile validation unit tests.
 *
 * @covers \AIHazirSite\Core\Catalog\ProfileValidator
 * @covers \AIHazirSite\Core\Catalog\CompanyProfile
 */
final class ProfileValidatorTest extends TestCase {

	/**
	 * A valid profile input.
	 *
	 * @param array<string, mixed> $overrides Changes.
	 * @return array<string, mixed>
	 */
	private static function input( array $overrides = array() ): array {
		return array_merge(
			array(
				'name'           => 'Örnek Kablo A.Ş.',
				'sector'         => 'Kablo üretimi',
				'country'        => 'tr',
				'languages'      => 'tr, EN',
				'contact_email'  => 'Satis@Ornek.com.tr',
				'contact_phone'  => '+90 (212) 555 12 34',
				'certifications' => "ISO 9001\nTSE\n",
			),
			$overrides
		);
	}

	/**
	 * Valid input is normalized; a corporate address gives no warning.
	 */
	public function test_valid_profile(): void {
		$result = ( new ProfileValidator() )->validate( self::input() );

		$this->assertTrue( $result->is_valid() );
		$this->assertSame( array(), $result->warnings );
		$profile = $result->profile();
		$this->assertInstanceOf( CompanyProfile::class, $profile );
		$this->assertSame( 'TR', $profile->country );
		$this->assertSame( array( 'tr', 'en' ), $profile->languages );
		$this->assertSame( 'satis@ornek.com.tr', $profile->contact_email );
		$this->assertSame( array( 'ISO 9001', 'TSE' ), $profile->certifications );
		$this->assertEquals( $profile, CompanyProfile::from_array( $profile->to_array() ) );
	}

	/**
	 * A free-mailbox address saves but warns.
	 */
	public function test_personal_email_warns_but_saves(): void {
		$result = ( new ProfileValidator() )->validate( self::input( array( 'contact_email' => 'ahmet.yilmaz@gmail.com' ) ) );

		$this->assertTrue( $result->is_valid() );
		$this->assertSame( array( ProfileValidator::PERSONAL_EMAIL_WARNING ), $result->warnings );
	}

	/**
	 * Invalid inputs.
	 *
	 * @return array<string, array{array<string, mixed>, string}>
	 */
	public static function invalid(): array {
		return array(
			'empty name'   => array( array( 'name' => '' ), 'name' ),
			'bad country'  => array( array( 'country' => 'TUR' ), 'country' ),
			'bad language' => array( array( 'languages' => 'turkce' ), 'languages' ),
			'bad email'    => array( array( 'contact_email' => 'satis@' ), 'contact_email' ),
			'bad phone'    => array( array( 'contact_phone' => 'bizi arayın' ), 'contact_phone' ),
		);
	}

	/**
	 * Invalid profile input is rejected on the right field.
	 *
	 * @dataProvider invalid
	 *
	 * @param array<string, mixed> $overrides Changes.
	 * @param string               $field     Field.
	 */
	public function test_invalid( array $overrides, string $field ): void {
		$result = ( new ProfileValidator() )->validate( self::input( $overrides ) );

		$this->assertFalse( $result->is_valid() );
		$this->assertArrayHasKey( $field, $result->errors );
	}

	/**
	 * The data model has no personal-data fields; changing the field lists must be deliberate.
	 */
	public function test_no_personal_data_fields(): void {
		$this->assertSame( array( 'name', 'sector', 'country', 'languages', 'contact_email', 'contact_phone', 'certifications' ), CompanyProfile::FIELDS );
		$this->assertSame(
			array( 'id', 'type', 'title', 'description', 'category', 'quantity', 'unit', 'price_min', 'price_max', 'currency', 'region', 'lead_time_days', 'valid_until', 'updated_at', 'attributes' ),
			Listing::FIELDS
		);
		foreach ( array_merge( CompanyProfile::FIELDS, Listing::FIELDS ) as $field ) {
			$this->assertDoesNotMatchRegularExpression( '/person|first_?name|last_?name|surname|birth|tc_?kimlik|national_id|ip_?address/i', $field );
		}
	}
}
