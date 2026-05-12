<?php
/**
 * Admin notice when the host lacks fastcgi_finish_request (degrades streaming UX).
 *
 * @package StarterAi
 */

declare(strict_types=1);

namespace StarterAi\Activation;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class StreamingCheck {
	/** @var callable():bool */
	private $detector;

	public function __construct( ?callable $detector = null ) {
		$this->detector = $detector ?? static fn(): bool => function_exists( 'fastcgi_finish_request' );
	}

	public function renderNotice(): void {
		if ( ( $this->detector )() ) {
			return;
		}
		printf(
			'<div class="notice notice-warning"><p>%s</p></div>',
			esc_html__(
				'Starter AI: PHP-FPM\'s fastcgi_finish_request() is not available on this host. The chat sidebar will still work but the first response of each turn will not feel streamed. Ask your hosting provider about enabling FastCGI or upgrading to PHP-FPM.',
				'starter-ai'
			)
		);
	}
}
