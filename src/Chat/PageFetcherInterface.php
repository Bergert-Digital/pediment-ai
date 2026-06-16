<?php
/**
 * Retrieves a web page's readable text from this WordPress host.
 *
 * @package PedimentAi
 */

declare(strict_types=1);

namespace PedimentAi\Chat;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Server-side page fetcher used as a fallback when Anthropic's hosted web_fetch
 * cannot reach an origin its egress is blocked from but this host can.
 */
interface PageFetcherInterface {
	/**
	 * Fetch a URL and return its readable text content.
	 *
	 * @param string $url Absolute http(s) URL.
	 * @return string|null Readable page text, or null when the fetch failed or the
	 *                     response was not a readable text/HTML document.
	 */
	public function fetch( string $url ): ?string;
}
