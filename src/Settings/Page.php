<?php
/**
 * Plugin settings page under Settings > Pediment AI.
 *
 * @package PedimentAi
 */

declare(strict_types=1);

namespace PedimentAi\Settings;

use PedimentAi\Usage\RateLimiter;
use PedimentAi\Usage\Tracker;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renders the admin settings UI and persists options via the Settings API.
 */
final class Page {
	public const SLUG = 'pediment-ai';

	public function register(): void {
		add_action( 'admin_menu', [ $this, 'addMenu' ] );
		add_action( 'admin_init', [ $this, 'registerSettings' ] );
	}

	public function addMenu(): void {
		// When the Pediment parent theme exposes its shared settings hub, mount
		// as a tab under Settings > Pediment Theme instead of adding our own
		// top-level menu entry. The hub owns the page shell (.wrap/<h1>/nav) and
		// invokes renderTabBody() for just our tab's body.
		if ( function_exists( 'pediment_settings_register_tab' ) ) {
			pediment_settings_register_tab( 'ai', __( 'AI', 'pediment-ai' ), [ $this, 'renderTabBody' ], 100 );
			return; // Don't also register a standalone menu.
		}

		// Fallback: no hub (Pediment before the settings-hub release, or a
		// non-Pediment theme). Keep the self-contained Settings > Pediment AI page.
		add_options_page(
			__( 'Pediment AI', 'pediment-ai' ),
			__( 'Pediment AI', 'pediment-ai' ),
			'manage_options',
			self::SLUG,
			[ $this, 'render' ]
		);
	}

	public function registerSettings(): void {
		register_setting( 'pediment_ai_group', OptionsStore::OPTION, [ 'type' => 'array', 'sanitize_callback' => [ $this, 'sanitize' ] ] );
		register_setting( 'pediment_ai_group', 'pediment_ai_rate_limits', [ 'type' => 'array', 'sanitize_callback' => [ $this, 'sanitizeLimits' ] ] );
	}

	public function sanitize( $input ): array {
		$store = new OptionsStore();
		$out   = $store->all();

		// This callback must be idempotent: WordPress runs the sanitize_option
		// filter TWICE when the option is first created — update_option() delegates
		// to add_option(), which sanitizes the already-sanitized value again. On
		// that second pass $input carries api_key_encrypted but no plaintext
		// api_key, so carry the encrypted value through or the key is dropped on
		// the very first save.
		if ( isset( $input['api_key_encrypted'] ) && '' !== (string) $input['api_key_encrypted'] ) {
			$out['api_key_encrypted'] = (string) $input['api_key_encrypted'];
		}

		// A freshly submitted plaintext key wins. Fold it in via withApiKey()
		// rather than setApiKey(): the latter would update_option() this same
		// option from inside its own sanitize_callback, re-entering this method.
		if ( isset( $input['api_key'] ) && '' !== (string) $input['api_key'] ) {
			$out = $store->withApiKey( $out, (string) $input['api_key'] );
		}
		if ( isset( $input['model_compose'] ) ) {
			$out['model_compose'] = sanitize_text_field( (string) $input['model_compose'] );
		}
		if ( isset( $input['model_refine'] ) ) {
			$out['model_refine'] = sanitize_text_field( (string) $input['model_refine'] );
		}
		$out['mock_mode'] = ! empty( $input['mock_mode'] );
		return $out;
	}

	public function sanitizeLimits( $input ): array {
		$limits = RateLimiter::DEFAULTS;
		foreach ( [ 'compose', 'edit', 'refine' ] as $k ) {
			if ( isset( $input[ $k ] ) && (int) $input[ $k ] > 0 ) {
				$limits[ $k ] = (int) $input[ $k ];
			}
		}
		return $limits;
	}

