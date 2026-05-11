<?php
namespace StarterAi\Tests;

class SmokeTest extends \WP_UnitTestCase {
	public function test_plugin_constants_defined(): void {
		$this->assertTrue( defined( 'STARTER_AI_VERSION' ) );
	}

	public function test_bootstrap_class_exists(): void {
		$this->assertTrue( class_exists( '\\StarterAi\\Bootstrap' ) );
	}
}
