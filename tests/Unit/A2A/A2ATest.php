<?php
/**
 * A2A card contract and JSON-RPC server.
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\Tests\Unit\A2A;

use AIHazirSite\Adapters\A2A\A2ASkills;
use AIHazirSite\Adapters\A2A\AgentCardBuilder;
use AIHazirSite\Adapters\A2A\AgentCardValidator;
use AIHazirSite\Adapters\A2A\JsonRpcServer;
use AIHazirSite\Core\Catalog\CompanyProfile;
use AIHazirSite\Core\Compliance\Checks\AdvancedCheck;
use AIHazirSite\Tests\Unit\UnitTestCase;

/**
 * Against the official A2A 1.0.0 definitions (a2a.proto).
 *
 * @covers \AIHazirSite\Adapters\A2A\AgentCardBuilder
 * @covers \AIHazirSite\Adapters\A2A\AgentCardValidator
 * @covers \AIHazirSite\Adapters\A2A\JsonRpcServer
 * @covers \AIHazirSite\Adapters\A2A\A2ASkills
 */
final class A2ATest extends UnitTestCase {

	/**
	 * Our card.
	 *
	 * @param string[] $skills Skills.
	 * @return array<string, mixed>
	 */
	private static function card( array $skills = array( A2ASkills::AVAILABILITY, A2ASkills::QUOTE ) ): array {
		return AgentCardBuilder::build( new CompanyProfile( 'Örnek Kablo A.Ş.', 'Kablo üretimi' ), 'Site', 'https://ornek.example/', 'https://ornek.example/wp-json/aihs/a2a', '1.6.0', $skills );
	}

	/**
	 * Contract: every REQUIRED field of AgentCard, AgentInterface and AgentSkill, with proto3 JSON names.
	 */
	public function test_card_contract(): void {
		$card = self::card();
		$this->assertSame( array(), AgentCardValidator::errors( $card ) );
		$this->assertSame( array( 'url', 'protocolBinding', 'protocolVersion' ), array_keys( $card['supportedInterfaces'][0] ) );
		$this->assertSame( array( 'JSONRPC', '1.0' ), array( $card['supportedInterfaces'][0]['protocolBinding'], $card['supportedInterfaces'][0]['protocolVersion'] ) );
		foreach ( $card['skills'] as $skill ) {
			foreach ( array( 'id', 'name', 'description', 'tags' ) as $field ) {
				$this->assertNotEmpty( $skill[ $field ], $field );
			}
		}
		$this->assertSame( array( A2ASkills::AVAILABILITY ), array_column( self::card( array( A2ASkills::AVAILABILITY ) )['skills'], 'id' ) );
		$this->assertSame( 'https://ornek.example/wp-json/aihs/a2a', AgentCardValidator::jsonrpc_url( $card ) );

		// The U1 "advanced" check expects the same required fields.
		$required = AgentCardValidator::REQUIRED;
		$checked  = AdvancedCheck::REQUIRED_FIELDS;
		sort( $required );
		sort( $checked );
		$this->assertSame( $required, $checked );
	}

	/**
	 * Half or old-format cards are rejected.
	 */
	public function test_invalid_cards(): void {
		$card = self::card();
		foreach ( AgentCardValidator::REQUIRED as $field ) {
			$broken = $card;
			unset( $broken[ $field ] );
			$this->assertNotSame( array(), AgentCardValidator::errors( $broken ), $field );
		}
		$old        = $card;
		$old['url'] = 'https://ornek.example/a2a';
		unset( $old['supportedInterfaces'] );
		$this->assertContains( 'supportedInterfaces eksik.', AgentCardValidator::errors( $old ), 'v0.2 style card (top-level url).' );

		$bad                        = $card;
		$bad['skills'][0]['tags']   = array();
		$bad['supportedInterfaces'] = array( array( 'url' => 'https://x.example' ) );
		$bad['capabilities']        = array( 'x' );
		$errors                     = AgentCardValidator::errors( $bad );
		$this->assertContains( 'skills[0].tags boş olmayan metin listesi olmalı.', $errors );
		$this->assertContains( 'supportedInterfaces[0].protocolBinding eksik.', $errors );
		$this->assertContains( 'capabilities nesne olmalı.', $errors );
		$this->assertSame( array( 'Kartvizit bir JSON nesnesi değil.' ), AgentCardValidator::errors( 'x' ) );

		$http                                  = $card;
		$http['supportedInterfaces'][0]['url'] = 'http://ornek.example/a2a';
		$this->assertNull( AgentCardValidator::jsonrpc_url( $http ), 'Only https endpoints are used.' );
	}

