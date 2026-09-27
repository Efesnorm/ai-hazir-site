<?php
/**
 * E-mail to the site owner about a new inquiry.
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\WordPress\Inquiry;

use AIHazirSite\Core\Contracts\InquiryNotifier;
use AIHazirSite\Core\Inquiry\Inquiry;
use AIHazirSite\WordPress\Catalog\WpListingRepository;
use AIHazirSite\WordPress\Inquiry\Admin\InquiryAdmin;

/**
 * One e-mail to the site's admin address. It carries the kind, source, listing, subject, the start of
 * the message and a link to the inquiry box, but no contact data (that stays in the admin screen).
 * Nothing is ever sent to the person or agent that wrote.
 */
final class WpInquiryNotifier implements InquiryNotifier {

	/**
	 * Sends the e-mail.
	 *
	 * @param Inquiry $inquiry Stored inquiry.
	 */
	public function notify_owner( Inquiry $inquiry ): bool {
		$to = (string) get_option( 'admin_email' );
		if ( '' === $to ) {
			return false;
		}
		$listing = null === $inquiry->listing_id ? null : ( new WpListingRepository() )->find( $inquiry->listing_id );
		$kinds   = InquiryAdmin::kind_labels();
		$sources = InquiryAdmin::source_labels();

		$subject = sprintf(
			/* translators: 1: site name, 2: inquiry number. */
			__( '[%1$s] Yeni AI Katalog talebi #%2$d', 'ai-hazir-site' ),
			wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES ),
			(int) $inquiry->id
		);
		$lines = array(
			__( 'Teklif kutunuza yeni bir talep geldi. Hiçbir yanıt otomatik gönderilmedi; talebi panelden inceleyin.', 'ai-hazir-site' ),
			'',
			__( 'Tür', 'ai-hazir-site' ) . ': ' . ( $kinds[ $inquiry->kind ] ?? $inquiry->kind ),
			__( 'Kaynak', 'ai-hazir-site' ) . ': ' . ( $sources[ $inquiry->source ] ?? $inquiry->source ) . ' (' . $inquiry->channel . ')',
			__( 'İlan', 'ai-hazir-site' ) . ': ' . ( null === $listing ? '–' : $listing->title ),
			__( 'Konu', 'ai-hazir-site' ) . ': ' . ( '' === $inquiry->subject ? '–' : $inquiry->subject ),
			__( 'Mesaj', 'ai-hazir-site' ) . ': ' . mb_substr( $inquiry->message, 0, 300 ) . ( mb_strlen( $inquiry->message ) > 300 ? '…' : '' ),
			'',
			__( 'İletişim bilgileri güvenlik için e-postada yer almaz; panelde görünür:', 'ai-hazir-site' ),
			InquiryAdmin::url(),
		);

		return (bool) wp_mail( $to, $subject, implode( "\n", $lines ) );
	}
}
