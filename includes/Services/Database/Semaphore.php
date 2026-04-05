<?php

namespace Wpbackup\Migrator\Services\Database;

/**
 * Simple semaphore implementation for preventing overlapping backup jobs
 *
 * Example of use:
 * $semaphore = new Semaphore('my_lock_name', 300);
 * if ($semaphore->lock(2)) {
 *     try {
 *         // do stuff ...
 *     } catch (Exception $e) {
 *         // exception handling
 *     }
 *     $semaphore->release();
 * } else {
 *     // could not get the lock
 * }
 */
class Semaphore {

	/**
	 * Time after which the lock will expire (in seconds)
	 *
	 * @var int
	 */
	protected $locked_for;

	/**
	 * Name for the lock in the WP options table
	 *
	 * @var string
	 */
	protected $option_name;

	/**
	 * Lock status - a boolean
	 *
	 * @var bool
	 */
	protected $acquired = false;

	/**
	 * Constructor. Instantiating does not lock anything, but sets up the details for future operations.
	 *
	 * @param string $name       Unique name for the lock. Should be no more than 51 characters.
	 * @param int    $locked_for Time (in seconds) after which the lock will expire if not released.
	 */
	public function __construct( $name, $locked_for = 300 ) {
		$this->option_name = 'wpbackup_lock_' . $name;
		$this->locked_for  = $locked_for;
	}

	/**
	 * Internal function to make sure that the lock is set up in the database
	 *
	 * @return int 0 means 'failed', 1 means 'already existed', 2 means 'exists because we created it'
	 */
	private function ensure_database_initialised() {
		global $wpdb;

		$sql = $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name = %s", $this->option_name );

		if ( 1 === (int) $wpdb->get_var( $sql ) ) {
			return 1;
		}

		$sql          = $wpdb->prepare( "INSERT INTO {$wpdb->options} (option_name, option_value, autoload) VALUES(%s, '0', 'no');", $this->option_name );
		$rows_affected = $wpdb->query( $sql );

		return ( $rows_affected > 0 ) ? 2 : 0;
	}

	/**
	 * Attempt to acquire the lock. If it was already acquired, then nothing extra will be done.
	 *
	 * @param int $retries How many times to retry (after a 1 second sleep each time)
	 *
	 * @return bool Whether the lock was successfully acquired or not
	 */
	public function lock( $retries = 0 ) {
		if ( $this->acquired ) {
			return true;
		}

		global $wpdb;

		$time_now      = time();
		$acquire_until = $time_now + $this->locked_for;

		$sql = $wpdb->prepare(
			"UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value < %d",
			$acquire_until,
			$this->option_name,
			$time_now
		);

		if ( 1 === $wpdb->query( $sql ) ) {
			$this->acquired = true;
			return true;
		}

		// See if the failure was caused by the row not existing
		if ( ! $this->ensure_database_initialised() ) {
			return false;
		}

		do {
			// Now that the row has been created, try again
			if ( 1 === $wpdb->query( $sql ) ) {
				$this->acquired = true;
				return true;
			}
			$retries--;
			if ( $retries >= 0 ) {
				sleep( 1 );
				// As a second has passed, update the time we are aiming for
				$time_now      = time();
				$acquire_until = $time_now + $this->locked_for;
				$sql           = $wpdb->prepare(
					"UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value < %d",
					$acquire_until,
					$this->option_name,
					$time_now
				);
			}
		} while ( $retries >= 0 );

		return false;
	}

	/**
	 * Release the lock
	 *
	 * N.B. We don't attempt to unlock it unless we locked it.
	 *
	 * @return bool If it returns false, then the lock was apparently not locked by us
	 */
	public function release() {
		if ( ! $this->acquired ) {
			return false;
		}

		global $wpdb;
		$sql   = $wpdb->prepare( "UPDATE {$wpdb->options} SET option_value = '0' WHERE option_name = %s", $this->option_name );
		$result = (int) $wpdb->query( $sql ) === 1;

		$this->acquired = false;

		return $result;
	}

	/**
	 * Cleans up the DB of any residual data. This should not be used as part of ordinary unlocking.
	 */
	public function delete() {
		$this->acquired = false;

		global $wpdb;
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s", $this->option_name ) );
	}

	/**
	 * Check if the lock is currently acquired by this instance
	 *
	 * @return bool
	 */
	public function is_acquired() {
		return $this->acquired;
	}
}
