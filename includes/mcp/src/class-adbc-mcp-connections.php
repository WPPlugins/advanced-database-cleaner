<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Per-connection access controls and bounded display-only client descriptions. */
final class ADBC_MCP_Connections {
	/** Activity and name changes may race this save; access changes must never be overwritten. */
	public static function set_access( string $id, bool $write, bool $expected_write, int $expected_revision ) {
		for ( $attempt = 0; $attempt < 5; $attempt++ ) {
			$old = ADBC_MCP_Store::grant( $id );
			$access = $old ? ADBC_MCP_Access::permissions( $old ) : null;
			if ( ! $access || $access['write_enabled'] !== $expected_write || $access['access_revision'] !== $expected_revision || $expected_revision >= PHP_INT_MAX - 1 ) {
				return new WP_Error( 'adbc_access_conflict', 'Connection access changed. Refresh and review it before saving again.', [ 'status' => 409 ] );
			}
			if ( $write === $expected_write ) {
				return true;
			}
			if ( ADBC_MCP_Store::replace( 'grants', $old, array_replace( $old, [ 'write_enabled' => $write, 'access_revision' => $expected_revision + 1 ] ) ) ) {
				if ( ! $write ) {
					// Generation checks also stop work created concurrently with this sweep.
					foreach ( ADBC_MCP_Store::each( 'previews' ) as $preview ) {
						if ( ( $preview['grant_id'] ?? '' ) === $id && ( $preview['access_revision'] ?? 0 ) <= $expected_revision ) {
							ADBC_MCP_Store::delete( 'previews', $preview['id'], $preview );
						}
					}
					foreach ( ADBC_MCP_Store::each( 'jobs' ) as $job ) {
						if ( ( $job['grant_id'] ?? '' ) === $id && ( $job['access_revision'] ?? 0 ) <= $expected_revision ) {
							ADBC_MCP_Jobs::cancel_record( $job );
						}
					}
				}
				return true;
			}
		}
		return new WP_Error( 'adbc_access_conflict', 'Could not save connection access. Refresh and review it before saving again.', [ 'status' => 409 ] );
	}
	public static function clean_name( $name ) {
		if ( ! is_string( $name ) || strlen( $name ) > 320 || ! preg_match( '//u', $name ) ) {
			return new WP_Error( 'adbc_invalid_connection_name', 'Use a connection name of at most 80 characters.', [ 'status' => 400 ] );
		}
		$name = trim( sanitize_text_field( $name ) );
		if ( mb_strlen( $name, 'UTF-8' ) > 80 ) {
			return new WP_Error( 'adbc_invalid_connection_name', 'Use a connection name of at most 80 characters.', [ 'status' => 400 ] );
		}
		return $name;
	}

	/** Keep only bounded software descriptions supplied by the authenticated client. */
	private static function client_info( $info ): array {
		if ( ! is_array( $info ) || ! isset( $info['name'], $info['version'] ) ) {
			return [];
		}
		$result = [];
		foreach ( [ 'name', 'version', 'title' ] as $field ) {
			if ( ! isset( $info[ $field ] ) ) {
				continue;
			}
			if ( ! is_string( $info[ $field ] ) || strlen( $info[ $field ] ) > 512 ) {
				return [];
			}
			$result[ $field ] = mb_substr( sanitize_text_field( $info[ $field ] ), 0, 80, 'UTF-8' );
		}
		return '' !== trim( $result['name'] ?? '' ) ? $result : [];
	}

	/** Best-effort bookkeeping after accepted MCP requests, throttled per authorization. */
	public static function record_activity( string $id, $info = null ): void {
		$info = self::client_info( $info );
		for ( $attempt = 0; $attempt < 3; $attempt++ ) {
			$old = ADBC_MCP_Store::grant( $id );
			if ( ! $old ) {
				return;
			}
			$changes = [];
			if ( empty( $old['last_used_at'] ) || time() - (int) strtotime( $old['last_used_at'] . ' UTC' ) >= 60 ) {
				$changes['last_used_at'] = gmdate( 'Y-m-d H:i:s' );
			}
			if ( $info && $info !== ( $old['client_info'] ?? [] ) ) {
				$changes['client_info'] = $info;
			}
			if ( ! $changes || ADBC_MCP_Store::replace( 'grants', $old, array_replace( $old, $changes ) ) ) {
				return;
			}
		}
	}

	/** Compare the name rather than record revision: activity must not block a rename. */
	public static function rename( string $id, $name, $expected_name ) {
		$name = self::clean_name( $name );
		if ( is_wp_error( $name ) ) {
			return $name;
		}
		if ( ! is_string( $expected_name ) ) {
			return new WP_Error( 'adbc_invalid_connection_name', 'Refresh the connection before renaming it.', [ 'status' => 400 ] );
		}
		for ( $attempt = 0; $attempt < 3; $attempt++ ) {
			$old = ADBC_MCP_Store::grant( $id );
			if ( ! $old ) {
				return new WP_Error( 'adbc_connection_not_found', 'This connection is unavailable or revoked.', [ 'status' => 404 ] );
			}
			if ( ( $old['connection_name'] ?? '' ) !== $expected_name ) {
				break;
			}
			if ( $name === $expected_name || ADBC_MCP_Store::replace( 'grants', $old, array_replace( $old, [ 'connection_name' => $name ] ) ) ) {
				return true;
			}
		}
		return new WP_Error( 'adbc_connection_name_conflict', 'The connection name changed. Refresh and review it before saving again.', [ 'status' => 409 ] );
	}
}
