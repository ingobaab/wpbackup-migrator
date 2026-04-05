<?php

namespace Wpbackup\Migrator\Services\Database;

use WP_Error;

/**
 * Database Backup Service - Resumable
 */
class Backup
{

	/**
	 * Maximum time per run in seconds
	 */
	const MAX_RUN_TIME = 25;

	/**
	 * Job data handler
	 *
	 * @var JobData
	 */
	private $jobdata;

	/**
	 * Backup directory
	 *
	 * @var string
	 */
	private $backup_dir;

	/**
	 * WPDB object
	 *
	 * @var \wpdb
	 */
	private $wpdb_obj;

	/**
	 * Table prefix (raw/unfiltered)
	 *
	 * @var string
	 */
	private $table_prefix_raw;

	/**
	 * Database connection info
	 *
	 * @var array
	 */
	private $dbinfo;

	/**
	 * File handle for the backup file
	 *
	 * @var resource
	 */
	private $dbhandle;

	/**
	 * Whether the file handle is gzip
	 *
	 * @var bool
	 */
	private $dbhandle_isgz = false;

	/**
	 * Current raw bytes written
	 *
	 * @var int
	 */
	private $db_current_raw_bytes = 0;

	/**
	 * Path to mysqldump binary (or false if not available)
	 *
	 * @var string|false
	 */
	private $binsqldump = false;

	/**
	 * Whether duplicate tables with case differences exist
	 *
	 * @var bool
	 */
	private $duplicate_tables_exist = false;

	/**
	 * Start time of this run
	 *
	 * @var float
	 */
	private $run_start_time;

	/**
	 * Constructor
	 *
	 * @param JobData|null $jobdata Job data handler, or null to create new
	 */
	public function __construct($jobdata = null)
	{
		global $wpdb;

		$this->wpdb_obj         = $wpdb;
		$this->table_prefix_raw = $wpdb->prefix;
		$this->dbinfo           = [
			'host' => DB_HOST,
			'name' => DB_NAME,
			'user' => DB_USER,
			'pass' => DB_PASSWORD,
		];

		if (null === $jobdata) {
			$this->jobdata = new JobData();
		}
		else {
			$this->jobdata = $jobdata;
		}

		$this->setup_backup_dir();
	}

	/**
	 * Setup backup directory
	 *
	 * @return void
	 */
	private function setup_backup_dir()
	{
		$upload_dir = wp_upload_dir();
		$new_dir    = $upload_dir['basedir'] . '/wpbackup-migrator';
		$old_dir    = $upload_dir['basedir'] . '/flywp-migrator';
		if ( ! file_exists( $new_dir ) && is_dir( $old_dir ) ) {
			@rename( $old_dir, $new_dir );
		}
		$this->backup_dir = $new_dir;

		if ( ! file_exists( $this->backup_dir ) ) {
			wp_mkdir_p( $this->backup_dir );
		}

		// Add .htaccess for security
		$htaccess = $this->backup_dir.'/.htaccess';
		if (! file_exists($htaccess)) {
			file_put_contents($htaccess, "deny from all\n");
		}

		// Add index.php for security
		$index = $this->backup_dir.'/index.php';
		if (! file_exists($index)) {
			file_put_contents($index, '<?php // Silence is golden');
		}
	}

	/**
	 * Get the backup directory
	 *
	 * @return string
	 */
	public function get_backup_dir()
	{
		return $this->backup_dir;
	}

	/**
	 * Get the job data handler
	 *
	 * @return JobData
	 */
	public function get_jobdata()
	{
		return $this->jobdata;
	}

