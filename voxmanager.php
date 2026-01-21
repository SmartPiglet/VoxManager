<?php
/**
 * Plugin Name: VoxManager
 * Plugin URI: https://voxeladdons.co.uk/voxmanager
 * Description: Manage VoxPro suite plugin status and update checks. (Personal use)
 * Version: 0.1.1
 * Author: VoxelAddons
 * Author URI: https://voxeladdons.co.uk
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: voxmanager
 * Requires at least: 5.8
 * Requires PHP: 7.4
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/includes/class-plugin.php';

Voxel\VoxManager\Plugin::instance();
