<?php

namespace Wpbackup\Migrator;

use Wpbackup\Migrator\Services\Database\Scheduler;

/**
 * Main plugin class
 */
class Plugin {

	/**
	 * Option name for the migration secret (shared with remote importer).
	 */
	const MIGRATION_KEY_OPTION = 'wpbackup_migration_key';

	/**
	 * Legacy option name (FlyWP Migrator and older installs).
	 */
	const LEGACY_MIGRATION_KEY_OPTION = 'flywp_migration_key';

	/**
	 * Plugin version
	 *
	 * @var string
	 */
	const VERSION = '1.3.0';

	/**
	 * Plugin instance
	 *
	 * @var Plugin|null
	 */
	private static $instance = null;

	/**
	 * Get plugin instance
	 *
	 * @return Plugin
	 */
	public static function instance() {
		if ( is_null( self::$instance ) ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Constructor
	 */
	private function __construct() {
		$this->define_constants();
		$this->init_hooks();
	}

	/**
	 * Define constants
	 *
	 * @return void
	 */
	private function define_constants() {
		define( 'WPBACKUP_MIGRATOR_VERSION', self::VERSION );
		define( 'WPBACKUP_MIGRATOR_PATH', dirname( WPBACKUP_MIGRATOR_FILE ) );
		define( 'WPBACKUP_MIGRATOR_INCLUDES', WPBACKUP_MIGRATOR_PATH . '/includes' );
		define( 'WPBACKUP_MIGRATOR_URL', plugins_url( '', WPBACKUP_MIGRATOR_FILE ) );
	}

	/**
	 * Initialize hooks
	 *
	 * @return void
	 */
	private function init_hooks() {
		register_activation_hook( WPBACKUP_MIGRATOR_FILE, [ $this, 'activate'] );

		// Initialize the plugin
		add_action( 'plugins_loaded', [ $this, 'init_plugin'] );

		// Register REST API routes
		add_action( 'rest_api_init', [ $this, 'register_rest_routes'] );

		// Add settings link to plugin listing
		add_filter( 'plugin_action_links_' . plugin_basename( WPBACKUP_MIGRATOR_FILE ), [ $this, 'add_plugin_action_links'] );
	}

	/**
	 * Plugin activation hook
	 *
	 * @return void
	 */
	public function activate() {
		$this->maybe_migrate_migration_key_option();
		if ( ! $this->get_migration_key() ) {
			$this->set_migration_key( wp_generate_password( 32, false ) );
		}
	}

	/**
	 * Copy legacy option into the new key if needed (idempotent).
	 *
	 * @return void
	 */
	private function maybe_migrate_migration_key_option() {
		$new = get_option( self::MIGRATION_KEY_OPTION, '' );
		if ( $new !== '' ) {
			return;
		}
		$legacy = get_option( self::LEGACY_MIGRATION_KEY_OPTION, '' );
		if ( $legacy !== '' ) {
			update_option( self::MIGRATION_KEY_OPTION, $legacy );
		}
	}

	/**
	 * Initialize plugin
	 *
	 * @return void
	 */
	public function init_plugin() {
		$this->maybe_migrate_migration_key_option();

		// Initialize the backup scheduler (registers cron hooks)
		Scheduler::init();

		new Admin();
	}

	/**
	 * Register REST API routes
	 *
	 * @return void
	 */
	public function register_rest_routes() {
		$api = new Api();
		$api->register_routes();
	}

	/**
	 * Get migration key
	 *
	 * @return string
	 */
	public function get_migration_key() {
		$this->maybe_migrate_migration_key_option();

		return get_option( self::MIGRATION_KEY_OPTION, '' );
	}

	/**
	 * Set migration key
	 *
	 * @param string $key Key value.
	 *
	 * @return void
	 */
	public function set_migration_key( $key ) {
		update_option( self::MIGRATION_KEY_OPTION, $key );
	}

	/**
	 * Add settings link to plugin action links
	 *
	 * @param array $links Plugin action links.
	 *
	 * @return array
	 */
	public function add_plugin_action_links( $links ) {
		$settings_link = '<a href="' . admin_url( 'admin.php?page=wpbackup-migrator' ) . '">' . __( 'Settings', 'wpbackup-migrator' ) . '</a>';
		array_unshift( $links, $settings_link );

		return $links;
	}
}