	/**
	 * Initialize a new backup job
	 *
	 * @return void
	 */
	public function init_job()
	{
		// Set SQL mode for compatibility
		Utilities::set_sql_mode($this->wpdb_obj);

		// Find mysqldump binary
		$this->binsqldump = MysqldumpFinder::find($this->backup_dir);

		// Get all tables
		$all_tables = $this->wpdb_obj->get_results('SHOW FULL TABLES', ARRAY_N);

		if (empty($all_tables) && ! empty($this->wpdb_obj->last_error)) {
			$all_tables = $this->wpdb_obj->get_results('SHOW TABLES', ARRAY_N);
			$all_tables = array_map(
				function ($table) {
					return ['name' => $table[0], 'type' => 'BASE TABLE'];
				},
				$all_tables
			);
		}
		else {
			$all_tables = array_map(
				function ($table) {
					return ['name' => $table[0], 'type' => isset($table[1]) ? $table[1] : 'BASE TABLE'];
				},
				$all_tables
			);
		}

		// Sort tables
		$table_prefix = $this->table_prefix_raw;
		usort(
			$all_tables,
			function ($a, $b) use ($table_prefix) {
				return Utilities::sort_tables($a, $b, $table_prefix);
			}
		);

		// Check for duplicate tables
		$all_table_names = array_map(
			function ($t) {
				return $t['name'];
			},
			$all_tables
		);
		foreach ($all_table_names as $table) {
			if (strtolower($table) !== $table && in_array(strtolower($table), $all_table_names, true)) {
				$this->duplicate_tables_exist = true;
				break;
			}
		}

		// Generate backup file path
		$backup_file = $this->backup_dir.'/db-'.$this->jobdata->nonce.'.sql.gz';

		// Initialize job data
		$this->jobdata->set_multi(
			[
				'status'                 => 'running',
				'started_at'             => time(),
				'updated_at'             => time(),
				'binsqldump'             => $this->binsqldump,
				'all_tables'             => $all_tables,
				'total_tables'           => count($all_tables),
				'current_table_index'    => 0,
				'current_table'          => null,
				'file_path'              => $backup_file,
				'header_written'         => false,
				'footer_written'         => false,
				'duplicate_tables_exist' => $this->duplicate_tables_exist,
				'fetch_rows'             => 1000,
				'bytes_written'          => 0,
			]
		);
	}

	/**
	 * Run backup in resumable mode (called by cron)
	 *
	 * @param int $resumption Resumption number
	 *
	 * @return void
	 */
	public function run_resumable($resumption = 0)
	{
		$this->run_start_time = microtime(true);

		// Load saved state
		$this->binsqldump             = $this->jobdata->get('binsqldump');
		$this->duplicate_tables_exist = $this->jobdata->get('duplicate_tables_exist', false);

		$all_tables          = $this->jobdata->get('all_tables', []);
		$current_table_index = $this->jobdata->get('current_table_index', 0);
		$backup_file         = $this->jobdata->get('file_path');
		$header_written      = $this->jobdata->get('header_written', false);

		// Check if we have tables to process
		if (empty($all_tables)) {
			$this->jobdata->set('status', 'failed');
			$this->jobdata->set('error', 'No tables to backup');
			$this->backup_finish();
			return;
		}

		// Open the backup file (append mode if header already written)
		if (false === $this->backup_db_open($backup_file, true, $header_written)) {
			$this->jobdata->set('status', 'failed');
			$this->jobdata->set('error', 'Could not open backup file');
			$this->backup_finish();
			return;
		}

		// Write header if not yet written
		if (! $header_written) {
			$this->backup_db_header();
			$this->jobdata->set('header_written', true);
			// Indicate useful progress
			JobScheduler::something_useful_happened();
		}

		// Process tables until we run out of time
		$total_tables = count($all_tables);

		while ($current_table_index < $total_tables) {
			// Check if we're running out of time
			if ($this->should_reschedule()) {
				$this->backup_db_close();
				$this->jobdata->set('current_table_index', $current_table_index);
				$this->jobdata->set('updated_at', time());
				// Use JobScheduler for rescheduling
				JobScheduler::reschedule(60);
				return;
			}

			$ti         = $all_tables[$current_table_index];
			$table      = $ti['name'];
			$table_type = $ti['type'];

			// Filter tables by prefix
			if (! empty($this->table_prefix_raw)) {
				if (! $this->duplicate_tables_exist && 0 !== stripos($table, $this->table_prefix_raw)) {
					$current_table_index++;
					continue;
				}
				if ($this->duplicate_tables_exist && 0 !== strpos($table, $this->table_prefix_raw)) {
					$current_table_index++;
					continue;
				}
			}

			$this->jobdata->set('current_table', $table);

			// Try mysqldump first, fall back to PHP
			$dump_result = false;
			if ($this->binsqldump && 'VIEW' !== $table_type) {
				$dump_result = $this->backup_table_bindump($this->binsqldump, $table);
			}

			if (! $dump_result) {
				$this->backup_table($table, $table_type);
			}

			$current_table_index++;
			$this->jobdata->set('current_table_index', $current_table_index);

			// Indicate useful progress after each table
			JobScheduler::something_useful_happened();
		}

		// All tables done - write footer
		if (! $this->jobdata->get('footer_written', false)) {
			$this->backup_triggers();
			$this->backup_routines();
			$this->backup_db_footer();
			$this->jobdata->set('footer_written', true);
		}

		$this->backup_db_close();

		// Mark as complete
		$this->jobdata->set('status', 'complete');
		$this->jobdata->set('updated_at', time());
		$this->jobdata->set('bytes_written', filesize($backup_file));

		// Finish the backup - clear scheduled events
		$this->backup_finish();
	}

