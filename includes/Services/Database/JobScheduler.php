<?php

namespace Wpbackup\Migrator\Services\Database;

/**
 * Job Scheduler for managing backup job resumption and timing
 *
 */
class JobScheduler {

	/**
	 * Default initial resume interval in seconds
	 */
	const DEFAULT_RESUME_INTERVAL = 100;

	/**
	 * Minimum resume interval in seconds
	 */
	const MIN_RESUME_INTERVAL = 60;

	/**
	 * Maximum resume interval in seconds (for overlap cases)
	 */
	const MAX_RESUME_INTERVAL_OVERLAP = 900;

	/**
	 * The job data instance
	 *
	 * @var JobData
	 */
	private static $jobdata;

	/**
	 * Current resumption number
	 *
	 * @var int
	 */
	private static $current_resumption = 0;

	/**
	 * When the backup process started (microtime)
	 *
	 * @var float
	 */
	private static $opened_log_time = 0;

	/**
	 * Whether something useful happened during this run
	 *
	 * @var bool
	 */
	private static $something_useful_happened = false;

	/**
	 * Timestamp when the next resumption is scheduled
	 *
	 * @var int|false
	 */
	private static $newresumption_scheduled = false;

	/**
	 * Set the job data instance for this run
	 *
	 * @param JobData $jobdata The job data instance
	 * @param int     $resumption The current resumption number
	 *
	 * @return void
	 */
	public static function set_jobdata( $jobdata, $resumption = 0 ) {
		self::$jobdata            = $jobdata;
		self::$current_resumption = $resumption;
		self::$opened_log_time    = microtime( true );
		self::$something_useful_happened = false;

		// Initialize resume interval if not set
		$resume_interval = $jobdata->get( 'resume_interval' );
		if ( empty( $resume_interval ) ) {
			$jobdata->set( 'resume_interval', self::get_initial_resume_interval() );
		}
	}

	/**
	 * Get the initial resume interval from transient or default
	 *
	 * @return int
	 */
	public static function get_initial_resume_interval() {
		$interval = get_site_transient( 'wpbackup_initial_resume_interval' );
		if ( ! $interval || ! is_numeric( $interval ) ) {
			$interval = get_site_transient( 'flywp_initial_resume_interval' );
		}
		if ( $interval && is_numeric( $interval ) ) {
			return (int) $interval;
		}

		return self::DEFAULT_RESUME_INTERVAL;
	}

	/**
	 * Record that the backup process is still alive
	 *
	 * This function is purely for timing - we just want to know the maximum run-time.
	 * It will also run a check on whether the resumption interval is being approached.
	 *
	 * @return void
	 */
	public static function record_still_alive() {
		if ( ! self::$jobdata ) {
			return;
		}

		// Update the record of maximum detected runtime on each run
		$time_passed = self::$jobdata->get( 'run_times', [] );
		if ( ! is_array( $time_passed ) ) {
			$time_passed = [];
		}

		$time_this_run                         = microtime( true ) - self::$opened_log_time;
		$time_passed[ self::$current_resumption ] = $time_this_run;
		self::$jobdata->set( 'run_times', $time_passed );

		$resume_interval = self::$jobdata->get( 'resume_interval' );
		if ( $time_this_run + 30 > $resume_interval ) {
			$new_interval = ceil( $time_this_run + 30 );
			set_site_transient( 'wpbackup_initial_resume_interval', (int) $new_interval, 8 * 86400 );
			self::$jobdata->set( 'resume_interval', $new_interval );
		}
	}

	/**
	 * Check if we need to reschedule based on timing
	 *
	 * This method helps with efficient scheduling
	 *
	 * @return void
	 */
	public static function reschedule_if_needed() {
		if ( ! self::$jobdata ) {
			return;
		}

		// If nothing is scheduled, then no re-scheduling is needed
		if ( empty( self::$newresumption_scheduled ) ) {
			return;
		}

		$time_away = self::$newresumption_scheduled - time();

		// 45 is chosen because it is 15 seconds more than what is used to detect recent activity on files
		if ( $time_away > 1 && $time_away <= 45 ) {
			// Increase interval generally by 45 seconds
			self::increase_resume_and_reschedule( 45 );
		}
	}

	/**
	 * Indicate that something useful happened
	 *
	 * Calling this at appropriate times is an important part of scheduling decisions.
	 *
	 * @return void
	 */
	public static function something_useful_happened() {
		if ( ! self::$jobdata ) {
			return;
		}

		self::record_still_alive();

		if ( ! self::$something_useful_happened ) {
			// Update the record of when something useful happened
			$useful_checkins = self::$jobdata->get( 'useful_checkins', [] );
			if ( ! is_array( $useful_checkins ) ) {
				$useful_checkins = [];
			}
			if ( ! in_array( self::$current_resumption, $useful_checkins, true ) ) {
				$useful_checkins[] = self::$current_resumption;
				self::$jobdata->set( 'useful_checkins', $useful_checkins );
			}
		}

		self::$something_useful_happened = true;

		// Check if we should deleteflag
		if ( self::$current_resumption >= 9 && false === self::$newresumption_scheduled ) {
			$resume_interval = max( self::$jobdata->get( 'resume_interval' ), 75 );
			$schedule_for    = time() + $resume_interval;
			self::$newresumption_scheduled = $schedule_for;
			self::schedule_event( $schedule_for, self::$current_resumption + 1, self::$jobdata->nonce );
		} else {
			self::reschedule_if_needed();
		}
	}

