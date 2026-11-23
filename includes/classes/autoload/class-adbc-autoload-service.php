<?php

if ( ! defined( 'ABSPATH' ) )
	exit;

/** Shared autoload policy. Callers authorize the target; this service never accepts a REST request. */
final class ADBC_Autoload_Service {
	private $blog_id;
	private $store;

	public function __construct( $blog_id ) {
		$this->blog_id = (int) $blog_id;
		$this->store = new ADBC_Autoload_Store( $this->blog_id );
		if ( $this->blog_id < 1 )
			self::fail( 'autoload_invalid_target', __( 'Invalid autoload site.', 'advanced-database-cleaner' ), 422 );
	}

	private function scoped( callable $work ) {
		$switched = is_multisite() && get_current_blog_id() !== $this->blog_id;
		if ( $switched )
			switch_to_blog( $this->blog_id );
		try {
			return $work();
		} finally {
			if ( $switched )
				restore_current_blog();
		}
	}

	public static function threshold() {
		$value = apply_filters( 'site_status_autoloaded_options_size_limit', 800000 );
		return is_numeric( $value ) && (int) $value > 0 ? (int) $value : 800000;
	}

	private static function values() {
		return array_values( array_filter( array_map( 'strval', function_exists( 'wp_autoload_values_to_autoload' ) ? wp_autoload_values_to_autoload() : [ 'yes' ] ), 'strlen' ) );
	}

	private static function table() {
		global $wpdb;
		if ( ! is_string( $wpdb->options ) || ! preg_match( '/^[a-zA-Z0-9_]+$/D', $wpdb->options ) )
			self::fail( 'autoload_table_unavailable', __( 'The options table could not be identified.', 'advanced-database-cleaner' ) );
		return '`' . $wpdb->options . '`';
	}

	private static function fail( $code, $message, $status = 503, array $details = [] ) {
		throw new ADBC_Autoload_Exception( $code, $message, $status, $details );
	}

	/** Cheap aggregate for the existing health indicator; no files or option writes. */
	public static function health() {
		global $wpdb;
		$values = self::values();
		$total = 0;
		if ( $values ) {
			$sql = 'SELECT COALESCE(SUM(OCTET_LENGTH(option_value)),0) FROM ' . self::table() . ' WHERE autoload IN (' . implode( ',', array_fill( 0, count( $values ), '%s' ) ) . ')';
			$total = $wpdb->get_var( $wpdb->prepare( $sql, $values ) );
			if ( $total === null || $wpdb->last_error )
				self::fail( 'autoload_scan_failed', __( 'Autoload size could not be measured.', 'advanced-database-cleaner' ) );
		}
		$limit = self::threshold();
		return [ 'size_bytes' => (int) $total, 'threshold_bytes' => $limit, 'status' => (int) $total >= $limit ? 'warning' : 'good' ];
	}

	private function scan() {
		global $wpdb;
		$values = self::values();
		$rows = [];
		if ( $values ) {
			$sql = 'SELECT option_id, option_name, autoload, OCTET_LENGTH(option_value) AS size_bytes FROM ' . self::table() . ' WHERE autoload IN (' . implode( ',', array_fill( 0, count( $values ), '%s' ) ) . ') ORDER BY size_bytes DESC, option_id ASC';
			$rows = $wpdb->get_results( $wpdb->prepare( $sql, $values ), ARRAY_A );
			if ( ! is_array( $rows ) || $wpdb->last_error )
				self::fail( 'autoload_scan_failed', __( 'Autoload options could not be scanned.', 'advanced-database-cleaner' ) );
		}
		$hardcoded = ADBC_Hardcoded_Items::instance();
		$options = $hardcoded->get_wordpress_items( 'options' );
		// The hardcoded singleton may already exist before a future remote caller switches blogs.
		$options[ $wpdb->prefix . 'user_roles' ] = '';
		$options[ 'theme_mods_' . get_stylesheet()] = '';
		$options[ 'theme_mods_' . get_template()] = '';
		$transients = $hardcoded->get_wordpress_items( 'transients' );
		$total = 0;
		$core = 0;
		$core_count = 0;
		foreach ( $rows as &$row ) {
			$row['option_id'] = (int) $row['option_id'];
			$row['size_bytes'] = (int) $row['size_bytes'];
			$row['core'] = $hardcoded->is_item_belongs_to_wp_core( $row['option_name'], 'options', $options ) || $hardcoded->is_item_belongs_to_wp_core( $row['option_name'], 'transients', $transients );
			$total += $row['size_bytes'];
			if ( $row['core'] ) {
				$core += $row['size_bytes'];
				$core_count++;
			}
		}
		unset( $row );
		$limit = self::threshold();
		return [ 'rows' => $rows, 'size_bytes' => $total, 'core_size_bytes' => $core, 'core_option_count' => $core_count, 'option_count' => count( $rows ), 'threshold_bytes' => $limit, 'status' => $total >= $limit ? 'warning' : 'good' ];
	}

