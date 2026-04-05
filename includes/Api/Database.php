<?php

namespace Wpbackup\Migrator\Api;

use Wpbackup\Migrator\Api;
use Wpbackup\Migrator\Services\Database\Backup;
use Wpbackup\Migrator\Services\Database\JobData;
use Wpbackup\Migrator\Services\Database\JobScheduler;
use Wpbackup\Migrator\Services\Database\Scheduler;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Database API Handler Class
 */
class Database {
	/**
	 * Register database related routes
	 *
	 * @param string $namespace API namespace.
	 *
	 * @return void
	 */
	public function register_routes( $namespace ) {
		// Create a new dump job
		register_rest_route(
			$namespace,
			'/database/dumps',
			[
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'create_dump' ],
				'permission_callback' => [ Api::class, 'check_permission' ],
			]
		);

		// Get dump job status
		register_rest_route(
			$namespace,
			'/database/dumps/(?P<job_id>[a-zA-Z0-9]+)',
			[
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => [ $this, 'get_dump_status' ],
				'permission_callback' => [ Api::class, 'check_permission' ],
			]
		);

		// Download a dump file
		register_rest_route(
			$namespace,
			'/database/dumps/(?P<job_id>[a-zA-Z0-9]+)/download',
			[
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => [ $this, 'download_dump' ],
				'permission_callback' => [ Api::class, 'check_permission' ],
			]
		);

		// Delete a dump job
		register_rest_route(
			$namespace,
			'/database/dumps/(?P<job_id>[a-zA-Z0-9]+)',
			[
				'methods'             => WP_REST_Server::DELETABLE,
				'callback'            => [ $this, 'delete_dump' ],
				'permission_callback' => [ Api::class, 'check_permission' ],
			]
		);
	}

	/**
	 * Create a new database dump (async)
	 *
	 * @param WP_REST_Request $request Request.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function create_dump( $request ) {
		// Create a new job
		$jobdata = new JobData();

		// Initialize the backup job
		$backup = new Backup( $jobdata );
		$backup->init_job();

		// Start the backup immediately (synchronously, but time-limited)
		// First run is direct, subsequent runs via cron
		// Set resource limits like Scheduler does
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 900 );
		}
		if ( function_exists( 'ini_set' ) ) {
			@ini_set( 'memory_limit', '256M' );
		}
		if ( function_exists( 'ignore_user_abort' ) ) {
			@ignore_user_abort( true );
		}

		// Initialize JobScheduler for this run
		JobScheduler::set_jobdata( $jobdata, 0 );

		// Update job metadata
		$jobdata->set( 'resumption', 0 );
		$jobdata->set( 'updated_at', time() );

		// Track when this run started
		$runs_started = [ 0 => microtime( true ) ];
		$jobdata->set( 'runs_started', $runs_started );

		// Run the first resumption directly
		$backup->run_resumable( 0 );

		// Return response immediately
		return rest_ensure_response(
			[
				'success' => true,
				'job_id'  => $jobdata->nonce,
				'status'  => 'running',
				'message' => 'Backup job started',
			]
		);
	}

	/**
	 * Get dump job status
	 *
	 * @param WP_REST_Request $request Request.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_dump_status( $request ) {
		$job_id = $request->get_param( 'job_id' );

		$jobdata = new JobData( $job_id );
		$status  = $jobdata->get( 'status' );

		if ( empty( $status ) ) {
			return new WP_Error(
				'job_not_found',
				'Dump job not found',
				[ 'status' => 404 ]
			);
		}

		$response = [
			'job_id'       => $job_id,
			'status'       => $status,
			'progress'     => [
				'current_table' => $jobdata->get( 'current_table' ),
				'table_index'   => $jobdata->get( 'current_table_index', 0 ),
				'total_tables'  => $jobdata->get( 'total_tables', 0 ),
			],
			'started_at'   => $jobdata->get( 'started_at' ),
			'updated_at'   => $jobdata->get( 'updated_at' ),
		];

		// Add resumption info if running
		if ( 'running' === $status ) {
			$response['resumption']      = $jobdata->get( 'resumption', 0 );
			$resume_interval             = $jobdata->get( 'resume_interval', 100 );
			$response['resume_interval'] = $resume_interval;
		}

		// Add file info if complete
		$file_path = $jobdata->get( 'file_path' );
		if ( 'complete' === $status && $file_path && file_exists( $file_path ) ) {
			$response['file']     = basename( $file_path );
			$response['size']     = filesize( $file_path );
			$response['download'] = rest_url( Api::NAMESPACE . '/database/dumps/' . $job_id . '/download' );
		}

		// Add error if failed
		if ( 'failed' === $status ) {
			$response['error'] = $jobdata->get( 'error' );
		}

		// Trigger cron only if job is running and hasn't been updated recently
		// This ensures stalled jobs get a kick without spamming on every poll
		if ( 'running' === $status ) {
			$updated_at = $jobdata->get( 'updated_at', 0 );
			// Only trigger if not updated in the last 30 seconds
			if ( time() - $updated_at > 30 ) {
				Scheduler::spawn_cron();
			}
		}

		return rest_ensure_response( $response );
	}

	/**
	 * Download a dump file
	 *
	 * @param WP_REST_Request $request Request.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function download_dump( $request ) {
		$job_id = $request->get_param( 'job_id' );

		$jobdata   = new JobData( $job_id );
		$file_path = $jobdata->get( 'file_path' );

		if ( empty( $file_path ) || ! file_exists( $file_path ) ) {
			return new WP_Error(
				'file_not_found',
				'Dump file not found',
				[ 'status' => 404 ]
			);
		}

		// Check if we should stream directly
		$direct = $request->get_param( 'direct' );

		if ( $direct ) {
			// Stream the file directly
			$this->stream_file( $file_path );
			exit;
		}

		// Return file info with a download URL
		return rest_ensure_response(
			[
				'success'  => true,
				'file'     => basename( $file_path ),
				'size'     => filesize( $file_path ),
				'path'     => $file_path,
				'download' => rest_url( Api::NAMESPACE . '/database/dumps/' . $job_id . '/download' ) . '?direct=1&secret=' . rawurlencode( wpbackup_migrator()->get_migration_key() ),
			]
		);
	}

	/**
	 * Stream a file to the client
	 *
	 * @param string $file_path Full path to file.
	 *
	 * @return void
	 */
	private function stream_file( $file_path ) {
		$filename = basename( $file_path );

		header( 'Content-Description: File Transfer' );
		header( 'Content-Type: application/octet-stream' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		header( 'Content-Transfer-Encoding: binary' );
		header( 'Expires: 0' );
		header( 'Cache-Control: must-revalidate' );
		header( 'Pragma: public' );
		header( 'Content-Length: ' . filesize( $file_path ) );

		// Clean output buffer
		if ( ob_get_level() ) {
			ob_end_clean();
		}

		readfile( $file_path );
	}

	/**
	 * Delete a dump job and its file
	 *
	 * @param WP_REST_Request $request Request.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function delete_dump( $request ) {
		$job_id = $request->get_param( 'job_id' );

		$jobdata   = new JobData( $job_id );
		$file_path = $jobdata->get( 'file_path' );

		// Clear any scheduled events for this job
		Scheduler::clear_scheduled( $job_id );

		// Delete the file if it exists
		if ( $file_path && file_exists( $file_path ) ) {
			@unlink( $file_path );
		}

		// Delete the job data
		$jobdata->delete_all();

		return rest_ensure_response(
			[
				'success' => true,
				'message' => 'Dump deleted successfully',
			]
		);
	}
}
