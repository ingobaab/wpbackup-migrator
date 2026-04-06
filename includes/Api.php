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
	 * Cached result of SHOW TABLE STATUS for the active WP prefix.
	 *
	 * @var array<int, object>|null
	 */
	private $table_status_cache = null;

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

		return rest_ensure_response( $this->get_site_info_payload( $user[0] ) );
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
		$user = get_users( [ 'role' => 'administrator', 'number' => 1 ] );

		return rest_ensure_response( $this->get_site_info_payload( $user[0] ) );
	}

	/**
	 * Shared JSON body for /verify (key) and /info (Application Password).
	 *
	 * @param \WP_User $admin Administrator user (first admin).
	 * @return array<string, mixed>
	 */
	private function get_site_info_payload( $admin ) {
		global $wpdb;

		$database_info = $this->get_database_info_for_info();

		return [
			'success'               => true,
			'username'              => $admin->user_login,
			'email'                 => $admin->user_email,
			'url'                   => home_url(),
			'site_title'            => get_bloginfo( 'name' ),
			'key'                   => wpbackup_migrator()->get_migration_key(),
			'is_multisite'          => is_multisite(),
			'prefix'                => $wpdb->prefix,
			'php_version'           => PHP_VERSION,
			'wp_version'            => get_bloginfo( 'version' ),
			'database_size'         => $database_info['total_data_bytes'],
			'database_info'         => $database_info,
			'media_size'            => $this->estimate_media_library_size_bytes(),
			'list_plugins'          => $this->get_list_plugins_for_info(),
			'list_themes'           => $this->get_list_themes_for_info(),
			'is_wp_cron_disabled'   => defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON === true,
		];
	}

	/**
	 * Übersicht der DB-Tabellen für /info.
	 *
	 * @return array{
	 *   table_count:int,
	 *   total_data_bytes:int,
	 *   total_index_bytes:int,
	 *   total_bytes:int,
	 *   tables:list<array{name:string, records:int, data_bytes:int, index_bytes:int, total_bytes:int}>
	 * }
	 */
	private function get_database_info_for_info() {
		$tables_meta = $this->get_database_table_status();
		$tables      = [];
		$total_data  = 0;
		$total_index = 0;
		$total_all   = 0;

		foreach ( $tables_meta as $table ) {
			$name       = isset( $table->Name ) ? (string) $table->Name : '';
			$rows       = isset( $table->Rows ) ? max( 0, (int) $table->Rows ) : 0;
			$data_bytes = isset( $table->Data_length ) ? max( 0, (int) $table->Data_length ) : 0;
			$idx_bytes  = isset( $table->Index_length ) ? max( 0, (int) $table->Index_length ) : 0;
			$sum_bytes  = $data_bytes + $idx_bytes;

			$total_data  += $data_bytes;
			$total_index += $idx_bytes;
			$total_all   += $sum_bytes;

			$tables[] = [
				'name'        => $name,
				'records'     => $rows,
				'data_bytes'  => $data_bytes,
				'index_bytes' => $idx_bytes,
				'total_bytes' => $sum_bytes,
			];
		}

		return [
			'table_count'        => count( $tables ),
			'total_data_bytes'   => min( $total_data, PHP_INT_MAX ),
			'total_index_bytes'  => min( $total_index, PHP_INT_MAX ),
			'total_bytes'        => min( $total_all, PHP_INT_MAX ),
			'tables'             => $tables,
		];
	}

	/**
	 * Installierte Plugins inkl. MU-Plugins (ohne Filesystem-Scan, nur WP-API).
	 *
	 * @return list<array<string, mixed>>
	 */
	private function get_list_plugins_for_info() {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$updates_obj = get_site_transient( 'update_plugins' );
		$response    = ( is_object( $updates_obj ) && isset( $updates_obj->response ) && is_array( $updates_obj->response ) )
			? $updates_obj->response
			: [];

		$out = [];

		$mu_plugins = get_mu_plugins();
		foreach ( $mu_plugins as $plugin_path => $data ) {
			if ( ! is_array( $data ) ) {
				continue;
			}
			$out[] = [
				'name'             => isset( $data['Name'] ) ? (string) $data['Name'] : '',
				'version'          => isset( $data['Version'] ) ? (string) $data['Version'] : '',
				'active'           => true,
				'slug'             => $this->get_mu_plugin_slug( $plugin_path ),
				'update_available' => false,
				'is_mu'            => true,
				'is_network'       => false,
				'requires_php'     => isset( $data['RequiresPHP'] ) ? (string) $data['RequiresPHP'] : '',
				'requires_wp'      => isset( $data['RequiresWP'] ) ? (string) $data['RequiresWP'] : '',
			];
		}

		$plugins = get_plugins();
		foreach ( $plugins as $plugin_file => $data ) {
			if ( ! is_array( $data ) ) {
				continue;
			}
			$network_active = is_multisite() && is_plugin_active_for_network( $plugin_file );
			$site_active    = is_plugin_active( $plugin_file );
			$out[]          = [
				'name'             => isset( $data['Name'] ) ? (string) $data['Name'] : '',
				'version'          => isset( $data['Version'] ) ? (string) $data['Version'] : '',
				'active'           => $site_active || $network_active,
				'slug'             => $this->get_plugin_slug_from_basename( $plugin_file ),
				'update_available' => isset( $response[ $plugin_file ] ),
				'is_mu'            => false,
				'is_network'       => $network_active,
				'requires_php'     => isset( $data['RequiresPHP'] ) ? (string) $data['RequiresPHP'] : '',
				'requires_wp'      => isset( $data['RequiresWP'] ) ? (string) $data['RequiresWP'] : '',
			];
		}

		usort(
			$out,
			static function ( $a, $b ) {
				return strcasecmp( (string) $a['name'], (string) $b['name'] );
			}
		);

		return $out;
	}

	/**
	 * Plugin-Slug aus relativer Plugin-Datei (z. B. akismet/akismet.php → akismet).
	 *
	 * @param string $plugin_file Pfad relativ zu wp-content/plugins.
	 * @return string
	 */
	private function get_plugin_slug_from_basename( $plugin_file ) {
		$dir = dirname( $plugin_file );
		if ( '.' === $dir ) {
			return basename( $plugin_file, '.php' );
		}
		return $dir;
	}

	/**
	 * Slug für MU-Plugin (Ordner- oder Dateiname relativ zu mu-plugins).
	 *
	 * @param string $full_path Absolute Pfadangabe.
	 * @return string
	 */
	private function get_mu_plugin_slug( $full_path ) {
		if ( ! defined( 'WPMU_PLUGIN_DIR' ) ) {
			return basename( $full_path, '.php' );
		}
		$root = wp_normalize_path( WPMU_PLUGIN_DIR );
		$path = wp_normalize_path( $full_path );
		if ( strpos( $path, $root . '/' ) !== 0 ) {
			return basename( $full_path, '.php' );
		}
		$rel = substr( $path, strlen( $root ) + 1 );
		$dir = dirname( $rel );
		if ( '.' === $dir || '' === $dir ) {
			return basename( $rel, '.php' );
		}
		return $dir;
	}

	/**
	 * Installierte Themes.
	 *
	 * @return list<array<string, mixed>>
	 */
	private function get_list_themes_for_info() {
		$themes            = wp_get_themes();
		$active_stylesheet = (string) get_option( 'stylesheet', '' );
		$out               = [];

		foreach ( $themes as $theme ) {
			if ( ! $theme instanceof \WP_Theme ) {
				continue;
			}
			$parent = $theme->parent();
			$out[]  = [
				'name'           => (string) $theme->get( 'Name' ),
				'version'        => (string) $theme->get( 'Version' ),
				'active'         => ( $theme->get_stylesheet() === $active_stylesheet ),
				'is_child'       => (bool) $parent,
				'parent'         => $parent ? (string) $parent->get_stylesheet() : '',
				'parent_version' => $parent ? (string) $parent->get( 'Version' ) : '',
			];
		}

		usort(
			$out,
			static function ( $a, $b ) {
				return strcasecmp( (string) $a['name'], (string) $b['name'] );
			}
		);

		return $out;
	}

	/**
	 * Schätzung der Uploads-/Mediathek-Größe in Bytes (ohne Filesystem-Scan).
	 *
	 * Nutzt ein einziges SQL-Statement (Attachments + _wp_attachment_metadata), wertet
	 * gespeicherte filesize aus (falls vorhanden) und schätzt sonst aus Abmessungen × MIME.
	 *
	 * @return int Geschätzte Gesamtgröße in Bytes (≥ 0).
	 */
	private function estimate_media_library_size_bytes() {
		global $wpdb;

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- statische Tabellennamen.
		$rows = $wpdb->get_results(
			"SELECT p.post_mime_type, pm.meta_value AS meta
			FROM {$wpdb->posts} p
			INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = '_wp_attachment_metadata'
			WHERE p.post_type = 'attachment' AND p.post_status = 'inherit'",
			ARRAY_A
		);

		if ( empty( $rows ) ) {
			return 0;
		}

		$total = 0;
		foreach ( $rows as $row ) {
			$meta = maybe_unserialize( $row['meta'] );
			if ( ! is_array( $meta ) ) {
				continue;
			}
			$mime = isset( $row['post_mime_type'] ) ? (string) $row['post_mime_type'] : '';
			$total += $this->sum_attachment_metadata_estimated_bytes( $meta, $mime );
		}

		return min( $total, PHP_INT_MAX );
	}

	/**
	 * Summiert geschätzte Byte-Größe für Hauptdatei und alle registrierten Derivat-Größen.
	 *
	 * @param array  $meta _wp_attachment_metadata.
	 * @param string $mime Attachment post_mime_type.
	 * @return int
	 */
	private function sum_attachment_metadata_estimated_bytes( array $meta, $mime ) {
		$sum = 0;

		if ( ! empty( $meta['filesize'] ) && is_numeric( $meta['filesize'] ) ) {
			$sum += (int) $meta['filesize'];
		} else {
			$w = isset( $meta['width'] ) ? (int) $meta['width'] : 0;
			$h = isset( $meta['height'] ) ? (int) $meta['height'] : 0;
			if ( $w > 0 && $h > 0 ) {
				$sum += $this->estimate_media_file_bytes_from_dimensions( $w, $h, $mime );
			}
		}

		if ( empty( $meta['sizes'] ) || ! is_array( $meta['sizes'] ) ) {
			return $sum;
		}

		foreach ( $meta['sizes'] as $size_data ) {
			if ( ! is_array( $size_data ) ) {
				continue;
			}
			if ( ! empty( $size_data['filesize'] ) && is_numeric( $size_data['filesize'] ) ) {
				$sum += (int) $size_data['filesize'];
				continue;
			}
			$sw = isset( $size_data['width'] ) ? (int) $size_data['width'] : 0;
			$sh = isset( $size_data['height'] ) ? (int) $size_data['height'] : 0;
			if ( $sw <= 0 || $sh <= 0 ) {
				continue;
			}
			$sub_mime = isset( $size_data['mime-type'] ) ? (string) $size_data['mime-type'] : $mime;
			$sum     += $this->estimate_media_file_bytes_from_dimensions( $sw, $sh, $sub_mime );
		}

		return $sum;
	}

	/**
	 * Heuristik: Dateigröße aus Pixeln und MIME (Bytes pro Pixel, empirisch grob).
	 *
	 * @param int    $width  Breite.
	 * @param int    $height Höhe.
	 * @param string $mime   MIME-Typ.
	 * @return int Geschätzte Größe in Bytes.
	 */
	private function estimate_media_file_bytes_from_dimensions( $width, $height, $mime ) {
		$w = max( 0, (int) $width );
		$h = max( 0, (int) $height );
		if ( $w === 0 || $h === 0 ) {
			return 0;
		}

		$pixels = $w * $h;
		$mime   = strtolower( (string) $mime );

		// Raster: typische Kompression; SVG separat (meist klein zur Fläche).
		if ( 0 === strpos( $mime, 'image/jpeg' ) || 'image/jpg' === $mime ) {
			$bpp = 0.22;
		} elseif ( 0 === strpos( $mime, 'image/webp' ) ) {
			$bpp = 0.18;
		} elseif ( 0 === strpos( $mime, 'image/png' ) ) {
			$bpp = 0.48;
		} elseif ( 0 === strpos( $mime, 'image/gif' ) ) {
			$bpp = 0.14;
		} elseif ( 0 === strpos( $mime, 'image/avif' ) ) {
			$bpp = 0.14;
		} elseif ( 0 === strpos( $mime, 'image/svg' ) ) {
			return (int) min( 512 * 1024, max( 2048, $pixels * 0.02 ) );
		} elseif ( 0 === strpos( $mime, 'image/' ) ) {
			$bpp = 0.25;
		} elseif ( 0 === strpos( $mime, 'video/' ) || 0 === strpos( $mime, 'audio/' ) ) {
			// Poster-ähnliche Metadaten ohne filesize: grobe Untergrenze.
			return (int) max( 50 * 1024, min( 200 * 1024 * 1024, $pixels * 0.05 ) );
		} else {
			return (int) max( 1024, min( 50 * 1024 * 1024, $pixels * 0.2 ) );
		}

		return (int) max( 1, round( $pixels * $bpp ) );
	}

	/**
	 * Get the total database size in bytes
	 *
	 * @return int Total database size in bytes
	 */
	private function get_database_size() {
		$tables = $this->get_database_table_status();
		$size   = 0;
		foreach ( $tables as $table ) {
			$size += isset( $table->Data_length ) ? max( 0, (int) $table->Data_length ) : 0;
		}
		return min( $size, PHP_INT_MAX );
	}

	/**
	 * Lädt Tabellenstatus für das aktuelle WP-Tabellenpräfix (einmalig pro Request).
	 *
	 * SHOW TABLE STATUS nutzt DB-Metadaten und ist i. d. R. deutlich schneller als COUNT(*) über alle Tabellen.
	 *
	 * @return array<int, object>
	 */
	private function get_database_table_status() {
		global $wpdb;

		if ( is_array( $this->table_status_cache ) ) {
			return $this->table_status_cache;
		}

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SHOW TABLE STATUS LIKE %s',
				$wpdb->esc_like( $wpdb->prefix ) . '%'
			)
		);

		$this->table_status_cache = is_array( $rows ) ? $rows : [];
		return $this->table_status_cache;
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
