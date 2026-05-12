<?php
namespace StarterAi\Tests\Activation;

use StarterAi\Activation\StreamingCheck;

class StreamingCheckTest extends \WP_UnitTestCase {
	public function test_renders_notice_when_function_missing(): void {
		$check = new StreamingCheck( fn() => false );
		ob_start();
		$check->renderNotice();
		$html = ob_get_clean();
		$this->assertStringContainsString( 'fastcgi_finish_request', $html );
		$this->assertStringContainsString( 'notice-warning', $html );
	}

	public function test_renders_nothing_when_function_present(): void {
		$check = new StreamingCheck( fn() => true );
		ob_start();
		$check->renderNotice();
		$this->assertSame( '', ob_get_clean() );
	}
}