	/**
	 * Finish the backup process and clean up
	 *
	 * @return void
	 */
	private function backup_finish() {
		$status = $this->jobdata->get('status');

		// Clear all future scheduled events for this job
		if (in_array($status, ['complete', 'failed'], true)) {
			JobScheduler::clear_all_scheduled($this->jobdata->nonce);
		}
	}

	/**
	 * Check if we should reschedule (running out of time)
	 *
	 * @return bool
	 */
	private function should_reschedule()
	{
		$elapsed = microtime(true) - $this->run_start_time;
		return $elapsed > self::MAX_RUN_TIME;
	}

	/**
	 * Open the backup file
	 *
	 * @param string $file      Full path to the file to open
	 * @param bool   $allow_gz  Use gzopen() if available
	 * @param bool   $append    Use append mode
	 *
	 * @return resource|false
	 */
	private function backup_db_open($file, $allow_gz = true, $append = false)
	{
		$mode = $append ? 'ab' : 'wb';

		if ($allow_gz && function_exists('gzopen')) {
			$this->dbhandle      = gzopen($file, $mode);
			$this->dbhandle_isgz = true;
		}
		else {
			$this->dbhandle      = fopen($file, $mode);
			$this->dbhandle_isgz = false;
		}

		if (false === $this->dbhandle) {
			return false;
		}

		$this->db_current_raw_bytes = 0;
		return $this->dbhandle;
	}

	/**
	 * Close the backup file
	 *
	 * @return bool
	 */
	private function backup_db_close()
	{
		if (! $this->dbhandle) {
			return true;
		}
		return $this->dbhandle_isgz ? gzclose($this->dbhandle) : fclose($this->dbhandle);
	}

	/**
	 * Write a line to the backup file
	 *
	 * @param string $write_line The line to write
	 *
	 * @return int|false
	 */
	private function stow($write_line)
	{
		if ('' === $write_line) {
			return 0;
		}

		$write_function = $this->dbhandle_isgz ? 'gzwrite' : 'fwrite';
		$ret            = call_user_func($write_function, $this->dbhandle, $write_line);

		$this->db_current_raw_bytes += strlen($write_line);

		return $ret;
	}

