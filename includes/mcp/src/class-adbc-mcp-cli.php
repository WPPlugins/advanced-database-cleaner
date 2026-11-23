<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Standard wp ability commands handle discovery and execution; this helper handles approval. */
final class ADBC_MCP_CLI {
	/**
	 * Confirm an ADBC destructive preview for the selected administrator.
	 *
	 * ## OPTIONS
	 *
	 * <confirmation_request_id>
	 * : The request ID returned by a destructive wp ability run call.
	 *
	 * [--yes]
	 * : Explicitly approve this preview without an interactive question.
	 *
	 * ## EXAMPLES
	 *
	 *     wp adbc confirm <confirmation_request_id> --user=admin
	 *     wp adbc confirm <confirmation_request_id> --user=admin --yes
	 */
	public static function confirm( array $args, array $assoc_args ): void {
		$result = ADBC_MCP_Access::with_context( static function () use ($args, $assoc_args) {
			if ( ! ADBC_MCP_Access::cli_user_selected() || 'cli' !== ADBC_MCP_Context::channel() ) {
				return new WP_Error( 'adbc_cli_user_required', 'Select an administrator explicitly with --user=<login-or-id>.' );
			}
			$id = $args[0];
			$pending = ADBC_MCP_Store::pending_preview( $id );
			if ( ! $pending ) {
				return new WP_Error( 'adbc_preview_required', 'The preview is invalid, expired, used, or belongs to another user or channel.' );
			}
			$upgrade = ADBC_MCP_Abilities::upgrade_response( $pending['ability'] );
			if ( $upgrade ) {
				return $upgrade;
			}
			$pending['targets']['confirmation_message'] = str_replace( 'Please reply in chat.', 'Approve in this terminal.', $pending['targets']['confirmation_message'] ?? '' );
			fwrite( STDERR, "WARNING: This action can permanently change or delete data. Completed changes cannot be undone.\n" );
			fwrite( STDERR, wp_json_encode( [ 'ability' => 'adbc/' . str_replace( '_', '-', $pending['ability'] ), 'targets' => $pending['targets'], 'expires_at' => $pending['expires_at'] . ' UTC' ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n" );
			if ( empty( $assoc_args['yes'] ) ) {
				if ( ! function_exists( 'stream_isatty' ) || ! stream_isatty( STDIN ) ) {
					return new WP_Error( 'adbc_cli_interaction_required', 'Confirmation needs an interactive terminal. For automation, explicitly approve this preview using --yes.' );
				}
				fwrite( STDERR, "Do you approve this specific action? Type yes to execute: " );
				if ( 'yes' !== strtolower( trim( (string) fgets( STDIN ) ) ) ) {
					return new WP_Error( 'adbc_cli_declined', 'Action not approved. No action was performed.' );
				}
			}
			return ADBC_MCP_Confirmation::from_cli( $id );
		} );
		if ( is_wp_error( $result ) ) {
			WP_CLI::error( $result->get_error_message() );
		}
		WP_CLI::line( wp_json_encode( $result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
	}
}
