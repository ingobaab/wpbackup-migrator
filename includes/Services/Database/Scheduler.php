<?php

namespace Wpbackup\Migrator\Services\Database;

/**
 * Job Scheduler for async backup operations
 */
class Scheduler {

	/**
	 * Cron hook name for backup resume
	 */
	const CRON_HOOK = 'wpbackup_backup_resume';

	/**
	 * Legacy hook (older plugin versions scheduled this name).
	 */
	const LEGACY_CRON_HOOK = 'flywp_backup_resume';

	/**
	 * Initialize the scheduler
	 *
	 * @return void
	 */
	public static function init() {
		add_action( self::CRON_HOOK, [ __CLASS__, 'resume_backup' ], 10, 2 );
		add_action( self::LEGACY_CRON_HOOK, [ __CLASS__, 'resume_backup' ], 10, 2 );
	}

	/**
	 * Schedule initial backup run
	 *
	 * Uses WordPress cron with immediate scheduling and triggers it
	 *
	 * @param string $nonce Job nonce.
	 *
	 * @return void
	 */
	public static function schedule_backup( $nonce ) {
		// Schedule to run immediately (time() - 1 helps to trigger it faster)
		wp_schedule_single_event( time() - 1, self::CRON_HOOK, [ 0, $nonce ] );

		// Trigger WP-Cron
		self::spawn_cron();
	}

	/**
	 * Resume backup callback (WP-Cron handler)
	 *
	 * @param int    $resumption Resumption number.
	 * @param string $nonce      Job nonce.
	 *
	 * @return void
	 */
	public static function resume_backup( $resumption, $nonce ) {
		// Increase limits
		self::set_resource_limits();

		$jobdata = new JobData( $nonce );

		// Check if job exists
		$status = $jobdata->get( 'status' );
		if ( empty( $status ) ) {
			return;
		}

		// Check if already complete or failed
		if ( in_array( $status, [ 'complete', 'failed' ], true ) ) {
			return;
		}

		// Initialize JobScheduler with this job
		JobScheduler::set_jobdata( $jobdata, $resumption );

		// Check for overlapping runs using activity detection on the backup file
		self::check_overlapping_runs( $jobdata, $resumption );

		// Update job metadata
		$jobdata->set( 'resumption', $resumption );
		$jobdata->set( 'updated_at', time() );

		// Track when this run started
		$runs_started = $jobdata->get( 'runs_started', [] );
		if ( ! is_array( $runs_started ) ) {
			$runs_started = [];
		}
		$runs_started[ $resumption ] = microtime( true );
		$jobdata->set( 'runs_started', $runs_started );

		// Run the backup
		$backup = new Backup( $jobdata );
		$backup->run_resumable( $resumption );
	}

	/**
	 * Check for overlapping backup runs
	 *
	 * @param JobData $jobdata    The job data instance.
	 * @param int     $resumption The current resumption number.
	 *
	 * @return void
	 */
	private static function check_overlapping_runs( $jobdata, $resumption ) {
		if ( 0 === $resumption ) {
			// First run - no need to check for overlaps yet
			return;
		}

		$backup_file = $jobdata->get( 'file_path' );
		if ( empty( $backup_file ) || ! file_exists( $backup_file ) ) {
			return;
		}

		$time_now = microtime( true );
		$time_mod = filemtime( $backup_file );

		// Check if file was modified within the last 30 seconds
		// This indicates another process is actively working on it
		if ( $time_now - $time_mod < 30 ) {
			JobScheduler::terminate_due_to_activity( $backup_file, $time_now, $time_mod, true );
		}

		// Also check runs_started for potential overlaps
		$runs_started = $jobdata->get( 'runs_started', [] );
		$time_passed  = $jobdata->get( 'run_times', [] );

		if ( ! is_array( $runs_started ) ) {
			$runs_started = [];
		}
		if ( ! is_array( $time_passed ) ) {
			$time_passed = [];
		}

		foreach ( $time_passed as $run => $passed ) {
			if ( isset( $runs_started[ $run ] ) && $runs_started[ $run ] + $time_passed[ $run ] + 30 > $time_now ) {
				if ( $run && $run == $resumption ) {
					$increase_resumption = false;
				} else {
					$increase_resumption = true;
				}
				JobScheduler::terminate_due_to_activity( 'check-in', $time_now, $runs_started[ $run ] + $time_passed[ $run ], $increase_resumption );
			}
		}
	}

	/**
	 * Set PHP resource limits for the backup process
	 *
	 * @return void
	 */
	private static function set_resource_limits() {
		// Increase time limit
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 900 );
		}

		// Increase memory limit
		if ( function_exists( 'ini_set' ) ) {
			@ini_set( 'memory_limit', '256M' );
		}

		// Ignore user abort
		if ( function_exists( 'ignore_user_abort' ) ) {
			@ignore_user_abort( true );
		}
	}

	/**
	 * Schedule next resumption
	 *
	 * This is now handled by JobScheduler
	 *
	 * @param int    $resumption Current resumption number.
	 * @param string $nonce      Job nonce.
	 * @param int    $delay      Delay in seconds (minimum 60).
	 *
	 * @return void
	 */
	public static function reschedule( $resumption, $nonce, $delay = 60 ) {
		// The minimum interval is now 60 seconds
		if ( $delay < 60 ) {
			$delay = 60;
		}

		// Clear any existing scheduled event
		wp_clear_scheduled_hook( self::CRON_HOOK, [ $resumption + 1, $nonce ] );
		wp_clear_scheduled_hook( self::LEGACY_CRON_HOOK, [ $resumption + 1, $nonce ] );

		$schedule_for = time() + $delay;
		wp_schedule_single_event( $schedule_for, self::CRON_HOOK, [ $resumption + 1, $nonce ] );
	}

	/**
	 * Spawn a loopback request to trigger WP-Cron
	 *
	 * Uses WordPress native spawn_cron() function which is more reliable
	 *
	 * @return bool
	 */
	public static function spawn_cron() {
		// Use WordPress native function which handles more edge cases
		if ( ! function_exists( 'spawn_cron' ) ) {
			require_once ABSPATH . WPINC . '/cron.php';
		}

		spawn_cron();

		return true;
	}

	/**
	 * Clear all scheduled events for a job
	 *
	 * @param string $nonce Job nonce.
	 *
	 * @return void
	 */
	public static function clear_scheduled( $nonce ) {
		JobScheduler::clear_all_scheduled( $nonce );
	}
}