	/**
	 * Write the backup header
	 *
	 * @return void
	 */
	private function backup_db_header()
	{
		$wp_version    = get_bloginfo('version');
		$mysql_version = $this->wpdb_obj->get_var('SELECT VERSION()');
		if ('' == $mysql_version) {
			$mysql_version = $this->wpdb_obj->db_version();
		}

		$wp_upload_dir = wp_upload_dir();

		$this->stow("# WordPress MySQL database backup\n");
		$this->stow("# Created by WPBackup Migrator\n");
		$this->stow("# WordPress Version: $wp_version, running on PHP ".phpversion().", MySQL $mysql_version\n");
		$this->stow('# Backup of: '.untrailingslashit(site_url())."\n");
		$this->stow('# Home URL: '.untrailingslashit(home_url())."\n");
		$this->stow('# Content URL: '.untrailingslashit(content_url())."\n");
		$this->stow('# Uploads URL: '.untrailingslashit($wp_upload_dir['baseurl'])."\n");
		$this->stow('# Table prefix: '.$this->table_prefix_raw."\n");
		$this->stow('# ABSPATH: '.trailingslashit(ABSPATH)."\n");
		$this->stow('# Site info: multisite='.(is_multisite() ? '1' : '0')."\n");
		$this->stow("# Site info: end\n");

		$this->stow("\n# Generated: ".date('l j. F Y H:i T')."\n");
		$this->stow('# Hostname: '.$this->dbinfo['host']."\n");
		$this->stow('# Database: '.Utilities::backquote($this->dbinfo['name'])."\n");

		$this->stow("# --------------------------------------------------------\n\n");

		$this->stow("/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;\n");
		$this->stow("/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;\n");
		$this->stow("/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;\n");
		$this->stow("/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;\n");
		$this->stow("/*!40101 SET NAMES utf8mb4 */;\n");
		$this->stow("/*!40101 SET foreign_key_checks = 0 */;\n\n");
	}

	/**
	 * Write the backup footer
	 *
	 * @return void
	 */
	private function backup_db_footer()
	{
		$this->stow("\n/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;\n");
		$this->stow("/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;\n");
		$this->stow("/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;\n");
		$this->stow("/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;\n");
	}

	/**
	 * Backup a table using mysqldump
	 *
	 * @param string $potsql     Path to mysqldump binary
	 * @param string $table_name Table name
	 *
	 * @return bool
	 */
	private function backup_table_bindump($potsql, $table_name)
	{
		$pfile = md5(time().rand()).'.tmp';
		file_put_contents($this->backup_dir.'/'.$pfile, "[mysqldump]\npassword=\"".addslashes($this->dbinfo['pass'])."\"\n");

		if ('win' === strtolower(substr(PHP_OS, 0, 3))) {
			$exec = 'cd '.escapeshellarg(str_replace('/', '\\', $this->backup_dir)).' & ';
		}
		else {
			$exec = 'cd '.escapeshellarg($this->backup_dir).'; ';
		}

		$msqld_max_allowed_packet = '64M';
		if ( defined( 'WPBACKUP_MYSQLDUMP_MAX_ALLOWED_PACKET' ) && ( is_int( WPBACKUP_MYSQLDUMP_MAX_ALLOWED_PACKET ) || is_string( WPBACKUP_MYSQLDUMP_MAX_ALLOWED_PACKET ) ) ) {
			$msqld_max_allowed_packet = WPBACKUP_MYSQLDUMP_MAX_ALLOWED_PACKET;
		} elseif ( defined( 'FLYWP_MYSQLDUMP_MAX_ALLOWED_PACKET' ) && ( is_int( FLYWP_MYSQLDUMP_MAX_ALLOWED_PACKET ) || is_string( FLYWP_MYSQLDUMP_MAX_ALLOWED_PACKET ) ) ) {
			$msqld_max_allowed_packet = FLYWP_MYSQLDUMP_MAX_ALLOWED_PACKET;
		}

		$exec .= "$potsql --defaults-file=$pfile --max-allowed-packet=$msqld_max_allowed_packet --quote-names --add-drop-table";

		$mysql_version = $this->wpdb_obj->get_var('SELECT VERSION()');
		if (empty($mysql_version)) {
			$mysql_version = $this->wpdb_obj->db_version();
		}
		if ($mysql_version && version_compare($mysql_version, '5.1', '>=')) {
			$exec .= ' --no-tablespaces';
		}

		$exec .= ' --skip-comments --skip-set-charset --allow-keywords --dump-date --extended-insert --user='.escapeshellarg($this->dbinfo['user']).' ';

		$host = $this->dbinfo['host'];
		if (preg_match('#^(.*):(\d+)$#', $host, $matches)) {
			$exec .= '--host='.escapeshellarg($matches[1]).' --port='.escapeshellarg($matches[2]).' ';
		}
		elseif (preg_match('#^(.*):(.*)$#', $host, $matches) && file_exists($matches[2])) {
			$exec .= '--host='.escapeshellarg($matches[1]).' --socket='.escapeshellarg($matches[2]).' ';
		}
		else {
			$exec .= '--host='.escapeshellarg($host).' ';
		}

		$exec .= $this->dbinfo['name'].' '.escapeshellarg($table_name);

		$ret        = false;
		$any_output = false;
		$gtid_found = false;
		$handle     = (function_exists('popen') && function_exists('pclose')) ? popen($exec, 'r') : false;

		if ($handle) {
			while (! feof($handle)) {
				$w = fgets($handle, 1048576);
				if (is_string($w) && $w) {
					if (preg_match('/^SET @@GLOBAL.GTID_PURGED/i', $w)) {
						$gtid_found = true;
					}

					if ($gtid_found) {
						if (false !== strpos($w, ';')) {
							$gtid_found = false;
						}
						continue;
					}

					$this->stow($w);
					$any_output = true;
				}
			}
			$ret_code = pclose($handle);
			if (0 !== $ret_code) {
				$ret = false;
			}
			else {
				if ($any_output) {
					$ret = true;
				}
			}
		}

		@unlink($this->backup_dir.'/'.$pfile);

		return $ret;
	}

