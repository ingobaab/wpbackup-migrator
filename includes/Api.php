<?php

namespace Wpbackup\Migrator;

use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * REST API Handler Class
 */
class Api {

	/**
	 * API namespace
	 *
	 * @var string
	 */
	const NAMESPACE = 'wpbackup-migrator/v1';

	/**
	 * Request header for the migration secret (current).
	 */
	const HEADER_MIGRATION_KEY = 'X-WPBackup-Key';

	/**
	 * Legacy request header (same semantics; kept for compatibility).
	 */
	const LEGACY_HEADER_MIGRATION_KEY = 'X-FlyWP-Key';

	/**
	 * Database API handler
	 *
	 * @var Api\Database
	 */
	private $database;

	/**
	 * Files API handler
	 *
	 * @var Api\Files
	 */
	private $files;

	/**
	 * Constructor
	 */
	public function __construct() {
		$this->database = new Api\Database();
		$this->files    = new Api\Files();
	}

	/**
	 * Register REST API routes
	 *
	 * @return void
	 */
	public function register_routes() {
		register_rest_route(
			self::NAMESPACE,
			'/verify',
			[
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'verify_key' ],
				'permission_callback' => '__return_true',
			]
		);

		register_rest_route(
			self::NAMESPACE,
			'/info',
			[
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => [ $this, 'get_info' ],
				'permission_callback' => function () {
					return current_user_can( 'manage_options' );
				},
			]
		);

		// Register database routes
		$this->database->register_routes( self::NAMESPACE );

		// Register files routes
		$this->files->register_routes( self::NAMESPACE );
	}

	/**
	 * Verify migration key
	 *
	 * @param WP_REST_Request $request Request.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function verify_key( $request ) {
		global $wpdb;

		$key = $request->get_param( 'key' );

		if ( empty( $key ) ) {
			return new WP_Error( 'invalid_key', __( 'Migration key is required', 'wpbackup-migrator' ) );
		}

		$stored_key = wpbackup_migrator()->get_migration_key();

		if ( $key !== $stored_key ) {
			return new WP_Error( 'invalid_key', __( 'Invalid migration key', 'wpbackup-migrator' ) );
		}

		// get the first admin user
		$user = get_users( [ 'role' => 'administrator', 'number' => 1 ] );

		return rest_ensure_response(
			[
				'success'               => true,
				'username'              => $user[0]->user_login,
				'email'                 => $user[0]->user_email,
				'url'                   => home_url(),
				'site_title'            => get_bloginfo( 'name' ),
				'key'                   => wpbackup_migrator()->get_migration_key(),
				'is_multisite'          => is_multisite(),
				'prefix'                => $wpdb->prefix,
				'php_version'           => PHP_VERSION,
				'wp_version'            => get_bloginfo( 'version' ),
				'database_size'         => $this->get_database_size(),
				'is_wp_cron_disabled'   => defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON === true,
			]
		);
	}

	/**
	 * Get migration info
	 *
	 * This endpoint is only used when the user has authorized
	 * via Application Passwords.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_info() {
		global $wpdb;

		$user = get_users( [ 'role' => 'administrator', 'number' => 1 ] );

		return rest_ensure_response(
			[
				'success'               => true,
				'username'              => $user[0]->user_login,
				'email'                 => $user[0]->user_email,
				'url'                   => home_url(),
				'site_title'            => get_bloginfo( 'name' ),
				'key'                   => wpbackup_migrator()->get_migration_key(),
				'is_multisite'          => is_multisite(),
				'prefix'                => $wpdb->prefix,
				'php_version'           => PHP_VERSION,
				'wp_version'            => get_bloginfo( 'version' ),
				'database_size'         => $this->get_database_size(),
				'is_wp_cron_disabled'   => defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON === true,
			]
		);
	}

	/**
	 * Get the total database size in bytes
	 *
	 * @return int Total database size in bytes
	 */
	private function get_database_size() {
		global $wpdb;

		$size = 0;

		// Get all tables with the site's prefix
		$tables = $wpdb->get_results(
			$wpdb->prepare(
				'SHOW TABLE STATUS LIKE %s',
				$wpdb->esc_like( $wpdb->prefix ) . '%'
			)
		);

		if ( $tables ) {
			foreach ( $tables as $table ) {
				// Only count Data_length, not Index_length
				// Indexes are not exported in SQL dumps (they're rebuilt on import)
				$size += $table->Data_length;
			}
		}

		return $size;
	}

	/**
	 * Read migration key from request (header or query).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return string
	 */
	private static function get_request_migration_key( $request ) {
		$key = $request->get_header( self::HEADER_MIGRATION_KEY );
		if ( ! empty( $key ) ) {
			return $key;
		}
		$key = $request->get_header( self::LEGACY_HEADER_MIGRATION_KEY );
		if ( ! empty( $key ) ) {
			return $key;
		}
		return (string) $request->get_param( 'secret' );
	}

	/**
	 * Check API permission
	 *
	 * @param WP_REST_Request $request Request.
	 *
	 * @return bool|WP_Error
	 */
	public static function check_permission( $request ) {
		$key = self::get_request_migration_key( $request );

		if ( empty( $key ) ) {
			return new WP_Error( 'unauthorized', __( 'Migration key is required', 'wpbackup-migrator' ) );
		}

		$stored_key = wpbackup_migrator()->get_migration_key();

		if ( $key !== $stored_key ) {
			return new WP_Error( 'unauthorized', __( 'Invalid migration key', 'wpbackup-migrator' ) );
		}

		return true;
	}
}
