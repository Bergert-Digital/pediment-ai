<?php
/**
 * CRUD for the chat_conversations and chat_messages tables.
 *
 * @package StarterAi
 */

declare(strict_types=1);

namespace StarterAi\Chat;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ConversationStore {
	private string $conversations;
	private string $messages;

	public function __construct() {
		global $wpdb;
		$this->conversations = $wpdb->prefix . 'starter_ai_chat_conversations';
		$this->messages      = $wpdb->prefix . 'starter_ai_chat_messages';
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
		return [
			'id'       => (int) $header['id'],
			'post_id'  => (int) $header['post_id'],
			'user_id'  => (int) $header['user_id'],
			'messages' => array_map( [ $this, 'hydrate' ], $rows ?: [] ),
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

	public function appendUserMessage( int $conversation_id, string $content ): int {
		return $this->insertMessage( $conversation_id, 'user', 'complete', $content );
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
		$wpdb->delete( $this->messages, [ 'conversation_id' => $conversation_id ] );
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