	private function fingerprint( array $scan, array $excluded = [] ) {
		$hash = hash_init( 'sha256' );
		hash_update( $hash, serialize( [ $this->blog_id, $scan['threshold_bytes'] ] ) );
		$excluded = array_fill_keys( $excluded, true );
		foreach ( $scan['rows'] as $row )
			if ( ! isset( $excluded[ $row['option_id'] ] ) )
				hash_update( $hash, serialize( $row ) );
		return hash_final( $hash );
	}

	private function plan( array $scan, array $state, array $recovery ) {
		$block = $state['fix_block'];
		$blocked = is_array( $block ) && hash_equals( (string) $block['fingerprint'], $this->fingerprint( $scan, $block['excluded_ids'] ) );
		$entries = [];
		$after = $scan['size_bytes'];
		if ( $scan['status'] === 'warning' && ! $blocked && ! $recovery['pending'] && ! $recovery['completion'] ) {
			foreach ( $scan['rows'] as $row ) {
				if ( $row['core'] || $row['size_bytes'] === 0 )
					continue;
				$entries[] = [ 'option_id' => $row['option_id'], 'name' => $row['option_name'], 'size_bytes' => $row['size_bytes'], 'original_autoload' => $row['autoload'], 'before' => $row['autoload'], 'after' => 'no' ];
				$after -= $row['size_bytes'];
				if ( $after < $scan['threshold_bytes'] )
					break;
			}
		}
		$reason = null;
		if ( $recovery['pending'] || $recovery['completion'] )
			$reason = 'recovery_pending';
		elseif ( $scan['status'] === 'warning' && ! $entries )
			$reason = $blocked ? 'fix_limit_reached' : 'no_actionable_options';
		$token = hash_hmac( 'sha256', $this->fingerprint( $scan ) . '|' . ( $reason ?: '' ), wp_salt( 'nonce' ) );
		return [
			'entries' => $entries,
			'preview' => [ 'size_bytes' => $scan['size_bytes'], 'threshold_bytes' => $scan['threshold_bytes'], 'reduction_bytes' => $scan['size_bytes'] - $after, 'projected_bytes' => $after, 'option_count' => count( $entries ), 'below_threshold' => $after < $scan['threshold_bytes'], 'fix_available' => count( $entries ) > 0, 'block_reason' => $reason, 'preview_token' => $token, 'healthy' => $scan['status'] === 'good' ],
		];
	}

	private function checkpoint( array $recovery ) {
		$pending = $recovery['pending'];
		if ( $pending && $pending['action'] === 'fix' )
			return [ 'id' => $pending['command_id'], 'created_at' => $pending['created_at'], 'changes' => $pending['entries'] ];
		return $recovery['checkpoint'];
	}

	private function point_summary( $point ) {
		return $point ? [ 'id' => $point['id'], 'created_at' => $point['created_at'], 'option_count' => count( $point['changes'] ) ] : null;
	}

