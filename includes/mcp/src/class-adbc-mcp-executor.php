<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Security boundary shared by MCP and third-party WordPress ability callers. */
final class ADBC_MCP_Executor {
	public const OPERATIONAL_SETTINGS = [ 'analytics_enabled', 'addons_activity_enabled', 'database_rows_batch', 'reduce_cpu_usage', 'cpu_work_time_ms', 'cpu_rest_time_ms', 'sql_or_native_cleanup_method', 'prevent_taking_action_on_wp_items' ];

	public static function can_execute() {
		$user_id = get_current_user_id();
		if ( ! $user_id ) {
			return false;
		}
		// Workers can outlive a permission or password change in another request.
		wp_cache_delete( $user_id, 'user_meta' );
		if ( is_multisite() ) {
			wp_cache_delete( get_current_network_id() . ':site_admins', 'site-options' );
			return is_super_admin( $user_id );
		}
		$roles = wp_roles();
		if ( ! $roles->use_db ) {
			return user_can( new WP_User( $user_id ), 'manage_options' );
		}
		// A new WP_User still uses the request's cached WP_Roles object. Check
		// committed role definitions too, so a running worker honors their removal.
		global $wpdb, $wp_roles;
		$value = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $roles->role_key ) );
		$current = is_string( $value ) ? @unserialize( $value, [ 'allowed_classes' => false ] ) : null;
		if ( ! is_array( $current ) || ! $current ) {
			return false;
		}
		if ( $current === $roles->roles ) {
			return user_can( new WP_User( $user_id ), 'manage_options' );
		}
		// Reload only on a changed definition. Keep normal WordPress role and
		// capability filters, and restore the caller's global role context afterward.
		wp_cache_delete( $roles->role_key, 'options' );
		wp_cache_delete( 'alloptions', 'options' );
		$wp_roles = clone $roles;
		$wp_roles->role_objects = [];
		$wp_roles->role_names = [];
		try {
			$wp_roles->for_site();
			return user_can( new WP_User( $user_id ), 'manage_options' );
		} catch (Throwable $error) {
			return false;
		} finally {
			$wp_roles = $roles;
		}
	}

	public static function execute( $slug, array $input = [] ) {
		if ( array_key_exists( 'confirmation_token', $input ) ) {
			return new WP_Error( 'adbc_preview_required', 'Use the confirmation ability after explicit approval; execution tokens are not accepted.', [ 'status' => 409 ] );
		}
		return ADBC_MCP_Access::with_access( $slug, static fn() => self::execute_internal( $slug, $input ) );
	}

	/** Internal entry point: consume an approved stored preview before passing its snapshot. */
	public static function execute_approved( string $slug, array $input, array $targets ) {
		return ADBC_MCP_Access::with_access( $slug, static fn() => self::execute_internal( $slug, $input, $targets ) );
	}

	private static function execute_internal( $slug, array $input, ?array $approved_targets = null ) {
		if ( ! ADBC_MCP_Settings::abilities_enabled() ) {
			return new WP_Error( 'adbc_abilities_disabled', 'ADBC abilities are disabled. Enable them in Settings > Developer integrations to use abilities, WP-CLI or MCP.', [ 'status' => 403 ] );
		}
		if ( ! ADBC_MCP_Store::ready() ) {
			return new WP_Error( 'adbc_storage_unavailable', 'MCP storage is unavailable. Check the MCP server page for details.', [ 'status' => 503 ] );
		}
		$definitions = ADBC_MCP_Abilities::definitions();
		if ( ! isset( $definitions[ $slug ] ) || ! self::can_execute() ) {
			return new WP_Error( 'adbc_forbidden', 'This ADBC ability is unavailable to this user.', [ 'status' => 403 ] );
		}
		$definition = $definitions[ $slug ];
		if ( ( ! $definition['read_only'] || ADBC_MCP_Context::grant_id() ) && ! ADBC_MCP_Access::authorized() ) {
			return new WP_Error( 'adbc_connection_required', 'This operation requires an authorized MCP, abilities, or CLI identity.', [ 'status' => 403 ] );
		}
		$invalid_list = ADBC_MCP_Abilities::validate_lists( $input, $definition['input_schema'] );
		if ( $invalid_list ) {
			return $invalid_list;
		}
		$upgrade = ADBC_MCP_Abilities::upgrade_response( $slug );
		if ( $upgrade ) {
			return $upgrade;
		}
		$selection_error = self::validate_cleanup_selection( $slug, $input );
		if ( $selection_error ) {
			return $selection_error;
		}
		if ( ! self::valid_operational_settings( $slug, $input ) ) {
			return new WP_Error( 'adbc_invalid_setting', 'This setting is not available through ADBC abilities.', [ 'status' => 400 ] );
		}
		$destructive = $definition['destructive'] || self::disables_core_protection( $slug, $input );
		$protection = self::protect_storage( $slug, $input );
		if ( is_wp_error( $protection ) ) {
			return $protection;
		}
		if ( $destructive ) {
			if ( null === $approved_targets ) {
				$targets = self::preview_targets( $slug, $input );
				if ( is_wp_error( $targets ) ) {
					return $targets;
				}
				$confirmation_token = ADBC_MCP_Store::issue_preview( $slug, $input, $targets );
				if ( is_wp_error( $confirmation_token ) ) {
					return $confirmation_token;
				}
				$public_targets = $targets;
				unset( $public_targets['_sql_snapshot'] );
				$stored_preview = ADBC_MCP_Store::get( 'previews', hash( 'sha256', $confirmation_token ) );
				$preview = [
					'success' => true,
					'preview' => true,
					'ability' => $slug,
					'targets' => $public_targets,
					'ask_user' => $targets['confirmation_message'],
					'warning' => 'This action can be destructive. The AI client must show this impact and wait for a new explicit user approval in chat before confirming. ADBC cannot verify the origin of a chat reply. Counts and sizes are a snapshot; category purge contents may change before execution. Completed database changes cannot be undone.',
					'approval_status' => 'awaiting_chat_confirmation',
					'confirmation_request_id' => $confirmation_token,
					'next_ability' => 'adbc/confirm-destructive-action',
					'message' => 'No action was performed. Show ask_user and the impact details, then stop and wait for explicit approval of this preview.',
					'expires_in_seconds' => $stored_preview ? max( 0, strtotime( $stored_preview['expires_at'] . ' UTC' ) - time() ) : 0,
				];
				if ( 'cli' === ADBC_MCP_Context::channel() ) {
					$preview['approval_status'] = 'awaiting_terminal_confirmation';
					$preview['next_command'] = 'wp adbc confirm ' . $confirmation_token . ' --user=<admin>';
					$preview['warning'] = 'This action can permanently change or delete data. Review the impact and approve this specific preview with wp adbc confirm. Completed changes cannot be undone.';
				}
				$snapshot = $targets['_sql_snapshot'] ?? [];
				return $preview;
			}
		}
		if ( $definition['background'] ) {
			$result = ADBC_MCP_Jobs::enqueue( $slug, $input );
			return $result;
		}
		$result = 'execute_sql' === $slug ? ADBC_MCP_SQL::execute_confirmed( $input, $approved_targets ?? [] ) : self::run_endpoint( $slug, $input );
		return $result;
	}

	public static function run_endpoint( $slug, array $input ) {
		return ADBC_MCP_Access::with_access( $slug, static fn() => self::run_endpoint_internal( $slug, $input ) );
	}
	private static function run_endpoint_internal( $slug, array $input ) {
		if ( 'execute_sql' === $slug ) {
			return new WP_Error( 'adbc_sql_preview_required', 'SQL writes require the confirmed snapshot flow.', [ 'status' => 409 ] );
		}
		$definitions = ADBC_MCP_Abilities::definitions();
		if ( ! isset( $definitions[ $slug ] ) || ! self::can_execute() ) {
			return new WP_Error( 'adbc_forbidden', 'This ADBC ability is unavailable.', [ 'status' => 403 ] );
		}
		$upgrade = ADBC_MCP_Abilities::upgrade_response( $slug );
		if ( $upgrade ) {
			return $upgrade;
		}
		$selection_error = self::validate_cleanup_selection( $slug, $input );
		if ( $selection_error ) {
			return $selection_error;
		}
		$protection = self::protect_storage( $slug, $input );
		if ( is_wp_error( $protection ) ) {
			return $protection;
		}
		$definition = $definitions[ $slug ];
		// Dashboard requests always include these filters. Ability callers may omit them.
		foreach ( [ 'site', 'interval' ] as $filter ) {
			if ( $definition['read_only'] && isset( $definition['input_schema']['properties'][ $filter ] ) && ! isset( $input[ $filter ] ) ) {
				$input[ $filter ] = 'all';
			}
		}
		if ( 0 === strpos( $slug, 'edit_scan_results_' ) ) {
			$input['manualCategorization']['send_to_server'] = isset( $input['manualCategorization']['send_to_server'] ) && '1' === (string) $input['manualCategorization']['send_to_server'] ? '1' : '0';
		}
		if ( isset( $input['selectedItems'] ) ) {
			$input['actionType'] = 'delete_items' === $slug ? 'delete_' . ( $input['itemsType'] ?? '' ) : ( 'delete_posts_by_post_type' === $slug ? 'delete_post_types' : $slug );
		}
		if ( 'update_settings' === $slug ) {
			$input = $input['settings'];
		}
		$request = new WP_REST_Request( 'POST', '/advanced-db-cleaner/v1/' . str_replace( '_', '-', $slug ) );
		if ( 'update_task' === $slug ) {
			$request->set_url_params( [ 'id' => $input['id'] ?? '' ] );
		}
		if ( in_array( $slug, [ 'create_task', 'update_task' ], true ) ) {
			$input = isset( $input['task'] ) && is_array( $input['task'] ) ? $input['task'] : [];
		}
		$request->set_header( 'content-type', 'application/json' );
		$request->set_body( wp_json_encode( $input ) );
		try {
			$method = [ $definition['class'], $definition['method'] ];
			if ( 'get_job' === $slug || 'cancel_job' === $slug ) {
				$response = call_user_func( $method, (string) ( $input['id'] ?? '' ) );
			} else {
				$response = 'get_license_status' === $slug ? call_user_func( $method, true ) : call_user_func( $method, $request );
			}
		} catch (Throwable $e) {
			ADBC_Logging::log_exception( __METHOD__, $e );
			return new WP_Error( 'adbc_operation_failed', 'The ADBC operation failed. Check the plugin log.', [ 'status' => 500 ] );
		}
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		if ( $response instanceof WP_REST_Response ) {
			$data = $response->get_data();
			if ( $response->get_status() >= 400 || ( is_array( $data ) && isset( $data['success'] ) && ! $data['success'] ) ) {
				return new WP_Error( 'adbc_operation_failed', is_array( $data ) && isset( $data['message'] ) ? $data['message'] : 'ADBC rejected the operation.', [ 'status' => $response->get_status() ] );
			}
		} else {
			$data = ADBC_MCP_SQL::class === $definition['class'] ? $response : [ 'success' => true, 'data' => $response ];
		}
		if ( ADBC_Change_Log::warning() ) {
			$data['logging_warning'] = ADBC_Change_Log::warning();
		}
		return $data;
	}

	/** Reject a mixed category selection before previewing or dispatching a worker. */
	private static function validate_cleanup_selection( $slug, array $input ): ?WP_Error {
		if ( 'delete_items' !== $slug ) {
			return null;
		}
		$type = $input['itemsType'] ?? null;
		if ( ! is_string( $type ) || '' === ADBC_Common_Validator::sanitize_items_type( $type ) ) {
			return new WP_Error( 'adbc_invalid_input', 'Select a valid cleanup category.', [ 'status' => 400 ] );
		}
		foreach ( $input['selectedItems'] ?? [] as $item ) {
			if ( ! is_array( $item ) || ( $item['items_type'] ?? null ) !== $type ) {
				return new WP_Error( 'adbc_invalid_input', 'Every selected item must belong to the requested cleanup category. No action was performed.', [ 'status' => 400 ] );
			}
		}
		return null;
	}

	/** The queue and authentication records must not be deleted by their own job. */
	private static function protect_storage( $slug, array $input ) {
		if ( ! in_array( $slug, [ 'delete_options', 'set_autoload_to_yes_options', 'set_autoload_to_no_options', 'delete_transients', 'set_autoload_to_yes_transients', 'set_autoload_to_no_transients', 'delete_cron_jobs' ], true ) ) {
			return true;
		}
		global $wpdb;
		$items = $input['selectedItems'] ?? [ $input ];
		foreach ( $items as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			$name = (string) ( $item['name'] ?? $item['optionName'] ?? '' );
			if ( isset( $item['id'], $item['site_id'] ) && in_array( $slug, [ 'delete_options', 'set_autoload_to_yes_options', 'set_autoload_to_no_options' ], true ) ) {
				$prefix = ADBC_Sites::instance()->get_prefix_from_site_id( (int) $item['site_id'] );
				if ( $prefix ) {
					$name = (string) $wpdb->get_var( $wpdb->prepare( 'SELECT option_name FROM %i WHERE option_id = %d', $prefix . 'options', (int) $item['id'] ) );
				}
			}
			if ( preg_match( '/^(?:_site_transient_(?:timeout_)?|_transient_(?:timeout_)?)?adbc_mcp_/i', $name ) ) {
				return new WP_Error( 'adbc_mcp_storage_protected', 'ADBC MCP connection, confirmation, and job records are protected. Use the MCP server controls to manage them.', [ 'status' => 409 ] );
			}
		}
		return true;
	}

	private static function valid_operational_settings( $slug, array $input ) {
		if ( 'get_setting' === $slug ) {
			$key = isset( $input['key'] ) ? (string) $input['key'] : '';
			return in_array( $key, self::OPERATIONAL_SETTINGS, true );
		}
		if ( 'update_settings' !== $slug || ! isset( $input['settings'] ) || ! is_array( $input['settings'] ) || ! $input['settings'] ) {
			return 'update_settings' !== $slug;
		}
		foreach ( array_keys( $input['settings'] ) as $key ) {
			if ( ! in_array( $key, self::OPERATIONAL_SETTINGS, true ) ) {
				return false;
			}
		}
		return true;
	}

	private static function disables_core_protection( $slug, array $input ) {
		return 'update_settings' === $slug && isset( $input['settings']['prevent_taking_action_on_wp_items'] ) && '0' === (string) $input['settings']['prevent_taking_action_on_wp_items'];
	}

	private static function preview_targets( $slug, array $input ) {
		if ( 'execute_sql' === $slug ) {
			return ADBC_MCP_SQL::preview( $input );
		}
		$targets = [ 'selected_count' => isset( $input['selectedItems'] ) && is_array( $input['selectedItems'] ) ? count( $input['selectedItems'] ) : null ];
		foreach ( [ 'itemsType', 'postTypeName', 'tableName', 'siteId', 'id' ] as $key ) {
			if ( isset( $input[ $key ] ) ) {
				$targets[ $key ] = sanitize_text_field( (string) $input[ $key ] );
			}
		}
		if ( isset( $input['selectedItems'] ) && is_array( $input['selectedItems'] ) ) {
			$targets['items'] = array_map( static function ( $item ) {
				return is_array( $item ) ? ( $item['name'] ?? $item['id'] ?? '[item]' ) : '[item]';
			}, $input['selectedItems'] );
		}
		$parameters = $input;
		unset( $parameters['selectedItems'] );
		if ( $parameters ) {
			$targets['parameters'] = $parameters;
		}
		if ( in_array( $slug, [ 'delete_tables', 'empty_rows_tables', 'convert_to_innodb_tables', 'optimize_tables', 'repair_tables' ], true ) ) {
			global $wpdb;
			$names = array_values( array_unique( array_filter( $targets['items'] ?? [], 'is_string' ) ) );
			if ( ! $names ) {
				return new WP_Error( 'adbc_preview_invalid_tables', 'Select at least one valid table to preview.', [ 'status' => 400 ] );
			}
			$placeholders = implode( ',', array_fill( 0, count( $names ), '%s' ) );
			$sql = $wpdb->prepare( "SELECT TABLE_NAME AS name, TABLE_ROWS AS estimated_rows, COALESCE(DATA_LENGTH, 0) + COALESCE(INDEX_LENGTH, 0) AS size_bytes FROM information_schema.TABLES WHERE TABLE_SCHEMA = %s AND TABLE_NAME IN ($placeholders)", array_merge( [ DB_NAME ], $names ) );
			$rows = $wpdb->get_results( $sql, ARRAY_A );
			if ( ! is_array( $rows ) || count( $rows ) !== count( $names ) ) {
				return new WP_Error( 'adbc_preview_stats_unavailable', 'Could not obtain size and row counts for every selected table.', [ 'status' => 409 ] );
			}
			$by_name = [];
			foreach ( $rows as $row ) {
				$by_name[ strtolower( $row['name'] ) ] = [ 'name' => $row['name'], 'size_bytes' => (int) $row['size_bytes'], 'size' => size_format( (int) $row['size_bytes'] ), 'rows' => null === $row['estimated_rows'] ? null : (int) $row['estimated_rows'], 'rows_are_estimated' => true ];
			}
			foreach ( $names as $name ) {
				if ( ! isset( $by_name[ strtolower( $name ) ] ) ) {
					return new WP_Error( 'adbc_preview_stats_unavailable', 'Could not obtain size and row counts for every selected table.', [ 'status' => 409 ] );
				}
			}
			$targets['table_stats'] = array_values( array_map( static fn( $name ) => $by_name[ strtolower( $name ) ], $names ) );
			$targets['total_size_bytes'] = array_sum( array_column( $targets['table_stats'], 'size_bytes' ) );
			$targets['total_size'] = size_format( $targets['total_size_bytes'] );
			$row_counts = array_column( $targets['table_stats'], 'rows' );
			$targets['total_estimated_rows'] = in_array( null, $row_counts, true ) ? null : array_sum( $row_counts );
		}
		if ( 'purge_items' === $slug && isset( $input['itemsType'] ) && is_string( $input['itemsType'] ) ) {
			$category = ADBC_General_Cleanup::get_general_data( $input['itemsType'] );
			$targets['affected_rows'] = (int) ( $category['total_count'] ?? 0 );
			$targets['total_size_bytes'] = (int) ( $category['total_size'] ?? 0 );
			$targets['total_size'] = size_format( $targets['total_size_bytes'] );
		}
		$message = 'This action can permanently change or delete data. Action: ' . $slug . '. ';
		if ( isset( $targets['table_stats'] ) ) {
			$message .= 'Tables: ' . implode( '; ', array_map( static fn( $table ) => $table['name'] . ' (' . $table['size'] . ', approximately ' . ( null === $table['rows'] ? 'unknown' : number_format_i18n( $table['rows'] ) ) . ' rows)', $targets['table_stats'] ) ) . '. ';
			$message .= 'Combined size: ' . $targets['total_size'] . '; estimated rows: ' . ( null === $targets['total_estimated_rows'] ? 'unknown' : number_format_i18n( $targets['total_estimated_rows'] ) ) . '. ';
		} elseif ( isset( $targets['affected_rows'] ) ) {
			$message .= 'Category: ' . $targets['itemsType'] . '; current count: ' . number_format_i18n( $targets['affected_rows'] ) . ' rows; size: ' . $targets['total_size'] . '. ';
		} elseif ( null !== $targets['selected_count'] ) {
			$message .= 'Selected targets: ' . $targets['selected_count'] . '. ';
			if ( ! empty( $targets['items'] ) ) {
				$message .= 'Target names or IDs: ' . implode( ', ', array_map( static fn( $item ) => wp_json_encode( (string) $item ), $targets['items'] ) ) . '. ';
			}
			$message .= 'Affected database row count and size are not available for this action. ';
		} else {
			$message .= 'Affected database row count and size are not available for this action. ';
		}
		if ( ! empty( $targets['parameters'] ) ) {
			$message .= 'Request details: ' . wp_json_encode( $targets['parameters'] ) . '. ';
		}
		$targets['confirmation_message'] = $message . 'This can permanently change or delete data. Do you explicitly approve this specific action? Please reply in chat.';
		return $targets;
	}

}
