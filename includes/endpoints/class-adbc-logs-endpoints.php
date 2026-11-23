<?php

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) )
	exit;

/**
 * ADBC Logs Endpoints.
 * 
 * This class provides the endpoints (controllers) for the logs routes.
 */
class ADBC_Logs_Endpoints {

	/**
	 * Get logs content.
	 *
	 * @param WP_REST_Request $log_object Log type to get its content ('debug' or 'wp-debug').
	 * @return WP_REST_Response The logs content.
	 */
	public static function get_logs_content( WP_REST_Request $log_object ) {

		try {

			$log_type = $log_object->get_param( 'log_type' );
			$sanitized_log_type = sanitize_key( $log_type );
			if ( 'database-changes' === $sanitized_log_type ) {
				$view = $log_object->get_param( 'view' );
				if ( 'timeline' === $view ) {
					$filters = self::change_log_filters( $log_object );
					if ( is_wp_error( $filters ) ) {
						return $filters;
					}
					$data = ADBC_Change_Log::timeline( $filters );
					foreach ( $data['entries'] as &$entry ) {
						$entry = self::change_log_actor( $entry );
					}
					unset( $entry );
					if ( ADBC_Change_Log::read_error() ) {
						return ADBC_Rest::error( ADBC_Change_Log::warning(), 503 );
					}
					return ADBC_Rest::success( '', $data );
				}
				if ( 'detail' === $view ) {
					$id = $log_object->get_param( 'id' );
					if ( ! is_string( $id ) || ! preg_match( '/^[a-f0-9-]{36}$/D', $id ) ) {
						return ADBC_Rest::error( 'Invalid log entry ID.', 400 );
					}
					$entry = ADBC_Change_Log::entry( $id );
					if ( ADBC_Change_Log::read_error() ) {
						return ADBC_Rest::error( ADBC_Change_Log::warning(), 503 );
					}
					return $entry ? ADBC_Rest::success( '', self::change_log_actor( $entry ) ) : ADBC_Rest::error( __( 'This entry is no longer available. Refresh the log; older entries may have been rotated or cleared.', 'advanced-database-cleaner' ), 404 );
				}
				$content = in_array( $log_object->get_param( 'export' ), [ '1', 1, true ], true ) ? ADBC_Change_Log::export() : implode( "\n", array_map( 'wp_json_encode', ADBC_Change_Log::recent() ) );
				if ( ADBC_Change_Log::read_error() ) {
					return ADBC_Rest::error( ADBC_Change_Log::warning(), 503 );
				}
				return ADBC_Rest::success( '', [ 'content' => $content ] );
			}

			if ( $sanitized_log_type !== 'debug' && $sanitized_log_type !== 'wp-debug' )
				return ADBC_Rest::error( "Invalid log file type.", ADBC_Rest::BAD_REQUEST );

			$log_content = ADBC_Logging::get_log_content( $sanitized_log_type );

			if ( $log_content['success'] === false )
				return ADBC_Rest::error( $log_content['message'], ADBC_Rest::INTERNAL_SERVER_ERROR );

			return ADBC_Rest::success( "", [ 'content' => $log_content['content'] ] );

		} catch (Exception $e) {

			return ADBC_Rest::error_for_uncaught_exception( __METHOD__, $e );

		}
	}

	private static function change_log_filters( WP_REST_Request $request ) {
		$filters = [];
		foreach ( [ 'start_date', 'end_date', 'source', 'outcome', 'operation' ] as $key ) {
			$value = $request->get_param( $key );
			if ( null === $value ) {
				$value = '';
			}
			if ( ! is_string( $value ) ) {
				return new WP_Error( 'adbc_log_filter', 'Invalid log filter.', [ 'status' => 400 ] );
			}
			$filters[ $key ] = $value;
		}
		foreach ( [ 'start_date', 'end_date' ] as $key ) {
			$value = $filters[ $key ];
			if ( $value && ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/D', $value ) || ! checkdate( (int) substr( $value, 5, 2 ), (int) substr( $value, 8, 2 ), (int) substr( $value, 0, 4 ) ) ) ) {
				return new WP_Error( 'adbc_log_filter', 'Invalid date. Use YYYY-MM-DD.', [ 'status' => 400 ] );
			}
		}
		if ( $filters['start_date'] && $filters['end_date'] && $filters['start_date'] > $filters['end_date'] ) {
			return new WP_Error( 'adbc_log_filter', 'The start date must not be after the end date.', [ 'status' => 400 ] );
		}
		if ( ! in_array( $filters['source'], [ '', 'dashboard', 'mcp', 'abilities', 'cli', 'automation' ], true ) || ! in_array( $filters['outcome'], [ '', 'success', 'failed', 'partial' ], true ) ) {
			return new WP_Error( 'adbc_log_filter', 'Invalid source or outcome.', [ 'status' => 400 ] );
		}
		if ( ! in_array( $filters['operation'], array_column( ADBC_Change_Log::operation_options(), 'key' ), true ) ) {
			return new WP_Error( 'adbc_log_filter', 'Invalid operation.', [ 'status' => 400 ] );
		}
		foreach ( [ 'page' => 1, 'per_page' => 25 ] as $key => $default ) {
			$value = $request->get_param( $key ) ?? $default;
			$value = filter_var( $value, FILTER_VALIDATE_INT );
			if ( false === $value || $value < 1 || $value > 1000 ) {
				return new WP_Error( 'adbc_log_filter', 'Invalid page or page size.', [ 'status' => 400 ] );
			}
			$filters[ $key ] = $value;
		}
		return $filters;
	}
	private static function change_log_actor( array $entry ) {
		$user = ! empty( $entry['user_id'] ) ? get_userdata( (int) $entry['user_id'] ) : false;
		$entry['user_label'] = $user ? $user->user_login : '';
		return $entry;
	}

	/**
	 * Clear log file content.
	 *
	 * @return WP_REST_Response Response.
	 */
	public static function clear_logs_content( $request = null ) {

		try {

			$cleared = $request instanceof WP_REST_Request && 'database-changes' === $request->get_param( 'log_type' ) ? ADBC_Change_Log::clear() : ADBC_Logging::clear_log();

			if ( $cleared === false )
				return ADBC_Rest::error(
					__( 'Cannot clear the log file content.', 'advanced-database-cleaner' ), ADBC_Rest::INTERNAL_SERVER_ERROR,
					0,
					[
						'message_links' => [
							[
								'text' => __( 'Check the logs', 'advanced-database-cleaner' ),
								'tab_id' => 'info_and_logs',
								'sub_tab_id' => 'debug',
							],
						],
					]
				);

			return ADBC_Rest::success( "" );

		} catch (Exception $e) {

			return ADBC_Rest::error_for_uncaught_exception( __METHOD__, $e );

		}
	}

}
