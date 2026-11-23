<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Durable MCP records in existing WordPress options; none are autoloaded. */
final class ADBC_MCP_Store {
	public const PREFIX = 'adbc_mcp_';
	private const KINDS = [ 'clients', 'grants', 'tokens', 'previews', 'preview_indexes', 'jobs', 'sessions' ];
	private const RECORD_LIMITS = [ 'grants' => 1000, 'tokens' => 10000, 'previews' => 1000, 'preview_indexes' => 1000, 'jobs' => 1000, 'sessions' => 1000 ];
	private const BYTE_LIMITS = [ 'clients' => 16384, 'grants' => 16384, 'tokens' => 65536, 'previews' => 1048576, 'preview_indexes' => 4096, 'jobs' => 524288, 'sessions' => 1048576 ];

	public static function ready(): bool {
		return null !== ADBC_MCP_Settings::config() && null !== ADBC_MCP_Settings::state();
	}

	public static function install(): void {
		if ( self::ready() ) {
			return;
		}
		try {
			if ( ! ADBC_MCP_Settings::install() || ! self::ready() ) {
				throw new RuntimeException( 'Could not initialize MCP storage.' );
			}
			ADBC_MCP_Settings::set_state( [ 'storage_error' => '' ] );
		} catch (Throwable $error) {
			ADBC_MCP_Settings::set_state( [ 'storage_error' => $error->getMessage() ] );
		}
	}

	public static function key( string $kind, string $id ): string {
		if ( ! in_array( $kind, self::KINDS, true ) ) {
			throw new InvalidArgumentException( 'Invalid MCP record kind.' );
		}
		return self::PREFIX . $kind . '_' . hash( 'sha256', $id );
	}

	/** Security and worker state bypasses a potentially stale object cache. */
	public static function get( string $kind, string $id ): ?array {
		global $wpdb;
		self::key( $kind, $id );
		if ( 'clients' === $kind ) {
			$row = ADBC_MCP_Settings::get( 'clients', [] )[ $id ] ?? null;
			return is_array( $row ) && ( $row['id'] ?? null ) === $id && is_int( $row['_revision'] ?? null ) && $row['_revision'] > 0 && $row['_revision'] < PHP_INT_MAX ? $row : null;
		}
		$value = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s AND OCTET_LENGTH(option_value) <= %d", self::key( $kind, $id ), self::BYTE_LIMITS[ $kind ] ) );
		$row = null === $value ? null : json_decode( $value, true );
		if ( ! is_array( $row ) || ! isset( $row['id'], $row['_revision'] ) || ! is_string( $row['id'] ) || ! hash_equals( $id, $row['id'] ) || ! is_int( $row['_revision'] ) || $row['_revision'] <= 0 || $row['_revision'] >= PHP_INT_MAX ) {
			return null;
		}
		if ( self::valid_expiry( $row['expires_at'] ?? null ) && strtotime( $row['expires_at'] . ' UTC' ) < time() ) {
			self::delete( $kind, $id, $row );
			return null;
		}
		return $row;
	}