	public function status() {
		return $this->scoped( function () {
			$scan = $this->scan();
			$state = $this->store->read( 'state' );
			$recovery = $this->store->read( 'recovery' );
			$plan = $this->plan( $scan, $state, $recovery );
			unset( $scan['rows'] );
			return array_merge( $scan, [ 'blog_id' => $this->blog_id, 'fix_available' => $plan['preview']['fix_available'], 'restore_point' => $this->point_summary( $this->checkpoint( $recovery ) ), 'pending_command_id' => $recovery['pending']['command_id'] ?? $recovery['completion']['command_id'] ?? null ] );
		} );
	}

	public function preview() {
		return $this->scoped( function () {
			return $this->plan( $this->scan(), $this->store->read( 'state' ), $this->store->read( 'recovery' ) )['preview'];
		} );
	}

	private function receipts( array $state ) {
		$receipts = $state['receipts'];
		if ( ! is_array( $receipts ) )
			self::fail( 'autoload_recovery_unreadable', __( 'Autoload command data is invalid. Existing files have been preserved.', 'advanced-database-cleaner' ) );
		foreach ( $receipts as $id => $receipt )
			if ( time() - (int) ( $receipt['completed_at'] ?? 0 ) > 604800 )
				unset( $receipts[ $id ] );
		return $receipts;
	}

	private function save_receipt( array &$state, array $receipt ) {
		$state['receipts'] = $this->receipts( $state );
		$state['receipts'][ $receipt['command_id'] ] = $receipt;
		uasort( $state['receipts'], static function ($a, $b) {
			return $a['completed_at'] <=> $b['completed_at']; } );
		while ( count( $state['receipts'] ) > 100 || strlen( wp_json_encode( $state['receipts'] ) ) > 65536 )
			array_shift( $state['receipts'] );
		$this->store->write( 'state', $state );
	}

	private function save_recovery( array $recovery ) {
		if ( ! $recovery['checkpoint'] && ! $recovery['pending'] && ! $recovery['completion'] )
			$this->store->remove_recovery();
		else
			$this->store->write( 'recovery', $recovery );
	}

	/** The completion marker bridges recovery replacement and the compact receipt write. */
	private function sync_completion( array &$state, array &$recovery ) {
		if ( ! $recovery['completion'] )
			return;
		$state['fix_block'] = $recovery['completion']['fix_block'];
		$this->save_receipt( $state, $recovery['completion']['receipt'] );
		$recovery['completion'] = null;
		$this->save_recovery( $recovery );
	}

	private function current_flag( array $entry ) {
		global $wpdb;
		$value = $wpdb->get_var( $wpdb->prepare( 'SELECT autoload FROM ' . self::table() . ' WHERE option_id=%d AND option_name=%s', $entry['option_id'], $entry['name'] ) );
		if ( $wpdb->last_error )
			self::fail( 'autoload_verification_failed', __( 'Autoload changes could not be verified. Recovery data has been preserved.', 'advanced-database-cleaner' ) );
		return $value;
	}

	private function invalidate( array $entries ) {
		wp_cache_delete( 'alloptions', 'options' );
		foreach ( $entries as $entry )
			wp_cache_delete( $entry['name'], 'options' );
	}

	private function incomplete( array $pending ) {
		return [ 'command_id' => $pending['command_id'], 'action' => $pending['action'], 'restore_point_id' => $pending['restore_point_id'], 'status' => 'indeterminate', 'result' => [ 'status' => 'failed', 'error_code' => 'autoload_operation_indeterminate', 'message' => __( 'Execution was interrupted or could not be verified. Recovery data is preserved. Use Undo latest fix to restore the saved flags.', 'advanced-database-cleaner' ) ] ];
	}

