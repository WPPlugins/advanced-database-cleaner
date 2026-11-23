<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Channel identity is derived from WordPress authentication, never ability input. */
final class ADBC_MCP_Access {
	private static $rest_contexts = [];

	/** Saved grant permissions are authoritative; absent write access is never granted. */
	public static function permissions( array $grant ): array {
		return [
			'read_enabled' => true,
			'write_enabled' => true === ( $grant['write_enabled'] ?? null ),
			'access_revision' => $grant['access_revision'] ?? 0,
		];
	}
	public static function revision(): int {
		if ( 'mcp' !== ADBC_MCP_Context::channel() ) {
			return 0;
		}
		$grant = ADBC_MCP_Store::grant( ADBC_MCP_Context::grant_id() );
		return $grant ? self::permissions( $grant )['access_revision'] : 0;
	}
	/** MCP permissions are live grant state; abilities use WordPress credentials directly. */
	public static function check( string $slug, ?int $expected_revision = null ) {
		$definition = ADBC_MCP_Abilities::definitions()[ $slug ] ?? [];
		$access = $definition['required_access'] ?? null;
		if ( ! in_array( $access, [ 'read', 'write' ], true ) ) {
			return new WP_Error( 'adbc_forbidden', 'This ADBC ability is unavailable to this user.', [ 'status' => 403 ] );
		}
		$invocation = self::can_invoke();
		if ( is_wp_error( $invocation ) ) {
			return $invocation;
		}
		if ( ! $invocation ) {
			return new WP_Error( 'adbc_forbidden', 'Administrator access is required.', [ 'status' => 403 ] );
		}
		if ( 'read' === $access && '' === ADBC_MCP_Context::grant_id() ) {
			return true;
		}
		if ( ! self::authorized() ) {
			return new WP_Error( 'adbc_connection_required', 'An authorized ADBC connection or CLI user is required.', [ 'status' => 403 ] );
		}
		if ( 'read' === $access || 'cli' === ADBC_MCP_Context::channel() || ADBC_MCP_Abilities::upgrade_response( $slug ) ) {
			return true;
		}
		$expected_revision = $expected_revision ?? ADBC_MCP_Context::access_revision();
		if ( 'abilities' === ADBC_MCP_Context::channel() ) {
			return null === $expected_revision || 0 === $expected_revision
				? true
				: new WP_Error( 'adbc_access_changed', 'Ability authorization changed. Request a new preview or start a new job.', [ 'status' => 409 ] );
		}
		$grant = ADBC_MCP_Store::grant( ADBC_MCP_Context::grant_id() );
		if ( ! $grant || ! self::permissions( $grant )['write_enabled'] ) {
			return new WP_Error( 'adbc_write_access_denied', 'This ADBC connection has read access only. Ask a WordPress administrator to enable Write access in MCP server > MCP config > Active connections. Changes save automatically. Do not retry through another tool or confirmation.', [ 'status' => 403 ] );
		}
		if ( null !== $expected_revision && $expected_revision !== self::permissions( $grant )['access_revision'] ) {
			return new WP_Error( 'adbc_access_changed', 'Connection access changed. Request a new preview or start a new job; earlier approvals cannot be reused.', [ 'status' => 409 ] );
		}
		return true;
	}
	/** Bind one synchronous operation to its access generation, including its final commit. */
	public static function with_access( string $slug, callable $callback, ?int $revision = null ) {
		$old = ADBC_MCP_Context::access_revision();
		if ( null !== $revision || null === $old ) {
			ADBC_MCP_Context::set_access_revision( $revision ?? self::revision() );
		}
		try {
			$allowed = self::check( $slug );
			return is_wp_error( $allowed ) ? $allowed : $callback();
		} finally {
			ADBC_MCP_Context::set_access_revision( $old );
		}
	}

