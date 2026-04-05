<?php

namespace Wpbackup\Migrator;

class Admin {

	/**
	 * Constructor
	 */
	public function __construct() {
		add_action( 'admin_menu', [ $this, 'admin_menu' ] );
	}

	/**
	 * Add admin menu
	 *
	 * @return void
	 */
	public function admin_menu() {
		$hook = add_menu_page(
			__( 'WPBackup Migrator', 'wpbackup-migrator' ),
			__( 'WPBackup Migrator', 'wpbackup-migrator' ),
			'manage_options',
			'wpbackup-migrator',
			[ $this, 'plugin_page' ],
			'dashicons-migrate',
		);

		add_action( "admin_head-$hook", [ $this, 'enqueue_scripts' ] );
	}

	/**
	 * Enqueue scripts and styles
	 *
	 * @param string $hook Current admin page hook.
	 *
	 * @return void
	 */
	public function enqueue_scripts() {
		wp_register_style(
			'wpbackup-migrator-styles',
			plugin_dir_url( __DIR__ ) . 'assets/css/admin.css',
			[],
			WPBACKUP_MIGRATOR_VERSION
		);
		wp_enqueue_style( 'wpbackup-migrator-styles' );

		wp_register_script(
			'wpbackup-migrator-scripts',
			plugin_dir_url( __DIR__ ) . 'assets/js/admin.js',
			[ 'jquery' ],
			WPBACKUP_MIGRATOR_VERSION,
			true
		);
		wp_enqueue_script( 'wpbackup-migrator-scripts' );
	}

	/**
	 * Get the encoded migration key
	 *
	 * @return string
	 */
	public function get_migration_key() {
		global $wpdb;

		$key          = wpbackup_migrator()->get_migration_key();
		$site_url     = home_url();
		$is_multisite = is_multisite() ? 'multisite' : 'single';

		// Encode the key with site URL, multisite status, and database prefix
		$site        = base64_encode( $site_url . '|' . $is_multisite . '|' . $wpdb->prefix );
		$encoded_key = base64_encode( $site . ':' . $key );

		return $encoded_key;
	}

	/**
	 * Plugin page callback
	 *
	 * @return void
	 */
	public function plugin_page() {
		?>
		<div class="wrap">
			<div class="wpbackup-card">
				<div class="wpbackup-header">
					<div class="wpbackup-logo">
						<span class="dashicons dashicons-migrate"></span>
					</div>
					<div>
						<h1 class="wpbackup-title"><?php esc_html_e( 'WPBackup Migrator', 'wpbackup-migrator' ); ?></h1>
						<p class="wpbackup-description"><?php esc_html_e( 'Connect this site to your migration or backup workflow', 'wpbackup-migrator' ); ?></p>
					</div>
				</div>

				<div class="wpbackup-instructions">
					<h2><?php esc_html_e( 'Migration instructions', 'wpbackup-migrator' ); ?></h2>
					<ol>
						<li><?php esc_html_e( 'Copy the migration key below using the copy button.', 'wpbackup-migrator' ); ?></li>
						<li><?php esc_html_e( 'Open your WPBackup Migrator destination (or migration tool) and start the import.', 'wpbackup-migrator' ); ?></li>
						<li><?php esc_html_e( 'When prompted, paste the migration key to authorize the connection.', 'wpbackup-migrator' ); ?></li>
						<li><?php esc_html_e( 'Complete the remaining steps in your migration wizard.', 'wpbackup-migrator' ); ?></li>
					</ol>
				</div>

				<div class="wpbackup-form-row">
					<label for="migration_key" class="wpbackup-label">
						<?php esc_html_e( 'Migration key', 'wpbackup-migrator' ); ?>
					</label>
					<div class="wpbackup-input-wrapper">
						<input type="password"
							id="wpbackup-migration-key"
							name="migration_key"
							value="<?php echo esc_attr( $this->get_migration_key() ); ?>"
							class="wpbackup-input"
							required
						>

						<div class="wpbackup-buttons-wrapper">
							<button type="button" class="wpbackup-toggle-password" aria-label="<?php esc_attr_e( 'Toggle password visibility', 'wpbackup-migrator' ); ?>">
								<span class="dashicons dashicons-visibility"></span>
							</button>

							<button type="button" class="wpbackup-copy-clipboard" aria-label="<?php esc_attr_e( 'Copy migration key to clipboard', 'wpbackup-migrator' ); ?>">
								<span class="dashicons dashicons-admin-page"></span>
								<span>Copy</span>
							</button>
						</div>
					</div>
				</div>
			</div>
		</div>
		<?php
	}
}