	/** Also used after process death, under the connection-owned mutation lock. Never replays writes. */
	private function reconcile( ADBC_Autoload_Lock $lock, array &$state, array &$recovery ) {
		$this->sync_completion( $state, $recovery );
		$pending = $recovery['pending'];
		if ( ! $pending )
			return null;
		$lock->assert_owned();
		$resolved = [];
		$changed = 0;
		$uncertain = false;
		foreach ( $pending['entries'] as $entry ) {
			$flag = $this->current_flag( $entry );
			if ( $flag === $entry['after'] ) {
				$resolved[] = $entry;
				if ( $entry['before'] !== $entry['after'] )
					$changed++;
			} elseif ( $pending['action'] === 'fix' && $flag !== $entry['before'] )
				$uncertain = true;
		}
		$lock->assert_owned();
		$this->invalidate( $pending['entries'] );
		if ( $uncertain )
			return $this->incomplete( $pending );
		$after = $this->scan();
		$skipped = count( $pending['entries'] ) - count( $resolved );
		if ( $pending['action'] === 'fix' ) {
			if ( $resolved )
				$recovery['checkpoint'] = [ 'id' => $pending['command_id'], 'created_at' => $pending['created_at'], 'changes' => $this->checkpoint_entries( $resolved ) ];
			$status = $after['status'] === 'good' ? 'success' : ( $changed ? 'partial_success' : 'failed' );
			if ( $after['status'] === 'warning' ) {
				$excluded = array_column( $pending['entries'], 'option_id' );
				$state['fix_block'] = [ 'excluded_ids' => $excluded, 'fingerprint' => $this->fingerprint( $after, $excluded ) ];
			} else
				$state['fix_block'] = null;
		} else {
			$done = [];
			foreach ( $resolved as $entry )
				$done[ $entry['option_id'] . ':' . $entry['name'] ] = true;
			$remaining = array_values( array_filter( $recovery['checkpoint']['changes'], static function ($entry) use ($done) {
				return ! isset( $done[ $entry['option_id'] . ':' . $entry['name'] ] ); } ) );
			$recovery['checkpoint']['changes'] = $remaining;
			if ( ! $remaining )
				$recovery['checkpoint'] = null;
			$status = ! $remaining ? 'success' : ( $resolved ? 'partial_success' : 'failed' );
			// Missing/recreated options were not put in the executable entries.
			$skipped = count( $remaining );
		}
		$error_code = null;
		if ( $status !== 'success' ) {
			if ( $pending['action'] === 'restore' )
				$error_code = 'restore_partially_completed';
			elseif ( ! $changed )
				$error_code = 'autoload_no_progress';
			else
				$error_code = $after['core_size_bytes'] >= $after['threshold_bytes'] ? 'threshold_not_reached_without_core_changes' : 'autoload_reduction_incomplete';
		}
		$receipt = [
			'command_id' => $pending['command_id'], 'action' => $pending['action'], 'restore_point_id' => $pending['restore_point_id'], 'status' => $status === 'failed' ? 'failed' : 'succeeded', 'completed_at' => time(),
			'result' => [ 'status' => $status, 'before_bytes' => $pending['before_bytes'], 'after_bytes' => $after['size_bytes'], 'threshold_bytes' => $after['threshold_bytes'], 'changed_count' => $changed, 'processed_count' => count( $resolved ), 'skipped_count' => $skipped, 'restore_point' => $this->point_summary( $recovery['checkpoint'] ), 'error_code' => $error_code ],
		];
		$recovery['pending'] = null;
		$recovery['completion'] = [ 'command_id' => $receipt['command_id'], 'receipt' => $receipt, 'fix_block' => $state['fix_block'] ];
		$this->store->write( 'recovery', $recovery );
		$this->sync_completion( $state, $recovery );
		return $receipt;
	}

	private function checkpoint_entries( array $entries ) {
		return array_map( static function ($entry) {
			return array_intersect_key( $entry, array_flip( [ 'option_id', 'name', 'size_bytes', 'original_autoload' ] ) ); }, $entries );
	}

