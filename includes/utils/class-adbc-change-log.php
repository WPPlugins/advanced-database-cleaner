<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Shared maintenance results. No dependency on the optional AI modules. */
final class ADBC_Change_Log {
	const HEADER = "<?php exit; ?>\n";
	const MAX_ENTRIES = 1000;
	const MAX_TARGET_NAMES = 1000;
	const MAX_BYTES = 5242880;
	// Room for 1,000 names of up to 191 bytes, including JSON escaping and query details.
	const MAX_ENTRY_BYTES = 2097152;
	const MAX_SQL_BYTES = 4096;
	const WARNING = 'Database maintenance continued, but its change log could not be written. Check permissions on the ADBC uploads folder.';
	const ACTIONS = [ 'autoload_fix', 'autoload_restore', 'execute_sql', 'delete_items', 'purge_items', 'delete_options', 'delete_transients', 'delete_posts_meta', 'delete_users_meta', 'delete_posts', 'delete_posts_batch', 'delete_posts_by_post_type', 'delete_cron_jobs', 'set_autoload_to_no_options', 'set_autoload_to_yes_options', 'set_autoload_to_no_transients', 'set_autoload_to_yes_transients', 'delete_tables', 'empty_tables', 'empty_rows_tables', 'optimize_tables', 'repair_tables', 'refresh_tables_counts', 'refresh_counts_tables', 'convert_tables_to_innodb', 'convert_to_innodb_tables' ];

	/** Public operation IDs group synonymous internal actions without rewriting historical logs. */
	public static function operation_key( $action ) {
		$aliases = [ 'autoload_restore' => 'autoload_fix', 'delete_posts_batch' => 'delete_posts', 'delete_posts_by_post_type' => 'delete_posts', 'empty_rows_tables' => 'empty_tables', 'refresh_counts_tables' => 'refresh_tables_counts', 'convert_to_innodb_tables' => 'convert_tables_to_innodb' ];
		return $aliases[ $action ] ?? $action;
	}
	public static function operation_options() {
		$labels = [
			'autoload_fix' => __( 'Autoload fix', 'advanced-database-cleaner' ),
			'execute_sql' => __( 'SQL write', 'advanced-database-cleaner' ),
			'delete_items' => __( 'Delete cleanup items', 'advanced-database-cleaner' ),
			'purge_items' => __( 'General cleanup', 'advanced-database-cleaner' ),
			'delete_options' => __( 'Delete options', 'advanced-database-cleaner' ),
			'delete_transients' => __( 'Delete transients', 'advanced-database-cleaner' ),
			'delete_posts_meta' => __( 'Delete post metadata', 'advanced-database-cleaner' ),
			'delete_users_meta' => __( 'Delete user metadata', 'advanced-database-cleaner' ),
			'delete_posts' => __( 'Delete posts by type', 'advanced-database-cleaner' ),
			'delete_cron_jobs' => __( 'Delete cron jobs', 'advanced-database-cleaner' ),
			'delete_tables' => __( 'Drop tables', 'advanced-database-cleaner' ),
			'empty_tables' => __( 'Empty tables', 'advanced-database-cleaner' ),
			'optimize_tables' => __( 'Optimize tables', 'advanced-database-cleaner' ),
			'repair_tables' => __( 'Repair tables', 'advanced-database-cleaner' ),
			'refresh_tables_counts' => __( 'Refresh table statistics', 'advanced-database-cleaner' ),
			'convert_tables_to_innodb' => __( 'Convert tables to InnoDB', 'advanced-database-cleaner' ),
			'set_autoload_to_no_options' => __( 'Disable option autoload', 'advanced-database-cleaner' ),
			'set_autoload_to_yes_options' => __( 'Enable option autoload', 'advanced-database-cleaner' ),
			'set_autoload_to_no_transients' => __( 'Disable transient autoload', 'advanced-database-cleaner' ),
			'set_autoload_to_yes_transients' => __( 'Enable transient autoload', 'advanced-database-cleaner' ),
		];
		$result = [ [ 'key' => '', 'label' => __( 'All operations', 'advanced-database-cleaner' ) ] ];
		foreach ( $labels as $key => $label )
			$result[] = [ 'key' => $key, 'label' => $label ];
		return $result;
	}
	private static $active = null;
	private static $context = [];
	private static $warning = '';
	private static $notifying = false;
	private static $repair = false;
	private static $query_bytes = 0;
	private static $read_error = false;

