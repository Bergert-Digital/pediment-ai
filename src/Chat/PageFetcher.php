<?php
/**
 * Fetches a web page over HTTP and reduces it to readable text.
 *
 * @package PedimentAi
 */

declare(strict_types=1);

namespace PedimentAi\Chat;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Retrieves a page with wp_remote_get and strips it to body copy. Used to recover
 * from Anthropic's hosted web_fetch failing on origins it cannot reach but this
 * host can (see TurnRunner's web_fetch fallback).
 */
final class PageFetcher implements PageFetcherInterface {
	private const TIMEOUT      = 15;
	private const MAX_REDIRECT = 5;
	/** Cap injected text so a large page cannot blow the model's context. */
	private const MAX_CHARS = 40000;

	public function fetch( string $url ): ?string {
		$url = esc_url_raw( $url );
		if ( '' === $url || ! preg_match( '#^https?://#i', $url ) ) {
			return null;
		}

		$response = wp_remote_get(
			$url,
			[
				'timeout'     => self::TIMEOUT,
				'redirection' => self::MAX_REDIRECT,
				'user-agent'  => 'Mozilla/5.0 (compatible; PedimentAI/1.0)',
				'headers'     => [ 'Accept' => 'text/html,application/xhtml+xml' ],
			]
		);

		if ( is_wp_error( $response ) ) {
			return null;
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		if ( $status < 200 || $status >= 300 ) {
			return null;
		}

		// Only attempt to read documents we can turn into text; skip PDFs, images, etc.
		$ctype = (string) wp_remote_retrieve_header( $response, 'content-type' );
		if ( '' !== $ctype && false === stripos( $ctype, 'html' ) && false === stripos( $ctype, 'text' ) ) {
			return null;
		}

		$text = self::htmlToText( (string) wp_remote_retrieve_body( $response ) );
		return '' === $text ? null : $text;
	}

	/**
	 * Reduces an HTML document to readable text, preserving block boundaries as
	 * newlines so headings and sections stay separable for the model to mirror.
	 */
	private static function htmlToText( string $html ): string {
		if ( '' === trim( $html ) ) {
			return '';
		}

		// Drop non-content regions wholesale — wp_strip_all_tags would otherwise
		// leave inline script/style bodies behind as plain text.
		$html = (string) preg_replace( '#<(script|style|noscript|template|svg)\b[^>]*>.*?</\1>#is', ' ', $html );
		$html = (string) preg_replace( '#<head\b[^>]*>.*?</head>#is', ' ', $html );

		// Turn block-level and line-break tags into newlines before stripping.
		$html = (string) preg_replace( '#</?(?:p|div|section|article|header|footer|nav|li|ul|ol|h[1-6]|br|tr|table|blockquote)\b[^>]*>#i', "\n", $html );

		$text = wp_strip_all_tags( $html );
		$text = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );

		// Collapse horizontal whitespace, then trim and de-duplicate blank lines.
		$text = (string) preg_replace( '#[ \t\x{00A0}]+#u', ' ', $text );
		$text = (string) preg_replace( '#\s*\n\s*#', "\n", $text );
		$text = (string) preg_replace( '#\n{3,}#', "\n\n", $text );
		$text = trim( $text );

		if ( strlen( $text ) > self::MAX_CHARS ) {
			$text = substr( $text, 0, self::MAX_CHARS ) . "\n…[truncated]";
		}

		return $text;
	}
}
