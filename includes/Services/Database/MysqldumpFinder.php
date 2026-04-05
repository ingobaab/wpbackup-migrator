<?php

namespace Wpbackup\Migrator\Services\Database;

/**
 * Find and validate mysqldump binary
 */
class MysqldumpFinder {

	/**
	 * Build the list of possible mysqldump executables
	 *
	 * @return string Comma-separated list of paths
	 */
	public static function build_mysqldump_list() {
		if ( 'win' == strtolower( substr( PHP_OS, 0, 3 ) ) && function_exists( 'glob' ) ) {
			$drives = array( 'C', 'D', 'E' );

			if ( ! empty( $_SERVER['DOCUMENT_ROOT'] ) ) {
				// Get the drive that this is running on
				$current_drive = strtoupper( substr( $_SERVER['DOCUMENT_ROOT'], 0, 1 ) );
				if ( ! in_array( $current_drive, $drives ) ) {
					array_unshift( $drives, $current_drive );
				}
			}

			$directories = array();

			foreach ( $drives as $drive_letter ) {
				$dir = glob( "$drive_letter:\\{Program Files\\MySQL\\{,MySQL*,etc}{,\\bin,\\?},mysqldump}\\mysqldump*", GLOB_BRACE );
				if ( is_array( $dir ) ) {
					$directories = array_merge( $directories, $dir );
				}
			}

			$drive_string = implode( ',', $directories );
			return $drive_string;

		} else {
			return '/usr/bin/mysqldump,/bin/mysqldump,/usr/local/bin/mysqldump,/usr/sfw/bin/mysqldump,/usr/xdg4/bin/mysqldump,/opt/bin/mysqldump';
		}
	}

	/**
	 * Detect if PHP is in safe mode
	 *
	 * @return bool
	 */
	public static function detect_safe_mode() {
		$safe_mode = @ini_get( 'safe_mode' );
		return $safe_mode && strtolower( $safe_mode ) !== 'off';
	}

	/**
	 * Find a working mysqldump binary
	 *
	 * @param string $backup_dir Directory for temporary files
	 *
	 * @return string|false Path to mysqldump or false if not found
	 */
	public static function find( $backup_dir ) {
		// The hosting provider may have explicitly disabled the popen or proc_open functions
		if ( self::detect_safe_mode() || ! function_exists( 'popen' ) || ! function_exists( 'escapeshellarg' ) ) {
			return false;
		}

		global $wpdb;
		$table_name = $wpdb->get_blog_prefix() . 'options';
		$pfile      = md5( time() . rand() ) . '.tmp';
		file_put_contents( $backup_dir . '/' . $pfile, "[mysqldump]\npassword=\"" . addslashes( DB_PASSWORD ) . "\"\n" );

		$result = false;
		foreach ( explode( ',', self::build_mysqldump_list() ) as $potsql ) {

			if ( ! @is_executable( $potsql ) ) {
				continue;
			}

			if ( 'win' == strtolower( substr( PHP_OS, 0, 3 ) ) ) {
				$exec    = 'cd ' . escapeshellarg( str_replace( '/', '\\', $backup_dir ) ) . ' & ';
				$siteurl = "'siteurl'";
				if ( false !== strpos( $potsql, ' ' ) ) {
					$potsql = '"' . $potsql . '"';
				}
			} else {
				$exec    = 'cd ' . escapeshellarg( $backup_dir ) . '; ';
				$siteurl = "\\'siteurl\\'";
				if ( false !== strpos( $potsql, ' ' ) ) {
					$potsql = "'" . $potsql . "'";
				}
			}

			// Allow --max_allowed_packet to be configured via constant
			$msqld_max_allowed_packet = '1M';
			if ( defined( 'WPBACKUP_MYSQLDUMP_MAX_ALLOWED_PACKET' ) && ( is_int( WPBACKUP_MYSQLDUMP_MAX_ALLOWED_PACKET ) || is_string( WPBACKUP_MYSQLDUMP_MAX_ALLOWED_PACKET ) ) ) {
				$msqld_max_allowed_packet = WPBACKUP_MYSQLDUMP_MAX_ALLOWED_PACKET;
			} elseif ( defined( 'FLYWP_MYSQLDUMP_MAX_ALLOWED_PACKET' ) && ( is_int( FLYWP_MYSQLDUMP_MAX_ALLOWED_PACKET ) || is_string( FLYWP_MYSQLDUMP_MAX_ALLOWED_PACKET ) ) ) {
				$msqld_max_allowed_packet = FLYWP_MYSQLDUMP_MAX_ALLOWED_PACKET;
			}

			$exec .= "$potsql --defaults-file=$pfile --max_allowed_packet=$msqld_max_allowed_packet --quote-names --add-drop-table";

			static $mysql_version = null;
			if ( null === $mysql_version ) {
				$mysql_version = $wpdb->get_var( 'SELECT VERSION()' );
				if ( '' == $mysql_version ) {
					$mysql_version = $wpdb->db_version();
				}
			}
			if ( $mysql_version && version_compare( $mysql_version, '5.1', '>=' ) ) {
				$exec .= ' --no-tablespaces';
			}

			$exec .= " --skip-comments --skip-set-charset --allow-keywords --dump-date --extended-insert --where=option_name=$siteurl --user=" . escapeshellarg( DB_USER ) . ' ';

			if ( preg_match( '#^(.*):(\d+)$#', DB_HOST, $matches ) ) {
				$exec .= '--host=' . escapeshellarg( $matches[1] ) . ' --port=' . escapeshellarg( $matches[2] ) . ' ';
			} elseif ( preg_match( '#^(.*):(.*)$#', DB_HOST, $matches ) && file_exists( $matches[2] ) ) {
				$exec .= '--host=' . escapeshellarg( $matches[1] ) . ' --socket=' . escapeshellarg( $matches[2] ) . ' ';
			} else {
				$exec .= '--host=' . escapeshellarg( DB_HOST ) . ' ';
			}

			$exec .= DB_NAME . ' ' . escapeshellarg( $table_name );

			$handle = ( function_exists( 'popen' ) && function_exists( 'pclose' ) ) ? popen( $exec, 'r' ) : false;
			if ( $handle ) {
				$output = '';
				// We expect the INSERT statement in the first 100KB
				while ( ! feof( $handle ) && strlen( $output ) < 102400 ) {
					$output .= fgets( $handle, 102400 );
				}
				$ret = pclose( $handle );
				// The manual page for pclose() claims that only -1 indicates an error, but this is untrue
				if ( 0 != $ret ) {
					// Binary mysqldump: error
				} else {
					if ( false !== stripos( $output, 'insert into' ) ) {
						$result = $potsql;
						break;
					}
				}
			}
		}

		if ( file_exists( $backup_dir . '/' . $pfile ) ) {
			unlink( $backup_dir . '/' . $pfile );
		}

		return $result;
	}
}
