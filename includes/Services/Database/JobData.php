<?php

namespace Wpbackup\Migrator\Services\Database;

/**
 * Database backup job data management
 */
class JobData {

	/**
	 * Option prefix for job data
	 */
	const OPTION_PREFIX = 'wpbackup_jobdata_';

	/**
	 * Legacy prefix (plugin was previously named flywp-migrator).
	 */
	const LEGACY_OPTION_PREFIX = 'flywp_jobdata_';

	/**
	 * Job nonce (12 character hex string)
	 *
	 * @var string
	 */
	public $nonce;

	/**
	 * Job data cache
	 *
	 * @var array|null
	 */
	private $jobdata = null;

	/**
	 * Whether $jobdata has been read from the database for this instance.
	 *
	 * @var bool
	 */
	private $loaded = false;

	/**
	 * Constructor
	 *
	 * @param string|false $nonce Job nonce, or false to generate a new one
	 */
	public function __construct( $nonce = false ) {
		if ( false === $nonce ) {
			// Generate new nonce
			$nonce = substr( md5( time() . rand() ), 20 );
		}
		$this->nonce = $nonce;
	}

	/**
	 * Load job row from the database, migrating legacy options if present.
	 *
	 * @return array
	 */
	private function fetch_jobdata_array() {
		if ( empty( $this->nonce ) ) {
			return [];
		}
		$data = get_site_option( self::OPTION_PREFIX . $this->nonce, [] );
		if ( is_array( $data ) && [] !== $data ) {
			return $data;
		}
		$legacy = get_site_option( self::LEGACY_OPTION_PREFIX . $this->nonce, [] );
		if ( is_array( $legacy ) && [] !== $legacy ) {
			update_site_option( self::OPTION_PREFIX . $this->nonce, $legacy );
			delete_site_option( self::LEGACY_OPTION_PREFIX . $this->nonce );
			return $legacy;
		}
		return is_array( $data ) ? $data : [];
	}

	/**
	 * Get a job data value
	 *
	 * @param string $key     Data key.
	 * @param mixed  $default Default value.
	 *
	 * @return mixed
	 */
	public function get( $key, $default = null ) {
		if ( ! $this->loaded ) {
			$this->jobdata = $this->fetch_jobdata_array();
			$this->loaded  = true;
		}
		if ( ! is_array( $this->jobdata ) ) {
			return $default;
		}
		return isset( $this->jobdata[ $key ] ) ? $this->jobdata[ $key ] : $default;
	}

	/**
	 * Set a job data value
	 *
	 * @param string $key   Data key.
	 * @param mixed  $value Value.
	 *
	 * @return void
	 */
	public function set( $key, $value ) {
		if ( ! $this->loaded ) {
			$this->jobdata = $this->fetch_jobdata_array();
			$this->loaded  = true;
		}
		if ( ! is_array( $this->jobdata ) ) {
			$this->jobdata = [];
		}
		$this->jobdata[ $key ] = $value;
		if ( $this->nonce ) {
			update_site_option( self::OPTION_PREFIX . $this->nonce, $this->jobdata );
			delete_site_option( self::LEGACY_OPTION_PREFIX . $this->nonce );
		}
	}

	/**
	 * Set multiple job data values at once
	 *
	 * @param array $data Key-value pairs to set.
	 *
	 * @return void
	 */
	public function set_multi( $data ) {
		if ( ! $this->loaded ) {
			$this->jobdata = $this->fetch_jobdata_array();
			$this->loaded  = true;
		}
		if ( ! is_array( $this->jobdata ) ) {
			$this->jobdata = [];
		}

		foreach ( $data as $key => $value ) {
			$this->jobdata[ $key ] = $value;
		}

		if ( ! empty( $this->nonce ) ) {
			update_site_option( self::OPTION_PREFIX . $this->nonce, $this->jobdata );
			delete_site_option( self::LEGACY_OPTION_PREFIX . $this->nonce );
		}
	}

	/**
	 * Delete a job data key
	 *
	 * @param string $key Data key.
	 *
	 * @return void
	 */
	public function delete( $key ) {
		if ( ! $this->loaded ) {
			$this->jobdata = $this->fetch_jobdata_array();
			$this->loaded  = true;
		}
		if ( ! is_array( $this->jobdata ) ) {
			$this->jobdata = [];
		}
		unset( $this->jobdata[ $key ] );
		if ( $this->nonce ) {
			update_site_option( self::OPTION_PREFIX . $this->nonce, $this->jobdata );
			delete_site_option( self::LEGACY_OPTION_PREFIX . $this->nonce );
		}
	}

	/**
	 * Get all job data as array
	 *
	 * @param string|null $job_id Optional job ID, uses current nonce if not provided.
	 *
	 * @return array
	 */
	public function get_array( $job_id = null ) {
		if ( null === $job_id ) {
			$job_id = $this->nonce;
		}
		$data = get_site_option( self::OPTION_PREFIX . $job_id, [] );
		if ( is_array( $data ) && [] !== $data ) {
			return $data;
		}
		$legacy = get_site_option( self::LEGACY_OPTION_PREFIX . $job_id, [] );
		if ( is_array( $legacy ) && [] !== $legacy ) {
			update_site_option( self::OPTION_PREFIX . $job_id, $legacy );
			delete_site_option( self::LEGACY_OPTION_PREFIX . $job_id );
			return $legacy;
		}
		return is_array( $data ) ? $data : [];
	}

	/**
	 * Set job data from array
	 *
	 * @param array $array Data array.
	 *
	 * @return void
	 */
	public function set_from_array( $array ) {
		$this->jobdata = $array;
		$this->loaded  = true;
		if ( ! empty( $this->nonce ) ) {
			update_site_option( self::OPTION_PREFIX . $this->nonce, $this->jobdata );
			delete_site_option( self::LEGACY_OPTION_PREFIX . $this->nonce );
		}
	}

	/**
	 * Reset job data (force re-fetch from database)
	 *
	 * @return void
	 */
	public function reset() {
		$this->jobdata = null;
		$this->loaded  = false;
	}

	/**
	 * Delete all job data from database
	 *
	 * @return bool
	 */
	public function delete_all() {
		$this->jobdata = [];
		$this->loaded   = true;
		delete_site_option( self::LEGACY_OPTION_PREFIX . $this->nonce );
		return delete_site_option( self::OPTION_PREFIX . $this->nonce );
	}

	/**
	 * Get the job nonce
	 *
	 * @return string
	 */
	public function get_nonce() {
		return $this->nonce;
	}
}