	/**
	 * Reschedule the next resumption for the specified amount of time in the future
	 *
	 * @param int $how_far_ahead Number of seconds
	 *
	 * @return void
	 */
	public static function reschedule( $how_far_ahead ) {
		if ( ! self::$jobdata ) {
			return;
		}

		$next_resumption = self::$current_resumption + 1;

		// Clear any existing scheduled event for this resumption
		self::clear_scheduled( $next_resumption );

		// Minimum interval
		if ( $how_far_ahead < self::MIN_RESUME_INTERVAL ) {
			$how_far_ahead = self::MIN_RESUME_INTERVAL;
		}

		$schedule_for = time() + $how_far_ahead;
		self::schedule_event( $schedule_for, $next_resumption, self::$jobdata->nonce );

		self::$newresumption_scheduled = $schedule_for;
	}

	/**
	 * Schedule a single cron event
	 *
	 * @param int    $schedule_for Timestamp when to run
	 * @param int    $resumption   Resumption number
	 * @param string $nonce        Job nonce
	 *
	 * @return void
	 */
	private static function schedule_event( $schedule_for, $resumption, $nonce ) {
		wp_schedule_single_event( $schedule_for, Scheduler::CRON_HOOK, [ $resumption, $nonce ] );
	}

	/**
	 * Clear scheduled events for a specific resumption
	 *
	 * @param int $resumption The resumption number to clear
	 *
	 * @return void
	 */
	private static function clear_scheduled( $resumption ) {
		if ( ! self::$jobdata ) {
			return;
		}

		wp_clear_scheduled_hook( Scheduler::CRON_HOOK, [ $resumption, self::$jobdata->nonce ] );
		wp_clear_scheduled_hook( Scheduler::LEGACY_CRON_HOOK, [ $resumption, self::$jobdata->nonce ] );
	}

	/**
	 * Terminate a backup run because other activity has been detected
	 *
	 * @param string $file              The file whose recent modification indicates activity
	 * @param int    $time_now          The current time
	 * @param int    $time_mod          The file modification time
	 * @param bool   $increase_resumption Whether to increase the resumption interval
	 *
	 * @return void
	 */
	public static function terminate_due_to_activity( $file, $time_now, $time_mod, $increase_resumption = true ) {
		if ( ! self::$jobdata ) {
			return;
		}

		// We check-in, to avoid 'no check in last time!' detectors firing
		self::record_still_alive();

		$file_size = is_file( $file ) ? round( filesize( $file ) / 1024, 1 ) . 'KB' : 'n/a';

		// Log the activity detection
		self::$jobdata->set( 'activity_detected', "File activity detected on " . basename( $file ) . " (time_mod=$time_mod, time_now=$time_now, diff=" . floor( $time_now - $time_mod ) . ", size=$file_size)" );

		$increase_by = $increase_resumption ? 120 : 0;
		self::increase_resume_and_reschedule( $increase_by, true );

		// Die to prevent overlapping runs
		die;
	}

	/**
	 * Increase the resumption interval and reschedule the next resumption
	 *
	 * @param int  $howmuch       How much to add to the existing resumption interval
	 * @param bool $due_to_overlap If true, indicates this is due to overlap detection
	 *
	 * @return void
	 */
	private static function increase_resume_and_reschedule( $howmuch = 120, $due_to_overlap = false ) {
		if ( ! self::$jobdata ) {
			return;
		}

		$resume_interval = max( (int) self::$jobdata->get( 'resume_interval' ), ( 0 === $howmuch ) ? 120 : 300 );

		if ( empty( self::$newresumption_scheduled ) && $due_to_overlap ) {
			// A new resumption will be scheduled to prevent the job ending
		}

		$new_resume = $resume_interval + $howmuch;

		// Adjust if running time exceeds new interval
		if ( self::$opened_log_time > 100 && microtime( true ) - self::$opened_log_time > $new_resume ) {
			$new_resume = ceil( microtime( true ) - self::$opened_log_time ) + 45;
			$howmuch    = $new_resume - $resume_interval;
		}

		// Cap at MAX_RESUME_INTERVAL_OVERLAP if due to overlap
		$how_far_ahead = $due_to_overlap ? min( $new_resume, self::MAX_RESUME_INTERVAL_OVERLAP ) : $new_resume;

		// If it is very long-running, schedule sooner
		if ( self::$current_resumption <= 1 && $new_resume > 720 ) {
			$how_far_ahead = 600;
		}

		if ( ! empty( self::$newresumption_scheduled ) || $due_to_overlap ) {
			self::reschedule( $how_far_ahead );
		}

		self::$jobdata->set( 'resume_interval', $new_resume );
	}

	/**
	 * Clear all scheduled events for a job (cleanup on completion/failure)
	 *
	 * @param string $nonce Job nonce
	 *
	 * @return void
	 */
	public static function clear_all_scheduled( $nonce ) {
		// Clear any resumption that might be scheduled
		for ( $i = 0; $i <= 100; $i++ ) {
			wp_clear_scheduled_hook( Scheduler::CRON_HOOK, [ $i, $nonce ] );
			wp_clear_scheduled_hook( Scheduler::LEGACY_CRON_HOOK, [ $i, $nonce ] );
		}
	}

	/**
	 * Reset static properties between jobs
	 *
	 * @return void
	 */
	public static function reset() {
		self::$jobdata                  = null;
		self::$current_resumption       = 0;
		self::$opened_log_time          = 0;
		self::$something_useful_happened = false;
		self::$newresumption_scheduled  = false;
	}
}
