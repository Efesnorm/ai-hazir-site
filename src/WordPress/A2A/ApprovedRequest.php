<?php
/**
 * An outgoing A2A request a person approved.
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\WordPress\A2A;

/**
 * Created only by A2AAdmin after the user confirmed the exact message (capability + nonce);
 * A2AOutbox sends nothing else (guarded by A2ASendsOnlyWithApprovalTest).
 */
final class ApprovedRequest {

	/**
	 * Constructor.
	 *
	 * @param string               $partner  Partner REST base (from the partner list).
	 * @param string               $endpoint Partner agent JSON-RPC URL (from its validated card).
	 * @param array<string, mixed> $request  The JSON-RPC request shown to and approved by the user.
	 * @param int                  $user_id  Approving user.
	 */
	public function __construct(
		public readonly string $partner,
		public readonly string $endpoint,
		public readonly array $request,
		public readonly int $user_id
	) {
	}
}
