<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Shared one-use confirmation; only trusted code passes the stored snapshot to execution. */
final class ADBC_MCP_Confirmation {
	public static function from_chat( array $input ) {
		if ( 'cli' === ADBC_MCP_Context::channel() ) {
			return new WP_Error( 'adbc_cli_confirmation_required', 'Use wp adbc confirm <confirmation_request_id> --user=<admin>, optionally with --yes.', [ 'status' => 403 ] );
		}
		if ( true !== ( $input['approved_in_chat'] ?? null ) ) {
			return new WP_Error( 'adbc_chat_approval_required', 'Explicit approval in a new user chat message is required.', [ 'status' => 400 ] );
		}
		return self::execute( $input['confirmation_request_id'] ?? null, 'chat_approved' );
	}
	public static function from_cli( string $request_id ) {
		if ( 'cli' !== ADBC_MCP_Context::channel() ) {
			return new WP_Error( 'adbc_forbidden', 'CLI confirmation requires an identified terminal user.', [ 'status' => 403 ] );
		}
		return self::execute( $request_id, 'terminal_approved' );
	}
	private static function execute( $request_id, string $outcome ) {
		$access = ADBC_MCP_Access::check( 'confirm_destructive_action' );
		if ( is_wp_error( $access ) ) {
			return $access;
		}
		if ( ! ADBC_MCP_Access::authorized() ) {
			return new WP_Error( 'adbc_forbidden', 'Administrator access or this connection was revoked.', [ 'status' => 403 ] );
		}
		$pending = ADBC_MCP_Store::pending_preview( $request_id );
		if ( ! $pending ) {
			return new WP_Error( 'adbc_preview_required', 'The confirmation request is invalid, expired, used, or belongs to another connection.', [ 'status' => 409 ] );
		}
		$upgrade = ADBC_MCP_Abilities::upgrade_response( $pending['ability'] );
		if ( $upgrade ) {
			return $upgrade;
		}
		return ADBC_MCP_Access::with_access( $pending['ability'], static fn() => self::execute_pending( $request_id, $pending ), $pending['access_revision'] );
	}
	private static function execute_pending( string $request_id, array $pending ) {
		if ( ! ADBC_MCP_Store::approve_client_preview( $request_id, $pending['ability'], $pending['args'] ) ) {
			return new WP_Error( 'adbc_preview_required', 'The confirmation request expired or was already used.', [ 'status' => 409 ] );
		}
		$targets = ADBC_MCP_Store::consume_preview_targets( $request_id, $pending['ability'], $pending['args'] );
		if ( null === $targets ) {
			return new WP_Error( 'adbc_preview_required', 'The confirmation request expired or was already used.', [ 'status' => 409 ] );
		}
		return ADBC_MCP_Executor::execute_approved( $pending['ability'], $pending['args'], $targets );
	}
}
