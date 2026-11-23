<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Durable options hold progress; Action Scheduler holds only ID and generation. */
final class ADBC_MCP_Jobs {
	private const GROUP = 'adbc-mcp';
	private const HOOK = 'adbc_mcp_run_job';
	public const HOUSEKEEPING_HOOK = 'adbc_mcp_housekeeping';
	private const SETTINGS = [ 'sql_or_native_cleanup_method', 'prevent_taking_action_on_wp_items', 'keep_last' ];
	public static function register(): void {
		add_action( self::HOOK, [ self::class, 'run' ], 10, 2 );
		add_action( self::HOUSEKEEPING_HOOK, [ self::class, 'recover' ] );
		add_action( 'init', [ self::class, 'schedule_recovery' ], 20 );
	}
	public static function schedule_recovery(): void {
		if ( ! ADBC_MCP_Settings::abilities_enabled() || ! ADBC_MCP_Store::ready() || ! function_exists( 'as_schedule_recurring_action' ) || ! ActionScheduler::is_initialized() ) {
			return;
		}
		if ( ! as_has_scheduled_action( self::HOUSEKEEPING_HOOK, [], self::GROUP ) && ! as_schedule_recurring_action( time() + 600, HOUR_IN_SECONDS, self::HOUSEKEEPING_HOOK, [], self::GROUP, true ) ) {
			ADBC_MCP_Settings::set_state( [ 'housekeeping_error' => 'MCP housekeeping could not be scheduled. Check the background queue.' ] );
		}
	}
	private static function settings(): array {
		global $wpdb;
		$stored = maybe_unserialize( $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", 'adbc_plugin_settings' ) ) );
		return array_intersect_key( is_array( $stored ) ? $stored : [], array_flip( self::SETTINGS ) );
	}
	private static function schedule( array $job ): bool {
		if ( ! function_exists( 'as_enqueue_async_action' ) || ! ActionScheduler::is_initialized() ) {
			return false;
		}
		$args = [ $job['id'], (int) $job['step'] ];
		return as_has_scheduled_action( self::HOOK, $args, self::GROUP ) || (bool) as_enqueue_async_action( self::HOOK, $args, self::GROUP, true );
	}
	public static function enqueue( $ability, array $args ) {
		$access = ADBC_MCP_Access::check( $ability );
		if ( is_wp_error( $access ) ) {
			return $access;
		}
		if ( ! ADBC_MCP_Store::ready() || ! function_exists( 'as_enqueue_async_action' ) || ! ActionScheduler::is_initialized() ) {
			return new WP_Error( 'adbc_queue_unavailable', 'The background queue is unavailable.', [ 'status' => 503 ] );
		}
		$id = wp_generate_uuid4();
		$now = current_time( 'mysql', true );
		$counts_rows = in_array( $ability, [ 'purge_items', 'delete_items', 'delete_posts_by_post_type' ], true ) && ! ADBC_MCP_Batches::is_table_operation( $ability, $args );
		if ( ! ADBC_MCP_Access::authorized() ) {
			return new WP_Error( 'adbc_forbidden', 'An authorized connection or CLI user is required.', [ 'status' => 403 ] );
		}
		$job = [ 'id' => $id, 'user_id' => get_current_user_id(), 'grant_id' => ADBC_MCP_Context::grant_id(), 'channel' => ADBC_MCP_Context::channel(), 'ability' => $ability, 'args' => $args, 'settings' => self::settings(), 'status' => 'queued', 'step' => 0, 'cursor' => [], 'in_flight' => false, 'result' => [ 'processed_items' => 0, 'affected_rows' => $counts_rows ? 0 : null, 'total_items' => ! $counts_rows && isset( $args['selectedItems'] ) ? count( $args['selectedItems'] ) : null ], 'created_at' => $now, 'updated_at' => $now ];
		$job['access_revision'] = ADBC_MCP_Context::access_revision() ?? ADBC_MCP_Access::revision();
		if ( ! ADBC_MCP_Store::add( 'jobs', $job ) ) {
			return new WP_Error( 'adbc_queue_failed', 'Could not store the job.', [ 'status' => 500 ] );
		}
		if ( ! self::schedule( $job ) ) {
			ADBC_MCP_Store::update( 'jobs', $id, [ 'scheduling_error' => 'Waiting for the background queue. The next status check will retry scheduling.' ] );
		}
		return [ 'success' => true, 'job_id' => $id, 'status' => 'queued' ];
	}

	/** Compare and checkpoint without overwriting another worker or cancellation. */
	private static function save( array &$job, array $changes ): void {
		$new = array_replace( $job, $changes, [ 'updated_at' => current_time( 'mysql', true ) ] );
		if ( ! ADBC_MCP_Store::replace( 'jobs', $job, $new ) ) {
			throw new RuntimeException( 'Could not checkpoint job progress. Review the partial result before retrying.' );
		}
		$new['_revision'] = (int) $job['_revision'] + 1;
		$job = $new;
	}
	private static function authorize( array $job ): void {
		if ( ! ADBC_MCP_Access::authorized() ) {
			throw new RuntimeException( 'The connection, Application Password, or administrator permission was revoked.' );
		}
		$access = ADBC_MCP_Access::check( $job['ability'], $job['access_revision'] ?? 0 );
		if ( is_wp_error( $access ) ) {
			throw new RuntimeException( $access->get_error_message() );
		}
		if ( self::settings() !== $job['settings'] ) {
			throw new RuntimeException( 'Cleanup settings changed. Review the partial result and start a new confirmed job.' );
		}
		foreach ( $job['settings'] as $key => $value ) {
			if ( ADBC_Settings::instance()->get_setting( $key ) !== $value ) {
				throw new RuntimeException( 'Cleanup settings changed during this request.' );
			}
		}
	}
	private static function memory_available(): bool {
		$limit = wp_convert_hr_to_bytes( ini_get( 'memory_limit' ) );
		return $limit <= 0 || memory_get_usage( true ) < $limit - max( 8 * MB_IN_BYTES, (int) ( $limit * 0.2 ) );
	}
	/** Reject corrupt durable state before claiming it or changing request identity. */
	private static function valid( ?array $job ): bool {
		if ( $job && array_key_exists( 'access_revision', $job ) && ( ! is_int( $job['access_revision'] ) || $job['access_revision'] < 0 ) ) {
			return false;
		}
		if ( ! $job || ! is_int( $job['user_id'] ?? null ) || $job['user_id'] < 1
			|| ! is_string( $job['grant_id'] ?? null ) || '' === $job['grant_id']
			|| ! in_array( $job['channel'] ?? null, [ 'mcp', 'abilities', 'cli' ], true )
			|| ! is_string( $job['ability'] ?? null ) || ! is_int( $job['step'] ?? null ) || $job['step'] < 0 || $job['step'] >= PHP_INT_MAX
			|| ! in_array( $job['status'] ?? null, [ 'queued', 'running', 'completed', 'failed', 'cancelled' ], true )
			|| ! is_bool( $job['in_flight'] ?? null ) || ! ADBC_MCP_Store::valid_expiry( $job['created_at'] ?? null ) || ! ADBC_MCP_Store::valid_expiry( $job['updated_at'] ?? null ) ) {
			return false;
		}
		$definition = ADBC_MCP_Abilities::definitions()[ $job['ability'] ] ?? null;
		if ( ! $definition ) {
			return false;
		}
		foreach ( [ 'args', 'settings', 'cursor', 'result' ] as $key ) {
			if ( ! is_array( $job[ $key ] ?? null ) ) {
				return false;
			}
		}
		foreach ( [ 'processed_items', 'affected_rows', 'total_items' ] as $key ) {
			if ( ! array_key_exists( $key, $job['result'] ) ) {
				return false;
			}
			$value = $job['result'][ $key ];
			if ( null === $value && 'processed_items' !== $key ) {
				continue;
			}
			if ( ! is_int( $value ) || $value < 0 || $value > PHP_INT_MAX - 20 ) {
				return false;
			}
		}
		foreach ( [ 'index', 'site_id' ] as $key ) {
			if ( array_key_exists( $key, $job['cursor'] ) && ( ! is_int( $job['cursor'][ $key ] ) || $job['cursor'][ $key ] < 0 || $job['cursor'][ $key ] >= PHP_INT_MAX ) ) {
				return false;
			}
		}
		if ( array_key_exists( 'pass_had_work', $job['cursor'] ) && ! is_bool( $job['cursor']['pass_had_work'] ) ) {
			return false;
		}
		if ( array_key_exists( 'tables', $job['cursor'] ) ) {
			if ( ! is_array( $job['cursor']['tables'] ) || ! array_is_list( $job['cursor']['tables'] ) ) {
				return false;
			}
			foreach ( $job['cursor']['tables'] as $table ) {
				if ( ! is_string( $table ) || '' === $table ) {
					return false;
				}
			}
		}
		return ! array_key_exists( 'expires_at', $job ) || ADBC_MCP_Store::valid_expiry( $job['expires_at'] );
	}
	public static function run( $id, $generation = null ): void {
		if ( ! ADBC_MCP_Store::ready() ) {
			return;
		}
		$job = ADBC_MCP_Store::get( 'jobs', (string) $id );
		if ( ! self::valid( $job ) || 'queued' !== $job['status'] || ( null !== $generation && (int) $generation !== (int) $job['step'] ) ) {
			return;
		}
		$claimed = array_replace( $job, [ 'status' => 'running', 'updated_at' => current_time( 'mysql', true ) ] );
		if ( ! ADBC_MCP_Store::replace( 'jobs', $job, $claimed ) ) {
			return;
		}
		$claimed['_revision'] = (int) $job['_revision'] + 1;
		$job = $claimed;
		$original_user = get_current_user_id();
		$original_context = ADBC_MCP_Context::snapshot();
		wp_set_current_user( (int) $job['user_id'] );
		ADBC_MCP_Context::set_grant_id( $job['grant_id'], $job['channel'] ?? 'mcp' );
		ADBC_MCP_Context::set_access_revision( $job['access_revision'] ?? 0 );
		$max_time = (int) ini_get( 'max_execution_time' );
		$remaining = $max_time > 0 ? $max_time - ( microtime( true ) - (float) ( $_SERVER['REQUEST_TIME_FLOAT'] ?? microtime( true ) ) ) - 5 : 5;
		$deadline = microtime( true ) + min( 5, max( 0, $remaining ) );
		try {
			self::authorize( $job );
			$done = false;
			for ( $units = 0; $units < 20 && microtime( true ) < $deadline; $units++ ) {
				if ( ! self::memory_available() ) {
					throw new RuntimeException( 'Not enough memory remains for the next unit of work.' );
				}
				self::authorize( $job );
				self::save( $job, [ 'in_flight' => true ] );
				$targets = [ 'items_type' => $job['args']['itemsType'] ?? '' ];
				$index = (int) ( $job['cursor']['index'] ?? 0 );
				if ( isset( $job['args']['selectedItems'][ $index ] ) ) {
					$targets['selected'] = [ $job['args']['selectedItems'][ $index ] ];
				} elseif ( isset( $job['cursor']['tables'][ $index ] ) ) {
					$targets['tables'] = [ $job['cursor']['tables'][ $index ] ];
				}
				$step = ADBC_Change_Log::context( [ 'operation_id' => $job['id'], 'job_id' => $job['id'], 'batch' => $job['step'], 'source' => $job['channel'], 'user_id' => $job['user_id'], 'connection_id' => $job['grant_id'] ], static function () use ($job, $targets) {
					return ADBC_Change_Log::run( $job['ability'], $targets, static function () use ($job) {
						$step = ADBC_MCP_Batches::step( $job['ability'], $job['args'], $job['cursor'] );
						ADBC_Change_Log::note( [ 'processed_items' => $step['processed'] ] );
						return $step;
					} );
				} );
				$result = $job['result'];
				if ( ADBC_Change_Log::warning() ) {
					$result['logging_warning'] = ADBC_Change_Log::warning();
				}
				$result['processed_items'] += $step['processed'];
				if ( null !== $result['affected_rows'] ) {
					$result['affected_rows'] += $step['affected_rows'];
				}
				self::save( $job, [ 'cursor' => $step['cursor'], 'result' => $result, 'in_flight' => false ] );
				$done = $step['done'];
				if ( $done || ADBC_MCP_Batches::is_table_operation( $job['ability'], $job['args'] ) ) {
					break;
				}
			}
			if ( $done ) {
				self::save( $job, [ 'status' => 'completed', 'expires_at' => gmdate( 'Y-m-d H:i:s', time() + 7 * DAY_IN_SECONDS ) ] );
			} else {
				self::save( $job, [ 'status' => 'queued', 'step' => (int) $job['step'] + 1 ] );
				if ( ! self::schedule( $job ) ) {
					self::save( $job, [ 'scheduling_error' => 'Waiting for the background queue.' ] );
				}
			}
		} catch (Throwable $error) {
			self::fail( $job, $error->getMessage() );
		} finally {
			ADBC_MCP_Context::restore( $original_context );
			wp_set_current_user( $original_user );
		}
	}
	private static function fail( array $job, string $message ): void {
		if ( ! empty( $job['in_flight'] ) && 'running' === $job['status'] && strtotime( $job['updated_at'] . ' UTC' ) < time() - HOUR_IN_SECONDS ) {
			ADBC_Change_Log::context( [ 'operation_id' => $job['id'], 'job_id' => $job['id'], 'batch' => $job['step'], 'source' => $job['channel'], 'user_id' => $job['user_id'], 'connection_id' => $job['grant_id'] ], static function () use ($job) {
				ADBC_Change_Log::run( $job['ability'], [], static function () {
					ADBC_Change_Log::note( [ 'outcome' => 'unknown', 'error_code' => 'worker_interrupted' ] ); } );
			} );
		}
		$result = array_replace( $job['result'], [ 'error' => $message, 'partial' => ! empty( $job['result']['processed_items'] ) || ! empty( $job['in_flight'] ), 'side_effects_unknown' => ! empty( $job['in_flight'] ) ] );
		ADBC_MCP_Store::replace( 'jobs', $job, array_replace( $job, [ 'status' => 'failed', 'result' => $result, 'updated_at' => current_time( 'mysql', true ), 'expires_at' => gmdate( 'Y-m-d H:i:s', time() + 7 * DAY_IN_SECONDS ) ] ) );
	}
	public static function recover( $continuation = false ): void {
		if ( ! ADBC_MCP_Store::ready() ) {
			return;
		}
		try {
			$page = ADBC_MCP_Store::page( 'jobs', 20, (int) ADBC_MCP_Settings::get_state( 'recovery_cursor', 0 ), false );
			ADBC_MCP_Settings::prune_metadata_budget();
			$rows = $page['records'];
			foreach ( $rows as $job ) {
				if ( ! self::valid( $job ) ) {
					continue;
				}
				if ( 'running' === $job['status'] && strtotime( $job['updated_at'] . ' UTC' ) < time() - HOUR_IN_SECONDS ) {
					self::fail( $job, 'The worker stopped unexpectedly. Review the partial result before retrying; no destructive step was replayed.' );
				} elseif ( 'queued' === $job['status'] ) {
					if ( ! self::schedule( $job ) ) {
						throw new RuntimeException( 'Could not reschedule a queued MCP job.' );
					}
					if ( ! empty( $job['scheduling_error'] ) ) {
						ADBC_MCP_Store::update( 'jobs', $job['id'], [ 'scheduling_error' => '' ] );
					}
				}
			}
			if ( ! ADBC_MCP_Settings::set_state( [ 'recovery_cursor' => $page['has_more'] ? $page['cursor'] : 0 ] ) ) {
				throw new RuntimeException( 'Could not checkpoint job recovery.' );
			}
			$more = ADBC_MCP_Store::prune() || $page['has_more'];
			// Alternate continuation arguments so a running unique action does not
			// prevent scheduling the next pass of the same hook.
			$next_args = [ ! $continuation ];
			if ( $more && ! as_has_scheduled_action( self::HOUSEKEEPING_HOOK, $next_args, self::GROUP ) && ! as_schedule_single_action( time() + 60, self::HOUSEKEEPING_HOOK, $next_args, self::GROUP, true ) ) {
				throw new RuntimeException( 'Could not schedule the next housekeeping batch.' );
			}
			if ( ! ADBC_MCP_Settings::set_state( [ 'last_housekeeping_at' => gmdate( 'Y-m-d H:i:s' ), 'housekeeping_error' => '' ] ) ) {
				throw new RuntimeException( 'Could not save housekeeping status.' );
			}
		} catch (Throwable $error) {
			ADBC_MCP_Settings::set_state( [ 'housekeeping_error' => substr( $error->getMessage(), 0, 1000 ) ] );
			throw $error;
		}
	}
	public static function get( $id ) {
		$job = ADBC_MCP_Store::get( 'jobs', (string) $id );
		if ( ! self::valid( $job ) || ! ADBC_MCP_Access::authorized() || $job['channel'] !== ADBC_MCP_Context::channel() || $job['user_id'] !== get_current_user_id() || $job['grant_id'] !== ADBC_MCP_Context::grant_id() ) {
			return new WP_Error( 'adbc_job_not_found', 'Job not found.', [ 'status' => 404 ] );
		}
		if ( 'queued' === $job['status'] && self::schedule( $job ) ) {
			ADBC_MCP_Store::update( 'jobs', $job['id'], [ 'scheduling_error' => '' ] );
		}
		return [ 'success' => true, 'job' => array_intersect_key( $job, array_flip( [ 'id', 'ability', 'status', 'result', 'created_at', 'updated_at', 'scheduling_error' ] ) ) ];
	}
	public static function cancel_record( array $job ): bool {
		if ( ! self::valid( $job ) || 'queued' !== $job['status'] ) {
			return false;
		}
		$new = array_replace( $job, [ 'status' => 'cancelled', 'updated_at' => current_time( 'mysql', true ), 'expires_at' => gmdate( 'Y-m-d H:i:s', time() + 7 * DAY_IN_SECONDS ) ] );
		if ( ! ADBC_MCP_Store::replace( 'jobs', $job, $new ) ) {
			return false;
		}
		as_unschedule_all_actions( self::HOOK, [ $job['id'], (int) $job['step'] ], self::GROUP );
		return true;
	}
	public static function cancel( $id ) {
		$access = ADBC_MCP_Access::check( 'cancel_job' );
		if ( is_wp_error( $access ) ) {
			return $access;
		}
		$job = ADBC_MCP_Store::get( 'jobs', (string) $id );
		$changed = self::valid( $job ) && ADBC_MCP_Access::authorized() && $job['channel'] === ADBC_MCP_Context::channel() && $job['user_id'] === get_current_user_id() && $job['grant_id'] === ADBC_MCP_Context::grant_id() && self::cancel_record( $job );
		return [ 'success' => (bool) $changed, 'status' => $changed ? 'cancelled' : 'already_running_or_unavailable' ];
	}
}
