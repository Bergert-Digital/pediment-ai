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
	 * @return array{id:int, post_id:int, user_id:int, messages:array<int,array<string,mixed>>}
	 */
	public function getOrCreate( int $post_id, int $user_id ): array {
		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT id FROM {$this->conversations} WHERE post_id = %d AND user_id = %d LIMIT 1",
				$post_id,
				$user_id
			),
			ARRAY_A
		);
		if ( $row ) {
			return $this->load( (int) $row['id'] );
		}
		$now = current_time( 'mysql', true );
		$wpdb->insert(
			$this->conversations,
			[ 'post_id' => $post_id, 'user_id' => $user_id, 'created_at' => $now, 'updated_at' => $now ]
		);
		return $this->load( (int) $wpdb->insert_id );
	}

	/**
	 * @return array{id:int, post_id:int, user_id:int, messages:array<int,array<string,mixed>>}|null
	 */
	public function findById( int $id ): ?array {
		$row = $this->loadHeader( $id );
		return $row ? $this->load( $id ) : null;
	}

	/**
	 * @return array{id:int, post_id:int, user_id:int, messages:array<int,array<string,mixed>>}
	 */
	private function load( int $id ): array {
		$header = $this->loadHeader( $id );
		if ( ! $header ) {
			return [ 'id' => 0, 'post_id' => 0, 'user_id' => 0, 'messages' => [] ];
		}
		global $wpdb;
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$this->messages} WHERE conversation_id = %d ORDER BY id ASC LIMIT 200",
				$id
			),
			ARRAY_A
		);
		$messages = array_map( [ $this, 'hydrate' ], $rows ?: [] );
		return [
			'id'       => (int) $header['id'],
			'post_id'  => (int) $header['post_id'],
			'user_id'  => (int) $header['user_id'],
			'messages' => $messages,
		];
	}

	/**
	 * @return array<string,string>|null
	 */
	private function loadHeader( int $id ): ?array {
		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$this->conversations} WHERE id = %d", $id ),
			ARRAY_A
		);
		return $row ?: null;
	}

	/**
	 * @param array<string,mixed> $row
	 * @return array<string,mixed>
	 */
	private function hydrate( array $row ): array {
		return [
			'id'         => (int) $row['id'],
			'role'       => (string) $row['role'],
			'status'     => (string) $row['status'],
			'content'    => (string) $row['content'],
			'tool_calls' => $row['tool_calls'] ? ( json_decode( (string) $row['tool_calls'], true ) ?: [] ) : [],
			'error'      => $row['error']      ? ( json_decode( (string) $row['error'],      true ) ?: null ) : null,
			'created_at' => (string) $row['created_at'],
		];
	}
}
