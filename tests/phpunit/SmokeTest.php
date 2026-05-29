<?php
namespace PedimentAi\Tests;

class SmokeTest extends \WP_UnitTestCase {
	public function test_plugin_constants_defined(): void {
		$this->assertTrue( defined( 'PEDIMENT_AI_VERSION' ) );
	}

	public function test_bootstrap_class_exists(): void {
		$this->assertTrue( class_exists( '\\PedimentAi\\Bootstrap' ) );
	}
}
