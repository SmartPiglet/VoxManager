<?php
/**
 * REST API controller for VoxManager automation.
 *
 * @package VoxManager
 */

namespace Voxel\VoxManager;

use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Rest_Controller {
	private Settings $settings;
	private Github_Client $github;
	private Installer $installer;

	public function __construct( Settings $settings, Github_Client $github, Installer $installer ) {
		$this->settings = $settings;
		$this->github = $github;
		$this->installer = $installer;
	}

	public function register_routes(): void {
		register_rest_route(
			'voxmanager/v1',
			'/cache/refresh',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'refresh_cache' ),
				'permission_callback' => array( $this, 'authorize_manage_request' ),
			)
		);

		register_rest_route(
			'voxmanager/v1',
			'/check-updates',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'check_updates' ),
				'permission_callback' => array( $this, 'authorize_manage_request' ),
			)
		);

		register_rest_route(
			'voxmanager/v1',
			'/plugins/install',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'install_plugin' ),
				'permission_callback' => array( $this, 'authorize_install_request' ),
				'args'                => array(
					'plugin' => array(
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);

		register_rest_route(
			'voxmanager/v1',
			'/plugins/update',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'update_plugin' ),
				'permission_callback' => array( $this, 'authorize_install_request' ),
				'args'                => array(
					'plugin' => array(
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);

		register_rest_route(
			'voxmanager/v1',
			'/github/validate',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'validate_github_token' ),
				'permission_callback' => array( $this, 'authorize_manage_request' ),
				'args'                => array(
					'token' => array(
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);

		register_rest_route(
			'voxmanager/v1',
			'/webhook/refresh',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'webhook_refresh' ),
					'permission_callback' => array( $this, 'authorize_manage_request' ),
				),
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'webhook_refresh' ),
					'permission_callback' => array( $this, 'authorize_manage_request' ),
					'args'                => array(
						'token' => array(
							'type'              => 'string',
							'required'          => false,
							'sanitize_callback' => 'sanitize_text_field',
						),
					),
				),
			)
		);
	}

	public function refresh_cache(): WP_REST_Response {
		delete_site_transient( 'update_plugins' );
		$this->github->clear_release_cache();

		return rest_ensure_response(
			array(
				'success' => true,
				'message' => __( 'Caches cleared.', 'voxmanager' ),
			)
		);
	}

	public function check_updates(): WP_REST_Response {
		delete_site_transient( 'update_plugins' );
		$this->github->clear_release_cache();
		wp_update_plugins();

		return rest_ensure_response(
			array(
				'success'      => true,
				'message'      => __( 'Update check completed.', 'voxmanager' ),
				'last_checked' => time(),
			)
		);
	}

	public function install_plugin( WP_REST_Request $request ) {
		$plugin_file = (string) $request->get_param( 'plugin' );
		$result = $this->installer->install_plugin( $plugin_file );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return rest_ensure_response(
			array(
				'success' => true,
				'message' => __( 'Plugin installed successfully.', 'voxmanager' ),
			)
		);
	}

	public function update_plugin( WP_REST_Request $request ) {
		$plugin_file = (string) $request->get_param( 'plugin' );
		$result = $this->installer->upgrade_plugin( $plugin_file );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return rest_ensure_response(
			array(
				'success' => true,
				'message' => __( 'Plugin updated successfully.', 'voxmanager' ),
			)
		);
	}

	public function validate_github_token( WP_REST_Request $request ) {
		$token = (string) $request->get_param( 'token' );
		$result = $this->github->validate_token( $token );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return rest_ensure_response(
			array(
				'success' => true,
				'message' => __( 'GitHub token is valid.', 'voxmanager' ),
			)
		);
	}

	public function webhook_refresh(): WP_REST_Response {
		delete_site_transient( 'update_plugins' );
		$this->github->clear_release_cache();

		return rest_ensure_response(
			array(
				'success' => true,
				'message' => __( 'Webhook processed.', 'voxmanager' ),
			)
		);
	}

	public function authorize_manage_request( WP_REST_Request $request ): bool {
		return $this->authorize_request( $request, $this->settings->get_required_capability() );
	}

	public function authorize_install_request( WP_REST_Request $request ): bool {
		return $this->authorize_request( $request, $this->settings->get_install_capability() );
	}

	private function authorize_request( WP_REST_Request $request, string $capability ): bool {
		if ( current_user_can( $capability ) ) {
			return true;
		}

		$api_key = $this->settings->get_api_key();
		if ( $api_key === '' ) {
			return false;
		}

		$provided = $this->get_api_key_from_request( $request );
		if ( $provided === '' ) {
			return false;
		}

		return hash_equals( $api_key, $provided );
	}

	private function get_api_key_from_request( WP_REST_Request $request ): string {
		$token = $request->get_header( 'x-voxmanager-token' );
		if ( is_string( $token ) && $token !== '' ) {
			return $token;
		}

		$token = $request->get_param( 'token' );
		return is_string( $token ) ? $token : '';
	}
}
