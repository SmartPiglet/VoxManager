<?php
/**
 * Plugin Name: VoxManager
 * Plugin URI: https://voxeladdons.co.uk/voxmanager
 * Description: Manage VoxPro suite plugin status and update checks. (Personal use)
 * Version: 1.2.3
 * Author: VoxelAddons
 * Author URI: https://voxeladdons.co.uk
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: voxmanager
 * Requires at least: 5.8
 * Requires PHP: 7.4
 *
 * @package VoxManager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Plugin constants.
define( 'VOXMANAGER_VERSION', '0.2.2' );
define( 'VOXMANAGER_PLUGIN_FILE', __FILE__ );
define( 'VOXMANAGER_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );

// Load text domain.
add_action(
	'init',
	function () {
		load_plugin_textdomain( 'voxmanager', false, dirname( plugin_basename( VOXMANAGER_PLUGIN_FILE ) ) . '/languages' );
	}
);

// Activation hook - initialize defaults.
register_activation_hook(
	__FILE__,
	function () {
		// Set default options if not already set.
		if ( false === get_option( 'voxmanager_settings' ) ) {
			add_option(
				'voxmanager_settings',
				array(
					'release_source' => 'releases',
					'package_source' => 'asset',
					'plugins'        => array(),
				),
				'',
				false
			);
		}

		if ( false === get_option( 'voxmanager_secrets' ) ) {
			add_option(
				'voxmanager_secrets',
				array(
					'github_token' => '',
					'api_key'      => '',
				),
				'',
				false
			);
		}
	}
);

// Deactivation hook - cleanup transients.
register_deactivation_hook(
	__FILE__,
	function () {
		// Clear cached release/repo data for all suite plugins.
		$settings = get_option( 'voxmanager_settings', array() );
		$plugins  = isset( $settings['plugins'] ) && is_array( $settings['plugins'] ) ? $settings['plugins'] : array();

		// Default plugins to clear.
		$default_repos = array(
			'SmartPiglet/VoxManager',
			'SmartPiglet/VoxPro',
			'SmartPiglet/VoxPoints',
			'SmartPiglet/VoxPay',
			'SmartPiglet/VoxLab',
			'SmartPiglet/VoxPulse',
		);

		// Collect all repos from settings.
		foreach ( $plugins as $config ) {
			if ( isset( $config['repo'] ) && is_string( $config['repo'] ) && '' !== $config['repo'] ) {
				$default_repos[] = $config['repo'];
			}
		}

		$default_repos = array_unique( $default_repos );

		// Delete transients for each repo.
		foreach ( $default_repos as $repo ) {
			$hash = md5( $repo );
			delete_site_transient( 'voxmanager_release_' . md5( $repo . '|releases' ) );
			delete_site_transient( 'voxmanager_release_' . md5( $repo . '|tags' ) );
			delete_site_transient( 'voxmanager_release_error_' . $hash );
			delete_site_transient( 'voxmanager_repo_info_' . $hash );
			delete_site_transient( 'voxmanager_repo_branches_' . $hash );
		}
	}
);

require_once VOXMANAGER_PLUGIN_DIR . 'includes/class-plugin.php';

Voxel\VoxManager\Plugin::instance();