	/**
	 * Standalone page: our own .wrap/<h1> shell wrapping the shared body.
	 * Used only on the fallback menu path (no Pediment settings hub).
	 */
	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Pediment AI Settings', 'pediment-ai' ); ?></h1>
			<?php $this->renderTabBody(); ?>
		</div>
		<?php
	}

	/**
	 * Tab body: everything inside the page shell. Emitted verbatim by the
	 * Pediment settings hub (which supplies .wrap/<h1>/nav) and, on the
	 * standalone fallback, by render() above. Must not emit page chrome.
	 */
	public function renderTabBody(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$store   = new OptionsStore();
		$limits  = (array) get_option( 'pediment_ai_rate_limits', RateLimiter::DEFAULTS );
		$usage   = ( new Tracker() )->totalsThisMonth();
		$env_key = defined( 'ANTHROPIC_API_KEY' );
		?>
			<form method="post" action="options.php">
				<?php settings_fields( 'pediment_ai_group' ); ?>

				<h2><?php esc_html_e( 'API key', 'pediment-ai' ); ?></h2>
				<?php if ( $env_key ) : ?>
					<p><?php esc_html_e( 'Loaded from ANTHROPIC_API_KEY env constant. Field below is ignored.', 'pediment-ai' ); ?></p>
				<?php endif; ?>
				<input type="password" name="<?php echo esc_attr( OptionsStore::OPTION ); ?>[api_key]" value="" autocomplete="off" placeholder="<?php esc_attr_e( 'Set or update Anthropic key', 'pediment-ai' ); ?>" class="regular-text" />
				<p class="description"><?php esc_html_e( 'Stored encrypted using wp_salt-derived key.', 'pediment-ai' ); ?></p>

				<h2><?php esc_html_e( 'Models', 'pediment-ai' ); ?></h2>
				<p>
					<label>Compose / Edit
						<input type="text" name="<?php echo esc_attr( OptionsStore::OPTION ); ?>[model_compose]" value="<?php echo esc_attr( (string) $store->get( 'model_compose', 'claude-sonnet-4-6' ) ); ?>" class="regular-text" />
					</label>
				</p>
				<p>
					<label>Refine
						<input type="text" name="<?php echo esc_attr( OptionsStore::OPTION ); ?>[model_refine]" value="<?php echo esc_attr( (string) $store->get( 'model_refine', 'claude-haiku-4-5' ) ); ?>" class="regular-text" />
					</label>
				</p>

				<h2><?php esc_html_e( 'Mock mode', 'pediment-ai' ); ?></h2>
				<label>
					<input type="checkbox" name="<?php echo esc_attr( OptionsStore::OPTION ); ?>[mock_mode]" value="1" <?php checked( (bool) $store->get( 'mock_mode', false ) ); ?> />
					<?php esc_html_e( 'Return fixture responses instead of calling Anthropic. For development.', 'pediment-ai' ); ?>
				</label>

				<h2><?php esc_html_e( 'Rate limits (per user per hour)', 'pediment-ai' ); ?></h2>
				<?php foreach ( [ 'compose', 'edit', 'refine' ] as $k ) : ?>
					<p><label><?php echo esc_html( ucfirst( $k ) ); ?>: <input type="number" min="1" name="pediment_ai_rate_limits[<?php echo esc_attr( $k ); ?>]" value="<?php echo esc_attr( (string) ( $limits[ $k ] ?? RateLimiter::DEFAULTS[ $k ] ) ); ?>" /></label></p>
				<?php endforeach; ?>

				<?php submit_button(); ?>
			</form>

			<h2><?php esc_html_e( 'Usage this month', 'pediment-ai' ); ?></h2>
			<ul>
				<li>Input tokens: <?php echo esc_html( number_format_i18n( $usage['input_tokens'] ) ); ?></li>
				<li>Output tokens: <?php echo esc_html( number_format_i18n( $usage['output_tokens'] ) ); ?></li>
				<li>Cache reads: <?php echo esc_html( number_format_i18n( $usage['cache_read_tokens'] ) ); ?></li>
				<li>Web fetches: <?php echo esc_html( number_format_i18n( $usage['web_fetch_count'] ) ); ?></li>
				<li>Estimated cost: $<?php echo esc_html( number_format_i18n( $usage['cost_usd'], 4 ) ); ?></li>
			</ul>
		<?php
	}
}
