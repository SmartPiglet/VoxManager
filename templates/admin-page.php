<?php
/**
 * VoxManager Admin Page Template
 *
 * @package VoxManager
 */

namespace Voxel\VoxManager;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$notices = isset( $initial_notices ) && is_array( $initial_notices ) ? $initial_notices : array();

if ( $checked_notice ) {
	$notices[] = __( 'Update check completed.', 'voxmanager' );
}
if ( $saved_notice ) {
	$notices[] = __( 'Settings saved.', 'voxmanager' );
}
if ( $synced_notice ) {
	$notices[] = __( 'GitHub info refreshed.', 'voxmanager' );
}
if ( $installed_notice ) {
	$notices[] = __( 'Plugin installed successfully.', 'voxmanager' );
}
if ( $install_error ) {
	$notices[] = '' !== $install_error_message
		? $install_error_message
		: __( 'Plugin installation failed.', 'voxmanager' );
}

$rate_limit = $this->github->get_rate_limit();
$rate_limit_ok = ! $this->github->is_rate_limit_low( 20 );
if ( is_array( $rate_limit ) && ! $rate_limit_ok ) {
	$notices[] = sprintf(
		/* translators: 1: remaining requests, 2: total limit */
		__( 'GitHub API rate limit low: %1$d/%2$d requests remaining.', 'voxmanager' ),
		(int) $rate_limit['remaining'],
		(int) $rate_limit['limit']
	);
}

$tabs = array(
	'overview' => esc_html__( 'Overview', 'voxmanager' ),
	'settings' => esc_html__( 'Settings', 'voxmanager' ),
	'setup'    => esc_html__( 'Setup', 'voxmanager' ),
);
$active_tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'overview';
if ( ! isset( $tabs[ $active_tab ] ) ) {
	$active_tab = 'overview';
}
?>
<div class="sticky-top">
	<div class="vx-head x-container">
		<h2><?php echo esc_html__( 'VoxManager', 'voxmanager' ); ?></h2>
		<?php if ( $active_tab === 'overview' ) : ?>
			<div class="vxh-actions">
				<form method="post" action="<?php echo esc_url( self_admin_url( 'admin-post.php' ) ); ?>">
					<?php wp_nonce_field( 'voxmanager_check_updates' ); ?>
					<input type="hidden" name="action" value="voxmanager_check_updates" />
					<button type="submit" class="ts-button btn-shadow ts-save-settings">
						<?php echo esc_html__( 'Check for updates', 'voxmanager' ); ?>
					</button>
				</form>
			</div>
		<?php elseif ( $active_tab === 'settings' ) : ?>
			<div class="vxh-actions">
				<button type="submit" form="voxmanager-settings-form" class="ts-button btn-shadow ts-save-settings">
					<?php echo esc_html__( 'Save settings', 'voxmanager' ); ?>
				</button>
			</div>
		<?php endif; ?>
	</div>
</div>
<div class="ts-spacer"></div>

<?php if ( ! empty( $notices ) ) : ?>
	<div class="x-container">
		<?php foreach ( $notices as $notice ) : ?>
			<div class="vx-info-box wide">
				<p><?php echo esc_html( $notice ); ?></p>
			</div>
			<div class="ts-spacer"></div>
		<?php endforeach; ?>
		<?php if ( $generated_api_key !== '' ) : ?>
			<div class="vx-info-box wide">
				<p><?php echo esc_html__( 'Your generated API key:', 'voxmanager' ); ?></p>
				<pre class="ts-snippet" style="word-break: break-all;"><span class="ts-italic"><?php echo esc_html( $generated_api_key ); ?></span></pre>
			</div>
			<div class="ts-spacer"></div>
		<?php endif; ?>
	</div>
<?php endif; ?>