	private function log( array $pending, callable $work, array $context ) {
		if ( ! class_exists( 'ADBC_Change_Log' ) )
			return $work();
		return ADBC_Change_Log::context( array_merge( [ 'source' => 'dashboard', 'operation_id' => $pending['command_id'] ], $pending['context'] ?? [], $context ), function () use ($pending, $work) {
			return ADBC_Change_Log::run( $pending['action'] === 'fix' ? 'autoload_fix' : 'autoload_restore', [ 'items_type' => 'options', 'selected' => $pending['entries'] ], function () use ($work, $pending) {
				$result = $work();
				$details = $result['result'] ?? [];
				ADBC_Change_Log::note( [ 'outcome' => $result['status'] === 'indeterminate' ? 'unknown' : ( $details['status'] === 'partial_success' ? 'partial' : ( $details['status'] === 'failed' ? 'failed' : 'success' ) ), 'affected_rows' => $details['changed_count'] ?? null, 'processed_items' => $details['processed_count'] ?? null, 'skipped_items' => $pending['action'] === 'restore' ? ( $details['skipped_count'] ?? 0 ) : 0, 'failed_items' => $pending['action'] === 'fix' ? ( $details['skipped_count'] ?? 0 ) : 0, 'error_code' => $details['error_code'] ?? null ] );
				return $result;
			}, [ 'method' => 'sql' ] );
		} );
	}

	public function execute( array $command, array $context = [] ) {
		return $this->scoped( function () use ($command, $context) {
			$id = $command['command_id'] ?? '';
			$action = $command['action'] ?? '';
			$restore_id = $command['restore_point_id'] ?? '';
			if ( ! is_string( $id ) || ! wp_is_uuid( $id ) || ! in_array( $action, [ 'fix', 'restore' ], true ) || ! is_string( $restore_id ) )
				self::fail( 'invalid_autoload_command', __( 'Invalid autoload command.', 'advanced-database-cleaner' ), 422 );
			$lock = new ADBC_Autoload_Lock();
			try {
				$state = $this->store->read( 'state' );
				$recovery = $this->store->read( 'recovery' );
				$this->sync_completion( $state, $recovery );
				$receipts = $this->receipts( $state );
				if ( isset( $receipts[ $id ] ) ) {
					$receipt = $receipts[ $id ];
					if ( $receipt['action'] !== $action || $receipt['restore_point_id'] !== $restore_id )
						self::fail( 'autoload_idempotency_conflict', __( 'This command ID was already used for another action.', 'advanced-database-cleaner' ), 409 );
					return $receipt;
				}
				if ( $recovery['pending'] ) {
					$old = $recovery['pending'];
					$result = $this->reconcile( $lock, $state, $recovery );
					if ( $old['command_id'] === $id ) {
						if ( $old['action'] !== $action || $old['restore_point_id'] !== $restore_id )
							self::fail( 'autoload_idempotency_conflict', __( 'This command ID was already used for another action.', 'advanced-database-cleaner' ), 409 );
						return $result;
					}
					if ( $recovery['pending'] && $action !== 'restore' )
						self::fail( 'autoload_recovery_pending', __( 'An interrupted autoload operation needs recovery. Use Undo latest fix before starting another fix.', 'advanced-database-cleaner' ), 409 );
				}
				$before = $this->scan();
				if ( $action === 'fix' ) {
					$plan = $this->plan( $before, $state, $recovery );
					if ( ! is_string( $command['preview_token'] ?? null ) || ! hash_equals( $plan['preview']['preview_token'], $command['preview_token'] ) )
						self::fail( 'autoload_preview_changed', __( 'Autoload options changed. Review the updated preview before confirming.', 'advanced-database-cleaner' ), 409, [ 'preview' => $plan['preview'] ] );
					if ( ! $plan['entries'] )
						self::fail( 'autoload_no_actionable_options', __( 'No options can currently be processed by the automatic fix.', 'advanced-database-cleaner' ), 409 );
					$entries = $plan['entries'];
				} else {
					$point = $this->checkpoint( $recovery );
					if ( ! $point || ! hash_equals( $point['id'], $restore_id ) )
						self::fail( 'restore_point_unavailable', __( 'The latest autoload restore point is no longer available.', 'advanced-database-cleaner' ), 409 );
					$entries = [];
					foreach ( $point['changes'] as $entry ) {
						$flag = $this->current_flag( $entry );
						if ( $flag !== null )
							$entries[] = array_merge( $entry, [ 'before' => $flag, 'after' => $entry['original_autoload'] ] );
					}
					// Explicit undo can resolve an indeterminate fix using its saved original flags.
					$recovery['checkpoint'] = $point;
				}
				$pending = [ 'command_id' => $id, 'action' => $action, 'restore_point_id' => $restore_id, 'created_at' => gmdate( 'c' ), 'before_bytes' => $before['size_bytes'], 'entries' => $entries ];
				$pending['context'] = array_intersect_key( array_merge( [ 'source' => 'dashboard', 'user_id' => get_current_user_id()], $context ), array_flip( [ 'source', 'user_id', 'connection_id', 'job_id' ] ) );
				$recovery['pending'] = $pending;
				// The complete before-image is durable before START TRANSACTION or any UPDATE.
				$this->store->write( 'recovery', $recovery );
				return $this->log( $pending, function () use ($lock, &$state, &$recovery, $pending) {
					try {
						$lock->query( 'START TRANSACTION' );
						foreach ( $pending['entries'] as $entry ) {
							global $wpdb;
							$sql = $wpdb->prepare( 'UPDATE ' . self::table() . ' SET autoload=%s WHERE option_id=%d AND option_name=%s AND autoload=%s', $entry['after'], $entry['option_id'], $entry['name'], $entry['before'] );
							if ( $pending['action'] === 'fix' )
								$sql .= $wpdb->prepare( ' AND OCTET_LENGTH(option_value)=%d', $entry['size_bytes'] );
							$lock->query( $sql );
							$this->invalidate( [ $entry ] );
						}
						$lock->query( 'COMMIT' );
					} catch (Throwable $error) {
						try {
							$lock->query( 'ROLLBACK' );
						} catch (Throwable $ignored) { /* The durable journal is authoritative. */
						}
					}
					$this->invalidate( $pending['entries'] );
					try {
						return $this->reconcile( $lock, $state, $recovery );
					} catch (Throwable $error) {
						return $this->incomplete( $pending );
					}
				}, $context );
			} finally {
				$lock->release();
			}
		} );
	}

