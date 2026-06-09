<?php
/**
 * Encrypted settings store for the Pediment AI plugin.
 *
 * @package PedimentAi
 */

declare(strict_types=1);

namespace PedimentAi\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Wraps the `pediment_ai_settings` option; encrypts the API key at rest.
 */
final class OptionsStore {
	public const OPTION = 'pediment_ai_settings';

	public function getApiKey(): string {
		if ( defined( 'ANTHROPIC_API_KEY' ) && '' !== (string) ANTHROPIC_API_KEY ) {
			return (string) ANTHROPIC_API_KEY;
		}
		$opts = $this->all();
		$enc  = (string) ( $opts['api_key_encrypted'] ?? '' );
		return '' === $enc ? '' : $this->decrypt( $enc );
	}

	public function setApiKey( string $plain ): void {
		update_option( self::OPTION, $this->withApiKey( $this->all(), $plain ) );
	}

	/**
	 * Return a copy of $opts with the API key encrypted into it (or cleared when
	 * $plain is empty). Pure: it does NOT persist. Use this — not setApiKey() —
	 * inside a register_setting sanitize_callback, where a nested update_option()
	 * on this same option would re-enter the callback and drop the new key before
	 * it is ever committed.
	 *
	 * @param array<string,mixed> $opts
	 * @return array<string,mixed>
	 */
	public function withApiKey( array $opts, string $plain ): array {
		$opts['api_key_encrypted'] = '' === $plain ? '' : $this->encrypt( $plain );
		return $opts;
	}

	public function get( string $key, $default = null ) {
		$all = $this->all();
		return $all[ $key ] ?? $default;
	}

	public function set( string $key, $value ): void {
		$opts          = $this->all();
		$opts[ $key ]  = $value;
		update_option( self::OPTION, $opts );
	}

	/**
	 * @return array<string,mixed>
	 */
	public function all(): array {
		$raw = get_option( self::OPTION, [] );
		return is_array( $raw ) ? $raw : [];
	}

	private function encrypt( string $plain ): string {
		if ( ! function_exists( 'sodium_crypto_secretbox' ) ) {
			return base64_encode( $plain );
		}
		$key   = $this->cipherKey();
		$nonce = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$ct    = sodium_crypto_secretbox( $plain, $nonce, $key );
		return base64_encode( $nonce . $ct );
	}

	private function decrypt( string $blob ): string {
		$raw = base64_decode( $blob, true );
		if ( false === $raw ) {
			return '';
		}
		if ( ! function_exists( 'sodium_crypto_secretbox_open' ) ) {
			return $raw;
		}
		$key   = $this->cipherKey();
		$nonce = substr( $raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$ct    = substr( $raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$plain = sodium_crypto_secretbox_open( $ct, $nonce, $key );
		return false === $plain ? '' : (string) $plain;
	}

	private function cipherKey(): string {
		return substr( hash( 'sha256', wp_salt( 'auth' ) . '|pediment-ai', true ), 0, SODIUM_CRYPTO_SECRETBOX_KEYBYTES );
	}
}
