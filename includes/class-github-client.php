<?php
/**
 * GitHub Client for VoxManager
 *
 * Handles communication with GitHub API for repository information,
 * releases, and authentication.
 *
 * @package VoxManager
 */

namespace Voxel\VoxManager;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * GitHub API client class.
 *
 * Provides methods to fetch repository info, releases, branches,
 * and handles authentication for private repositories.
 */
final class Github_Client {

	/**
	 * Settings instance for retrieving configuration.
	 *
	 * @var Settings
	 */
	private Settings $settings;

	/**
	 * Constructor.
	 *
	 * @param Settings $settings Settings instance.
	 */
	public function __construct( Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Filter HTTP request arguments to add GitHub authentication headers.
	 *
	 * @param array  $args HTTP request arguments.
	 * @param string $url  Request URL.
	 * @return array Modified arguments.
	 */
	public function filter_http_request_args( array $args, string $url ): array {
		$token = $this->settings->get_github_token();
		if ( '' === $token ) {
			return $args;
		}

		$host = wp_parse_url( $url, PHP_URL_HOST );
		if ( ! is_string( $host ) ) {
			return $args;
		}

		$host = strtolower( $host );
		if ( 'api.github.com' !== $host && 'github.com' !== $host && 'objects.githubusercontent.com' !== $host && 'codeload.github.com' !== $host ) {
			return $args;
		}

		$headers         = isset( $args['headers'] ) && is_array( $args['headers'] ) ? $args['headers'] : array();
		$headers         = $this->apply_github_auth_headers( $headers );
		$args['headers'] = $headers;

		return $args;
	}

	/**
	 * Get current GitHub API rate limit status.
	 *
	 * @return array{remaining: int, limit: int, reset: int}|null Rate limit info or null on error.
	 */
	public function get_rate_limit(): ?array {
		$cache_key = 'voxmanager_github_rate_limit';
		$cached    = get_site_transient( $cache_key );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$response = wp_remote_get(
			'https://api.github.com/rate_limit',
			array(
				'timeout' => 10,
				'headers' => $this->apply_github_auth_headers( array() ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return null;
		}

		$status_code = wp_remote_retrieve_response_code( $response );
		if ( $status_code < 200 || $status_code >= 300 ) {
			return null;
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $data ) || ! isset( $data['resources']['core'] ) ) {
			return null;
		}

		$core = $data['resources']['core'];
		$info = array(
			'remaining' => isset( $core['remaining'] ) ? (int) $core['remaining'] : 0,
			'limit'     => isset( $core['limit'] ) ? (int) $core['limit'] : 0,
			'reset'     => isset( $core['reset'] ) ? (int) $core['reset'] : 0,
		);

		// Cache for 1 minute.
		set_site_transient( $cache_key, $info, MINUTE_IN_SECONDS );

		return $info;
	}

	/**
	 * Check if we're near the rate limit.
	 *
	 * @param int $threshold Minimum remaining requests before warning.
	 * @return bool True if rate limit is low.
	 */
	public function is_rate_limit_low( int $threshold = 10 ): bool {
		$rate_limit = $this->get_rate_limit();
		if ( ! is_array( $rate_limit ) ) {
			return false;
		}

		return $rate_limit['remaining'] <= $threshold;
	}

	public function get_repo_info( string $repo ) {
		$repo = $this->settings->sanitize_repo( $repo );
		if ( $repo === '' ) {
			return null;
		}

		$cache_key = $this->get_repo_cache_key( $repo, 'info' );
		$cached = get_site_transient( $cache_key );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$response = wp_remote_get(
			sprintf( 'https://api.github.com/repos/%s', $repo ),
			array(
				'timeout' => 15,
				'headers' => $this->apply_github_auth_headers( array() ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return null;
		}

		$status_code = wp_remote_retrieve_response_code( $response );
		if ( $status_code < 200 || $status_code >= 300 ) {
			return null;
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $data ) ) {
			return null;
		}

		$info = array(
			'default_branch' => isset( $data['default_branch'] ) ? (string) $data['default_branch'] : '',
			'private'        => isset( $data['private'] ) ? (bool) $data['private'] : null,
		);

		set_site_transient( $cache_key, $info, 10 * MINUTE_IN_SECONDS );

		return $info;
	}

	public function get_repo_branches( string $repo ): array {
		$repo = $this->settings->sanitize_repo( $repo );
		if ( $repo === '' ) {
			return array();
		}

		$cache_key = $this->get_repo_cache_key( $repo, 'branches' );
		$cached = get_site_transient( $cache_key );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$response = wp_remote_get(
			sprintf( 'https://api.github.com/repos/%s/branches?per_page=100', $repo ),
			array(
				'timeout' => 15,
				'headers' => $this->apply_github_auth_headers( array() ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return array();
		}

		$status_code = wp_remote_retrieve_response_code( $response );
		if ( $status_code < 200 || $status_code >= 300 ) {
			return array();
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $data ) ) {
			return array();
		}

		$branches = array();
		foreach ( $data as $branch ) {
			if ( is_array( $branch ) && isset( $branch['name'] ) ) {
				$branches[] = (string) $branch['name'];
			}
		}

		$branches = array_values( array_unique( array_filter( $branches ) ) );

		$info = $this->get_repo_info( $repo );
		$default_branch = is_array( $info ) ? (string) ( $info['default_branch'] ?? '' ) : '';
		if ( $default_branch !== '' && in_array( $default_branch, $branches, true ) ) {
			$branches = array_merge( array( $default_branch ), array_diff( $branches, array( $default_branch ) ) );
		}

		set_site_transient( $cache_key, $branches, 10 * MINUTE_IN_SECONDS );

		return $branches;
	}

	public function get_latest_release( string $repo ) {
		$repo = $this->settings->sanitize_repo( $repo );
		if ( $repo === '' ) {
			return null;
		}

		$settings = $this->settings->get_settings();
		$source = isset( $settings['release_source'] ) ? (string) $settings['release_source'] : 'releases';
		$source = $source === 'tags' ? 'tags' : 'releases';

		$cache_key = $this->get_release_cache_key( $repo, $source );
		$cached = get_site_transient( $cache_key );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$api_url = $source === 'tags'
			? sprintf( 'https://api.github.com/repos/%s/tags?per_page=1', $repo )
			: sprintf( 'https://api.github.com/repos/%s/releases/latest', $repo );

		$response = wp_remote_get(
			$api_url,
			array(
				'timeout' => 15,
				'headers' => $this->apply_github_auth_headers( array() ),
			)
		);

		if ( is_wp_error( $response ) ) {
			$this->set_release_error( $repo, $response->get_error_message() );
			return null;
		}

		$status_code = wp_remote_retrieve_response_code( $response );
		if ( $status_code < 200 || $status_code >= 300 ) {
			$this->set_release_error( $repo, 'HTTP ' . $status_code );
			return null;
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( $source === 'tags' ) {
			$tag = is_array( $data ) ? ( $data[0] ?? null ) : null;
			if ( ! is_array( $tag ) ) {
				$this->set_release_error( $repo, 'Missing tag data' );
				return null;
			}

			$data = array(
				'tag_name'     => $tag['name'] ?? '',
				'name'         => $tag['name'] ?? '',
				'zipball_url'  => $tag['zipball_url'] ?? '',
				'html_url'     => 'https://github.com/' . $repo . '/releases/tag/' . rawurlencode( (string) ( $tag['name'] ?? '' ) ),
				'body'         => '',
				'published_at' => '',
				'assets'       => array(),
			);
		}

		if ( ! is_array( $data ) || empty( $data['tag_name'] ) ) {
			$this->set_release_error( $repo, 'Missing release data' );
			return null;
		}

		$release = array(
			'tag_name'     => (string) ( $data['tag_name'] ?? '' ),
			'name'         => (string) ( $data['name'] ?? '' ),
			'zipball_url'  => (string) ( $data['zipball_url'] ?? '' ),
			'html_url'     => (string) ( $data['html_url'] ?? '' ),
			'body'         => (string) ( $data['body'] ?? '' ),
			'published_at' => (string) ( $data['published_at'] ?? '' ),
			'assets'       => is_array( $data['assets'] ?? null ) ? $data['assets'] : array(),
		);

		set_site_transient( $cache_key, $release, 10 * MINUTE_IN_SECONDS );
		$this->clear_release_error( $repo );

		return $release;
	}

	public function resolve_release_package( array $release, string $plugin_file, array $config ): string {
		$plugin_dir = dirname( $plugin_file );
		$slug = isset( $config['slug'] ) ? (string) $config['slug'] : $plugin_dir;
		$assets = isset( $release['assets'] ) && is_array( $release['assets'] ) ? $release['assets'] : array();
		$settings = $this->settings->get_settings();
		$package_source = isset( $settings['package_source'] ) ? (string) $settings['package_source'] : 'zipball';

		if ( $package_source === 'zipball' ) {
			return isset( $release['zipball_url'] ) ? (string) $release['zipball_url'] : '';
		}

		$asset_url = $this->resolve_release_asset_url( $assets, $plugin_dir, $slug );
		if ( $asset_url !== '' ) {
			return $asset_url;
		}

		return isset( $release['zipball_url'] ) ? (string) $release['zipball_url'] : '';
	}

	public function get_branch_package_url( string $repo, string $branch ): string {
		$repo = $this->settings->sanitize_repo( $repo );
		$branch = $this->settings->sanitize_branch( $branch );

		if ( $repo === '' || $branch === '' ) {
			return '';
		}

		return sprintf( 'https://api.github.com/repos/%s/zipball/%s', $repo, rawurlencode( $branch ) );
	}

	public function get_branch_plugin_version( string $repo, string $branch, string $plugin_file ): string {
		$repo = $this->settings->sanitize_repo( $repo );
		$branch = $this->settings->sanitize_branch( $branch );

		if ( $repo === '' || $branch === '' ) {
			return '';
		}

		$paths = $this->get_repo_file_candidates( $plugin_file );
		$last_error = '';

		foreach ( $paths as $path ) {
			$error = '';
			$contents = $this->get_repo_file_contents( $repo, $path, $branch, $error );
			if ( $contents === '' ) {
				if ( $error !== '' ) {
					$last_error = $error;
				}
				continue;
			}

			$version = $this->parse_plugin_version( $contents );
			if ( $version !== '' ) {
				$this->clear_release_error( $repo );
				return $this->normalize_version( $version );
			}
		}

		if ( $last_error === '' ) {
			$last_error = __( 'Missing plugin version on branch.', 'voxmanager' );
		}

		$this->set_release_error( $repo, $last_error );
		return '';
	}

	public function get_release_error( string $repo ): array {
		$error = get_site_transient( $this->get_release_error_key( $repo ) );
		return is_array( $error ) ? $error : array();
	}

	public function clear_release_cache(): void {
		$suite_plugins = $this->settings->get_suite_plugins();

		foreach ( $suite_plugins as $plugin_file => $config ) {
			if ( empty( $config['repo'] ) ) {
				continue;
			}

			$repo = $this->settings->sanitize_repo( $config['repo'] );
			delete_site_transient( $this->get_release_cache_key( $repo, 'releases' ) );
			delete_site_transient( $this->get_release_cache_key( $repo, 'tags' ) );
			delete_site_transient( $this->get_release_error_key( $repo ) );
			delete_site_transient( $this->get_repo_cache_key( $repo, 'info' ) );
			delete_site_transient( $this->get_repo_cache_key( $repo, 'branches' ) );

			$branch = isset( $config['branch'] ) ? $this->settings->sanitize_branch( (string) $config['branch'] ) : '';
			if ( $branch !== '' ) {
				$paths = $this->get_repo_file_candidates( $plugin_file );
				foreach ( $paths as $path ) {
					delete_site_transient( $this->get_repo_file_cache_key( $repo, $path, $branch ) );
				}
			}
		}
	}

	private function apply_github_auth_headers( array $headers ): array {
		$token = $this->settings->get_github_token();
		if ( $token === '' ) {
			return $headers;
		}

		$auth_scheme = strpos( $token, 'github_pat_' ) === 0 ? 'Bearer' : 'token';
		$headers['Authorization'] = $auth_scheme . ' ' . $token;
		$headers['Accept'] = 'application/vnd.github+json';
		$headers['User-Agent'] = 'VoxManager';

		return $headers;
	}

	private function resolve_release_asset_url( array $assets, string $plugin_dir, string $slug ): string {
		$first_zip = '';
		$needle_dirs = array_filter( array( strtolower( $plugin_dir ), strtolower( $slug ) ) );

		foreach ( $assets as $asset ) {
			if ( ! is_array( $asset ) ) {
				continue;
			}

			$name = isset( $asset['name'] ) ? (string) $asset['name'] : '';
			$url  = isset( $asset['browser_download_url'] ) ? (string) $asset['browser_download_url'] : '';

			if ( $name === '' || $url === '' ) {
				continue;
			}

			if ( strtolower( substr( $name, -4 ) ) !== '.zip' ) {
				continue;
			}

			if ( $first_zip === '' ) {
				$first_zip = $url;
			}

			$name_lower = strtolower( $name );
			foreach ( $needle_dirs as $needle ) {
				if ( $needle !== '' && strpos( $name_lower, $needle ) !== false ) {
					return $url;
				}
			}
		}

		return $first_zip;
	}

	private function get_repo_file_candidates( string $plugin_file ): array {
		$plugin_file = ltrim( $plugin_file, '/' );
		$basename = basename( $plugin_file );

		$paths = array( $basename, $plugin_file );
		$lower_plugin = strtolower( $plugin_file );
		$lower_base = strtolower( $basename );

		if ( $lower_plugin !== $plugin_file ) {
			$paths[] = $lower_plugin;
		}

		if ( $lower_base !== $basename ) {
			$paths[] = $lower_base;
		}

		return array_values( array_unique( array_filter( $paths ) ) );
	}

	private function get_repo_file_contents( string $repo, string $path, string $branch, string &$error ): string {
		$error = '';
		$repo = $this->settings->sanitize_repo( $repo );
		$branch = $this->settings->sanitize_branch( $branch );
		$path = ltrim( $path, '/' );

		if ( $repo === '' || $branch === '' || $path === '' ) {
			$error = __( 'Invalid repo file configuration.', 'voxmanager' );
			return '';
		}

		$cache_key = $this->get_repo_file_cache_key( $repo, $path, $branch );
		$cached = get_site_transient( $cache_key );
		if ( is_string( $cached ) && $cached !== '' ) {
			return $cached;
		}

		$encoded_path = $this->encode_repo_path( $path );
		$api_url = sprintf(
			'https://api.github.com/repos/%s/contents/%s?ref=%s',
			$repo,
			$encoded_path,
			rawurlencode( $branch )
		);

		$response = wp_remote_get(
			$api_url,
			array(
				'timeout' => 15,
				'headers' => $this->apply_github_auth_headers( array() ),
			)
		);

		if ( is_wp_error( $response ) ) {
			$error = $response->get_error_message();
			return '';
		}

		$status_code = wp_remote_retrieve_response_code( $response );
		if ( $status_code < 200 || $status_code >= 300 ) {
			$error = 'HTTP ' . $status_code;
			return '';
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $data ) || empty( $data['content'] ) || empty( $data['encoding'] ) ) {
			$error = __( 'Missing file content.', 'voxmanager' );
			return '';
		}

		if ( $data['encoding'] !== 'base64' ) {
			$error = __( 'Unsupported file encoding.', 'voxmanager' );
			return '';
		}

		$decoded = base64_decode( str_replace( "\n", '', (string) $data['content'] ) );
		if ( ! is_string( $decoded ) || $decoded === '' ) {
			$error = __( 'Unable to decode file contents.', 'voxmanager' );
			return '';
		}

		set_site_transient( $cache_key, $decoded, 10 * MINUTE_IN_SECONDS );
		return $decoded;
	}

	private function encode_repo_path( string $path ): string {
		$segments = explode( '/', $path );
		$encoded = array();
		foreach ( $segments as $segment ) {
			$encoded[] = rawurlencode( $segment );
		}

		return implode( '/', $encoded );
	}

	private function get_repo_file_cache_key( string $repo, string $path, string $branch ): string {
		return 'voxmanager_repo_file_' . md5( $repo . '|' . $branch . '|' . $path );
	}

	private function parse_plugin_version( string $contents ): string {
		if ( preg_match( '/^\s*Version:\s*(.+)$/mi', $contents, $matches ) ) {
			return trim( $matches[1] );
		}

		return '';
	}

	private function normalize_version( string $raw ): string {
		$raw = trim( $raw );
		if ( '' === $raw ) {
			return '';
		}

		$raw = ltrim( $raw, 'vV' );
		if ( preg_match( '/(\d+\.[\d.]+)/', $raw, $matches ) ) {
			return $matches[1];
		}

		return $raw;
	}

	private function get_release_cache_key( string $repo, string $source = '' ): string {
		if ( $source === '' ) {
			$settings = $this->settings->get_settings();
			$source = isset( $settings['release_source'] ) ? (string) $settings['release_source'] : 'releases';
		}

		return 'voxmanager_release_' . md5( $repo . '|' . $source );
	}

	private function get_repo_cache_key( string $repo, string $type ): string {
		return 'voxmanager_repo_' . $type . '_' . md5( $repo );
	}

	private function set_release_error( string $repo, string $message ): void {
		$message = trim( $message );
		if ( $message === '' ) {
			return;
		}

		$error = array(
			'message' => $message,
			'time'    => time(),
		);

		set_site_transient( $this->get_release_error_key( $repo ), $error, 10 * MINUTE_IN_SECONDS );
	}

	private function clear_release_error( string $repo ): void {
		delete_site_transient( $this->get_release_error_key( $repo ) );
	}

	private function get_release_error_key( string $repo ): string {
		return 'voxmanager_release_error_' . md5( $repo );
	}
}
