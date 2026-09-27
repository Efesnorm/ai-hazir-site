<?php
/**
 * Tells the site owner about a new inquiry.
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\Core\Contracts;

use AIHazirSite\Core\Inquiry\Inquiry;

/**
 * Only the site owner is ever notified; nothing is sent to the person or agent that wrote.
 */
interface InquiryNotifier {

	/**
	 * Notifies the site owner.
	 *
	 * @param Inquiry $inquiry Stored inquiry.
	 * @return bool Whether the notification was handed over for delivery.
	 */
	public function notify_owner( Inquiry $inquiry ): bool;
}
