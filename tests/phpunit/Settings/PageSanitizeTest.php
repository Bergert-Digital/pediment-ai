<?php
namespace PedimentAi\Tests\Settings;

use PedimentAi\Settings\OptionsStore;
use PedimentAi\Settings\Page;

/**
 * Exercises the Settings-API save path end to end: the sanitize callback is
 * registered on the option, then update_option() is driven exactly as
 * wp-admin/options.php does on form submit. This is the path that the unit
 * tests on OptionsStore (which call setApiKey() directly) never cover.
 */
class PageSanitizeTest extends \WP_UnitTestCase {
	public function setUp(): void {
		parent::setUp();
		delete_option( 'pediment_ai_settings' );
	}

	private function constantMasksStoredKey(): bool {
		// When ANTHROPIC_API_KEY is set (e.g. via .wp-env.override.json), getApiKey()
		// short-circuits to it, so assertions on the *stored* key can't go through
		// getApiKey(). We assert on the raw encrypted option instead, which is what
		// actually proves the Settings-API save persisted the key.
		return defined( 'ANTHROPIC_API_KEY' ) && '' !== (string) ANTHROPIC_API_KEY;
	}

	public function test_saving_via_settings_api_persists_api_key(): void {
		( new Page() )->registerSettings();

		// Mirror the form POST: plaintext api_key plus the model fields.
		update_option(
			OptionsStore::OPTION,
			[
				'api_key'       => 'sk-ant-formflow',
				'model_compose' => 'claude-sonnet-4-6',
				'model_refine'  => 'claude-haiku-4-5',
			]
		);

		$raw = get_option( OptionsStore::OPTION );
		$this->assertNotEmpty(
			$raw['api_key_encrypted'] ?? '',
			'API key was dropped by the Settings-API save path (sanitize callback re-entrancy).'
		);
		if ( ! $this->constantMasksStoredKey() ) {
			$this->assertSame( 'sk-ant-formflow', ( new OptionsStore() )->getApiKey() );
		}
	}

	public function test_saving_other_fields_preserves_existing_api_key(): void {
		( new OptionsStore() )->setApiKey( 'sk-ant-existing' );
		$encrypted_before = get_option( OptionsStore::OPTION )['api_key_encrypted'] ?? '';
		$this->assertNotEmpty( $encrypted_before );

		( new Page() )->registerSettings();

		// User re-saves the page without typing a new key (api_key field blank).
		update_option(
			OptionsStore::OPTION,
			[
				'api_key'       => '',
				'model_compose' => 'claude-opus-4-7',
			]
		);

		$raw = get_option( OptionsStore::OPTION );
		$this->assertSame(
			$encrypted_before,
			$raw['api_key_encrypted'] ?? '',
			'Blank-saving the page must preserve the existing encrypted key.'
		);
		$this->assertSame( 'claude-opus-4-7', $raw['model_compose'] ?? '' );
		if ( ! $this->constantMasksStoredKey() ) {
			$this->assertSame( 'sk-ant-existing', ( new OptionsStore() )->getApiKey() );
		}
	}
}