	/**
	 * Backup a table using PHP
	 *
	 * @param string $table      Table name
	 * @param string $table_type Table type
	 *
	 * @return void
	 */
	private function backup_table($table, $table_type = 'BASE TABLE')
	{
		$dump_as_table = (false === $this->duplicate_tables_exist && 0 === stripos($table, $this->table_prefix_raw) && 0 !== strpos($table, $this->table_prefix_raw)) ? $this->table_prefix_raw.substr($table, strlen($this->table_prefix_raw)) : $table;

		$table_structure = $this->wpdb_obj->get_results('DESCRIBE '.Utilities::backquote($table));
		if (! $table_structure) {
			return;
		}

		$this->write_table_backup_beginning($table, $dump_as_table, $table_type, $table_structure);

		if ('VIEW' === $table_type) {
			$this->stow("\n# End of data contents of table ".Utilities::backquote($table)."\n\n");
			return;
		}

		$integer_fields   = [];
		$binary_fields    = [];
		$bit_fields       = [];
		$primary_key      = false;
		$primary_key_type = '';

		foreach ($table_structure as $struct) {
			if (isset($struct->Key) && 'PRI' === $struct->Key && '' !== $struct->Field) {
				$primary_key      = (false === $primary_key) ? $struct->Field : null;
				$primary_key_type = $struct->Type;
			}

			$type_lower = strtolower($struct->Type);
			if (preg_match('/^(tiny|small|medium|big)?int/i', $type_lower)) {
				$integer_fields[strtolower($struct->Field)] = true;
			}

			if (preg_match('/^(binary|varbinary|tinyblob|mediumblob|blob|longblob)/i', $type_lower)) {
				$binary_fields[strtolower($struct->Field)] = true;
			}

			if (preg_match('/^bit(?:\(([0-9]+)\))?$/i', trim($struct->Type), $matches)) {
				$bit_fields[strtolower($struct->Field)] = ! empty($matches[1]) ? max(1, (int) $matches[1]) : 1;
			}
		}

		$use_primary_key = false;
		if (is_string($primary_key) && preg_match('#^(small|medium|big)?int(\(| |$)#i', $primary_key_type)) {
			$use_primary_key = true;
		}

		$fetch_rows   = $this->jobdata->get('fetch_rows', 1000);
		$start_record = $use_primary_key ? -1 : 0;

		$search  = ["\x00", "\x0a", "\x0d", "\x1a"];
		$replace = ['\0', '\n', '\r', '\Z'];

		do {
			if ($use_primary_key) {
				$pk_condition = Utilities::backquote($primary_key).((-1 === $start_record) ? ' >= 0' : " > $start_record");
				$table_data   = $this->wpdb_obj->get_results(
					'SELECT * FROM '.Utilities::backquote($table).
					" WHERE $pk_condition ORDER BY ".Utilities::backquote($primary_key).
					" ASC LIMIT $fetch_rows",
					ARRAY_A
				);
			}
			else {
				$table_data = $this->wpdb_obj->get_results(
					'SELECT * FROM '.Utilities::backquote($table).
					" LIMIT $start_record, $fetch_rows",
					ARRAY_A
				);
			}

			if (empty($table_data)) {
				break;
			}

			$entries = 'INSERT INTO '.Utilities::backquote($dump_as_table).' VALUES ';

			$this_entry = '';
			foreach ($table_data as $row) {
				if ($this_entry) {
					$this_entry .= ",\n ";
				}
				$this_entry .= '(';
				$key_count   = 0;

				foreach ($row as $key => $value) {
					if ($key_count) {
						$this_entry .= ', ';
					}
					$key_count++;

					if ($use_primary_key && strtolower($primary_key) === strtolower($key) && $value > $start_record) {
						$start_record = $value;
					}

					if (isset($integer_fields[strtolower($key)])) {
						$value       = (null === $value || '' === $value) ? 'NULL' : $value;
						$value       = ('' === $value) ? "''" : $value;
						$this_entry .= $value;
					}
					elseif (isset($binary_fields[strtolower($key)])) {
						if (null === $value) {
							$this_entry .= 'NULL';
						}
						elseif ('' === $value) {
							$this_entry .= "''";
						}
						else {
							$this_entry .= '0x'.bin2hex($value);
						}
					}
					elseif (isset($bit_fields[strtolower($key)])) {
						if (null === $value) {
							$this_entry .= 'NULL';
						}
						else {
							mbstring_binary_safe_encoding();
							$val_len = is_string($value) ? strlen($value) : 0;
							reset_mbstring_encoding();
							$hex = '';
							for ($i = 0; $i < $val_len; $i++) {
								$hex .= sprintf('%02X', ord($value[$i]));
							}
							$this_entry .= "b'".str_pad(Utilities::hex2bin($hex), $bit_fields[strtolower($key)], '0', STR_PAD_LEFT)."'";
						}
					}
					else {
						$this_entry .= (null === $value) ? 'NULL' : "'".str_replace($search, $replace, str_replace("'", "\\'", str_replace('\\', '\\\\', $value)))."'";
					}
				}
				$this_entry .= ')';

				if (strlen($this_entry) > 524288) {
					$this_entry .= ';';
					$this->stow(" \n".$entries.$this_entry);
					$this_entry = '';
				}
			}

			if ($this_entry) {
				$this_entry .= ';';
				$this->stow(" \n".$entries.$this_entry);
			}

			if (! $use_primary_key) {
				$start_record += $fetch_rows;
			}
		} while (count($table_data) > 0);

		$this->stow("\n# End of data contents of table ".Utilities::backquote($table)."\n\n");
	}