	/**
	 * JSON-RPC: version, methods, parts, skills; results as a Task with a DataPart artifact.
	 */
	public function test_server(): void {
		$calls   = array();
		$n       = 0;
		$server  = new JsonRpcServer(
			array( A2ASkills::AVAILABILITY ),
			static function ( string $skill, array $data ) use ( &$calls ): array {
				$calls[] = array( $skill, $data );
				return array(
					'state' => 'TASK_STATE_COMPLETED',
					'text'  => 'Evet.',
					'data'  => array( 'answer' => 'yes' ),
				);
			},
			static function () use ( &$n ): string {
				return 'id-' . ( ++$n );
			}
		);
		$request = JsonRpcServer::send_message(
			'm1',
			array(
				'skill'      => A2ASkills::AVAILABILITY,
				'listing_id' => 12,
			)
		);

		$response = $server->handle( $request, '1.0' );
		$task     = $response['result']['task'];
		$this->assertSame( 'm1', $response['id'] );
		$this->assertSame( 'TASK_STATE_COMPLETED', $task['status']['state'] );
		$this->assertSame( 'ROLE_AGENT', $task['status']['message']['role'] );
		$this->assertEquals( (object) array( 'answer' => 'yes' ), $task['artifacts'][0]['parts'][0]['data'] );
		$this->assertSame( array( array( A2ASkills::AVAILABILITY, $request['params']['message']['parts'][0]['data'] ) ), $calls );

		$code = static fn( array $r ): int => (int) ( $r['error']['code'] ?? 0 );
		$this->assertSame( JsonRpcServer::VERSION_NOT_SUPPORTED, $code( $server->handle( $request, '' ) ), 'No header = 0.3.' );
		$this->assertSame( JsonRpcServer::VERSION_NOT_SUPPORTED, $code( $server->handle( $request, '0.3' ) ) );
		$this->assertSame( JsonRpcServer::PARSE_ERROR, $code( $server->handle( null, '1.0' ) ) );
		$this->assertSame( JsonRpcServer::INVALID_REQUEST, $code( $server->handle( array( 'method' => 'SendMessage' ), '1.0' ) ) );
		$this->assertSame( JsonRpcServer::UNSUPPORTED_OPERATION, $code( $server->handle( array_merge( $request, array( 'method' => 'GetTask' ) ), '1.0' ) ) );
		$this->assertSame( JsonRpcServer::METHOD_NOT_FOUND, $code( $server->handle( array_merge( $request, array( 'method' => 'message/send' ) ), '1.0' ) ) );

		$text                               = $request;
		$text['params']['message']['parts'] = array( array( 'text' => 'Stokta var mı?' ) );
		$this->assertSame( JsonRpcServer::CONTENT_NOT_SUPPORTED, $code( $server->handle( $text, '1.0' ) ) );
		$other = $request;
		$other['params']['message']['parts'][0]['data']['skill'] = A2ASkills::QUOTE;
		$this->assertSame( JsonRpcServer::INVALID_PARAMS, $code( $server->handle( $other, '1.0' ) ), 'Skill not offered here.' );
		$agent                              = $request;
		$agent['params']['message']['role'] = 'ROLE_AGENT';
		$this->assertSame( JsonRpcServer::INVALID_PARAMS, $code( $server->handle( $agent, '1.0' ) ) );
		$this->assertCount( 1, $calls, 'Refused requests never reach a skill.' );
	}
}
