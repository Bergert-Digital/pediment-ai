<?php
/**
 * GitHub-release auto-updates for the Pediment AI plugin.
 *
 * Points Plugin Update Checker at the public GitHub repo's releases so updates
 * arrive through wp-admin's normal one-click flow (Plugins screen / Dashboard →
 * Updates) instead of manual zip uploads.
 *
 * @package PedimentAi
 */

declare(strict_types=1);

namespace PedimentAi;

use YahnisElsts\PluginUpdateChecker\v5\PucFactory;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Updater {
	/** Public repo whose GitHub Releases drive plugin updates. */
	private const REPO_URL = 'https://github.com/Bergert-Digital/Pediment-AI/';

	/**
	 * Wire the update checker to this repo's GitHub releases.
	 *
	 * @param string $plugin_file Absolute path to the main plugin file.
	 */
	public static function register( string $plugin_file ): void {
		if ( ! class_exists( PucFactory::class ) ) {
			return;
		}

		$checker = PucFactory::buildUpdateChecker( self::REPO_URL, $plugin_file, 'pediment-ai' );

		// Fallback branch for reading the version header if a release is ever absent.
		if ( method_exists( $checker, 'setBranch' ) ) {
			$checker->setBranch( 'main' );
		}

		// Install the built release asset (pediment-ai.zip) rather than GitHub's
		// auto-generated "Source code" zip, which has the wrong folder name and
		// ships no vendor/ autoloader.
		$api = $checker->getVcsApi();
		if ( method_exists( $api, 'enableReleaseAssets' ) ) {
			$api->enableReleaseAssets( '/pediment-ai\.zip$/' );
		}
	}
}
