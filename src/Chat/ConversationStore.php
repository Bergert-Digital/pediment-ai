<?php
/**
 * CRUD for the chat_conversations and chat_messages tables.
 *
 * @package PedimentAi
 */

declare(strict_types=1);

namespace PedimentAi\Chat;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ConversationStore {
	private string $conversations;
	private string $messages;
	private string $attachments;

	public function __construct() {
		global $wpdb;
		$this->conversations = $wpdb->prefix . 'pediment_ai_chat_conversations';
		$this->messages      = $wpdb->prefix . 'pediment_ai_chat_messages';
		$this->attachments   = $wpdb->prefix . 'pediment_ai_chat_attachments';
	}

	/**
	 * Get the conversation for a (post, user) pair, creating one if absent.
	 *
	 * Note: not race-safe. Two concurrent calls with the same arguments can both
	 * miss the SELECT and produce duplicate rows. The race is benign in the chat
	 * use case (a single user is unlikely to open two editor sessions on the same
	 * post in the same instant), but a future migration could add a UNIQUE
	 * constraint on (post_id, user_id) and switch to INSERT IGNORE if it bites.
	 *
	 * @return array{id:int, post_id:int, user_id:int, messages:array<int,array<string,mixed>>}
	 */
	public function getOrCreate( int $post_id, int $user_id ): array {
		global $wpdb;
		$header = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT id, post_id, user_id, created_at, updated_at FROM {$this->conversations} WHERE post_id = %d AND user_id = %d LIMIT 1",
				$post_id,
				$user_id
			),
			ARRAY_A
		);
		if ( ! $header ) {
			$now = current_time( 'mysql', true );
			$wpdb->insert(
				$this->conversations,
				[ 'post_id' => $post_id, 'user_id' => $user_id, 'created_at' => $now, 'updated_at' => $now ]
			);
			$header = $this->loadHeader( (int) $wpdb->insert_id );
		}
		return $this->buildResult( $header );
	}

	/**
	 * @return array{id:int, post_id:int, user_id:int, messages:array<int,array<string,mixed>>}|null
	 */
	public function findById( int $id ): ?array {
		$header = $this->loadHeader( $id );
		return $header ? $this->buildResult( $header ) : null;
	}

	/**
	 * @param array<string,string> $header
	 * @return array{id:int, post_id:int, user_id:int, messages:array<int,array<string,mixed>>}
	 */
	private function buildResult( array $header ): array {
		global $wpdb;
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, role, status, content, tool_calls, error, created_at FROM {$this->messages} WHERE conversation_id = %d ORDER BY id ASC LIMIT 200",
				(int) $header['id']
			),
			ARRAY_A
		);
		$messages = array_map( [ $this, 'hydrate' ], $rows ?: [] );
		return [
			'id'       => (int) $header['id'],
			'post_id'  => (int) $header['post_id'],
			'user_id'  => (int) $header['user_id'],
			'messages' => $this->attachImages( $messages ),
		];
	}

	/**
	 * @return array<string,string>|null
	 */
	private function loadHeader( int $id ): ?array {
		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT id, post_id, user_id, created_at, updated_at FROM {$this->conversations} WHERE id = %d", $id ),
			ARRAY_A
		);
		return $row ?: null;
	}

	/**
	 * @param array<string,mixed> $row
	 * @return array<string,mixed>
	 */
	private function hydrate( array $row ): array {
		$result = [
			'id'         => (int) $row['id'],
			'role'       => (string) $row['role'],
			'status'     => (string) $row['status'],
			'content'    => (string) $row['content'],
			'tool_calls' => $row['tool_calls'] ? ( json_decode( (string) $row['tool_calls'], true ) ?: [] ) : [],
			'error'      => $row['error'] ? ( json_decode( (string) $row['error'], true ) ?: null ) : null,
			'created_at' => (string) $row['created_at'],
		];
		if ( isset( $row['conversation_id'] ) ) {
			$result['conversation_id'] = (int) $row['conversation_id'];
		}
		return $result;
	}

	/**
	 * @param array<int,array{media_type:string,data:string}> $images
	 */
	public function appendUserMessage( int $conversation_id, string $content, array $images = [] ): int {
		$id = $this->insertMessage( $conversation_id, 'user', 'complete', $content );
		foreach ( $images as $img ) {
			$this->insertAttachment( $id, (string) ( $img['media_type'] ?? '' ), (string) ( $img['data'] ?? '' ) );
		}
		return $id;
	}

	private function insertAttachment( int $message_id, string $media_type, string $data ): void {
		global $wpdb;
		$result = $wpdb->insert( $this->attachments, [
			'message_id' => $message_id,
			'media_type' => $media_type,
			'data'       => $data,
			'created_at' => current_time( 'mysql', true ),
		] );
		if ( false === $result ) {
			error_log( 'pediment-ai: failed to persist chat attachment: ' . $wpdb->last_error );
		}
	}

	/**
	 * @return array<int,array{media_type:string,data:string}>
	 */
	public function getAttachments( int $message_id ): array {
		global $wpdb;
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT media_type, data FROM {$this->attachments} WHERE message_id = %d ORDER BY id ASC",
				$message_id
			),
			ARRAY_A
		);
		return array_map(
			static fn( $r ) => [ 'media_type' => (string) $r['media_type'], 'data' => (string) $r['data'] ],
			$rows ?: []
		);
	}

	public function startAssistantTurn( int $conversation_id ): int {
		return $this->insertMessage( $conversation_id, 'assistant', 'streaming', '' );
	}

	public function appendAssistantDelta( int $message_id, string $delta ): void {
		global $wpdb;
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$this->messages} SET content = CONCAT(content, %s), updated_at = %s WHERE id = %d",
				$delta,
				current_time( 'mysql', true ),
				$message_id
			)
		);
	}

	/**
	 * @param array<string,mixed> $call
	 */
	public function recordToolCall( int $message_id, array $call ): void {
		global $wpdb;
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT tool_calls FROM {$this->messages} WHERE id = %d", $message_id ), ARRAY_A );
		$calls = $row && $row['tool_calls'] ? ( json_decode( (string) $row['tool_calls'], true ) ?: [] ) : [];
		$calls[] = $call;
		$wpdb->update(
			$this->messages,
			[ 'tool_calls' => wp_json_encode( $calls ), 'updated_at' => current_time( 'mysql', true ) ],
			[ 'id' => $message_id ]
		);
	}

	public function complete( int $message_id ): void {
		global $wpdb;
		$wpdb->update(
			$this->messages,
			[ 'status' => 'complete', 'updated_at' => current_time( 'mysql', true ) ],
			[ 'id' => $message_id ]
		);
	}

	public function fail( int $message_id, string $code, string $message ): void {
		global $wpdb;
		$wpdb->update(
			$this->messages,
			[
				'status'     => 'error',
				'error'      => wp_json_encode( [ 'code' => $code, 'message' => $message ] ),
				'updated_at' => current_time( 'mysql', true ),
			],
			[ 'id' => $message_id ]
		);
	}

	public function abort( int $message_id ): void {
		global $wpdb;
		$wpdb->update(
			$this->messages,
			[ 'status' => 'aborted', 'updated_at' => current_time( 'mysql', true ) ],
			[ 'id' => $message_id ]
		);
	}

	public function isAborted( int $message_id ): bool {
		global $wpdb;
		$status = (string) $wpdb->get_var( $wpdb->prepare( "SELECT status FROM {$this->messages} WHERE id = %d", $message_id ) );
		return 'aborted' === $status;
	}

	/**
	 * @return array<string,mixed>|null
	 */
	public function getMessage( int $message_id ): ?array {
		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT id, conversation_id, role, status, content, tool_calls, error, created_at FROM {$this->messages} WHERE id = %d",
				$message_id
			),
			ARRAY_A
		);
		return $row ? $this->hydrate( $row ) : null;
	}

	public function clear( int $conversation_id ): void {
		global $wpdb;
		$wpdb->query(
			$wpdb->prepare(
				"DELETE a FROM {$this->attachments} a
				 JOIN {$this->messages} m ON m.id = a.message_id
				 WHERE m.conversation_id = %d",
				$conversation_id
			)
		);
		$wpdb->delete( $this->messages, [ 'conversation_id' => $conversation_id ] );
	}

	/**
	 * Attach each user message's images. One query for the whole conversation.
	 *
	 * @param array<int,array<string,mixed>> $messages
	 * @return array<int,array<string,mixed>>
	 */
	private function attachImages( array $messages ): array {
		global $wpdb;
		$userIds = [];
		foreach ( $messages as $m ) {
			if ( 'user' === ( $m['role'] ?? '' ) ) {
				$userIds[] = (int) $m['id'];
			}
		}
		if ( [] === $userIds ) {
			return $messages;
		}
		$placeholders = implode( ',', array_fill( 0, count( $userIds ), '%d' ) );
		$rows         = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT message_id, media_type, data FROM {$this->attachments} WHERE message_id IN ({$placeholders}) ORDER BY id ASC",
				...$userIds
			),
			ARRAY_A
		);
		$byMessage = [];
		foreach ( $rows ?: [] as $r ) {
			$byMessage[ (int) $r['message_id'] ][] = [ 'media_type' => (string) $r['media_type'], 'data' => (string) $r['data'] ];
		}
		foreach ( $messages as &$m ) {
			if ( 'user' === ( $m['role'] ?? '' ) ) {
				$m['attachments'] = $byMessage[ (int) $m['id'] ] ?? [];
			}
		}
		unset( $m );
		return $messages;
	}

	private function insertMessage( int $conversation_id, string $role, string $status, string $content ): int {
		global $wpdb;
		$now = current_time( 'mysql', true );
		$wpdb->insert(
			$this->messages,
			[
				'conversation_id' => $conversation_id,
				'role'            => $role,
				'status'          => $status,
				'content'         => $content,
				'created_at'      => $now,
				'updated_at'      => $now,
			]
		);
		return (int) $wpdb->insert_id;
	}
}
