<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ADBC_MCP_Admin {
	public static function register(): void {
		register_rest_route( 'advanced-db-cleaner/v1', '/mcp/admin', [
			[ 'methods' => 'GET', 'callback' => [ self::class, 'status' ], 'permission_callback' => [ self::class, 'permitted' ] ],
			[ 'methods' => 'POST', 'callback' => [ self::class, 'change' ], 'permission_callback' => [ self::class, 'permitted' ] ],
		] );
	}
	public static function permitted( WP_REST_Request $request ): bool {
		$cookie = isset( $_COOKIE[ LOGGED_IN_COOKIE ] ) ? (string) $_COOKIE[ LOGGED_IN_COOKIE ] : '';
		return ADBC_MCP_Executor::can_execute()
			&& '' !== $cookie
			&& (int) wp_validate_auth_cookie( $cookie, 'logged_in' ) === get_current_user_id()
			&& (bool) wp_verify_nonce( (string) $request->get_header( 'x-wp-nonce' ), 'wp_rest' );
	}
	public static function status( ?WP_REST_Request $request = null ): array {
		if ( $request && 'integrations' === $request->get_param( 'view' ) ) {
			return self::integration_status();
		}
		$grants = self::public_records( 'grants', [ 'id', 'client_id', 'user_id', 'auth_type', 'channel', 'revoked', 'created_at', 'connection_name', 'last_used_at', 'client_info', '_revision', 'write_enabled', 'access_revision' ] );
		foreach ( $grants as &$grant ) {
			$grant = array_replace( $grant, ADBC_MCP_Access::permissions( $grant ) );
			$grant['channel'] = $grant['channel'] ?? 'mcp';
			$client = 'oauth' === $grant['auth_type'] ? ADBC_MCP_Store::get( 'clients', $grant['client_id'] ) : null;
			$grant['client_name'] = $client['name'] ?? '';
			$user = get_userdata( (int) $grant['user_id'] );
			$grant['user_login'] = $user ? $user->user_login : '';
			$grant['user_display_name'] = $user ? $user->display_name : '';
			if ( 'app_password' === $grant['auth_type'] && class_exists( 'WP_Application_Passwords' ) ) {
				foreach ( WP_Application_Passwords::get_user_application_passwords( (int) $grant['user_id'] ) as $app ) {
					if ( $app['uuid'] === $grant['client_id'] ) {
						$grant['app_password_name'] = $app['name'];
						break;
					}
				}
			}
		}
		unset( $grant );
		$app_passwords = [];
		if ( class_exists( 'WP_Application_Passwords' ) ) {
			foreach ( WP_Application_Passwords::get_user_application_passwords( get_current_user_id() ) as $app ) {
				$app_passwords[] = [ 'uuid' => $app['uuid'], 'name' => $app['name'] ];
			}
		}
		return [ 'storage_ready' => ADBC_MCP_Store::ready(), 'storage_error' => ADBC_MCP_Settings::get_state( 'storage_error', '' ), 'audit_error' => ADBC_Change_Log::stored_warning(), 'housekeeping_error' => self::housekeeping_error(), 'capacity_error' => ADBC_MCP_Settings::get_state( 'capacity_error', '' ), 'enabled' => ADBC_MCP_Settings::mcp_enabled(), 'abilities_enabled' => ADBC_MCP_Settings::abilities_enabled(), 'ready' => ADBC_MCP_OAuth::transport_secure(), 'resource_url' => ADBC_MCP_OAuth::resource(), 'authorization_url' => ADBC_MCP_OAuth::authorization_url(), 'token_url' => ADBC_MCP_OAuth::token_url(), 'profile_url' => admin_url( 'profile.php#application-passwords-section' ), 'connections' => $grants, 'application_passwords' => $app_passwords ];
	}
	/** Lightweight settings snapshot shared by the admin page and REST responses. */
	public static function integration_status(): array {
		return [ 'enabled' => ADBC_MCP_Settings::mcp_enabled(), 'abilities_enabled' => ADBC_MCP_Settings::abilities_enabled(), 'ready' => ADBC_MCP_OAuth::transport_secure(), 'storage_ready' => ADBC_MCP_Store::ready(), 'storage_error' => ADBC_MCP_Settings::get_state( 'storage_error', '' ) ];
	}
	private static function abilities_required(): WP_Error {
		return new WP_Error( 'adbc_abilities_required', 'Enable ADBC abilities in Settings before enabling the MCP server.', [ 'status' => 400 ] );
	}
	private static function housekeeping_error(): string {
		$error = (string) ADBC_MCP_Settings::get_state( 'housekeeping_error', '' );
		$last = ADBC_MCP_Settings::get_state( 'last_housekeeping_at', '' );
		if ( '' === $error && ADBC_MCP_Store::valid_expiry( $last ) && strtotime( $last . ' UTC' ) < time() - 3 * HOUR_IN_SECONDS ) {
			return 'MCP housekeeping has not completed recently. Check the background queue and WordPress cron.';
		}
		return $error;
	}
	private static function public_records( string $kind, array $fields ): array {
		$records = ADBC_MCP_Store::records( $kind, 100 );
		if ( 'grants' === $kind ) {
			$records = array_filter( $records, static fn( $row ) => 'mcp' === ( $row['channel'] ?? 'mcp' ) );
		}
		return array_values( array_map( static fn( $row ) => array_intersect_key( $row, array_flip( $fields ) ), $records ) );
	}
	public static function change( WP_REST_Request $request ) {
		$action = $request->get_param( 'action' );
		if ( ! ADBC_MCP_Store::ready() ) {
			return new WP_Error( 'adbc_storage_unavailable', ADBC_MCP_Settings::get_state( 'storage_error', 'MCP storage is unavailable.' ), [ 'status' => 503 ] );
		}
		switch ( $action ) {
			case 'apply_settings':
				return self::apply_settings( $request );
			case 'enable':

				$enable = $request->get_param( 'enabled' );
				if ( ! is_bool( $enable ) ) {
					return new WP_Error( 'adbc_invalid_settings', 'The server setting must be a boolean.', [ 'status' => 400 ] );
				}
				if ( $enable && ! ADBC_MCP_Settings::abilities_enabled() ) {
					return self::abilities_required();
				}
				if ( $enable && ! ADBC_MCP_OAuth::transport_secure() ) {
					return new WP_Error( 'adbc_https_required', 'HTTPS is required.', [ 'status' => 400 ] );
				}
				if ( $enable ) {
					try {
						ADBC_MCP_OAuth::ensure_keys();
					} catch (Throwable $error) {
						return new WP_Error( 'adbc_oauth_unavailable', 'OAuth signing keys could not be initialized. ' . $error->getMessage(), [ 'status' => 500 ] );
					}
				}
				if ( ! ADBC_MCP_Settings::set( 'enabled', $enable ) ) {
					return self::settings_write_error();
				}
				if ( ! $enable ) {
					self::revoke_all();
				}
				break;
			case 'revoke_all_connections':
				if ( ! self::revoke_all() ) {
					return new WP_Error( 'adbc_revoke_failed', 'Some connections could not be revoked. Refresh and review their access before trying again.', [ 'status' => 500 ] );
				}
				break;
			case 'set_connection_access':
				$id = $request->get_param( 'id' );
				$write = $request->get_param( 'write_enabled' );
				$expected_write = $request->get_param( 'expected_write_enabled' );
				$revision = $request->get_param( 'expected_access_revision' );
				if ( ! is_string( $id ) || '' === $id || ! is_bool( $write ) || ! is_bool( $expected_write ) || ! is_int( $revision ) || $revision < 0 ) {
					return new WP_Error( 'adbc_invalid_settings', 'Invalid connection access change.', [ 'status' => 400 ] );
				}
				$saved = ADBC_MCP_Connections::set_access( $id, $write, $expected_write, $revision );
				if ( is_wp_error( $saved ) ) {
					return $saved;
				}
				break;
			case 'rename_connection':
				$id = $request->get_param( 'id' );
				if ( ! is_string( $id ) || '' === $id ) {
					return new WP_Error( 'adbc_connection_not_found', 'Connection ID is required.', [ 'status' => 400 ] );
				}
				$renamed = ADBC_MCP_Connections::rename( $id, $request->get_param( 'name' ), $request->get_param( 'expected_name' ) );
				if ( is_wp_error( $renamed ) ) {
					return $renamed;
				}
				break;
			case 'revoke_connection':
				$id = (string) $request->get_param( 'id' );
				if ( ! self::revoke_connection( $id ) ) {
					return new WP_Error( 'adbc_revoke_failed', 'Could not revoke this connection. Refresh and try again.', [ 'status' => 500 ] );
				}
				break;
			case 'add_app_password':
				$uuid = $request->get_param( 'uuid' );
				if ( ! is_string( $uuid ) || '' === $uuid ) {
					return new WP_Error( 'adbc_app_password_not_found', 'Choose an Application Password for this administrator.', [ 'status' => 400 ] );
				}
				$found = false;
				if ( class_exists( 'WP_Application_Passwords' ) ) {
					foreach ( WP_Application_Passwords::get_user_application_passwords( get_current_user_id() ) as $app ) {
						if ( $app['uuid'] === $uuid ) {
							$found = true;
							break;
						}
					}
				}
				if ( ! $found ) {
					return new WP_Error( 'adbc_app_password_not_found', 'Application password not found for this administrator.', [ 'status' => 404 ] );
				}
				if ( ADBC_MCP_Store::grant( ADBC_MCP_OAuth::app_grant_id( get_current_user_id(), $uuid ) ) ) {
					return new WP_Error( 'adbc_connection_exists', 'This Application Password is already connected. Refresh the list.', [ 'status' => 409 ] );
				}
				if ( ! ADBC_MCP_Store::put( 'grants', [ 'id' => ADBC_MCP_OAuth::app_grant_id( get_current_user_id(), $uuid ), 'revoked' => 0, 'client_id' => $uuid, 'user_id' => get_current_user_id(), 'auth_type' => 'app_password', 'created_at' => current_time( 'mysql', true ) ] ) ) {
					return new WP_Error( 'adbc_connection_failed', 'Could not store the connection.', [ 'status' => 500 ] );
				}
				break;
			case 'clear_audit':
				if ( ! ADBC_MCP_Audit::clear() ) {
					return new WP_Error( 'adbc_audit_unavailable', 'Could not clear the MCP audit.', [ 'status' => 500 ] );
				}
				break;
			default:
				return new WP_Error( 'adbc_invalid_action', 'Unknown MCP administration action.', [ 'status' => 400 ] );
		}
		return self::status();
	}

	/** The ordinary Settings page saves only the two integration switches here. */
	private static function apply_settings( WP_REST_Request $request ) {
		$removed = [ 'clients', 'connections', 'metadata_url', 'registration', 'app_password_uuid' ];
		if ( array_filter( $removed, static fn( $field ) => $request->has_param( $field ) ) || ( ! $request->has_param( 'enabled' ) && ! $request->has_param( 'abilities_enabled' ) ) ) {
			return new WP_Error( 'adbc_invalid_settings', 'Only integration switches can be saved here.', [ 'status' => 400 ] );
		}
		$change_mcp = $request->has_param( 'enabled' ) || $request->has_param( 'expected_enabled' );
		$enabled = $change_mcp ? $request->get_param( 'enabled' ) : ADBC_MCP_Settings::mcp_enabled();
		$expected_enabled = $change_mcp ? $request->get_param( 'expected_enabled' ) : $enabled;
		if ( ! is_bool( $enabled ) || ! is_bool( $expected_enabled ) ) {
			return new WP_Error( 'adbc_invalid_settings', 'The server setting must be a boolean.', [ 'status' => 400 ] );
		}
		if ( $change_mcp && ADBC_MCP_Settings::mcp_enabled() !== $expected_enabled ) {
			return self::settings_conflict();
		}
		$change_abilities = $request->has_param( 'abilities_enabled' ) || $request->has_param( 'expected_abilities_enabled' );
		$abilities_enabled = $change_abilities ? $request->get_param( 'abilities_enabled' ) : ADBC_MCP_Settings::abilities_enabled();
		$expected_abilities = $change_abilities ? $request->get_param( 'expected_abilities_enabled' ) : $abilities_enabled;
		if ( ! is_bool( $abilities_enabled ) || ! is_bool( $expected_abilities ) ) {
			return new WP_Error( 'adbc_invalid_settings', 'The abilities setting must be a boolean.', [ 'status' => 400 ] );
		}
		if ( $change_abilities && ADBC_MCP_Settings::abilities_enabled() !== $expected_abilities ) {
			return self::settings_conflict();
		}
		if ( ! $abilities_enabled ) {
			if ( $change_mcp && $enabled ) {
				return self::abilities_required();
			}
			$enabled = false;
		}
		if ( $enabled && ! ADBC_MCP_OAuth::transport_secure() ) {
			return new WP_Error( 'adbc_https_required', 'HTTPS is required.', [ 'status' => 400 ] );
		}

		if ( $enabled ) {
			try {
				ADBC_MCP_OAuth::ensure_keys();
			} catch (Throwable $error) {
				return new WP_Error( 'adbc_oauth_unavailable', 'OAuth signing keys could not be initialized. ' . $error->getMessage(), [ 'status' => 500 ] );
			}
		}

		if ( $change_mcp || $change_abilities ) {
			$saved = ADBC_MCP_Settings::mutate_config( static function ( $row ) use ( $enabled, $expected_enabled, $abilities_enabled, $expected_abilities, $change_mcp, $change_abilities ) {
				$current_abilities = true === ( $row['abilities_enabled'] ?? false );
				$current_mcp = ! empty( $row['enabled'] ) && $current_abilities;
				if ( ( $change_mcp && $current_mcp !== $expected_enabled ) || ( $change_abilities && $current_abilities !== $expected_abilities ) ) {
					return false;
				}
				$next_abilities = $change_abilities ? $abilities_enabled : $current_abilities;
				$next_mcp = $change_mcp ? $enabled : ( $current_mcp && $next_abilities );
				if ( $next_mcp && ! $next_abilities ) {
					return false;
				}
				$row['enabled'] = $next_mcp;
				if ( $change_abilities ) {
					$row['abilities_enabled'] = $next_abilities;
				}
				return $row;
			} );
			if ( ! $saved ) {
				return self::settings_write_error();
			}
			if ( $expected_enabled && ! ADBC_MCP_Settings::mcp_enabled() ) {
				self::revoke_all();
			}
			if ( $change_abilities && $expected_abilities && ! $abilities_enabled ) {
				self::cancel_abilities_work();
			}
		}
		return self::integration_status();
	}
	private static function settings_conflict(): WP_Error {
		return new WP_Error( 'adbc_settings_conflict', 'MCP settings changed since this page was loaded. Refresh and review your changes before saving again.', [ 'status' => 409 ] );
	}
	private static function settings_write_error(): WP_Error {
		return new WP_Error( 'adbc_settings_write_failed', 'MCP settings could not be fully saved. Refresh and review the saved settings before trying again.', [ 'status' => 500 ] );
	}
	private static function revoke_connection( string $id ): bool {
		$revoked = false;
		for ( $attempt = 0; $attempt < 5; $attempt++ ) {
			$grant = ADBC_MCP_Store::get( 'grants', $id );
			if ( $grant && 'mcp' !== ( $grant['channel'] ?? 'mcp' ) ) {
				return false;
			}
			if ( ! $grant || ! empty( $grant['revoked'] ) || ADBC_MCP_Store::replace( 'grants', $grant, array_replace( $grant, [ 'revoked' => 1 ] ) ) ) {
				$revoked = true;
				break;
			}
		}
		if ( ! $revoked ) {
			return false;
		}
		ADBC_MCP_Store::delete_matching( 'sessions', [ 'grant_id' => $id ] );
		ADBC_MCP_Store::delete_matching( 'previews', [ 'grant_id' => $id ] );
		foreach ( ADBC_MCP_Store::each( 'jobs' ) as $job ) {
			if ( $job['grant_id'] === $id && 'queued' === $job['status'] ) {
				ADBC_MCP_Jobs::cancel_record( $job );
			}
		}
		return true;
	}
	private static function revoke_all(): bool {
		$success = true;
		foreach ( ADBC_MCP_Store::each( 'grants' ) as $grant ) {
			if ( 'mcp' === ( $grant['channel'] ?? 'mcp' ) ) {
				$success = self::revoke_connection( $grant['id'] ) && $success;
			}
		}
		ADBC_MCP_Store::delete_matching( 'sessions' );
		foreach ( ADBC_MCP_Store::each( 'previews' ) as $preview ) {
			if ( 'mcp' === ( $preview['channel'] ?? 'mcp' ) ) {
				ADBC_MCP_Store::delete( 'previews', $preview['id'], $preview );
			}
		}
		foreach ( ADBC_MCP_Store::each( 'jobs' ) as $job ) {
			if ( 'mcp' === ( $job['channel'] ?? 'mcp' ) && 'queued' === $job['status'] ) {
				ADBC_MCP_Jobs::cancel_record( $job );
			}
		}
		return $success;
	}
	private static function cancel_abilities_work(): void {
		foreach ( ADBC_MCP_Store::each( 'previews' ) as $preview ) {
			if ( in_array( $preview['channel'] ?? '', [ 'abilities', 'cli' ], true ) ) {
				ADBC_MCP_Store::delete( 'previews', $preview['id'], $preview );
			}
		}
		foreach ( ADBC_MCP_Store::each( 'jobs' ) as $job ) {
			if ( in_array( $job['channel'] ?? '', [ 'abilities', 'cli' ], true ) && 'queued' === $job['status'] ) {
				ADBC_MCP_Jobs::cancel_record( $job );
			}
		}
	}
	public static function fetch_metadata( string $url, ?bool $cache = null ) {
		if ( strlen( $url ) > 190 || strlen( $url ) < 12 || ! preg_match( '~^https://~i', $url ) ) {
			return new WP_Error( 'adbc_invalid_metadata_url', 'Use a short HTTPS client metadata URL.', [ 'status' => 400 ] );
		}
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || empty( $parts['host'] ) || empty( $parts['path'] ) || '/' === $parts['path'] || isset( $parts['user'] ) || isset( $parts['pass'] ) || isset( $parts['fragment'] ) || isset( $parts['query'] ) || preg_match( '~/\.\.?(/|$)~', $parts['path'] ) ) {
			return new WP_Error( 'adbc_invalid_metadata_url', 'Invalid client metadata URL.', [ 'status' => 400 ] );
		}
		$cache = ( $cache ?? true ) && null !== ADBC_MCP_Store::get( 'clients', $url );
		$cached = $cache ? get_transient( 'adbc_mcp_cimd_' . hash( 'sha256', $url ) ) : false;
		if ( is_array( $cached ) && 'none' === ( $cached['token_endpoint_auth_method'] ?? null ) ) {
			return $cached;
		}
		if ( ! ADBC_MCP_Executor::can_execute() || ! ADBC_MCP_Settings::reserve_metadata_fetch( get_current_user_id() ) ) {
			return new WP_Error( 'adbc_metadata_rate_limited', 'Too many client metadata requests. Wait a minute and try connecting again.', [ 'status' => 429 ] );
		}
		$response = self::request_metadata( $url );
		if ( is_wp_error( $response ) && 'adbc_metadata_transport' === $response->get_error_code() ) {
			return $response;
		}
		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			return new WP_Error( 'adbc_metadata_unavailable', 'Could not fetch the client metadata document.', [ 'status' => 400 ] );
		}
		$metadata = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( is_array( $metadata ) && array_key_exists( 'token_endpoint_auth_method', $metadata ) && 'none' !== $metadata['token_endpoint_auth_method'] ) {
			return new WP_Error( 'adbc_unsupported_client_auth', 'Automatic metadata clients must use token_endpoint_auth_method=none. Register clients requiring a client secret manually; private_key_jwt is not supported.', [ 'status' => 400 ] );
		}
		if ( ! is_array( $metadata ) || ( $metadata['client_id'] ?? null ) !== $url || empty( $metadata['client_name'] ) || ! is_string( $metadata['client_name'] ) || ! isset( $metadata['redirect_uris'] ) || ! is_array( $metadata['redirect_uris'] ) || count( $metadata['redirect_uris'] ) < 1 || count( $metadata['redirect_uris'] ) > 10 ) {
			return new WP_Error( 'adbc_invalid_metadata', 'Client metadata must have the exact client_id, client_name, and redirect_uris.', [ 'status' => 400 ] );
		}
		foreach ( $metadata['redirect_uris'] as $uri ) {
			if ( ! is_string( $uri ) || strlen( $uri ) > 2048 || ! self::valid_redirect( $uri ) ) {
				return new WP_Error( 'adbc_invalid_metadata', 'Client metadata has an invalid redirect URI.', [ 'status' => 400 ] );
			}
		}
		$clean = [ 'client_name' => substr( sanitize_text_field( $metadata['client_name'] ), 0, 190 ), 'redirect_uris' => array_values( array_unique( $metadata['redirect_uris'] ) ) ];
		if ( $cache ) {
			self::cache_metadata( $url, $clean );
		}
		return $clean;
	}
	public static function cache_metadata( string $url, array $metadata ): void {
		if ( ADBC_MCP_Store::get( 'clients', $url ) ) {
			set_transient( 'adbc_mcp_cimd_' . hash( 'sha256', $url ), $metadata + [ 'token_endpoint_auth_method' => 'none' ], 300 );
		}
	}

	/** Pin the actual connection: URL validation alone cannot prevent DNS rebinding. */
	private static function request_metadata( string $url ) {
		if ( ! function_exists( 'curl_init' ) || ! defined( 'CURLOPT_RESOLVE' ) ) {
			return new WP_Error( 'adbc_metadata_transport', 'Metadata clients require cURL with DNS pinning. Register the OAuth client manually on this host instead.', [ 'status' => 400 ] );
		}
		$proxy = new WP_HTTP_Proxy();
		if ( $proxy->is_enabled() && $proxy->send_through_proxy( $url ) ) {
			return new WP_Error( 'adbc_metadata_transport', 'Metadata clients require direct HTTPS access for DNS pinning. Register the OAuth client manually on this host instead.', [ 'status' => 400 ] );
		}
		$transport = static function ( &$requested, &$headers, &$data, &$type, &$options ) use ( $url ): void {
			if ( $requested !== $url ) {
				return;
			}
			if ( ! function_exists( 'curl_init' ) || ! defined( 'CURLOPT_RESOLVE' ) || ! empty( $options['proxy'] ) ) {
				throw new \WpOrg\Requests\Exception( 'Client metadata requires a direct cURL connection with DNS pinning.', 'adbc_metadata_transport' );
			}
			$options['transport'] = \WpOrg\Requests\Transport\Curl::class;
			$options['verify'] = ABSPATH . WPINC . '/certificates/ca-bundle.crt';
			$options['verifyname'] = true;
		};
		$pin = static function ( $handle, $args, $requested ) use ( $url ): void {
			if ( $requested !== $url ) {
				return;
			}
			$host = (string) wp_parse_url( $url, PHP_URL_HOST );
			$ip = gethostbyname( $host );
			$parts = array_map( 'intval', explode( '.', $ip ) );
			$public = filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE );
			if ( ! $public || ( 100 === $parts[0] && $parts[1] >= 64 && $parts[1] <= 127 ) || $parts[0] >= 224
				|| ( 192 === $parts[0] && 0 === $parts[1] && in_array( $parts[2], [ 0, 2 ], true ) )
				|| ( 192 === $parts[0] && 88 === $parts[1] && 99 === $parts[2] )
				|| ( 198 === $parts[0] && in_array( $parts[1], [ 18, 19 ], true ) )
				|| ( 198 === $parts[0] && 51 === $parts[1] && 100 === $parts[2] )
				|| ( 203 === $parts[0] && 0 === $parts[1] && 113 === $parts[2] ) ) {
				throw new \WpOrg\Requests\Exception( 'Client metadata must resolve to a public IPv4 address.', 'adbc_metadata_address' );
			}
			$port = (int) ( wp_parse_url( $url, PHP_URL_PORT ) ?: 443 );
			// Environment proxies must not delegate target DNS resolution elsewhere.
			if ( ! curl_setopt( $handle, CURLOPT_PROXY, '' ) || ! curl_setopt( $handle, CURLOPT_RESOLVE, [ $host . ':' . $port . ':' . $ip ] ) ) {
				throw new \WpOrg\Requests\Exception( 'Could not pin the client metadata address.', 'adbc_metadata_address' );
			}
		};
		add_action( 'requests-requests.before_request', $transport, PHP_INT_MAX, 5 );
		add_action( 'http_api_curl', $pin, PHP_INT_MAX, 3 );
		try {
			return wp_safe_remote_get( $url, [ 'timeout' => 5, 'redirection' => 0, 'limit_response_size' => 32768, 'sslverify' => true, 'headers' => [ 'Accept' => 'application/json' ] ] );
		} finally {
			remove_action( 'requests-requests.before_request', $transport, PHP_INT_MAX );
			remove_action( 'http_api_curl', $pin, PHP_INT_MAX );
		}
	}
	public static function valid_redirect( string $uri ): bool {
		if ( preg_match( '/[\x00-\x20\x7f]/', $uri ) ) {
			return false;
		}
		$parts = wp_parse_url( $uri );
		if ( ! is_array( $parts ) || isset( $parts['fragment'] ) || isset( $parts['user'] ) || isset( $parts['pass'] ) || empty( $parts['scheme'] ) ) {
			return false;
		}
		$scheme = strtolower( $parts['scheme'] );
		if ( in_array( $scheme, [ 'javascript', 'vbscript', 'data', 'file', 'about', 'blob', 'ftp', 'ftps' ], true ) ) {
			return false;
		}
		if ( 'https' === $scheme ) {
			return ! empty( $parts['host'] );
		}
		if ( 'http' === $scheme ) {
			return isset( $parts['host'] ) && in_array( strtolower( $parts['host'] ), [ '127.0.0.1', 'localhost', '[::1]' ], true );
		}
		return (bool) preg_match( '/^[a-z][a-z0-9+.-]*$/', $scheme ) && ( ! empty( $parts['path'] ) || ! empty( $parts['host'] ) );
	}
}