	/**
	 * Write the table structure
	 *
	 * @param string $table           Full table name
	 * @param string $dump_as_table   Table name to dump as
	 * @param string $table_type      Table type
	 * @param array  $table_structure Table structure from DESCRIBE
	 *
	 * @return void
	 */
	private function write_table_backup_beginning($table, $dump_as_table, $table_type, $table_structure)
	{
		$this->stow("\n# Delete any existing table ".Utilities::backquote($table)."\n\nDROP TABLE IF EXISTS ".Utilities::backquote($dump_as_table).";\n");

		if ('VIEW' === $table_type) {
			$this->stow('DROP VIEW IF EXISTS '.Utilities::backquote($dump_as_table).";\n");
		}

		$description = ('VIEW' === $table_type) ? 'view' : 'table';

		$this->stow("\n# Table structure of $description ".Utilities::backquote($table)."\n\n");

		$create_table = $this->wpdb_obj->get_results('SHOW CREATE TABLE '.Utilities::backquote($table), ARRAY_N);
		if (false === $create_table) {
			$this->stow("#\n# Error with SHOW CREATE TABLE for $table\n#\n");
		}
		$create_line = Utilities::str_lreplace('TYPE=', 'ENGINE=', $create_table[0][1]);

		if (preg_match('/ENGINE=([^\s;]+)/', $create_line, $eng_match)) {
			$engine = $eng_match[1];
			if ('myisam' === strtolower($engine)) {
				$create_line = preg_replace('/PAGE_CHECKSUM=\d\s?/', '', $create_line, 1);
			}
		}

		if ($dump_as_table !== $table) {
			$create_line = Utilities::str_replace_once($table, $dump_as_table, $create_line);
		}

		$this->stow($create_line.' ;');

		if (false === $table_structure) {
			$this->stow("#\n# Error getting $description structure of $table\n#\n");
		}

		$this->stow("\n\n# ".sprintf("Data contents of $description %s", Utilities::backquote($table))."\n\n");
	}

