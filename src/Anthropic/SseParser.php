<?php
/**
 * Incremental Server-Sent-Events parser.
 *
 * Fed arbitrary byte chunks (as they arrive from cURL); emits each complete
 * `data:` event as soon as its terminating blank line has been seen, so
 * callers can dispatch events in real time instead of buffering the whole
 * response.
 *
 * @package PedimentAi
 */

declare(strict_types=1);

namespace PedimentAi\Anthropic;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class SseParser {
	private string $buffer = '';

	/**
	 * Append a raw chunk; return any events completed by it.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function push( string $chunk ): array {
		$this->buffer .= $chunk;
		$events        = [];

		while ( preg_match( '/\r?\n\r?\n/', $this->buffer, $m, PREG_OFFSET_CAPTURE ) ) {
			$pos          = (int) $m[0][1];
			$block        = substr( $this->buffer, 0, $pos );
			$this->buffer = substr( $this->buffer, $pos + strlen( $m[0][0] ) );
			foreach ( $this->parseBlock( $block ) as $e ) {
				$events[] = $e;
			}
		}

		return $events;
	}

	/**
	 * Parse any trailing complete block not terminated by a blank line.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function flush(): array {
		$tail         = $this->buffer;
		$this->buffer = '';
		return '' === trim( $tail ) ? [] : $this->parseBlock( $tail );
	}

	/**
	 * Decode the `data:` lines of one SSE block into event arrays.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function parseBlock( string $block ): array {
		$out = [];
		foreach ( preg_split( "/\r?\n/", trim( $block ) ) as $line ) {
			if ( ! str_starts_with( $line, 'data: ' ) ) {
				continue;
			}
			$decoded = json_decode( substr( $line, 6 ), true );
			if ( is_array( $decoded ) ) {
				$out[] = $decoded;
			}
		}
		return $out;
	}
}