	public static function warning() {
		return self::$warning;
	}
	public static function read_error() {
		return self::$read_error;
	}
	public static function stored_warning() {
		$notifications = ADBC_Notifications::instance()->get_all_non_dismissed();
		return self::$warning ?: ( isset( $notifications['database_change_log'] ) ? self::WARNING : '' );
	}
	public static function path() {
		return ADBC_UPLOADS_DIR_PATH . '/mcp-audit/database-changes.php';
	}
	public static function context( array $context, callable $callback ) {
		$old = self::$context;
		self::$context = array_intersect_key( $context, array_flip( [ 'source', 'user_id', 'connection_id', 'operation_id', 'job_id', 'batch', 'automation_id' ] ) );
		try {
			return $callback();
		} finally {
			self::$context = $old;
		}
	}
	/** Only explicit maintenance entry points open a scope; nested calls share it. */
	public static function run( $action, array $targets, callable $callback, array $options = [] ) {
		if ( ! in_array( $action, self::ACTIONS, true ) ) {
			return $callback();
		}
		if ( null !== self::$active ) {
			return $callback();
		}
		$channel = class_exists( 'ADBC_MCP_Context', false ) ? ADBC_MCP_Context::channel() : '';
		$source = in_array( $channel, [ 'mcp', 'abilities', 'cli' ], true ) ? $channel : ( defined( 'WP_CLI' ) && WP_CLI ? 'cli' : 'dashboard' );
		$identity = array_merge( [ 'source' => $source, 'user_id' => get_current_user_id(), 'connection_id' => class_exists( 'ADBC_MCP_Context', false ) ? ADBC_MCP_Context::grant_id() : '', 'operation_id' => wp_generate_uuid4()], self::$context );
		self::$active = array_merge( $identity, [ 'version' => 1, 'id' => wp_generate_uuid4(), 'timestamp' => gmdate( 'Y-m-d\TH:i:s\Z' ), 'site_id' => get_current_blog_id(), 'action' => $action, 'method' => $options['method'] ?? ADBC_Settings::instance()->get_setting( 'sql_or_native_cleanup_method' ), 'outcome' => 'success', 'targets' => self::targets( $targets ), 'queries' => [], 'targeted_rows' => null, 'affected_rows' => null, 'processed_items' => null ] );
		$started = microtime( true );
		$result = null;
		self::$query_bytes = 0;
		if ( 'native' === self::$active['method'] ) {
			self::$active['method'] = 'wordpress';
		}
		try {
			$result = $callback();
			if ( is_wp_error( $result ) ) {
				self::note( [ 'outcome' => in_array( self::$active['outcome'], [ 'rolled_back', 'unknown', 'partial' ], true ) ? self::$active['outcome'] : 'failed', 'error_code' => $result->get_error_code()] );
			} elseif ( ! empty( $options['failures'] ) && is_array( $result ) ) {
				$total = self::$active['targets']['items_count'] ?? self::$active['targets']['tables_count'] ?? null;
				$failures = isset( $result['not_converted'] ) ? $result['not_converted'] : $result;
				$skipped = (int) ( $result['skipped_locked'] ?? 0 );
				self::note( [ 'processed_items' => null === $total ? null : max( 0, $total - count( $failures ) - $skipped ), 'skipped_items' => $skipped ] );
				if ( $failures ) {
					self::note( [ 'outcome' => count( $failures ) === $total ? 'failed' : 'partial', 'failed_items' => count( $failures ) ] );
				} elseif ( $skipped && $skipped === $total ) {
					self::note( [ 'outcome' => 'no_change' ] );
				}
			}
			if ( isset( $options['count_result'] ) && is_numeric( $result ) ) {
				self::note( [ $options['count_result'] => max( 0, (int) $result ) ] );
			} elseif ( is_array( $result ) && isset( $result['processed'], $result['deleted'] ) ) {
				self::note( [ 'processed_items' => (int) $result['processed'] ] );
			}
		} catch (Throwable $error) {
			$completed = array_filter( self::$active['queries'], function ($query) {
				return 'success' === $query['outcome'] && ( null === $query['affected_rows'] || $query['affected_rows'] > 0 );
			} );
			$outcome = in_array( self::$active['outcome'], [ 'unknown', 'rolled_back' ], true ) ? self::$active['outcome'] : ( $completed ? 'partial' : ( 'wordpress' === self::$active['method'] ? 'unknown' : 'failed' ) );
			self::note( [ 'outcome' => $outcome, 'error_code' => 'execution_exception' ] );
			throw $error;
		} finally {
			$entry = self::$active;
			self::$active = null;
			$entry['duration_ms'] = (int) round( ( microtime( true ) - $started ) * 1000 );
			if ( 'success' === $entry['outcome'] && 0 === $entry['affected_rows'] ) {
				$entry['outcome'] = 'no_change';
			}
			self::persist( self::normalize_outcome( $entry ) );
		}
		if ( self::$warning && is_array( $result ) && isset( $result['success'] ) ) {
			$result['logging_warning'] = self::$warning;
		}
		return $result;
	}
	public static function note( array $details ) {
		if ( null === self::$active ) {
			return;
		}
		foreach ( [ 'outcome', 'targeted_rows', 'affected_rows', 'processed_items', 'failed_items', 'skipped_items', 'insert_id', 'method', 'error_code' ] as $key ) {
			if ( array_key_exists( $key, $details ) && ( is_scalar( $details[ $key ] ) || null === $details[ $key ] ) ) {
				self::$active[ $key ] = is_string( $details[ $key ] ) ? substr( preg_replace( '/[^a-zA-Z0-9_-]/', '', $details[ $key ] ), 0, 100 ) : $details[ $key ];
			}
		}
	}
	/** Explicit direct SQL only. Never installs a global query observer. */
	public static function query( $sql, $results = false ) {
		global $wpdb;
		$table_maintenance = (bool) preg_match( '/^\s*(OPTIMIZE|REPAIR|ANALYZE)\s+TABLE\b/i', $sql );
		try {
			if ( $table_maintenance ) {
				// These statements can return SQL errors as rows without setting last_error.
				$rows = $wpdb->get_results( $sql );
				$ok = false;
				$error = false;
				foreach ( $rows ?: [] as $row ) {
					$type = strtolower( (string) ( $row->Msg_type ?? '' ) );
					$text = strtolower( (string) ( $row->Msg_text ?? '' ) );
					$error = $error || 'error' === $type;
					$ok = $ok || ( 'status' === $type && in_array( $text, [ 'ok', 'table is already up to date' ], true ) );
				}
				$answer = $ok && ! $error && ! $wpdb->last_error ? ( $results ? $rows : count( $rows ) ) : false;
			} else {
				$answer = $results ? $wpdb->get_results( $sql ) : $wpdb->query( $sql );
			}
		} catch (Throwable $error) {
			self::record_query( $sql, null, true );
			throw $error;
		}
		$failed = '' !== $wpdb->last_error || ( $table_maintenance && false === $answer );
		self::record_query( $sql, $failed ? null : (int) $wpdb->rows_affected, $failed );
		return $answer;
	}
	public static function record_query( $sql, $affected, $failed = false ) {
		if ( null === self::$active || ! preg_match( '/^\s*(INSERT|REPLACE|UPDATE|DELETE|DROP|TRUNCATE|ALTER|OPTIMIZE|REPAIR|ANALYZE)\b/i', $sql, $match ) ) {
			return;
		}
		$dml = in_array( strtoupper( $match[1] ), [ 'INSERT', 'REPLACE', 'UPDATE', 'DELETE' ], true );
		$query = [ 'sql_hash' => hash( 'sha256', $sql ), 'operation' => strtoupper( $match[1] ), 'site_id' => get_current_blog_id(), 'affected_rows' => $dml && ! $failed ? $affected : null, 'outcome' => $failed ? 'failed' : 'success' ];
		$masked = self::mask_sql( $sql );
		if ( null !== $masked ) {
			$query['sql'] = substr( $masked, 0, self::MAX_SQL_BYTES );
			$query['sql_original_bytes'] = strlen( $sql );
			$query['sql_masked_bytes'] = strlen( $masked );
			$query['sql_logged_bytes'] = strlen( $query['sql'] );
			$query['sql_truncated'] = strlen( $masked ) > self::MAX_SQL_BYTES;
		} else {
			$query['sql_unavailable'] = true;
		}
		$bytes = strlen( wp_json_encode( $query ) );
		if ( count( self::$active['queries'] ) < 100 && self::$query_bytes + $bytes < 49152 ) {
			self::$active['queries'][] = $query;
			self::$query_bytes += $bytes;
		} else {
			self::$active['queries_omitted'] = ( self::$active['queries_omitted'] ?? 0 ) + 1;
		}
		if ( $failed ) {
			self::note( [ 'outcome' => 'failed', 'error_code' => 'database_write_failed' ] );
		}
		if ( $dml && ! $failed ) {
			self::$active['affected_rows'] = ( self::$active['affected_rows'] ?? 0 ) + (int) $affected;
		}
	}
	/** Conservative SQL lexer. Ambiguity removes SQL text, never exposes raw input. */
	public static function mask_sql( $sql ) {
		$out = '';
		$length = strlen( $sql );
		for ( $i = 0; $i < $length; ) {
			$c = $sql[ $i ];
			if ( '#' === $c || ( '-' === $c && substr( $sql, $i, 2 ) === '--' && ( ! isset( $sql[ $i + 2 ] ) || ord( $sql[ $i + 2 ] ) <= 32 ) ) ) {
				$end = strpos( $sql, "\n", $i );
				$i = false === $end ? $length : $end + 1;
				$out .= ' ';
				continue;
			}
			if ( '/*' === substr( $sql, $i, 2 ) ) {
				$end = strpos( $sql, '*/', $i + 2 );
				if ( false === $end ) {
					return null;
				}
				$i = $end + 2;
				$out .= ' ';
				continue;
			}
			if ( "'" === $c || '"' === $c || '`' === $c ) {
				$start = $i++;
				$closed = false;
				while ( $i < $length ) {
					if ( '\\' === $sql[ $i ] && '`' !== $c ) {
						return null;
					} // SQL mode can change backslash semantics.
					if ( $c === $sql[ $i ] ) {
						if ( isset( $sql[ $i + 1 ] ) && $sql[ $i + 1 ] === $c ) {
							$i += 2;
							continue;
						}
						$i++;
						$closed = true;
						break;
					}
					$i++;
				}
				if ( ! $closed ) {
					return null;
				}
				$out .= '`' === $c ? substr( $sql, $start, $i - $start ) : '?';
				continue;
			}
			if ( ctype_alpha( $c ) || '_' === $c || '$' === $c ) {
				$start = $i++;
				while ( $i < $length && ( ctype_alnum( $sql[ $i ] ) || '_' === $sql[ $i ] || '$' === $sql[ $i ] ) ) {
					$i++;
				}
				$word = substr( $sql, $start, $i - $start );
				if ( isset( $sql[ $i ] ) && "'" === $sql[ $i ] && ( in_array( strtoupper( $word ), [ 'X', 'B', 'N' ], true ) || '_' === $word[0] ) ) {
					continue;
				}
				$out .= in_array( strtoupper( $word ), [ 'NULL', 'TRUE', 'FALSE' ], true ) ? '?' : $word;
				continue;
			}
			if ( ctype_digit( $c ) || ( '.' === $c && isset( $sql[ $i + 1 ] ) && ctype_digit( $sql[ $i + 1 ] ) ) ) {
				if ( ! preg_match( '/\G(?:0x[0-9a-f]+|0b[01]+|(?:\d+(?:\.\d*)?|\.\d+)(?:e[+-]?\d+)?)/i', $sql, $m, 0, $i ) ) {
					return null;
				}
				$i += strlen( $m[0] );
				$out .= '?';
				continue;
			}
			if ( ord( $c ) > 127 || false !== strpos( '@\\', $c ) ) {
				return null;
			}
			$out .= $c;
			$i++;
		}
		return trim( preg_replace( '/\s+/', ' ', $out ) );
	}
	/** Bounded identifiers only, never row values or complete request payloads. */
	private static function targets( array $targets ) {
		$result = [];
		$names = [];
		$sites = [];
		$count = 0;
		$visit = function ($value, $depth = 0) use (&$visit, &$names, &$sites, &$count) {
			if ( $depth > 4 || ! is_array( $value ) ) {
				return;
			}
			if ( isset( $value['site_id'] ) ) {
				$sites[ (int) $value['site_id'] ] = true;
			}
			if ( isset( $value['id'] ) || ( isset( $value['name'] ) && is_scalar( $value['name'] ) ) ) {
				$count++;
				if ( isset( $value['name'] ) && is_scalar( $value['name'] ) && count( $names ) < self::MAX_TARGET_NAMES ) {
					$names[] = substr( (string) $value['name'], 0, 191 );
				}
				return;
			}
			foreach ( $value as $key => $child ) {
				if ( is_array( $child ) ) {
					$visit( $child, $depth + 1 );
				}
			}
		};
		foreach ( [ 'tables', 'items_type', 'category' ] as $key ) {
			if ( isset( $targets[ $key ] ) ) {
				$result[ $key ] = is_array( $targets[ $key ] ) ? array_map( function ($v) {
					return substr( (string) $v, 0, 191 );
				}, array_slice( $targets[ $key ], 0, 50 ) ) : substr( (string) $targets[ $key ], 0, 191 );
			}
		}
		$visit( $targets );
		if ( isset( $targets['tables'] ) && is_array( $targets['tables'] ) ) {
			$result['tables_count'] = count( $targets['tables'] );
		}
		if ( isset( $result['tables_count'] ) ) {
			$result['tables_omitted'] = max( 0, $result['tables_count'] - count( $result['tables'] ) );
		}
		if ( $count ) {
			$result['items_count'] = $count;
			$result['names'] = $names;
			$result['names_omitted'] = max( 0, $count - count( $names ) );
		}
		if ( $sites ) {
			$result['site_ids'] = array_slice( array_keys( $sites ), 0, 50 );
		}
		return $result;
	}
	private static function notify( $failed ) {
		$previous = self::$warning;
		self::$warning = $failed ? self::WARNING : '';
		if ( self::$notifying ) {
			return;
		}
		self::$notifying = true;
		try {
			$notifications = ADBC_Notifications::instance();
			$failed ? $notifications->add_notification( 'database_change_log' ) : $notifications->delete_notification( 'database_change_log' );
		} catch (Throwable $ignored) { /* Logging must never change maintenance outcomes. */
		}
		self::$notifying = false;
		if ( $failed && self::WARNING !== $previous && defined( 'WP_CLI' ) && WP_CLI && class_exists( 'WP_CLI' ) ) {
			WP_CLI::warning( self::WARNING );
		}
	}
	private static function directory( $create = false ) {
		$dir = dirname( self::path() );
		clearstatcache( true, $dir );
		if ( is_link( $dir ) || ( file_exists( $dir ) && ! is_dir( $dir ) ) ) {
			return false;
		}
		if ( ! is_dir( $dir ) && ( ! $create || ! wp_mkdir_p( $dir ) ) ) {
			return false;
		}
		$root = realpath( ADBC_UPLOADS_DIR_PATH );
		$resolved = realpath( $dir );
		return $root && $resolved && ! is_link( $dir ) && dirname( $resolved ) === $root;
	}
	private static function safe( $path ) {
		clearstatcache( true, $path );
		return self::directory() && dirname( $path ) === dirname( self::path() ) && ! is_link( $path ) && ( ! file_exists( $path ) || is_file( $path ) );
	}
	private static function same( $handle, $path ) {
		if ( ! self::safe( $path ) || ! is_file( $path ) ) {
			return false;
		}
		$a = fstat( $handle );
		$b = @lstat( $path );
		return $a && $b && ( $b['mode'] & 0170000 ) === 0100000 && $a['dev'] === $b['dev'] && $a['ino'] === $b['ino'];
	}
	private static function locked( callable $callback, $create = true ) {
		if ( ! self::directory( $create ) ) {
			return false;
		}
		$path = dirname( self::path() ) . '/changes-lock.php';
		if ( ! self::safe( $path ) ) {
			return false;
		}
		$handle = @fopen( $path, 'c+' );
		if ( ! $handle ) {
			return false;
		}
		try {
			if ( ! self::same( $handle, $path ) || ! flock( $handle, LOCK_EX ) || ! self::same( $handle, $path ) ) {
				return false;
			}
			if ( 0 === fstat( $handle )['size'] && strlen( self::HEADER ) !== fwrite( $handle, self::HEADER ) ) {
				return false;
			}
			return $callback();
		} finally {
			flock( $handle, LOCK_UN );
			fclose( $handle );
		}
	}
	private static function lines() {
		$path = self::path();
		if ( ! self::safe( $path ) ) {
			return false;
		}
		self::$repair = false;
		if ( ! file_exists( $path ) ) {
			return [];
		}
		$handle = @fopen( $path, 'rb' );
		if ( ! $handle ) {
			return false;
		}
		try {
			if ( ! self::same( $handle, $path ) || fgets( $handle, 100 ) !== self::HEADER ) {
				return false;
			}
			$lines = [];
			$bytes = 0;
			while ( false !== ( $line = fgets( $handle, self::MAX_ENTRY_BYTES + 2 ) ) ) {
				// Never read an unbounded/corrupt file or accept a partial record.
				$bytes += strlen( $line );
				if ( $bytes > self::MAX_BYTES + self::MAX_ENTRY_BYTES || strlen( $line ) > self::MAX_ENTRY_BYTES ) {
					return false;
				}
				if ( substr( $line, -1 ) !== "\n" ) {
					self::$repair = true;
					break;
				}
				$row = json_decode( $line, true );
				$valid = is_array( $row ) && 1 === ( $row['version'] ?? null );
				foreach ( [ 'id', 'timestamp', 'action', 'outcome', 'source' ] as $key ) {
					$valid = $valid && isset( $row[ $key ] ) && is_string( $row[ $key ] );
				}
				$valid = $valid && ( ! isset( $row['queries'] ) || is_array( $row['queries'] ) );
				if ( $valid ) {
					$lines[] = $line;
				} else {
					self::$repair = true;
				}
				if ( count( $lines ) > self::MAX_ENTRIES + 1 ) {
					return false;
				}
			}
			return $lines;
		} finally {
			fclose( $handle );
		}
	}
	private static function replace_file( array $lines ) {
		$path = self::path();
		if ( ! self::safe( $path ) ) {
			return false;
		}
		$temp = dirname( $path ) . '/changes-' . bin2hex( random_bytes( 12 ) ) . '.php';
		$handle = @fopen( $temp, 'x+b' );
		if ( ! $handle ) {
			return false;
		}
		try {
			if ( ! self::same( $handle, $temp ) || strlen( self::HEADER ) !== fwrite( $handle, self::HEADER ) ) {
				return false;
			}
			foreach ( $lines as $line ) {
				if ( strlen( $line ) !== fwrite( $handle, $line ) ) {
					return false;
				}
			}
			if ( ! fflush( $handle ) || ! self::same( $handle, $temp ) ) {
				return false;
			}
			fclose( $handle );
			$handle = null;
			if ( ! self::safe( $path ) || ! @rename( $temp, $path ) ) {
				return false;
			}
			return true;
		} finally {
			if ( is_resource( $handle ) ) {
				fclose( $handle );
			}
			if ( self::safe( $temp ) && file_exists( $temp ) ) {
				@unlink( $temp );
			}
		}
	}
	/** Under the shared lock, any abandoned temporary replacement is safe to retire. */
	private static function cleanup_temps() {
		foreach ( glob( dirname( self::path() ) . '/changes-*.php' ) ?: [] as $path ) {
			if ( preg_match( '/^changes-[a-f0-9]{24}\.php$/', basename( $path ) ) && ( ! self::safe( $path ) || ! @unlink( $path ) ) ) {
				return false;
			}
		}
		return true;
	}
	private static function persist( array $entry ) {
		try {
			$entry['queries_omitted'] = $entry['queries_omitted'] ?? 0;
			while ( true ) {
				$encoded = wp_json_encode( $entry );
				if ( ! is_string( $encoded ) ) {
					self::notify( true );
					return false;
				}
				$line = $encoded . "\n";
				if ( strlen( $line ) <= self::MAX_ENTRY_BYTES ) {
					break;
				}
				if ( ! $entry['queries'] ) {
					self::notify( true );
					return false;
				}
				array_pop( $entry['queries'] );
				$entry['queries_omitted']++;
			}
			if ( strlen( $line ) > self::MAX_ENTRY_BYTES ) {
				self::notify( true );
				return false;
			}
			$saved = self::locked( function () use ($line) {
				if ( ! self::cleanup_temps() ) {
					return false;
				}
				$lines = self::lines();
				if ( false === $lines ) {
					return false;
				}
				$bytes = strlen( self::HEADER ) + array_sum( array_map( 'strlen', $lines ) ) + strlen( $line );
				if ( self::$repair || ! file_exists( self::path() ) || count( $lines ) >= self::MAX_ENTRIES || $bytes > self::MAX_BYTES ) {
					$lines[] = $line;
					while ( count( $lines ) > self::MAX_ENTRIES || $bytes > self::MAX_BYTES ) {
						$bytes -= strlen( array_shift( $lines ) );
					}
					return self::replace_file( $lines );
				}
				$handle = @fopen( self::path(), 'ab' );
				if ( ! $handle ) {
					return false;
				}
				try {
					return self::same( $handle, self::path() ) && strlen( $line ) === fwrite( $handle, $line ) && fflush( $handle );
				} finally {
					fclose( $handle );
				}
			} );
			self::notify( ! $saved );
			return $saved;
		} catch (Throwable $ignored) {
			self::notify( true );
			return false;
		}
	}
	public static function recent( $limit = 100 ) {
		self::$read_error = false;
		$result = self::locked( function () use ($limit) {
			$lines = self::lines();
			return false === $lines ? false : array_map( function ($line) {
				return self::normalize_outcome( json_decode( $line, true ) );
			}, array_reverse( array_slice( $lines, -min( self::MAX_ENTRIES, max( 1, (int) $limit ) ) ) ) );
		}, false );
		if ( false === $result && ( file_exists( self::path() ) || ( file_exists( dirname( self::path() ) ) && ! is_dir( dirname( self::path() ) ) ) ) ) {
			self::$read_error = true;
			self::$warning = 'The database change log could not be read. Check the ADBC uploads folder.';
		}
		return false === $result ? [] : $result;
	}
	public static function clear() {
		return self::locked( function () {
			return self::cleanup_temps() && self::replace_file( [] );
		} );
	}
	public static function export() {
		$rows = self::recent( self::MAX_ENTRIES );
		return wp_json_encode( array_reverse( $rows ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . "\n";
	}

	/** Three user-facing results, retaining execution facts for diagnostics and old records. */
	public static function normalize_outcome( array $entry ) {
		$status = $entry['execution_status'] ?? $entry['outcome'] ?? 'unknown';
		$entry['execution_status'] = $status;
		$skipped = max( 0, (int) ( $entry['skipped_items'] ?? 0 ) );
		$failed = max( 0, (int) ( $entry['failed_items'] ?? 0 ) );
		$progress = ( $entry['affected_rows'] ?? 0 ) > 0 || ( $entry['processed_items'] ?? 0 ) > 0 || ( $entry['confirmed_affected_rows'] ?? 0 ) > 0;
		$unverified = ! empty( $entry['requires_verification'] ) || 'unknown' === $status
			|| ! in_array( $status, [ 'success', 'committed', 'no_change', 'failed', 'rolled_back', 'partial' ], true )
			|| in_array( $entry['error_code'] ?? '', [ 'adbc_sql_outcome_unknown', 'worker_interrupted' ], true );
		$entry['requires_verification'] = $unverified;
		if ( $unverified ) {
			$entry['outcome'] = $progress ? 'partial' : 'failed';
			$entry['reason'] = 'execution_unverified';
			if ( ( $entry['affected_rows'] ?? 0 ) > 0 ) {
				$entry['confirmed_affected_rows'] = $entry['affected_rows'];
			}
			$entry['affected_rows'] = null;
		} elseif ( 'rolled_back' === $status ) {
			$entry['outcome'] = 'failed';
			$entry['reason'] = 'transaction_rolled_back';
			$entry['affected_rows'] = 0;
		} elseif ( 'partial' === $status && ! $progress && 0 === ( $entry['processed_items'] ?? null ) && ( $failed || $skipped ) ) {
			$entry['outcome'] = 'failed';
			$entry['reason'] = $skipped && ! $failed ? 'all_targets_skipped' : 'execution_failed';
		} elseif ( 'partial' === $status || ( ( $failed || $skipped || 'failed' === $status ) && $progress ) ) {
			$entry['outcome'] = 'partial';
			$entry['reason'] = $skipped && ! $failed ? 'some_targets_skipped' : 'some_targets_failed';
		} elseif ( 'failed' === $status || $failed || $skipped ) {
			$entry['outcome'] = 'failed';
			$entry['reason'] = $skipped && ! $failed ? 'all_targets_skipped' : 'execution_failed';
		} else {
			$entry['outcome'] = 'success';
			$entry['reason'] = 'no_change' === $status || 0 === ( $entry['affected_rows'] ?? null ) ? 'no_rows_changed' : ( 'committed' === $status ? 'transaction_committed' : 'operation_completed' );
		}
		return $entry;
	}

	/** Filter the entire bounded history before paging; summary responses exclude SQL text. */
	public static function timeline( array $filters ) {
		$rows = self::recent( self::MAX_ENTRIES );
		$retained_total = count( $rows );
		$rows = array_values( array_filter( $rows, static function ($row) use ($filters) {
			$day = substr( $row['timestamp'], 0, 10 );
			$outcome = $row['outcome'];
			return ( ! $filters['start_date'] || $day >= $filters['start_date'] )
				&& ( ! $filters['end_date'] || $day <= $filters['end_date'] )
				&& ( ! $filters['source'] || ( $row['source'] ?? '' ) === $filters['source'] )
				&& ( empty( $filters['operation'] ) || self::operation_key( $row['action'] ?? '' ) === $filters['operation'] )
				&& ( ! $filters['outcome'] || $outcome === $filters['outcome'] );
		} ) );
		$total = count( $rows );
		$page = min( $filters['page'], max( 1, (int) ceil( $total / $filters['per_page'] ) ) );
		$entries = array_slice( $rows, ( $page - 1 ) * $filters['per_page'], $filters['per_page'] );
		foreach ( $entries as &$entry ) {
			$queries = $entry['queries'] ?? [];
			$entry['queries_count'] = count( $queries ) + (int) ( $entry['queries_omitted'] ?? 0 );
			$entry['operations'] = array_values( array_unique( array_filter( array_column( $queries, 'operation' ), 'is_string' ) ) );
			$entry['sql_truncated'] = (bool) array_filter( $queries, static function ($query) {
				return ! empty( $query['sql_truncated'] );
			} );
			unset( $entry['queries'] );
		}
		unset( $entry );
		return [ 'entries' => $entries, 'total' => $total, 'retained_total' => $retained_total, 'page' => $page, 'per_page' => $filters['per_page'], 'operation_options' => self::operation_options()];
	}
	public static function entry( $id ) {
		foreach ( self::recent( self::MAX_ENTRIES ) as $entry ) {
			if ( $entry['id'] === $id ) {
				return $entry;
			}
		}
		return null;
	}
}
