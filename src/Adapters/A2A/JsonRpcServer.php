<?php
/**
 * A2A JSON-RPC binding (server side).
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\Adapters\A2A;

/**
 * JSON-RPC 2.0 with A2A 1.0 semantics: `SendMessage` runs a skill and answers with a Task in a
 * final state (results as a DataPart artifact). Tasks are not stored, so the task methods answer
 * UnsupportedOperationError. The A2A-Version must be 1.x (an empty header means 0.3 and is refused).
 */
final class JsonRpcServer {

	public const PARSE_ERROR           = -32700;
	public const INVALID_REQUEST       = -32600;
	public const METHOD_NOT_FOUND      = -32601;
	public const INVALID_PARAMS        = -32602;
	public const UNSUPPORTED_OPERATION = -32004;
	public const CONTENT_NOT_SUPPORTED = -32005;
	public const VERSION_NOT_SUPPORTED = -32009;

	/**
	 * A2A methods that exist but are not offered here.
	 */
	public const UNSUPPORTED = array( 'SendStreamingMessage', 'GetTask', 'ListTasks', 'CancelTask', 'SubscribeToTask', 'CreateTaskPushNotificationConfig', 'GetTaskPushNotificationConfig', 'ListTaskPushNotificationConfigs', 'DeleteTaskPushNotificationConfig', 'GetExtendedAgentCard' );

	/**
	 * Final task states a skill may return.
	 */
	public const STATES = array( 'TASK_STATE_COMPLETED', 'TASK_STATE_REJECTED', 'TASK_STATE_FAILED', 'TASK_STATE_INPUT_REQUIRED' );

	/**
	 * Constructor.
	 *
	 * @param string[] $skills   Offered skill ids.
	 * @param callable $dispatch fn( string $skill, array $data ): array{state: string, text: string, data: array<string, mixed>}.
	 * @param callable $new_id   fn(): string, a unique id (task, message, artifact ids).
	 *
	 * @phpstan-param list<string> $skills
	 * @phpstan-param callable(string, array<string, mixed>): array{state: string, text: string, data: array<string, mixed>} $dispatch
	 * @phpstan-param callable(): string $new_id
	 */
	public function __construct(
		private readonly array $skills,
		private $dispatch,
		private $new_id
	) {
	}

	/**
	 * The JSON-RPC response for a request.
	 *
	 * @param mixed  $request Decoded body (null when the body was not JSON).
	 * @param string $version A2A-Version header ('' when absent).
	 * @return array<string, mixed>
	 */
	public function handle( mixed $request, string $version ): array {
		if ( null === $request ) {
			return self::error( null, self::PARSE_ERROR, 'Gövde geçerli JSON değil.' );
		}
		$id = is_array( $request ) && ( is_string( $request['id'] ?? null ) || is_int( $request['id'] ?? null ) ) ? $request['id'] : null;
		if ( ! is_array( $request ) || '2.0' !== ( $request['jsonrpc'] ?? null ) || ! is_string( $request['method'] ?? null ) ) {
			return self::error( $id, self::INVALID_REQUEST, 'JSON-RPC 2.0 isteği bekleniyor.' );
		}
		if ( 1 !== preg_match( '/^1\.\d+$/', trim( $version ) ) ) {
			return self::error( $id, self::VERSION_NOT_SUPPORTED, 'Desteklenen A2A sürümü: 1.0 (A2A-Version başlığı).' );
		}
		$method = $request['method'];
		if ( in_array( $method, self::UNSUPPORTED, true ) ) {
			return self::error( $id, self::UNSUPPORTED_OPERATION, $method . ' bu agent\'ta sunulmuyor; görevler anında sonuçlanır.' );
		}
		if ( 'SendMessage' !== $method ) {
			return self::error( $id, self::METHOD_NOT_FOUND, 'Bilinmeyen yöntem.' );
		}

		$message = is_array( $request['params'] ?? null ) && is_array( $request['params']['message'] ?? null ) ? $request['params']['message'] : null;
		if ( null === $message || ! is_string( $message['messageId'] ?? null ) || 'ROLE_USER' !== ( $message['role'] ?? null ) || ! is_array( $message['parts'] ?? null ) ) {
			return self::error( $id, self::INVALID_PARAMS, 'params.message (messageId, role ROLE_USER, parts) gerekli.' );
		}
		$data = null;
		foreach ( $message['parts'] as $part ) {
			if ( is_array( $part ) && is_array( $part['data'] ?? null ) && ! array_is_list( $part['data'] ) ) {
				$data = $part['data'];
				break;
			}
		}
		if ( null === $data ) {
			return self::error( $id, self::CONTENT_NOT_SUPPORTED, 'Bir DataPart (application/json) bekleniyor; beceri "skill" alanında.' );
		}
		$skill = is_string( $data['skill'] ?? null ) ? $data['skill'] : '';
		if ( ! in_array( $skill, $this->skills, true ) ) {
			return self::error( $id, self::INVALID_PARAMS, 'Bilinmeyen beceri. Sunulanlar: ' . implode( ', ', $this->skills ) . '.' );
		}

		$result  = ( $this->dispatch )( $skill, $data );
		$state   = in_array( $result['state'], self::STATES, true ) ? $result['state'] : 'TASK_STATE_FAILED';
		$context = is_string( $message['contextId'] ?? null ) && '' !== $message['contextId'] ? $message['contextId'] : ( $this->new_id )();
		$task_id = ( $this->new_id )();
		return array(
			'jsonrpc' => '2.0',
			'id'      => $id,
			'result'  => array(
				'task' => array(
					'id'        => $task_id,
					'contextId' => $context,
					'status'    => array(
						'state'   => $state,
						'message' => array(
							'messageId' => ( $this->new_id )(),
							'contextId' => $context,
							'taskId'    => $task_id,
							'role'      => 'ROLE_AGENT',
							'parts'     => array( array( 'text' => $result['text'] ) ),
						),
					),
					'artifacts' => array(
						array(
							'artifactId' => ( $this->new_id )(),
							'name'       => $skill,
							'parts'      => array(
								array(
									'data'      => (object) $result['data'],
									'mediaType' => 'application/json',
								),
							),
						),
					),
				),
			),
		);
	}

	/**
	 * A JSON-RPC error response.
	 *
	 * @param string|int|null $id      Request id.
	 * @param int             $code    Code.
	 * @param string          $message Message.
	 * @return array<string, mixed>
	 */
	public static function error( string|int|null $id, int $code, string $message ): array {
		return array(
			'jsonrpc' => '2.0',
			'id'      => $id,
			'error'   => array(
				'code'    => $code,
				'message' => $message,
			),
		);
	}

	/**
	 * A SendMessage request for a skill (used by our client for partner agents).
	 *
	 * @param string               $message_id Unique message id.
	 * @param array<string, mixed> $data       Skill input (with "skill").
	 * @return array<string, mixed>
	 */
	public static function send_message( string $message_id, array $data ): array {
		return array(
			'jsonrpc' => '2.0',
			'id'      => $message_id,
			'method'  => 'SendMessage',
			'params'  => array(
				'message' => array(
					'messageId' => $message_id,
					'role'      => 'ROLE_USER',
					'parts'     => array(
						array(
							'data'      => $data,
							'mediaType' => 'application/json',
						),
					),
				),
			),
		);
	}
}
