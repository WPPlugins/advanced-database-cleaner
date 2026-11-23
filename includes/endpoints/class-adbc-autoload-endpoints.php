<?php

if ( ! defined( 'ABSPATH' ) )
	exit;

/** Native adapter only. WPVitals will authorize its own mapped targets at merge time. */
final class ADBC_Autoload_Endpoints {
	private static function respond( WP_REST_Request $request, $action ) {
		try {
			// Retain this check after WPVitals removes the plugin-wide main-site bootstrap restriction.
			if ( ! is_main_site() || ( is_multisite() && (int) get_current_blog_id() !== (int) get_main_site_id() ) ) {
				return new WP_Error( 'autoload_main_site_only', __( 'Native autoload maintenance is available on the main site only.', 'advanced-database-cleaner' ), [ 'status' => 403 ] );
			}
			foreach ( [ 'blog_id', 'site_id', 'target', 'table' ] as $key )
				if ( $request->has_param( $key ) ) {
					return new WP_Error( 'autoload_invalid_target', __( 'This action always applies to the main site and does not accept another target.', 'advanced-database-cleaner' ), [ 'status' => 422 ] );
				}
			if ( strlen( (string) $request->get_body() ) > 4096 )
				return new WP_Error( 'autoload_request_too_large', __( 'The request is too large.', 'advanced-database-cleaner' ), [ 'status' => 413 ] );
			$service = new ADBC_Autoload_Service( get_current_blog_id() );
			if ( $action === 'status' )
				$data = $service->status();
			elseif ( $action === 'preview' )
				$data = $service->preview();
			elseif ( $action === 'command_status' )
				$data = [ 'command' => $service->command_status( $request->get_param( 'command_id' ) ) ];
			else {
				if ( $request->get_param( 'acknowledged' ) !== true )
					return new WP_Error( 'autoload_confirmation_required', __( 'Confirm the autoload action before processing.', 'advanced-database-cleaner' ), [ 'status' => 422 ] );
				$data = [ 'command' => $service->execute( (array) $request->get_json_params() ) ];
			}
			return ADBC_Rest::success( '', $data );
		} catch (ADBC_Autoload_Exception $error) {
			return new WP_Error( $error->error_code, $error->getMessage(), array_merge( [ 'status' => $error->status ], $error->details ) );
		} catch (Throwable $error) {
			ADBC_Logging::log_exception( 'autoload_' . $action, $error );
			return new WP_Error( 'autoload_unavailable', __( 'Autoload maintenance could not be completed. Existing recovery files have been preserved.', 'advanced-database-cleaner' ), [ 'status' => 503 ] );
		}
	}
	public static function status( WP_REST_Request $request ) {
		return self::respond( $request, 'status' );
	}
	public static function preview( WP_REST_Request $request ) {
		return self::respond( $request, 'preview' );
	}
	public static function execute( WP_REST_Request $request ) {
		return self::respond( $request, 'execute' );
	}
	public static function command_status( WP_REST_Request $request ) {
		return self::respond( $request, 'command_status' );
	}
}
