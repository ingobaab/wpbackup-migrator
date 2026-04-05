<?php

namespace Wpbackup\Migrator\Services\Database;

/**
 * Database utility functions
 */
class Utilities {

	/**
	 * Wrap a database identifier with backticks
	 *
	 * @param string|array $name The identifier to wrap
	 *
	 * @return string|array
	 */
	public static function backquote( $name ) {
		if ( ! empty( $name ) && '*' !== $name ) {
			if ( is_array( $name ) ) {
				$result = [];
				foreach ( $name as $key => $val ) {
					$result[ $key ] = '`' . $val . '`';
				}
				return $result;
			} else {
				return '`' . $name . '`';
			}
		}

		return $name;
	}

	/**
	 * Replace the last occurrence of a string
	 *
	 * @param string  $search         The value being searched for
	 * @param string  $replace        The replacement value
	 * @param string  $subject        The string being searched and replaced on
	 * @param boolean $case_sensitive Whether the replacement should be case sensitive
	 *
	 * @return string
	 */
	public static function str_lreplace( $search, $replace, $subject, $case_sensitive = true ) {
		$pos = $case_sensitive ? strrpos( $subject, $search ) : strripos( $subject, $search );
		if ( false !== $pos ) {
			$subject = substr_replace( $subject, $replace, $pos, strlen( $search ) );
		}
		return $subject;
	}

	/**
	 * Replace only the first occurrence of a string
	 *
	 * @param string $needle   The search term
	 * @param string $replace  The replacement term
	 * @param string $haystack The string to replace within
	 *
	 * @return string
	 */
	public static function str_replace_once( $needle, $replace, $haystack ) {
		$pos = strpos( $haystack, $needle );
		return ( false !== $pos ) ? substr_replace( $haystack, $replace, $pos, strlen( $needle ) ) : $haystack;
	}

	/**
	 * Sort tables for backup - options table first, then users, then core tables
	 *
	 * @param array  $a_arr           First table array
	 * @param array  $b_arr           Second table array
	 * @param string $table_prefix    The table prefix
	 *
	 * @return int
	 */
	public static function sort_tables( $a_arr, $b_arr, $table_prefix = '' ) {
		$a            = $a_arr['name'];
		$a_table_type = $a_arr['type'];
		$b            = $b_arr['name'];
		$b_table_type = $b_arr['type'];

		// Views must always go after tables (since they can depend upon them)
		if ( 'VIEW' === $a_table_type && 'VIEW' !== $b_table_type ) {
			return 1;
		}
		if ( 'VIEW' === $b_table_type && 'VIEW' !== $a_table_type ) {
			return -1;
		}

		if ( $a === $b ) {
			return 0;
		}

		// Priority tables
		if ( $a === $table_prefix . 'options' ) {
			return -1;
		}
		if ( $b === $table_prefix . 'options' ) {
			return 1;
		}
		if ( $a === $table_prefix . 'site' ) {
			return -1;
		}
		if ( $b === $table_prefix . 'site' ) {
			return 1;
		}
		if ( $a === $table_prefix . 'blogs' ) {
			return -1;
		}
		if ( $b === $table_prefix . 'blogs' ) {
			return 1;
		}
		if ( $a === $table_prefix . 'users' ) {
			return -1;
		}
		if ( $b === $table_prefix . 'users' ) {
			return 1;
		}
		if ( $a === $table_prefix . 'usermeta' ) {
			return -1;
		}
		if ( $b === $table_prefix . 'usermeta' ) {
			return 1;
		}

		if ( empty( $table_prefix ) ) {
			return strcmp( $a, $b );
		}

		// Core WP tables should come before plugin tables
		$core_tables = [
			'terms',
			'term_taxonomy',
			'termmeta',
			'term_relationships',
			'commentmeta',
			'comments',
			'links',
			'postmeta',
			'posts',
			'site',
			'sitemeta',
			'blogs',
			'blogversions',
			'blogmeta',
		];

		$na = self::str_replace_once( $table_prefix, '', $a );
		$nb = self::str_replace_once( $table_prefix, '', $b );

		if ( in_array( $na, $core_tables, true ) && ! in_array( $nb, $core_tables, true ) ) {
			return -1;
		}
		if ( ! in_array( $na, $core_tables, true ) && in_array( $nb, $core_tables, true ) ) {
			return 1;
		}

		return strcmp( $a, $b );
	}

	/**
	 * Set SQL mode for compatibility during backup/restore
	 *
	 * @param \wpdb $wpdb_obj The WPDB object
	 *
	 * @return void
	 */
	public static function set_sql_mode( $wpdb_obj ) {
		// Get current SQL mode
		$current_mode = $wpdb_obj->get_var( 'SELECT @@SESSION.sql_mode' );

		if ( empty( $current_mode ) ) {
			return;
		}

		// Modes to remove for compatibility
		$modes_to_remove = [
			'NO_ZERO_DATE',
			'NO_ZERO_IN_DATE',
			'STRICT_TRANS_TABLES',
			'STRICT_ALL_TABLES',
			'TRADITIONAL',
			'ONLY_FULL_GROUP_BY',
			'ANSI_QUOTES',
		];

		$modes = array_map( 'trim', explode( ',', $current_mode ) );
		$modes = array_diff( $modes, $modes_to_remove );
		$modes = array_filter( $modes );

		if ( ! empty( $modes ) ) {
			$wpdb_obj->query( "SET SESSION sql_mode='" . implode( ',', $modes ) . "'" );
		} else {
			$wpdb_obj->query( "SET SESSION sql_mode=''" );
		}
	}

	/**
	 * Convert hexadecimal to binary string
	 *
	 * @param string $hex Hexadecimal number
	 *
	 * @return string Binary representation
	 */
	public static function hex2bin( $hex ) {
		$table = [
			'0' => '0000',
			'1' => '0001',
			'2' => '0010',
			'3' => '0011',
			'4' => '0100',
			'5' => '0101',
			'6' => '0110',
			'7' => '0111',
			'8' => '1000',
			'9' => '1001',
			'a' => '1010',
			'b' => '1011',
			'c' => '1100',
			'd' => '1101',
			'e' => '1110',
			'f' => '1111',
		];

		$bin = '';

		if ( ! preg_match( '/^[0-9a-f]+$/i', $hex ) ) {
			return '';
		}

		for ( $i = 0; $i < strlen( $hex ); $i++ ) {
			$bin .= $table[ strtolower( substr( $hex, $i, 1 ) ) ];
		}

		return $bin;
	}
}
