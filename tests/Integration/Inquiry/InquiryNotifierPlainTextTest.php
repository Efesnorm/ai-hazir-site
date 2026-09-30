<?php
/**
 * The owner's e-mail carries no markup written by the sender (1.14.2).
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\Tests\Integration\Inquiry;

use AIHazirSite\Core\Inquiry\Inquiry;
use AIHazirSite\Core\Inquiry\InquiryContact;
use AIHazirSite\WordPress\Inquiry\WpInquiryNotifier;
use WP_UnitTestCase;

/**
 * Found in the system check: a mail plugin that turns all mail into HTML would render the sender's markup.
 *
 * @covers \AIHazirSite\WordPress\Inquiry\WpInquiryNotifier
 */
final class InquiryNotifierPlainTextTest extends WP_UnitTestCase {

	/**
	 * Fresh mailer; a valid sender (the test site is "localhost").
	 */
	public function set_up(): void {
		parent::set_up();
		update_option( 'admin_email', 'sahip@ornek.example' );
		add_filter( 'wp_mail_from', static fn(): string => 'wordpress@ornek.example' );
		reset_phpmailer_instance();
	}

	/**
	 * Tags are removed from the message and subject; the text stays; the mail is declared plain text.
	 */
	public function test_markup_is_stripped_and_mail_is_plain_text(): void {
		$inquiry = new Inquiry(
			7,
			Inquiry::KIND_QUOTE_REQUEST,
			Inquiry::SOURCE_AI,
			Inquiry::CHANNEL_A2A,
			null,
			'<b>Acil</b> teklif',
			'Fiyat için <a href="https://sahte.example/giris">buraya tıklayın</a><script>alert(1)</script> lütfen.',
			new InquiryContact( 'Ayşe', '', 'ayse@alici.example' ),
			Inquiry::STATUS_NEW,
			0,
			array(),
			'hash',
			'2026-09-30T10:00:00Z'
		);

		$this->assertTrue( ( new WpInquiryNotifier() )->notify_owner( $inquiry ) );

		$mail = tests_retrieve_phpmailer_instance()->get_sent( 0 );
		$this->assertNotFalse( $mail );
		$this->assertStringNotContainsString( '<', $mail->body );
		$this->assertStringNotContainsString( 'sahte.example', $mail->body );
		$this->assertStringNotContainsString( 'alert', $mail->body );
		$this->assertStringContainsString( 'Fiyat için buraya tıklayın lütfen.', $mail->body );
		$this->assertStringContainsString( 'Acil teklif', $mail->body );
		$this->assertStringContainsString( 'text/plain', $mail->header );
		$this->assertStringNotContainsString( 'ayse@alici.example', $mail->body );
	}
}
