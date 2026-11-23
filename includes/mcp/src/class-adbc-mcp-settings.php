<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Two small, non-autoloaded arrays. Security-sensitive reads bypass object caches. */
final class ADBC_MCP_Settings {
	public const CONFIG = 'adbc_mcp_config';
	public const STATE = 'adbc_mcp_state';
	public const MAX_CLIENTS = 100;
	private const CONFIG_BYTES = 1048576;
	private const STATE_BYTES = 16384;

	private static function read( string $name ): ?array {
		global $wpdb;
		$limit = self::CONFIG === $name ? self::CONFIG_BYTES : self::STATE_BYTES;
		$raw = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s AND OCTET_LENGTH(option_value) <= %d", $name, $limit ) );
		$value = is_string( $raw ) ? json_decode( $raw, true ) : null;
		return is_array( $value ) && is_int( $value['_revision'] ?? null ) && $value['_revision'] > 0 && $value['_revision'] < PHP_INT_MAX ? $value : null;
	}
	public static function config(): ?array {
		return self::read( self::CONFIG );
	}
	public static function state(): ?array {
		return self::read( self::STATE );
	}
	public static function get( string $field, $default = false ) {
		return self::config()[ $field ] ?? $default;
	}
	public static function get_state( string $field, $default = false ) {
		return self::state()[ $field ] ?? $default;
	}
	public static function abilities_enabled(): bool {
		$config = self::config();
		return true === ( $config['abilities_enabled'] ?? false );
	}
	public static function mcp_enabled(): bool {
		$config = self::config();
		return ! empty( $config['enabled'] ) && true === ( $config['abilities_enabled'] ?? false );
	}

	/** Retry a whole-array compare-and-swap without losing another writer's fields. */
	private static function mutate( string $name, callable $change ): bool {
		global $wpdb;
		for ( $attempt = 0; $attempt < 5; $attempt++ ) {
			$old = self::read( $name );
			if ( ! $old ) {
				return false;
			}
			$new = $change( $old );
			if ( ! is_array( $new ) ) {
				return false;
			}
			if ( $new === $old ) {
				return true;
			}
			$new['_revision'] = $old['_revision'] + 1;
			$encoded = wp_json_encode( $new );
			if ( ! is_string( $encoded ) || strlen( $encoded ) > ( self::CONFIG === $name ? self::CONFIG_BYTES : self::STATE_BYTES ) ) {
				return false;
			}
			$changed = $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->options} SET option_value = %s, autoload = 'off' WHERE option_name = %s AND BINARY option_value = BINARY %s", $encoded, $name, wp_json_encode( $old ) ) );
			self::uncache( $name );
			if ( false === $changed ) {
				return false;
			}
			if ( 1 === $changed ) {
				return true;
			}
		}
		return false;
	}
	public static function mutate_config( callable $change ): bool {
		return self::mutate( self::CONFIG, $change );
	}
	public static function set( string $field, $value ): bool {
		if ( ! in_array( $field, [ 'enabled', 'abilities_enabled', 'keys' ], true ) ) {
			return false;
		}
		return self::mutate_config( static function ( $row ) use ( $field, $value ) {
			$row[ $field ] = $value;
			return $row; } );
	}
	public static function add( string $field, $value ): bool {
		if ( 'keys' !== $field ) {
			return false;
		}
		return self::mutate_config( static function ( $row ) use ( $field, $value ) {
			if ( array_key_exists( $field, $row ) ) {
				return false;
			}
			$row[ $field ] = $value;
			return $row;
		} );
	}
	public static function delete( string $field ): bool {
		if ( 'keys' !== $field ) {
			return false;
		}
		return self::mutate_config( static function ( $row ) use ( $field ) {
			unset( $row[ $field ] );
			return $row; } );
	}
	public static function set_state( array $changes ): bool {
		$fields = [ 'cursors', 'recovery_cursor', 'storage_error', 'audit_error', 'housekeeping_error', 'capacity_error', 'capacity_error_kind', 'last_housekeeping_at' ];
		if ( array_diff( array_keys( $changes ), $fields ) ) {
			return false;
		}
		return self::mutate( self::STATE, static fn( $row ) => array_replace( $row, $changes ) );
	}
	public static function set_cursor( string $kind, int $cursor ): bool {
		return self::mutate( self::STATE, static function ( $row ) use ( $kind, $cursor ) {
			$row['cursors'][ $kind ] = $cursor;
			return $row; } );
	}
	/** Bound uncached OAuth discovery without creating per-request option/transient rows. */
	public static function reserve_metadata_fetch( int $user_id ): bool {
		if ( $user_id < 1 ) {
			return false;
		}
		$window = intdiv( time(), 60 );
		return self::mutate( self::STATE, static function ( $state ) use ( $user_id, $window ) {
			$budgets = self::metadata_budgets( $state, $window );
			if ( ( ! isset( $budgets[ $user_id ] ) && count( $budgets ) >= 100 ) || ( $budgets[ $user_id ]['count'] ?? 0 ) >= 10 ) {
				return false;
			}
			$budgets[ $user_id ] = [ 'window' => $window, 'count' => ( $budgets[ $user_id ]['count'] ?? 0 ) + 1 ];
			$state['oauth_metadata_requests'] = $budgets;
			return $state;
		} );
	}
	private static function metadata_budgets( array $state, int $window ): array {
		$rows = $state['oauth_metadata_requests'] ?? [];
		return is_array( $rows ) ? array_filter( $rows, static fn( $row ) => is_array( $row ) && ( $row['window'] ?? null ) === $window && is_int( $row['count'] ?? null ) && $row['count'] >= 0 ) : [];
	}
	public static function prune_metadata_budget(): void {
		self::mutate( self::STATE, static function ( $state ) {
			if ( array_key_exists( 'oauth_metadata_requests', $state ) ) {
				$state['oauth_metadata_requests'] = self::metadata_budgets( $state, intdiv( time(), 60 ) );
			}
			return $state;
		} );
	}
	private static function uncache( string $name ): void {
		wp_cache_delete( $name, 'options' );
		wp_cache_delete( 'notoptions', 'options' );
	}

	/** Initialize the current layout without importing or resetting existing records. */
	public static function install(): bool {
		if ( null === self::state() ) {
			$state = [ '_revision' => 1, 'cursors' => [], 'recovery_cursor' => 0, 'storage_error' => '', 'audit_error' => '', 'housekeeping_error' => '', 'capacity_error' => '', 'last_housekeeping_at' => '' ];
			if ( ! add_option( self::STATE, wp_json_encode( $state ), '', false ) && null === self::state() ) {
				return false;
			}
		}
		if ( null === self::config() ) {
			$config = [ '_revision' => 1, 'enabled' => false, 'abilities_enabled' => false, 'clients' => [], 'client_sequence' => 0 ];
			if ( ! add_option( self::CONFIG, wp_json_encode( $config ), '', false ) && null === self::config() ) {
				return false;
			}
		}
		return null !== self::config() && null !== self::state();
	}
}
