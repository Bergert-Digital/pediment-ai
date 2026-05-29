<?php
/**
 * Fixture-driven mock provider for tests and dev mode.
 *
 * @package PedimentAi
 */

declare(strict_types=1);

namespace PedimentAi\Mock;

use PedimentAi\Anthropic\ProviderInterface;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Returns canned Anthropic responses by reading JSON files from a fixtures directory.
 */
final class MockProvider implements ProviderInterface {
	public function __construct( private readonly string $fixturesDir ) {}

	public function messages( array $args ) {
		$text  = $this->concatenateUserText( $args );
		$tools = array_column( $args['tools'] ?? [], 'name' );

		if ( in_array( 'emit_block', $tools, true ) ) {
			$fixture = $this->resolveRefineFixture( $text );
		} else {
			$fixture = $this->resolveComposeOrEditFixture( $text );
		}

		$path = $this->fixturesDir . '/' . $fixture . '.json';
		if ( ! file_exists( $path ) ) {
			return new \WP_Error( 'pediment_ai_mock_missing', "Missing fixture: {$fixture}" );
		}
		$data = json_decode( (string) file_get_contents( $path ), true );
		if ( ! is_array( $data ) ) {
			return new \WP_Error( 'pediment_ai_mock_invalid', "Invalid fixture: {$fixture}" );
		}
		return $data;
	}

	private function concatenateUserText( array $args ): string {
		$out = '';
		foreach ( $args['messages'] ?? [] as $msg ) {
			foreach ( (array) ( $msg['content'] ?? [] ) as $part ) {
				if ( is_array( $part ) && 'text' === ( $part['type'] ?? '' ) ) {
					$out .= "\n" . (string) ( $part['text'] ?? '' );
				}
			}
		}
		return $out;
	}

	private function resolveComposeOrEditFixture( string $text ): string {
		if ( false !== stripos( $text, 'Edit instruction:' ) || false !== stripos( $text, 'existing block tree' ) ) {
			if ( preg_match( '/\b(add|insert)\b/i', $text ) && false !== stripos( $text, 'faq' ) ) {
				return 'edit-add-faq';
			}
			return 'edit-shorten';
		}

		if ( preg_match( '/Page type:\s*(\w+)/i', $text, $m ) ) {
			$slug = strtolower( $m[1] );
			foreach ( [ 'landing', 'about', 'services', 'contact' ] as $known ) {
				if ( $slug === $known ) {
					return 'compose-' . $known;
				}
			}
		}
		return 'compose-landing';
	}

	/**
	 * @param array<string,mixed> $args
	 * @return \Generator<int,array<string,mixed>>|\WP_Error
	 */
	public function stream_messages( array $args ) {
		$text    = $this->concatenateUserText( $args );
		$fixture = $this->resolveChatFixture( $text );
		$path    = $this->fixturesDir . '/chat/' . $fixture . '.json';
		if ( ! file_exists( $path ) ) {
			return new \WP_Error( 'pediment_ai_mock_missing', "Missing chat fixture: {$fixture}" );
		}
		$raw = (string) file_get_contents( $path );

		// Substitute __SELECTED_CID__ with the actual selected-block clientId so update_block
		// mock fixtures hit the real block in the user's canvas.
		if ( false !== strpos( $raw, '__SELECTED_CID__' )
			&& preg_match( '/"selected_block"\s*:\s*\{[^}]*"clientId"\s*:\s*"([^"]+)"/', $text, $m ) ) {
			$raw = str_replace( '__SELECTED_CID__', $m[1], $raw );
		}

		$events = json_decode( $raw, true );
		if ( ! is_array( $events ) ) {
			return new \WP_Error( 'pediment_ai_mock_invalid', "Invalid chat fixture: {$fixture}" );
		}
		return ( static function () use ( $events ) {
			foreach ( $events as $e ) {
				yield $e;
			}
		} )();
	}

	private function resolveChatFixture( string $text ): string {
		if ( false !== stripos( $text, 'selected_block.clientId' ) || false !== stripos( $text, 'selected paragraph' ) ) {
			return 'update-selected';
		}
		return 'insert-paragraph';
	}

	private function resolveRefineFixture( string $text ): string {
		if ( preg_match( '/pediment\/([a-z\-]+)/i', $text, $m ) ) {
			$candidate = 'refine-' . strtolower( $m[1] );
			$path      = $this->fixturesDir . '/' . $candidate . '.json';
			if ( file_exists( $path ) ) {
				return $candidate;
			}
		}
		return 'refine-hero';
	}
}
