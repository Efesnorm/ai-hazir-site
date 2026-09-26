<?php
/**
 * AI bot and referral measurement (A0).
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\Modules\Measurement;

use AIHazirSite\Core\Features;
use AIHazirSite\Core\Module;
use AIHazirSite\Core\Storage\HitStore;

/**
 * Wires the tracker into WordPress. When the `measurement` feature is off,
 * no counting hooks are registered at all.
 */
final class MeasurementModule implements Module {

	/**
	 * Registers hooks.
	 */
	public function register(): void {
		if ( ! Features::is_enabled( Features::MEASUREMENT ) ) {
			return;
		}

		$tracker = new Tracker( new HitStore() );
		add_action( 'parse_request', array( $tracker, 'capture_current_request' ), 0 );
		add_action( 'shutdown', array( $tracker, 'on_shutdown' ) );
	}
}