	public function command_status( $id ) {
		return $this->scoped( function () use ($id) {
			if ( ! is_string( $id ) || ! wp_is_uuid( $id ) )
				self::fail( 'invalid_command_id', __( 'Invalid command ID.', 'advanced-database-cleaner' ), 422 );
			$state = $this->store->read( 'state' );
			$receipts = $this->receipts( $state );
			if ( isset( $receipts[ $id ] ) )
				return $receipts[ $id ];
			$recovery = $this->store->read( 'recovery' );
			if ( ( $recovery['pending']['command_id'] ?? null ) !== $id && ( $recovery['completion']['command_id'] ?? null ) !== $id )
				self::fail( 'command_not_found', __( 'This autoload command is no longer available.', 'advanced-database-cleaner' ), 404 );
			try {
				$lock = new ADBC_Autoload_Lock();
			} catch (ADBC_Autoload_Exception $error) {
				if ( $error->error_code !== 'autoload_busy' )
					throw $error;
				return [ 'command_id' => $id, 'status' => 'running' ];
			}
			try {
				$state = $this->store->read( 'state' );
				$recovery = $this->store->read( 'recovery' );
				$this->sync_completion( $state, $recovery );
				$receipts = $this->receipts( $state );
				if ( isset( $receipts[ $id ] ) )
					return $receipts[ $id ];
				if ( ( $recovery['pending']['command_id'] ?? null ) !== $id )
					self::fail( 'command_not_found', __( 'This autoload command is no longer available.', 'advanced-database-cleaner' ), 404 );
				$pending = $recovery['pending'];
				return $this->log( $pending, function () use ($lock, &$state, &$recovery) {
					return $this->reconcile( $lock, $state, $recovery ); }, [] );
			} finally {
				$lock->release();
			}
		} );
	}
}