	public static function register(): void {
		add_filter( 'rest_request_before_callbacks', [ self::class, 'before_rest' ], 10, 3 );
		add_filter( 'rest_request_after_callbacks', [ self::class, 'after_rest' ], 10, 3 );
	}
	public static function before_rest( $response, $handler, $request ) {
		if ( preg_match( '~^/wp-abilities/v1/abilities/adbc/[^/]+/run$~', $request->get_route() ) ) {
			self::$rest_contexts[ spl_object_id( $request ) ] = ADBC_MCP_Context::snapshot();
			ADBC_MCP_Context::set_grant_id( '', 'abilities' );
			// WordPress 6.9 leaves GET scalars as strings. Keep the public integer schema.
			if ( 'GET' === $request->get_method() ) {
				$query = $request->get_query_params();
				$value = $query['input']['row_limit'] ?? null;
				if ( is_string( $value ) && preg_match( '/^(?:0|[1-9][0-9]{0,3})$/', $value ) ) {
					$query['input']['row_limit'] = (int) $value;
					$request->set_query_params( $query );
				}
				$manual = $query['input']['showManualCorrectionsOnly'] ?? null;
				if ( is_string( $manual ) && in_array( $manual, [ 'true', 'false', '1', '0' ], true ) ) {
					$query['input']['showManualCorrectionsOnly'] = in_array( $manual, [ 'true', '1' ], true );
					$request->set_query_params( $query );
				}
			}
		}
		return $response;
	}
	public static function after_rest( $response, $handler, $request ) {
		$id = spl_object_id( $request );
		if ( isset( self::$rest_contexts[ $id ] ) ) {
			ADBC_MCP_Context::restore( self::$rest_contexts[ $id ] );
			unset( self::$rest_contexts[ $id ] );
		}
		return $response;
	}
	public static function secure(): bool {
		return is_ssl() || 'local' === wp_get_environment_type();
	}
	public static function cli_id(): string {
		return 'cli-' . hash( 'sha256', get_current_blog_id() . '|' . home_url( '/' ) . '|' . get_current_user_id() );
	}
	public static function abilities_id( int $user_id, string $uuid ): string {
		// Execution identity only: bind previews/jobs to the WordPress credential,
		// without creating a persistent ADBC connection or storing the password.
		return 'abilities-' . $user_id . '-' . $uuid;
	}
	private static function password_exists( int $user_id, string $uuid ): bool {
		return class_exists( 'WP_Application_Passwords' ) && (bool) WP_Application_Passwords::get_user_application_password( $user_id, $uuid );
	}
	public static function cli_user_selected(): bool {
		if ( ! defined( 'WP_CLI' ) || ! WP_CLI || ! class_exists( 'WP_CLI' ) ) {
			return false;
		}
		$selected = WP_CLI::get_runner()->config['user'] ?? '';
		if ( ! $selected ) {
			return false;
		}
		$user = get_user_by( is_numeric( $selected ) ? 'id' : 'login', $selected );
		return $user && (int) $user->ID === get_current_user_id();
	}
	public static function can_invoke() {
		if ( ! ADBC_MCP_Settings::abilities_enabled() ) {
			return new WP_Error( 'adbc_abilities_disabled', 'ADBC abilities are disabled. Enable them in Settings > Developer integrations to use abilities, WP-CLI or MCP.', [ 'status' => 403 ] );
		}
		if ( ! ADBC_MCP_Executor::can_execute() ) {
			return false;
		}
		$channel = ADBC_MCP_Context::channel();
		if ( ADBC_MCP_Context::grant_id() ) {
			return self::authorized();
		}
		if ( 'abilities' === $channel || ( 'php' === $channel && function_exists( 'wp_is_serving_rest_request' ) && wp_is_serving_rest_request() ) ) {
			$uuid = rest_get_authenticated_app_password();
			if ( ! self::secure() || ! is_string( $uuid ) || ! self::password_exists( get_current_user_id(), $uuid ) ) {
				return new WP_Error( 'adbc_application_password_required', 'Use an administrator Application Password over HTTPS to execute ADBC abilities.', [ 'status' => 403 ] );
			}
			return true;
		}
		if ( defined( 'WP_CLI' ) && WP_CLI && ! self::cli_user_selected() ) {
			return new WP_Error( 'adbc_cli_user_required', 'Select an administrator explicitly with --user=<login-or-id>.', [ 'status' => 403 ] );
		}
		return true;
	}
	/** Used again before confirmations, SQL commits and every background unit. */
	public static function authorized(): bool {
		if ( ! ADBC_MCP_Settings::abilities_enabled() ) {
			return false;
		}
		if ( ! ADBC_MCP_Executor::can_execute() || ! ADBC_MCP_Store::ready() ) {
			return false;
		}
		$id = ADBC_MCP_Context::grant_id();
		$channel = ADBC_MCP_Context::channel();
		if ( 'cli' === $channel ) {
			return hash_equals( self::cli_id(), $id );
		}
		if ( 'abilities' === $channel ) {
			$prefix = 'abilities-' . get_current_user_id() . '-';
			if ( ! str_starts_with( $id, $prefix ) ) {
				return false;
			}
			$uuid = substr( $id, strlen( $prefix ) );
			return wp_is_uuid( $uuid, 4 ) && self::password_exists( get_current_user_id(), $uuid );
		}
		if ( 'mcp' !== $channel || ! $id ) {
			return false;
		}
		$grant = ADBC_MCP_Store::grant( $id );
		if ( ! $grant || (int) $grant['user_id'] !== get_current_user_id() || ( $grant['channel'] ?? 'mcp' ) !== $channel ) {
			return false;
		}
		if ( 'mcp' === $channel && ! ADBC_MCP_Settings::mcp_enabled() ) {
			return false;
		}
		if ( 'app_password' === $grant['auth_type'] && ! self::password_exists( (int) $grant['user_id'], $grant['client_id'] ) ) {
			return false;
		}
		return true;
	}
	private static function enter() {
		$allowed = self::can_invoke();
		if ( is_wp_error( $allowed ) ) {
			return $allowed;
		}
		if ( ! $allowed ) {
			return new WP_Error( 'adbc_forbidden', 'Administrator access or this connection was revoked.', [ 'status' => 403 ] );
		}
		if ( ADBC_MCP_Context::grant_id() ) {
			return true;
		}
		if ( 'abilities' === ADBC_MCP_Context::channel() || ( function_exists( 'wp_is_serving_rest_request' ) && wp_is_serving_rest_request() ) ) {
			$uuid = rest_get_authenticated_app_password();
			$id = self::abilities_id( get_current_user_id(), $uuid );
			ADBC_MCP_Context::set_grant_id( $id, 'abilities' );
			if ( ! self::authorized() ) {
				return new WP_Error( 'adbc_application_password_required', 'The Application Password or administrator permission is no longer available.', [ 'status' => 403 ] );
			}
		} elseif ( defined( 'WP_CLI' ) && WP_CLI ) {
			ADBC_MCP_Context::set_grant_id( self::cli_id(), 'cli' );
		}
		return true;
	}
	public static function invoke( string $slug, array $input ) {
		// WordPress schema validation permits these boolean scalars; native filters require booleans.
		$manual = $input['showManualCorrectionsOnly'] ?? null;
		if ( in_array( $manual, [ 'true', 'false', '1', '0', 1, 0 ], true ) ) {
			$input['showManualCorrectionsOnly'] = in_array( $manual, [ 'true', '1', 1 ], true );
		}
		return self::with_context( static fn() => 'confirm_destructive_action' === $slug ? ADBC_MCP_Confirmation::from_chat( $input ) : ADBC_MCP_Executor::execute( $slug, $input ) );
	}
	public static function with_context( callable $callback ) {
		$previous = ADBC_MCP_Context::snapshot();
		try {
			$entered = self::enter();
			if ( is_wp_error( $entered ) ) {
				return $entered;
			}
			return $callback();
		} finally {
			ADBC_MCP_Context::restore( $previous );
		}
	}
}
