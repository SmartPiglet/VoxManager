<?php

namespace Voxel\VoxManager;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/class-settings.php';
require_once __DIR__ . '/class-encryptor.php';
require_once __DIR__ . '/class-github-client.php';
require_once __DIR__ . '/class-updater.php';
require_once __DIR__ . '/class-installer.php';
require_once __DIR__ . '/class-admin-page.php';
require_once __DIR__ . '/class-rest-controller.php';

final class Plugin {
	private static ?self $instance = null;
	private Settings $settings;
	private Github_Client $github;
	private Updater $updater;
	private Installer $installer;
	private Admin_Page $admin;
	private Rest_Controller $rest;

	private function __construct() {
		$this->settings = new Settings();
		$this->github = new Github_Client( $this->settings );
		$this->updater = new Updater( $this->settings, $this->github );
		$this->installer = new Installer( $this->settings, $this->github );
		$this->admin = new Admin_Page( $this->settings, $this->github, $this->updater, $this->installer );
		$this->rest = new Rest_Controller( $this->settings, $this->github, $this->installer );

		$this->register_hooks();
	}

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	private function register_hooks(): void {
		add_action( 'admin_init', array( $this->settings, 'register_settings' ) );
		add_action( 'rest_api_init', array( $this->settings, 'register_settings' ) );
		add_action( 'admin_menu', array( $this->admin, 'register_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this->admin, 'enqueue_admin_assets' ) );
		add_filter( 'admin_body_class', array( $this->admin, 'filter_admin_body_class' ) );
		add_action( 'admin_post_voxmanager_check_updates', array( $this->admin, 'handle_check_updates' ) );
		add_action( 'admin_post_voxmanager_save_settings', array( $this->admin, 'handle_save_settings' ) );
		add_action( 'admin_post_voxmanager_sync_github', array( $this->admin, 'handle_sync_github' ) );
		add_action( 'admin_post_voxmanager_self_license', array( $this->admin, 'handle_self_license' ) );
		add_action( 'admin_post_voxmanager_install_plugin', array( $this->installer, 'handle_install_plugin' ) );

		add_filter( 'pre_set_site_transient_update_plugins', array( $this->updater, 'filter_update_transient' ), 100 );
		add_filter( 'plugins_api', array( $this->updater, 'filter_plugins_api' ), 10, 3 );
		add_filter( 'http_request_args', array( $this->github, 'filter_http_request_args' ), 10, 2 );
		add_filter( 'upgrader_source_selection', array( $this->installer, 'filter_upgrader_source_selection' ), 10, 4 );
		add_action( 'rest_api_init', array( $this->rest, 'register_routes' ) );
		add_filter( 'voxpro_commons_config', array( $this, 'filter_voxpro_commons_config' ) );
	}

	public function filter_voxpro_commons_config( array $config ): array {
		$config['voxmanager'] = array(
			'devMode' => $this->settings->is_dev_mode_enabled(),
		);

		return $config;
	}
}
