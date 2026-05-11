<?php
namespace StarterAi\Tests\Settings;

use StarterAi\Settings\OptionsStore;

class OptionsStoreTest extends \WP_UnitTestCase {
	public function setUp(): void {
		parent::setUp();
		delete_option( 'starter_ai_settings' );
	}

	public function test_set_and_get_api_key_round_trips(): void {
		$store = new OptionsStore();
		$store->setApiKey( 'sk-ant-test123' );
		$this->assertSame( 'sk-ant-test123', $store->getApiKey() );
	}

	public function test_stored_key_is_not_plaintext_in_option(): void {
		$store = new OptionsStore();
		$store->setApiKey( 'sk-ant-test123' );
		$raw = get_option( 'starter_ai_settings' );
		$this->assertIsString( $raw['api_key_encrypted'] ?? null );
		$this->assertNotSame( 'sk-ant-test123', $raw['api_key_encrypted'] );
	}

	public function test_get_api_key_returns_empty_when_unset(): void {
		$this->assertSame( '', ( new OptionsStore() )->getApiKey() );
	}

	public function test_models_and_mock_toggle_persist(): void {
		$store = new OptionsStore();
		$store->set( 'model_compose', 'claude-opus-4-7' );
		$store->set( 'mock_mode',     true );
		$this->assertSame( 'claude-opus-4-7', $store->get( 'model_compose' ) );
		$this->assertTrue( $store->get( 'mock_mode' ) );
	}
}