	public static function add( string $kind, array $row ): bool {
		global $wpdb;
		self::key( $kind, (string) ( $row['id'] ?? '' ) );
		$row = array_merge( self::defaults( $kind ), $row, [ '_revision' => 1 ] );
		$encoded = wp_json_encode( $row );
		if ( ! is_string( $encoded ) || strlen( $encoded ) > self::BYTE_LIMITS[ $kind ] ) {
			self::capacity_error( $kind );
			return false;
		}
		if ( 'clients' === $kind ) {
			return ADBC_MCP_Settings::mutate_config( static function ( $config ) use ( $row ) {
				if ( isset( $config['clients'][ $row['id'] ] ) || count( $config['clients'] ) >= ADBC_MCP_Settings::MAX_CLIENTS ) {
					return false;
				}
				$row['_sequence'] = ++$config['client_sequence'];
				$config['clients'][ $row['id'] ] = $row;
				return $config;
			} );
		}
		// Serialize only new-record admission; never change a caller's transaction.
		$lock = hash( 'sha256', DB_NAME . '|' . $wpdb->options . '|' . $kind . '|adbc-mcp-capacity' );
		if ( '1' !== (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 1)', $lock ) ) ) {
			return false;
		}
		try {
			$count = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( self::PREFIX . $kind . '_' ) . '%' ) );
			if ( null === $count || $wpdb->last_error ) {
				return false;
			}
			if ( (int) $count >= self::RECORD_LIMITS[ $kind ] ) {
				self::capacity_error( $kind );
				return false;
			}
			$saved = add_option( self::key( $kind, (string) $row['id'] ), $encoded, '', false );
			if ( $saved && ADBC_MCP_Settings::get_state( 'capacity_error_kind', '' ) === $kind ) {
				ADBC_MCP_Settings::set_state( [ 'capacity_error' => '', 'capacity_error_kind' => '' ] );
			}
			return $saved;
		} finally {
			$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) );
		}
	}
	private static function capacity_error( string $kind ): void {
		ADBC_MCP_Settings::set_state( [ 'capacity_error_kind' => $kind, 'capacity_error' => 'MCP ' . $kind . ' storage limit reached. Narrow the request or check housekeeping before retrying.' ] );
	}

	/** Compare the whole record and revision so only one concurrent claim wins. */
	public static function replace( string $kind, array $old, array $new ): bool {
		global $wpdb;
		$key = self::key( $kind, (string) $old['id'] );
		$new['id'] = $old['id'];
		$new['_revision'] = (int) ( $old['_revision'] ?? 0 ) + 1;
		if ( 'grants' === $kind && ! empty( $new['revoked'] ) && empty( $old['revoked'] ) ) {
			$new['revoked_at'] = gmdate( 'Y-m-d H:i:s' );
		}
		$encoded = wp_json_encode( $new );
		if ( ! is_string( $encoded ) || strlen( $encoded ) > self::BYTE_LIMITS[ $kind ] ) {
			self::capacity_error( $kind );
			return false;
		}
		if ( 'clients' === $kind ) {
			$new['_sequence'] = $old['_sequence'];
			return ADBC_MCP_Settings::mutate_config( static function ( $config ) use ( $old, $new ) {
				if ( ( $config['clients'][ $old['id'] ] ?? null ) !== $old ) {
					return false;
				}
				$config['clients'][ $old['id'] ] = $new;
				return $config;
			} );
		}
		$changed = $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->options} SET option_value = %s, autoload = 'off' WHERE option_name = %s AND BINARY option_value = BINARY %s", $encoded, $key, wp_json_encode( $old ) ) );
		self::uncache( $key );
		return 1 === $changed;
	}

	public static function update( string $kind, string $id, array $changes ): bool {
		for ( $attempt = 0; $attempt < 3; $attempt++ ) {
			$old = self::get( $kind, $id );
			if ( ! $old ) {
				return false;
			}
			if ( self::replace( $kind, $old, array_replace( $old, $changes ) ) ) {
				return true;
			}
		}
		return false;
	}

	public static function put( string $kind, array $row ): bool {
		$old = self::get( $kind, (string) $row['id'] );
		return $old ? self::update( $kind, (string) $row['id'], $row ) : self::add( $kind, $row );
	}

	public static function delete( string $kind, string $id, ?array $expected = null ): bool {
		global $wpdb;
		$key = self::key( $kind, $id );
		if ( 'clients' === $kind ) {
			return ADBC_MCP_Settings::mutate_config( static function ( $config ) use ( $id, $expected ) {
				if ( ! isset( $config['clients'][ $id ] ) || ( null !== $expected && $config['clients'][ $id ] !== $expected ) ) {
					return false;
				}
				unset( $config['clients'][ $id ] );
				return $config;
			} );
		}
		$sql = null === $expected ? $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s", $key ) : $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s AND BINARY option_value = BINARY %s", $key, wp_json_encode( $expected ) );
		$changed = $wpdb->query( $sql );
		self::uncache( $key );
		if ( 1 === $changed && 'previews' === $kind && $expected ) {
			self::forget_preview_index( $expected );
		}
		return 1 === $changed;
	}
	private static function forget_preview_index( array $preview ): void {
		if ( ! isset( $preview['user_id'], $preview['grant_id'], $preview['ability'], $preview['args_hash'] ) ) {
			return;
		}
		$id = hash( 'sha256', wp_json_encode( [ $preview['user_id'], $preview['grant_id'], $preview['ability'], $preview['args_hash'] ] ) );
		$index = self::get( 'preview_indexes', $id );
		if ( $index && ( $index['preview_id'] ?? null ) === $preview['id'] ) {
			self::delete( 'preview_indexes', $id, $index );
		}
	}

	private static function uncache( string $key ): void {
		wp_cache_delete( $key, 'options' );
		wp_cache_delete( 'notoptions', 'options' );
	}

	/** Bounded pages for the dashboard, revocation, and housekeeping. */
	public static function records( string $kind, int $limit = 100, int $after = 0, bool $latest = true ): array {
		return self::page( $kind, $limit, $after, $latest )['records'];
	}

	/** Advance over raw rows even when a corrupt record is skipped. */
	public static function page( string $kind, int $limit = 100, int $after = 0, bool $latest = true ): array {
		global $wpdb;
		self::key( $kind, '' );
		$limit = min( 1000, max( 1, $limit ) );
		if ( 'clients' === $kind ) {
			$all = [];
			foreach ( ADBC_MCP_Settings::get( 'clients', [] ) as $row ) {
				if ( ( $row['_sequence'] ?? 0 ) > $after ) {
					$all[ $row['_sequence'] ] = $row;
				}
			}
			$latest ? krsort( $all ) : ksort( $all );
			$result = array_slice( $all, 0, $limit, true );
			return [ 'records' => $result, 'cursor' => $result ? (int) array_key_last( $result ) : $after, 'has_more' => count( $all ) > $limit ];
		}
		$order = $latest ? 'DESC' : 'ASC';
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT option_id,option_name,OCTET_LENGTH(option_value) AS bytes FROM {$wpdb->options} WHERE option_name LIKE %s AND option_id > %d ORDER BY option_id $order LIMIT %d", $wpdb->esc_like( self::PREFIX . $kind . '_' ) . '%', $after, $limit ), ARRAY_A );
		if ( $wpdb->last_error ) {
			throw new RuntimeException( 'Could not read MCP storage records.' );
		}
		$result = [];
		$cursor = $after;
		$bytes = 0;
		$more = count( $rows ?: [] ) === $limit;
		foreach ( $rows ?: [] as $row ) {
			if ( $bytes + (int) $row['bytes'] > 2 * MB_IN_BYTES && $bytes > 0 ) {
				$more = true;
				break;
			}
			$cursor = (int) $row['option_id'];
			if ( (int) $row['bytes'] > self::BYTE_LIMITS[ $kind ] ) {
				self::capacity_error( $kind );
				continue;
			}
			$raw = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_id = %d AND OCTET_LENGTH(option_value) <= %d", $cursor, self::BYTE_LIMITS[ $kind ] ) );
			if ( $wpdb->last_error ) {
				throw new RuntimeException( 'Could not read an MCP storage record.' );
			}
			$bytes += strlen( (string) $raw );
			$value = null === $raw ? null : json_decode( $raw, true );
			if ( is_array( $value ) && isset( $value['id'], $value['_revision'] ) && is_string( $value['id'] ) && is_int( $value['_revision'] ) && $value['_revision'] > 0 && $value['_revision'] < PHP_INT_MAX && hash_equals( self::key( $kind, $value['id'] ), $row['option_name'] ) ) {
				$result[ $cursor ] = $value;
			}
		}
		return [ 'records' => $result, 'cursor' => $cursor, 'has_more' => $more ];
	}

	public static function each( string $kind ): Generator {
		$after = 0;
		$limit = 'jobs' === $kind ? 20 : 100;
		do {
			$page = self::page( $kind, $limit, $after, false );
			$after = $page['cursor'];
			foreach ( $page['records'] as $row ) {
				yield $row;
			}
		} while ( $page['has_more'] );
	}

	public static function delete_matching( string $kind, array $where = [] ): void {
		foreach ( self::each( $kind ) as $row ) {
			if ( ! array_diff_assoc( $where, array_intersect_key( $row, $where ) ) ) {
				self::delete( $kind, $row['id'], $row );
			}
		}
	}

	private static function defaults( string $kind ): array {
		$defaults = [ 'clients' => [ 'enabled' => 1, 'metadata_url' => '' ], 'grants' => [ 'channel' => 'mcp', 'revoked' => 0, 'connection_name' => '', 'last_used_at' => '', 'client_info' => [], 'write_enabled' => false, 'access_revision' => 1 ], 'tokens' => [ 'revoked' => 0 ], 'previews' => [ 'approved' => 0 ] ];
		return $defaults[ $kind ] ?? [];
	}

	public static function issue_preview( $ability, array $args, array $summary ) {
		$allowed = ADBC_MCP_Access::check( $ability );
		if ( is_wp_error( $allowed ) ) {
			return $allowed;
		}
		$grant = ADBC_MCP_Context::grant_id();
		$args_hash = hash( 'sha256', wp_json_encode( $args ) );
		$index_id = hash( 'sha256', wp_json_encode( [ get_current_user_id(), $grant, $ability, $args_hash ] ) );
		$index = self::get( 'preview_indexes', $index_id );
		$old = $index && is_string( $index['preview_id'] ?? null ) ? self::get( 'previews', $index['preview_id'] ) : null;
		if ( $old && ( self::preview_matches( $old, $ability, $args, 0 ) || ( 'execute_sql' === $ability && self::preview_matches( $old, $ability, $args, 1 ) ) ) ) {
			$stored = json_decode( $old['summary_json'], true );
			$secret = hash_hmac( 'sha256', $stored['_confirmation_nonce'], wp_salt( 'auth' ) );
			if ( empty( $old['approved'] ) && hash_equals( $old['id'], hash( 'sha256', $secret ) ) && ( 'execute_sql' !== $ability || ( $stored['targets']['_sql_snapshot']['digest'] ?? null ) === ( $summary['_sql_snapshot']['digest'] ?? null ) ) ) {
				return $secret;
			}
			if ( ! self::delete( 'previews', $old['id'], $old ) ) {
				return new WP_Error( 'adbc_preview_conflict', 'The previous preview changed. Request a new preview.', [ 'status' => 409 ] );
			}
		}
		$nonce = bin2hex( random_bytes( 32 ) );
		$secret = hash_hmac( 'sha256', $nonce, wp_salt( 'auth' ) );
		$expires = gmdate( 'Y-m-d H:i:s', time() + 300 );
		$row = [ 'id' => hash( 'sha256', $secret ), 'user_id' => get_current_user_id(), 'grant_id' => $grant, 'channel' => ADBC_MCP_Context::channel(), 'ability' => $ability, 'args_hash' => $args_hash, 'summary_json' => wp_json_encode( [ '_confirmation_nonce' => $nonce, 'args' => $args, 'targets' => $summary ] ), 'approved' => 0, 'created_at' => current_time( 'mysql', true ), 'expires_at' => $expires ];
		$row['access_revision'] = ADBC_MCP_Context::access_revision() ?? ADBC_MCP_Access::revision();
		if ( ! self::add( 'previews', $row ) ) {
			return new WP_Error( 'adbc_preview_failed', 'Could not create the approval request.', [ 'status' => 500 ] );
		}
		self::put( 'preview_indexes', [ 'id' => $index_id, 'preview_id' => $row['id'], 'expires_at' => $expires ] );
		return $secret;
	}

	private static function preview( $secret ): ?array {
		return is_string( $secret ) && preg_match( '/^[a-f0-9]{64}$/', $secret ) ? self::get( 'previews', hash( 'sha256', $secret ) ) : null;
	}

	private static function preview_matches( array $row, string $ability, array $args, int $approved ): bool {
		if ( ! isset( $row['user_id'], $row['grant_id'], $row['ability'], $row['approved'], $row['expires_at'], $row['args_hash'], $row['summary_json'] )
			|| ! is_int( $row['user_id'] ) || $row['user_id'] < 1 || ! is_string( $row['grant_id'] ) || ! is_string( $row['ability'] )
			|| ! in_array( $row['approved'], [ 0, 1 ], true ) || ! self::valid_expiry( $row['expires_at'] )
			|| ! is_string( $row['args_hash'] ) || ! preg_match( '/^[a-f0-9]{64}$/', $row['args_hash'] ) || ! is_string( $row['summary_json'] ) ) {
			return false;
		}
		$stored = json_decode( $row['summary_json'], true );
		if ( ! is_array( $stored ) || ! is_array( $stored['args'] ?? null ) || ! is_array( $stored['targets'] ?? null )
			|| ! is_string( $stored['_confirmation_nonce'] ?? null ) || ! preg_match( '/^[a-f0-9]{64}$/', $stored['_confirmation_nonce'] ) ) {
			return false;
		}
		$grant = ADBC_MCP_Context::grant_id();
		$revision = $row['access_revision'] ?? 0;
		return is_int( $revision ) && $revision >= 0 && true === ADBC_MCP_Access::check( $ability, $revision ) && ( $row['channel'] ?? 'mcp' ) === ADBC_MCP_Context::channel() && (int) $row['user_id'] === get_current_user_id() && $row['grant_id'] === $grant && $row['ability'] === $ability && (int) $row['approved'] === $approved && strtotime( $row['expires_at'] . ' UTC' ) >= time() && hash_equals( $row['args_hash'], hash( 'sha256', wp_json_encode( $args ) ) );
	}

	public static function pending_preview( $secret ) {
		$grant = ADBC_MCP_Context::grant_id();
		$row = self::preview( $secret );
		if ( '' === $grant || ! ADBC_MCP_Access::authorized() || ! $row || ! is_string( $row['summary_json'] ?? null ) || ! is_string( $row['ability'] ?? null ) ) {
			return false;
		}
		$stored = json_decode( $row['summary_json'], true );
		if ( ! is_array( $stored ) || ! is_array( $stored['targets'] ?? null ) ) {
			return false;
		}
		$args = $stored['args'] ?? null;
		$definition = ADBC_MCP_Abilities::definitions()[ $row['ability'] ] ?? null;
		if ( ! is_array( $args ) || ! $definition || ! $definition['destructive'] || array_key_exists( 'confirmation_token', $args ) || ! self::preview_matches( $row, $row['ability'], $args, 0 ) ) {
			return false;
		}
		return [ 'ability' => $row['ability'], 'args' => $args, 'targets' => array_diff_key( $stored['targets'] ?? [], [ '_sql_snapshot' => true ] ), 'expires_at' => $row['expires_at'], 'access_revision' => $row['access_revision'] ?? 0 ];
	}

	public static function approve_client_preview( $secret, $ability, array $args ): bool {
		$row = self::preview( $secret );
		return $row && self::preview_matches( $row, $ability, $args, 0 ) && self::replace( 'previews', $row, array_replace( $row, [ 'approved' => 1 ] ) );
	}

	public static function consume_preview( $secret, $ability, array $args ): bool {
		return null !== self::consume_preview_targets( $secret, $ability, $args );
	}

	/** Return only the snapshot whose approved record this request atomically claimed. */
	public static function consume_preview_targets( $secret, $ability, array $args ): ?array {
		$row = self::preview( $secret );
		if ( ! $row || ! self::preview_matches( $row, $ability, $args, 1 ) || ! self::delete( 'previews', $row['id'], $row ) ) {
			return null;
		}
		$stored = json_decode( $row['summary_json'], true );
		return is_array( $stored['targets'] ?? null ) ? $stored['targets'] : null;
	}

	public static function discard_preview( $secret, $ability, array $args ): void {
		$row = self::preview( $secret );
		if ( $row && self::preview_matches( $row, $ability, $args, (int) $row['approved'] ) ) {
			self::delete( 'previews', $row['id'], $row );
		}
	}

	public static function grant( $id ): ?array {
		$row = self::get( 'grants', (string) $id );
		if ( $row && ( ! isset( $row['write_enabled'], $row['access_revision'] ) || ! is_bool( $row['write_enabled'] ) || ! is_int( $row['access_revision'] ) || $row['access_revision'] < 0 || $row['access_revision'] >= PHP_INT_MAX ) ) {
			return null;
		}
		if ( ! $row || ! isset( $row['user_id'], $row['client_id'], $row['auth_type'], $row['revoked'] )
			|| ! ( is_int( $row['user_id'] ) || is_string( $row['user_id'] ) ) || false === filter_var( $row['user_id'], FILTER_VALIDATE_INT, [ 'options' => [ 'min_range' => 1 ] ] )
			|| ! is_string( $row['client_id'] ) || '' === $row['client_id'] || ! in_array( $row['auth_type'], [ 'oauth', 'app_password' ], true )
			|| ! in_array( $row['revoked'], [ 0, false, '0' ], true ) || 'mcp' !== ( $row['channel'] ?? 'mcp' ) ) {
			return null;
		}
		if ( 'oauth' === $row['auth_type'] ) {
			$client = self::get( 'clients', $row['client_id'] );
			if ( ! $client || empty( $client['enabled'] ) ) {
				return null;
			}
		}
		return $row;
	}

	/** Stored deadlines use UTC SQL timestamps, never relative date expressions. */
	public static function valid_expiry( $value ): bool {
		return is_string( $value ) && (bool) preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D', $value ) && false !== strtotime( $value . ' UTC' );
	}

	public static function prune(): bool {
		$more = false;
		$deadline = microtime( true ) + 3;
		foreach ( [ 'previews', 'preview_indexes', 'tokens', 'sessions', 'jobs', 'grants' ] as $kind ) {
			$cursors = ADBC_MCP_Settings::get_state( 'cursors', [] );
			// Finished kinds stay finished until the entire sweep completes. Otherwise
			// differently sized retained backlogs can keep continuations cycling.
			if ( -1 === ( $cursors[ $kind ] ?? 0 ) ) {
				continue;
			}
			if ( microtime( true ) >= $deadline ) {
				$more = true;
				break;
			}
			$limit = in_array( $kind, [ 'jobs', 'grants' ], true ) ? 20 : 100;
			$page = self::page( $kind, $limit, (int) ( $cursors[ $kind ] ?? 0 ), false );
			$more = $more || $page['has_more'];
			$cursor = (int) ( $cursors[ $kind ] ?? 0 );
			$finished = true;
			foreach ( $page['records'] as $option_id => $row ) {
				if ( microtime( true ) >= $deadline ) {
					$finished = false;
					$more = true;
					break;
				}
				$cursor = (int) $option_id;
				$expired = self::valid_expiry( $row['expires_at'] ?? null ) && strtotime( $row['expires_at'] . ' UTC' ) < time();
				$remove = 'grants' === $kind ? self::removable_grant( $row ) : $expired || ( 'tokens' === $kind && ( ! empty( $row['revoked'] ) || ! self::grant( (string) ( $row['grant_id'] ?? '' ) ) ) );
				if ( $remove && ! self::delete( $kind, $row['id'], $row ) ) {
					global $wpdb;
					if ( $wpdb->last_error ) {
						throw new RuntimeException( 'Could not clean an expired MCP record.' );
					}
				}
			}
			$next_cursor = $finished ? ( $page['has_more'] ? $page['cursor'] : -1 ) : $cursor;
			if ( ! ADBC_MCP_Settings::set_cursor( $kind, $next_cursor ) ) {
				throw new RuntimeException( 'Could not checkpoint MCP housekeeping.' );
			}
		}
		if ( ! $more && ! ADBC_MCP_Settings::set_state( [ 'cursors' => [] ] ) ) {
			throw new RuntimeException( 'Could not finish MCP housekeeping.' );
		}
		return $more;
	}
	private static function removable_grant( array $grant ): bool {
		global $wpdb;
		if ( empty( $grant['revoked'] ) ) {
			return false;
		}
		if ( ! self::valid_expiry( $grant['revoked_at'] ?? null ) ) {
			// Start retention for already revoked records without resetting it each pass.
			self::replace( 'grants', $grant, array_replace( $grant, [ 'revoked_at' => gmdate( 'Y-m-d H:i:s' ) ] ) );
			return false;
		}
		if ( strtotime( $grant['revoked_at'] . ' UTC' ) >= time() - 7 * DAY_IN_SECONDS ) {
			return false;
		}
		// Keep a linked MCP password's revocation tombstone until WordPress also
		// revokes that password; relinking is an explicit administrator action.
		if ( 'app_password' === ( $grant['auth_type'] ?? '' ) && WP_Application_Passwords::get_user_application_password( (int) $grant['user_id'], (string) $grant['client_id'] ) ) {
			return false;
		}
		foreach ( [ 'tokens', 'jobs', 'sessions', 'previews' ] as $kind ) {
			$count = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE %s AND JSON_UNQUOTE(JSON_EXTRACT(IF(JSON_VALID(option_value),option_value,'{}'),'$.grant_id')) = %s", $wpdb->esc_like( self::PREFIX . $kind . '_' ) . '%', $grant['id'] ) );
			if ( null === $count || $wpdb->last_error || (int) $count > 0 ) {
				return false;
			}
		}
		return true;
	}
}
