<?php
/**
 * Plugin settings page under Settings > Starter AI.
 *
 * @package StarterAi
 */

declare(strict_types=1);

namespace StarterAi\Settings;

use StarterAi\Usage\RateLimiter;
use StarterAi\Usage\Tracker;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renders the admin settings UI and persists options via the Settings API.
 */
final class Page {
	public const SLUG = 'starter-ai';

	public function register(): void {
		add_action( 'admin_menu', [ $this, 'addMenu' ] );
		add_action( 'admin_init', [ $this, 'registerSettings' ] );
	}

	public function addMenu(): void {
		add_options_page(
			__( 'Starter AI', 'starter-ai' ),
			__( 'Starter AI', 'starter-ai' ),
			'manage_options',
			self::SLUG,
			[ $this, 'render' ]
		);
	}

	public function registerSettings(): void {
		register_setting( 'starter_ai_group', OptionsStore::OPTION, [ 'type' => 'array', 'sanitize_callback' => [ $this, 'sanitize' ] ] );
		register_setting( 'starter_ai_group', 'starter_ai_rate_limits', [ 'type' => 'array', 'sanitize_callback' => [ $this, 'sanitizeLimits' ] ] );
	}

	public function sanitize( $input ): array {
		$store = new OptionsStore();
		$out   = $store->all();

		if ( isset( $input['api_key'] ) && '' !== (string) $input['api_key'] ) {
			$store->setApiKey( (string) $input['api_key'] );
			$out = $store->all();
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

	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$store   = new OptionsStore();
		$limits  = (array) get_option( 'starter_ai_rate_limits', RateLimiter::DEFAULTS );
		$usage   = ( new Tracker() )->totalsThisMonth();
		$env_key = defined( 'ANTHROPIC_API_KEY' );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Starter AI Settings', 'starter-ai' ); ?></h1>
			<form method="post" action="options.php">
				<?php settings_fields( 'starter_ai_group' ); ?>

				<h2><?php esc_html_e( 'API key', 'starter-ai' ); ?></h2>
				<?php if ( $env_key ) : ?>
					<p><?php esc_html_e( 'Loaded from ANTHROPIC_API_KEY env constant. Field below is ignored.', 'starter-ai' ); ?></p>
				<?php endif; ?>
				<input type="password" name="<?php echo esc_attr( OptionsStore::OPTION ); ?>[api_key]" value="" autocomplete="off" placeholder="<?php esc_attr_e( 'Set or update Anthropic key', 'starter-ai' ); ?>" class="regular-text" />
				<p class="description"><?php esc_html_e( 'Stored encrypted using wp_salt-derived key.', 'starter-ai' ); ?></p>

				<h2><?php esc_html_e( 'Models', 'starter-ai' ); ?></h2>
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

				<h2><?php esc_html_e( 'Mock mode', 'starter-ai' ); ?></h2>
				<label>
					<input type="checkbox" name="<?php echo esc_attr( OptionsStore::OPTION ); ?>[mock_mode]" value="1" <?php checked( (bool) $store->get( 'mock_mode', false ) ); ?> />
					<?php esc_html_e( 'Return fixture responses instead of calling Anthropic. For development.', 'starter-ai' ); ?>
				</label>

				<h2><?php esc_html_e( 'Rate limits (per user per hour)', 'starter-ai' ); ?></h2>
				<?php foreach ( [ 'compose', 'edit', 'refine' ] as $k ) : ?>
					<p><label><?php echo esc_html( ucfirst( $k ) ); ?>: <input type="number" min="1" name="starter_ai_rate_limits[<?php echo esc_attr( $k ); ?>]" value="<?php echo esc_attr( (string) ( $limits[ $k ] ?? RateLimiter::DEFAULTS[ $k ] ) ); ?>" /></label></p>
				<?php endforeach; ?>

				<?php submit_button(); ?>
			</form>

			<h2><?php esc_html_e( 'Usage this month', 'starter-ai' ); ?></h2>
			<ul>
				<li>Input tokens: <?php echo esc_html( number_format_i18n( $usage['input_tokens'] ) ); ?></li>
				<li>Output tokens: <?php echo esc_html( number_format_i18n( $usage['output_tokens'] ) ); ?></li>
				<li>Cache reads: <?php echo esc_html( number_format_i18n( $usage['cache_read_tokens'] ) ); ?></li>
				<li>Web fetches: <?php echo esc_html( number_format_i18n( $usage['web_fetch_count'] ) ); ?></li>
				<li>Estimated cost: $<?php echo esc_html( number_format_i18n( $usage['cost_usd'], 4 ) ); ?></li>
			</ul>
		</div>
		<?php
	}
}