	/**
	 * Backup database triggers
	 *
	 * @return void
	 */
	private function backup_triggers()
	{
		$triggers = $this->wpdb_obj->get_results('SHOW TRIGGERS', ARRAY_A);
		if (empty($triggers)) {
			return;
		}

		$this->stow("\n# Triggers\n\n");
		$this->stow("DELIMITER ;;\n\n");

		foreach ($triggers as $trigger) {
			$trigger_name = $trigger['Trigger'];
			$create       = $this->wpdb_obj->get_row('SHOW CREATE TRIGGER '.Utilities::backquote($trigger_name), ARRAY_N);
			if ($create && isset($create[2])) {
				$this->stow('DROP TRIGGER IF EXISTS '.Utilities::backquote($trigger_name).";;\n");
				$this->stow($create[2].";;\n\n");
			}
		}

		$this->stow("DELIMITER ;\n\n");
	}

	/**
	 * Backup stored routines
	 *
	 * @return void
	 */
	private function backup_routines()
	{
		$db_name = $this->dbinfo['name'];

		$procedures = $this->wpdb_obj->get_results(
			$this->wpdb_obj->prepare('SHOW PROCEDURE STATUS WHERE Db = %s', $db_name),
			ARRAY_A
		);

		if (! empty($procedures)) {
			$this->stow("\n# Stored Procedures\n\n");
			$this->stow("DELIMITER ;;\n\n");

			foreach ($procedures as $proc) {
				$proc_name = $proc['Name'];
				$create    = $this->wpdb_obj->get_row('SHOW CREATE PROCEDURE '.Utilities::backquote($proc_name), ARRAY_N);
				if ($create && isset($create[2])) {
					$this->stow('DROP PROCEDURE IF EXISTS '.Utilities::backquote($proc_name).";;\n");
					$this->stow($create[2].";;\n\n");
				}
			}

			$this->stow("DELIMITER ;\n\n");
		}

		$functions = $this->wpdb_obj->get_results(
			$this->wpdb_obj->prepare('SHOW FUNCTION STATUS WHERE Db = %s', $db_name),
			ARRAY_A
		);

		if (! empty($functions)) {
			$this->stow("\n# Stored Functions\n\n");
			$this->stow("DELIMITER ;;\n\n");

			foreach ($functions as $func) {
				$func_name = $func['Name'];
				$create    = $this->wpdb_obj->get_row('SHOW CREATE FUNCTION '.Utilities::backquote($func_name), ARRAY_N);
				if ($create && isset($create[2])) {
					$this->stow('DROP FUNCTION IF EXISTS '.Utilities::backquote($func_name).";;\n");
					$this->stow($create[2].";;\n\n");
				}
			}

			$this->stow("DELIMITER ;\n\n");
		}
	}
}
