<?php
/**
 * Per-bot access policy.
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\Core\Access;

/**
 * Bot id → allow | disallow | default. "default" writes nothing: the bot follows the
 * site's general (User-agent: *) rules.
 */
final class BotPolicy {

	public const ALLOW    = 'allow';
	public const DISALLOW = 'disallow';
	public const DEFAULT  = 'default';

	public const MODES = array( self::ALLOW, self::DISALLOW, self::DEFAULT );

	/**
	 * Non-default modes by bot id.
	 *
	 * @var array<string, string>
	 */
	private array $modes = array();

	/**
	 * Constructor.
	 *
	 * @param array<string, string> $modes Bot id → mode; invalid and default entries are dropped.
	 */
	public function __construct( array $modes = array() ) {
		foreach ( $modes as $bot_id => $mode ) {
			if ( in_array( $mode, array( self::ALLOW, self::DISALLOW ), true ) && '' !== (string) $bot_id ) {
				$this->modes[ (string) $bot_id ] = $mode;
			}
		}
	}

	/**
	 * Mode of a bot.
	 *
	 * @param string $bot_id Bot id.
	 */
	public function mode( string $bot_id ): string {
		return $this->modes[ $bot_id ] ?? self::DEFAULT;
	}

	/**
	 * Whether the site owner chose to block the bot.
	 *
	 * @param string $bot_id Bot id.
	 */
	public function is_disallowed( string $bot_id ): bool {
		return self::DISALLOW === $this->mode( $bot_id );
	}

	/**
	 * Copy with one bot changed.
	 *
	 * @param string $bot_id Bot id.
	 * @param string $mode   Mode.
	 */
	public function with( string $bot_id, string $mode ): self {
		$modes            = $this->modes;
		$modes[ $bot_id ] = $mode;
		return new self( $modes );
	}

	/**
	 * Non-default entries only.
	 *
	 * @return array<string, string>
	 */
	public function to_array(): array {
		return $this->modes;
	}

	/**
	 * From stored data (anything invalid is ignored).
	 *
	 * @param mixed $data Stored data.
	 */
	public static function from_array( mixed $data ): self {
		$modes = array();
		foreach ( is_array( $data ) ? $data : array() as $bot_id => $mode ) {
			if ( is_string( $mode ) ) {
				$modes[ (string) $bot_id ] = $mode;
			}
		}
		return new self( $modes );
	}
}