<div id="vx-voxmanager-tabs" class="x-container" data-active-tab="<?php echo esc_attr( $active_tab ); ?>">
	<div class="x-row">
		<div class="x-col-12">
			<ul class="inner-tabs inner-tabs">
				<?php foreach ( $tabs as $tab_key => $tab_label ) : ?>
					<li data-vx-tab="<?php echo esc_attr( $tab_key ); ?>" <?php echo $active_tab === $tab_key ? 'class="current-item"' : ''; ?>>
						<a href="#" data-vx-tab-link="<?php echo esc_attr( $tab_key ); ?>"><?php echo esc_html( $tab_label ); ?></a>
					</li>
				<?php endforeach; ?>
			</ul>
		</div>

		<div class="x-col-12" data-vx-tab-panel="overview" aria-hidden="<?php echo $active_tab === 'overview' ? 'false' : 'true'; ?>"<?php echo $active_tab === 'overview' ? '' : ' style="display:none;"'; ?>>
			<div class="ts-group">
				<div class="ts-group-head">
					<h3><?php echo esc_html__( 'Update controls', 'voxmanager' ); ?></h3>
				</div>
				<div class="x-row">
			<div class="x-col-12">
				<?php if ( $last_checked ) : ?>
					<p class="mt0">
						<?php
						printf(
							/* translators: %s: formatted date/time of last update check */
							esc_html__( 'Last checked: %s', 'voxmanager' ),
							esc_html( $last_checked )
						);
						?>
					</p>
				<?php else : ?>
					<p class="mt0"><?php echo esc_html__( 'Run "Check for updates" to refresh the update data.', 'voxmanager' ); ?></p>
				<?php endif; ?>
			</div>
		</div>
	</div>

			<div class="ts-spacer"></div>

			<div class="ts-group">
				<div class="ts-group-head">
					<h3><?php echo esc_html__( 'Suite status', 'voxmanager' ); ?></h3>
				</div>
				<div class="vx-panels">
					<?php foreach ( $suite_plugins as $plugin_file => $config ) : ?>
						<?php
						$resolved_plugin_file = $this->settings->resolve_installed_plugin_file( $plugin_file, $installed_map );
						$plugin_data = $installed[ $resolved_plugin_file ] ?? null;
						$is_installed = is_array( $plugin_data );
						$is_active = $is_installed && is_plugin_active( $resolved_plugin_file );
						$is_network_active = $is_installed && function_exists( 'is_plugin_active_for_network' ) && is_plugin_active_for_network( $resolved_plugin_file );

						$status = esc_html__( 'Missing', 'voxmanager' );
						if ( $is_installed ) {
							if ( $is_network_active ) {
								$status = esc_html__( 'Network Active', 'voxmanager' );
							} elseif ( $is_active ) {
								$status = esc_html__( 'Active', 'voxmanager' );
							} else {
								$status = esc_html__( 'Installed', 'voxmanager' );
							}
						}

						$label = $config['label'];
						if ( '' === $label && $is_installed ) {
							$label = $plugin_data['Name'] ?? '';
						}
						if ( '' === $label ) {
							$label = $plugin_file;
						}

						$version = $is_installed ? (string) ( $plugin_data['Version'] ?? '' ) : '';
						$update_data = $this->updater->get_update_data( $updates, $resolved_plugin_file );
						$error = $config['repo'] ? $this->github->get_release_error( $config['repo'] ) : array();
						$error_message = isset( $error['message'] ) ? (string) $error['message'] : '';
						$error_time = isset( $error['time'] ) ? (int) $error['time'] : 0;
						$update_label = '';
						$update_action = '';
						$install_action = '';

						if ( ! $is_installed ) {
							$update_label = esc_html__( 'Not installed', 'voxmanager' );
							if ( current_user_can( $this->settings->get_install_capability() ) ) {
								$install_url = wp_nonce_url(
									self_admin_url( 'admin-post.php?action=voxmanager_install_plugin&plugin=' . rawurlencode( $plugin_file ) ),
									'voxmanager_install_plugin_' . $plugin_file
								);
								$install_action = '<a class="ts-button ts-outline" href="' . esc_url( $install_url ) . '">' . esc_html__( 'Install', 'voxmanager' ) . '</a>';
							}
						} elseif ( $error_message !== '' ) {
							$update_label = esc_html( sprintf( __( 'Update check failed: %s', 'voxmanager' ), $error_message ) );
						} elseif ( $update_data['available'] ) {
							$update_label = esc_html( sprintf( __( 'Update available: %s', 'voxmanager' ), $update_data['version'] ) );
							$update_url = wp_nonce_url(
								self_admin_url( 'update.php?action=upgrade-plugin&plugin=' . $resolved_plugin_file ),
								'upgrade-plugin_' . $resolved_plugin_file
							);
							$update_action = '<a class="ts-button ts-save-settings btn-shadow" href="' . esc_url( $update_url ) . '">' . esc_html__( 'Update now', 'voxmanager' ) . '</a>';
						} elseif ( $last_checked ) {
							$update_label = esc_html__( 'Up to date', 'voxmanager' );
						} else {
							$update_label = esc_html__( 'Not checked yet', 'voxmanager' );
						}

						$repo_link = '-';
						if ( $config['repo'] ) {
							$repo_url = 'https://github.com/' . ltrim( $config['repo'], '/' );
							$repo_link = '<a href="' . esc_url( $repo_url ) . '" target="_blank" rel="noopener">' . esc_html( $config['repo'] ) . '</a>';
						}
						?>
						<div class="vx-panel">
							<div class="panel-info">
								<h3><?php echo esc_html( $label ); ?></h3>
								<ul>
									<li><?php echo esc_html( $plugin_file ); ?></li>
									<li><?php echo esc_html( sprintf( __( 'Version: %s', 'voxmanager' ), $version !== '' ? $version : '-' ) ); ?></li>
									<li><?php echo esc_html( sprintf( __( 'Status: %s', 'voxmanager' ), $status ) ); ?></li>
									<li><?php echo esc_html( sprintf( __( 'Update: %s', 'voxmanager' ), $update_label ) ); ?></li>
									<?php if ( $error_message !== '' ) : ?>
										<li>
											<?php
											$error_timestamp = $error_time > 0 ? date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $error_time ) : '';
											echo esc_html(
												sprintf(
													/* translators: 1: error message, 2: timestamp */
													__( 'Last error: %1$s %2$s', 'voxmanager' ),
													$error_message,
													$error_timestamp !== '' ? '(' . $error_timestamp . ')' : ''
												)
											);
											?>
										</li>
									<?php endif; ?>
									<li><?php echo wp_kses_post( sprintf( __( 'Repo: %s', 'voxmanager' ), $repo_link ) ); ?></li>
								</ul>
							</div>
							<?php if ( $install_action ) : ?>
								<?php echo wp_kses_post( $install_action ); ?>
							<?php elseif ( $update_action ) : ?>
								<?php echo wp_kses_post( $update_action ); ?>
							<?php endif; ?>
						</div>
					<?php endforeach; ?>
				</div>
			</div>
		</div>

		<div class="x-col-12" data-vx-tab-panel="settings" aria-hidden="<?php echo $active_tab === 'settings' ? 'false' : 'true'; ?>"<?php echo $active_tab === 'settings' ? '' : ' style="display:none;"'; ?>>
			<div class="ts-group">
				<div class="ts-group-head">
					<h3><?php echo esc_html__( 'GitHub settings', 'voxmanager' ); ?></h3>
				</div>
				<div class="x-row">
					<div class="x-col-12">
						<form method="post" action="<?php echo esc_url( self_admin_url( 'admin-post.php' ) ); ?>">
							<?php wp_nonce_field( 'voxmanager_sync_github' ); ?>
							<input type="hidden" name="action" value="voxmanager_sync_github" />
							<button type="submit" class="ts-button ts-outline">
								<?php echo esc_html__( 'Refresh GitHub info', 'voxmanager' ); ?>
							</button>
						</form>
					</div>
				</div>

				<form id="voxmanager-settings-form" method="post" action="<?php echo esc_url( self_admin_url( 'admin-post.php' ) ); ?>">
					<?php wp_nonce_field( 'voxmanager_save_settings' ); ?>
					<input type="hidden" name="action" value="voxmanager_save_settings" />

					<div class="x-row">
						<div class="ts-form-group x-col-12">
							<label for="voxmanager_github_token"><?php echo esc_html__( 'GitHub token', 'voxmanager' ); ?></label>
							<?php
							$token_placeholder = $has_github_token ? '********' : '';
							?>
							<input type="password" id="voxmanager_github_token" name="voxmanager_github_token" value="" placeholder="<?php echo esc_attr( $token_placeholder ); ?>" />
							<p><?php echo esc_html__( 'Used to access private GitHub repos. Leave blank to keep the existing token.', 'voxmanager' ); ?></p>
							<?php if ( $token_defined ) : ?>
								<p><?php echo esc_html__( 'VOXMANAGER_GITHUB_TOKEN is set in wp-config.php. Saved tokens will be used when the field is not empty.', 'voxmanager' ); ?></p>
							<?php endif; ?>
						</div>
						<div class="ts-form-group x-col-12">
							<label for="voxmanager_api_key"><?php echo esc_html__( 'API key', 'voxmanager' ); ?></label>
							<?php
							$api_key_placeholder = $has_api_key ? '********' : '';
							?>
							<input type="password" id="voxmanager_api_key" name="voxmanager_api_key" value="" placeholder="<?php echo esc_attr( $api_key_placeholder ); ?>" />
							<p><?php echo esc_html__( 'Optional token for REST automation and webhooks. Leave blank to keep the existing key.', 'voxmanager' ); ?></p>
							<div class="basic-ul">
								<li>
									<button type="submit" name="voxmanager_generate_api_key" value="1" class="ts-button ts-outline">
										<?php echo esc_html__( 'Generate API key', 'voxmanager' ); ?>
									</button>
								</li>
							</div>
							<?php if ( $api_key_defined ) : ?>
								<p><?php echo esc_html__( 'VOXMANAGER_API_KEY is set in wp-config.php. Saved keys will be used when the field is not empty.', 'voxmanager' ); ?></p>
							<?php endif; ?>
						</div>
						<div class="ts-form-group x-col-6">
							<label for="voxmanager_release_source"><?php echo esc_html__( 'Release source', 'voxmanager' ); ?></label>
							<?php $release_source = isset( $settings['release_source'] ) ? (string) $settings['release_source'] : 'releases'; ?>
							<select id="voxmanager_release_source" name="voxmanager_release_source">
								<option value="releases" <?php echo selected( $release_source, 'releases', false ); ?>><?php echo esc_html__( 'Latest release', 'voxmanager' ); ?></option>
								<option value="tags" <?php echo selected( $release_source, 'tags', false ); ?>><?php echo esc_html__( 'Latest tag', 'voxmanager' ); ?></option>
							</select>
						</div>
						<div class="ts-form-group x-col-6">
							<label for="voxmanager_package_source"><?php echo esc_html__( 'Package source', 'voxmanager' ); ?></label>
							<?php $package_source = isset( $settings['package_source'] ) ? (string) $settings['package_source'] : 'asset'; ?>
							<select id="voxmanager_package_source" name="voxmanager_package_source">
								<option value="zipball" <?php echo selected( $package_source, 'zipball', false ); ?>><?php echo esc_html__( 'Repo zipball', 'voxmanager' ); ?></option>
								<option value="asset" <?php echo selected( $package_source, 'asset', false ); ?>><?php echo esc_html__( 'Release asset', 'voxmanager' ); ?></option>
							</select>
							<p><?php echo esc_html__( 'Repo zipball pulls directly from the repository. Release assets use uploaded zip files.', 'voxmanager' ); ?></p>
						</div>
						<div class="ts-form-group x-col-12">
							<?php $dev_mode = ! empty( $settings['dev_mode'] ); ?>
							<label for="voxmanager_dev_mode"><?php echo esc_html__( 'Development bypass', 'voxmanager' ); ?></label>
							<div class="basic-checkbox">
								<input type="checkbox" id="voxmanager_dev_mode" name="voxmanager_dev_mode" value="1" <?php checked( $dev_mode ); ?> />
								<span><?php echo esc_html__( 'Allow VoxPro dashboards to render satellite cards without an active license.', 'voxmanager' ); ?></span>
							</div>
						</div>
					</div>

					<div class="ts-spacer"></div>
					<h3><?php echo esc_html__( 'Suite plugins', 'voxmanager' ); ?></h3>
					<div class="x-row">
						<?php foreach ( $suite_plugins as $plugin_file => $config ) : ?>
							<?php
							$label = $config['label'] !== '' ? $config['label'] : $plugin_file;
							$repo_info = $config['repo'] ? $this->github->get_repo_info( $config['repo'] ) : null;
							$default_branch = is_array( $repo_info ) ? (string) ( $repo_info['default_branch'] ?? '' ) : '';
							$branches = $config['repo'] ? $this->github->get_repo_branches( $config['repo'] ) : array();
							$selected_branch = $config['branch'] !== '' ? $config['branch'] : $default_branch;
							?>
							<div class="ts-form-group x-col-12">
								<label><?php echo esc_html( $label ); ?> <span class="ts-italic ts-gray">(<?php echo esc_html( $plugin_file ); ?>)</span></label>
								<div class="x-row">
									<div class="ts-form-group x-col-4">
										<label><?php echo esc_html__( 'Slug', 'voxmanager' ); ?></label>
										<input type="text" name="voxmanager_plugins[<?php echo esc_attr( $plugin_file ); ?>][slug]" value="<?php echo esc_attr( $config['slug'] ); ?>" />
									</div>
									<div class="ts-form-group x-col-4">
										<label><?php echo esc_html__( 'Repository (owner/repo)', 'voxmanager' ); ?></label>
										<input type="text" name="voxmanager_plugins[<?php echo esc_attr( $plugin_file ); ?>][repo]" value="<?php echo esc_attr( $config['repo'] ); ?>" />
									</div>
									<div class="ts-form-group x-col-4">
										<label><?php echo esc_html__( 'Branch', 'voxmanager' ); ?></label>
										<?php if ( ! empty( $branches ) ) : ?>
											<select name="voxmanager_plugins[<?php echo esc_attr( $plugin_file ); ?>][branch]">
												<option value=""><?php echo esc_html__( 'Use releases/tags', 'voxmanager' ); ?></option>
												<?php foreach ( $branches as $branch_name ) : ?>
													<?php $label_suffix = $branch_name === $default_branch ? ' (' . esc_html__( 'default', 'voxmanager' ) . ')' : ''; ?>
													<option value="<?php echo esc_attr( $branch_name ); ?>" <?php echo selected( $selected_branch, $branch_name, false ); ?>><?php echo esc_html( $branch_name . $label_suffix ); ?></option>
												<?php endforeach; ?>
											</select>
										<?php else : ?>
											<input type="text" name="voxmanager_plugins[<?php echo esc_attr( $plugin_file ); ?>][branch]" value="<?php echo esc_attr( $selected_branch ); ?>" placeholder="<?php echo esc_attr__( 'main', 'voxmanager' ); ?>" />
											<p><?php echo esc_html__( 'GitHub branch list unavailable. Check token or repo permissions.', 'voxmanager' ); ?></p>
										<?php endif; ?>
									</div>
								</div>
							</div>
						<?php endforeach; ?>
					</div>

					<button type="submit" class="ts-button ts-save-settings btn-shadow">
						<?php echo esc_html__( 'Save settings', 'voxmanager' ); ?>
					</button>
				</form>
			</div>
		</div>

		<div class="x-col-12" data-vx-tab-panel="setup" aria-hidden="<?php echo $active_tab === 'setup' ? 'false' : 'true'; ?>"<?php echo $active_tab === 'setup' ? '' : ' style="display:none;"'; ?>>
			<div class="ts-group">
				<div class="ts-group-head">
					<h3><?php echo esc_html__( 'Setup hints', 'voxmanager' ); ?></h3>
				</div>
				<div class="x-row">
					<?php
					$webhook_url = rest_url( 'voxmanager/v1/webhook/refresh' );
					$cache_url = rest_url( 'voxmanager/v1/cache/refresh' );
					$check_url = rest_url( 'voxmanager/v1/check-updates' );
					$install_url = rest_url( 'voxmanager/v1/plugins/install' );
					$update_url = rest_url( 'voxmanager/v1/plugins/update' );
					?>
					<div class="ts-form-group x-col-12">
						<span class="vx-info-box wide">
							<p><?php echo esc_html__( 'Set a GitHub token if you need private repos or higher API limits. Optional: set an API key to authorize REST automation and webhooks.', 'voxmanager' ); ?></p>
						</span>
					</div>
					<div class="ts-form-group x-col-12">
						<label><?php echo esc_html__( 'Webhook refresh URL', 'voxmanager' ); ?></label>
						<pre class="ts-snippet" style="word-break: break-all;"><span class="ts-green">POST</span> <span class="ts-italic"><?php echo esc_html( $webhook_url ); ?></span></pre>
						<p class="mt10"><?php echo esc_html__( 'Send X-VoxManager-Token header or ?token=... query string when using the API key.', 'voxmanager' ); ?></p>
					</div>
					<div class="ts-form-group x-col-12">
						<label><?php echo esc_html__( 'Automation endpoints', 'voxmanager' ); ?></label>
						<div class="basic-ul">
							<li><span class="ts-green">POST</span> <?php echo esc_html( $cache_url ); ?></li>
							<li><span class="ts-green">POST</span> <?php echo esc_html( $check_url ); ?></li>
							<li><span class="ts-green">POST</span> <?php echo esc_html( $install_url ); ?> <span class="ts-italic">plugin=VoxPro/voxpro.php</span></li>
							<li><span class="ts-green">POST</span> <?php echo esc_html( $update_url ); ?> <span class="ts-italic">plugin=VoxPro/voxpro.php</span></li>
						</div>
					</div>
					<div class="ts-form-group x-col-12">
						<span class="vx-info-box wide">
							<p><?php echo esc_html__( 'After saving settings, run "Check for updates" to refresh update data.', 'voxmanager' ); ?></p>
						</span>
					</div>
				</div>
			</div>
		</div>
	</div>
</div>

<script>
(() => {
	const wrapper = document.getElementById('vx-voxmanager-tabs');
	if (!wrapper) {
		return;
	}

	const tabs = Array.from(wrapper.querySelectorAll('[data-vx-tab]'));
	const panels = Array.from(wrapper.querySelectorAll('[data-vx-tab-panel]'));
	const defaultTab = wrapper.dataset.activeTab || 'overview';

	const setActive = (tabKey) => {
		tabs.forEach((tab) => {
			tab.classList.toggle('current-item', tab.dataset.vxTab === tabKey);
		});
		panels.forEach((panel) => {
			const isActive = panel.dataset.vxTabPanel === tabKey;
			panel.style.display = isActive ? '' : 'none';
			panel.setAttribute('aria-hidden', isActive ? 'false' : 'true');
		});
	};

	setActive(defaultTab);

	wrapper.addEventListener('click', (event) => {
		const link = event.target.closest('[data-vx-tab-link]');
		if (!link) {
			return;
		}

		event.preventDefault();
		const tabKey = link.getAttribute('data-vx-tab-link');
		if (tabKey) {
			setActive(tabKey);
		}
	});
})();
</script>
