<?php
/**
 * WP-CLI command that dumps the runtime block schema to a JSON file.
 *
 * @package PedimentAi
 */

declare(strict_types=1);

namespace PedimentAi\Cli;

use PedimentAi\Anthropic\SchemaBuilder;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Implements `wp pediment-ai dump-schema`.
 */
final class DumpSchemaCommand {
	/**
	 * Dumps the runtime block schema to a JSON file.
	 *
	 * ## OPTIONS
	 *
	 * [--output=<path>]
	 * : File path to write to. Defaults to plugin's schema/blocks.json.
	 *
	 * @when after_wp_load
	 *
	 * @param array<int,string>    $args       Positional args (unused).
	 * @param array<string,string> $assoc_args Associative args.
	 */
	public function __invoke( array $args, array $assoc_args ): void {
		$schema = ( new SchemaBuilder() )->build( true );
		$path   = isset( $assoc_args['output'] )
			? (string) $assoc_args['output']
			: PEDIMENT_AI_PLUGIN_DIR . '/schema/blocks.json';

		if ( ! is_dir( dirname( $path ) ) ) {
			mkdir( dirname( $path ), 0777, true );
		}
		file_put_contents( $path, wp_json_encode( $schema, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );

		if ( class_exists( '\\WP_CLI' ) ) {
			\WP_CLI::success( "Schema written to {$path} (" . count( $schema['blocks'] ) . ' blocks)' );
		}
	}
}
