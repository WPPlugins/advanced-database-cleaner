<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** One catalog powers both WordPress abilities and the ADBC MCP tool list. */
final class ADBC_MCP_Abilities {
	private static $definitions;

	public static function definitions() {
		if ( null !== self::$definitions ) {
			return self::$definitions;
		}
		$definitions = [];
		$add = static function ( $slug, $required_access, $class, $method, $schema = 'none', $destructive = false, $premium = false, $background = false ) use ( &$definitions ) {
			$label = ucwords( str_replace( '_', ' ', $slug ) );
			$definitions[ $slug ] = [
				'label' => $label,
				'description' => $label . ' using the same validation and behavior as the ADBC dashboard.' . ( $destructive ? ' This can permanently change or delete data. This first call ONLY previews the targets, size, and available row counts; it never executes. Show the preview to the user and stop. Only after a NEW explicit user approval in chat may you call adbc/confirm-destructive-action (or the MCP confirm-destructive-action tool) with the returned confirmation_request_id.' : '' ),
				'class' => $class,
				'method' => $method,
				'input_schema' => self::schema( $schema, $destructive, $slug ),
				'required_access' => $required_access,
				'read_only' => 'read' === $required_access,
				'destructive' => $destructive,
				'background' => $background,
				'requires_premium' => $premium,
			];
		};
		foreach ( [
			'get_database_context' => [ 'context', 'none', false, 'Get the database name, prefixes, server version, charset, and SQL limits.' ],
			'query_database' => [ 'query', 'sql_read', false, 'Run a single SELECT on this WordPress database. Joins and aggregates are supported; subqueries and unions are not. Default 100 rows, maximum 1000. Results and cells are capped and truncation is reported. Use unique column aliases; DISTINCT ordering must use selected columns.' ],
			'explain_query' => [ 'explain', 'sql', false, 'Explain a supported SELECT without executing it. Uses ordinary EXPLAIN, never EXPLAIN ANALYZE.' ],
			'execute_sql' => [ 'preview', 'sql', true, 'Preview INSERT VALUES, UPDATE WHERE, or DELETE WHERE on one InnoDB base table with a primary key. Maximum 1000 rows. SQL bypasses WordPress hooks, retention, and core-item protection. The first call never writes; show the exact SQL and impact to the user, stop, and use confirm-destructive-action only after a NEW explicit approval in chat.' ],
		] as $slug => $sql_tool ) {
			$add( $slug, $sql_tool[2] ? 'write' : 'read', ADBC_MCP_SQL::class, $sql_tool[0], $sql_tool[1], $sql_tool[2], true );
			$definitions[ $slug ]['description'] = $sql_tool[3];
			$definitions[ $slug ]['read_only'] = ! $sql_tool[2];
		}

		// Inventory and table maintenance.
		foreach ( [
			'get_tables_list' => [ 'get_tables_list', 'filters' ],
			'get_tables_names' => [ 'get_tables_names', 'none' ],
			'get_table_rows' => [ 'get_table_rows', 'table_filters' ],
			'get_table_structure' => [ 'get_table_structure', 'table' ],
			'count_total_not_scanned_tables' => [ 'count_total_not_scanned_tables', 'none' ],
			'count_total_tables_to_optimize' => [ 'count_total_tables_to_optimize', 'none' ],
			'count_total_tables_to_repair' => [ 'count_total_tables_to_repair', 'none' ],
			'count_total_tables_with_invalid_prefix' => [ 'count_total_tables_with_invalid_prefix', 'none' ],
		] as $slug => $row ) {
			$add( $slug, 'read', ADBC_Tables_Endpoints::class, $row[0], $row[1], false, false, false );
		}
		foreach ( [ 'optimize_tables', 'repair_tables', 'refresh_counts_tables', 'convert_to_innodb_tables', 'empty_rows_tables', 'delete_tables' ] as $slug ) {
			$add( $slug, 'write', ADBC_Tables_Endpoints::class, $slug, 'selected', 'refresh_counts_tables' !== $slug, false, true );
		}

		$groups = [
			'options' => [ ADBC_Options_Endpoints::class, [ 'get_options_list', 'count_big_options', 'count_total_not_scanned_options', 'get_autoload_health' ], [ 'set_autoload_to_yes_options', 'set_autoload_to_no_options', 'delete_options' ] ],
			'transients' => [ ADBC_Transients_Endpoints::class, [ 'get_transients_list', 'count_big_transients', 'count_total_not_scanned_transients', 'count_expired_transients' ], [ 'set_autoload_to_yes_transients', 'set_autoload_to_no_transients', 'delete_transients' ] ],
			'posts_meta' => [ ADBC_Posts_Meta_Endpoints::class, [ 'get_posts_meta_list', 'count_big_posts_meta', 'count_total_not_scanned_posts_meta', 'count_duplicated_posts_meta', 'count_unused_posts_meta' ], [ 'delete_posts_meta' ] ],
			'users_meta' => [ ADBC_Users_Meta_Endpoints::class, [ 'get_users_meta_list', 'count_big_users_meta', 'count_total_not_scanned_users_meta', 'count_duplicated_users_meta', 'count_unused_users_meta' ], [ 'delete_users_meta' ] ],
			'post_types' => [ ADBC_Post_Types_Endpoints::class, [ 'get_post_types_list', 'count_total_not_scanned_post_types', 'count_total_large_non_public_post_types' ], [] ],
			'cron_jobs' => [ ADBC_Cron_Jobs_Endpoints::class, [ 'get_cron_jobs_list', 'count_total_not_scanned_cron_jobs', 'count_total_cron_jobs_with_no_action' ], [ 'delete_cron_jobs' ] ],
		];
		foreach ( $groups as $group ) {
			foreach ( $group[1] as $slug ) {
				$add( $slug, 'read', $group[0], $slug, 0 === strpos( $slug, 'get_' ) ? 'filters' : 'none', false, false, false );
			}
			foreach ( $group[2] as $slug ) {
				$add( $slug, 'write', $group[0], $slug, 'selected', true, false, true );
			}
		}
		$add( 'list_posts_by_post_type', 'read', ADBC_Post_Types_Endpoints::class, 'list_posts_by_post_type', 'post_type' );
		$add( 'delete_posts_by_post_type', 'write', ADBC_Post_Types_Endpoints::class, 'delete_posts_by_post_type', 'selected', true, false, true );
		$add( 'get_column_value_from_table', 'read', ADBC_Common_Endpoints::class, 'get_column_value_from_table', 'column_value', false, false, false );

		// General cleanup, retention, and automations.
		foreach ( [ 'get_general_data', 'get_items' ] as $slug ) {
			$add( $slug, 'read', ADBC_General_Cleanup_Endpoints::class, $slug, 'get_items' === $slug ? 'cleanup_filters_required' : 'cleanup_filters' );
		}
		$add( 'delete_items', 'write', ADBC_General_Cleanup_Endpoints::class, 'delete_items', 'cleanup_selected', true, false, true );
		$add( 'purge_items', 'write', ADBC_General_Cleanup_Endpoints::class, 'purge_items', 'cleanup_type', true, false, true );
		$add( 'get_keep_last', 'read', ADBC_General_Cleanup_Endpoints::class, 'get_keep_last' );
		$add( 'set_keep_last', 'write', ADBC_General_Cleanup_Endpoints::class, 'set_keep_last', 'keep_last', true );
		foreach ( [ 'delete_keep_last', 'activate_auto_count', 'deactivate_auto_count' ] as $slug ) {
			$add( $slug, 'write', ADBC_General_Cleanup_Endpoints::class, $slug, 'cleanup_types', 'delete_keep_last' === $slug );
		}
		foreach ( [ 'list_tasks', 'get_task' ] as $slug ) {
			$add( $slug, 'read', ADBC_Automation_Endpoints::class, $slug, 'get_task' === $slug ? 'task_id' : 'task_filter' );
		}
		foreach ( [ 'create_task', 'update_task', 'delete_task' ] as $slug ) {
			$add( $slug, 'write', ADBC_Automation_Endpoints::class, $slug, 'delete_task' === $slug ? 'task_id' : ( 'create_task' === $slug ? 'task_create' : 'task_update' ), true );
		}
		$add( 'get_task_events_log', 'read', ADBC_Automation_Endpoints::class, 'get_task_events_log', 'event_log', false, true, false );
		$add( 'clear_task_events_log', 'write', ADBC_Automation_Endpoints::class, 'clear_task_events_log', 'task_id', true, true );

		// Settings, diagnostic data, and logs.
		$add( 'get_setting', 'read', ADBC_Settings_Endpoints::class, 'get_setting', 'setting_key' );
		$add( 'update_settings', 'write', ADBC_Settings_Endpoints::class, 'update_settings', 'settings', true );
		$add( 'get_system_information', 'read', ADBC_Info_Endpoints::class, 'get_system_information' );
		$add( 'get_all_schedule_frequencies', 'read', ADBC_Common_Endpoints::class, 'get_all_schedule_frequencies' );
		$add( 'get_last_week_database_size_for_free_version', 'read', ADBC_Common_Endpoints::class, 'get_last_week_database_size_for_free_version' );
		$add( 'get_logs_content', 'read', ADBC_Logs_Endpoints::class, 'get_logs_content', 'log_type', false, false, false );
		$add( 'clear_logs_content', 'write', ADBC_Logs_Endpoints::class, 'clear_logs_content', 'none', true );
		$add( 'get_job', 'read', ADBC_MCP_Jobs::class, 'get', 'task_id' );
		$add( 'cancel_job', 'write', ADBC_MCP_Jobs::class, 'cancel', 'task_id' );

		foreach ( [
			'tables' => ADBC_Tables_Endpoints::class,
			'options' => ADBC_Options_Endpoints::class,
			'transients' => ADBC_Transients_Endpoints::class,
			'posts_meta' => ADBC_Posts_Meta_Endpoints::class,
			'users_meta' => ADBC_Users_Meta_Endpoints::class,
			'post_types' => ADBC_Post_Types_Endpoints::class,
			'cron_jobs' => ADBC_Cron_Jobs_Endpoints::class,
		] as $type => $class ) {
			$slug = 'edit_scan_results_' . $type;
			$add( $slug, 'write', $class, $slug, 'correction', true, true );
		}
		$add( 'is_scan_exists', 'read', ADBC_Scan_Endpoints::class, 'is_scan_exists', 'scan_type', false, true );
		if ( ADBC_VERSION_TYPE === 'PREMIUM' ) {
			$balance = ADBC_IS_PRO_VERSION ? 'get_pro_scan_balance' : 'get_remote_scan_balance';
			$add( $balance, 'read', ADBC_Scan_Endpoints::class, $balance, 'none', false, true );
		}
		foreach ( [ 'get_database_chart_data_by_day', 'get_database_chart_data_by_month', 'get_tables_chart_data_by_day', 'get_tables_chart_data_by_month' ] as $slug ) {
			$add( $slug, 'read', ADBC_Analytics_Endpoints::class, $slug, false !== strpos( $slug, 'tables_' ) ? 'chart_tables' : 'chart_range', false, true );
		}
		$add( 'get_last_week_database_size', 'read', ADBC_Analytics_Endpoints::class, 'get_last_week_database_size', 'none', false, true );
		$add( 'get_addons_list', 'read', ADBC_Addons_Endpoints::class, 'get_addons_list', 'none', false, true );
		$add( 'get_addons_activity_timeline', 'read', ADBC_Addons_Endpoints::class, 'get_addons_activity_timeline', 'activity', false, true, false );
		$add( 'clear_addons_activity', 'write', ADBC_Addons_Endpoints::class, 'clear_addons_activity', 'none', true, true );
		$add( 'get_license_status', 'read', ADBC_License_Manager::class, 'get_license_data', 'none', false, true, false );
		$add( 'activate_license', 'write', ADBC_License_Endpoints::class, 'activate_license', 'license', false, true, false );
		$add( 'refresh_license', 'write', ADBC_License_Endpoints::class, 'refresh_license', 'none', false, true, false );
		$add( 'deactivate_license', 'write', ADBC_License_Endpoints::class, 'deactivate_license', 'none', true, true, false );
		$definitions['confirm_destructive_action'] = [
			'label' => 'Confirm Destructive Action',
			'description' => 'Execute a previewed destructive action only after showing its risk and impact and receiving a NEW explicit user approval in chat. ADBC cannot verify who wrote the reply; the AI client must honor this instruction. CLI users must use wp adbc confirm.',
			'input_schema' => [ 'type' => 'object', 'properties' => [ 'confirmation_request_id' => [ 'type' => 'string', 'pattern' => '^[a-f0-9]{64}$' ], 'approved_in_chat' => [ 'type' => 'boolean', 'description' => 'True only after a NEW user reply approving this exact preview.' ] ], 'required' => [ 'confirmation_request_id', 'approved_in_chat' ], 'additionalProperties' => false ],
			'required_access' => 'write', 'read_only' => false, 'destructive' => true, 'background' => false,
		];
		foreach ( $definitions as $slug => &$definition ) {
			if ( ! empty( $definition['requires_premium'] ) ) {
				if ( ADBC_VERSION_TYPE !== 'PREMIUM' ) {
					$definition['description'] = $definition['label'] . ' requires ADBC Premium. In Free, this tool only returns an upgrade invitation; it does not read data, create a preview, or perform an action. Show the upgrade message to the user.';
				} else {
					$definition['description'] .= 'get_pro_scan_balance' === $slug ? ' Available only in ADBC Pro.' : ( 'get_remote_scan_balance' === $slug ? ' Available only in ADBC Premium.' : ' Requires ADBC Premium or Pro.' );
				}
			}
		}
		unset( $definition );
		self::$definitions = $definitions;
		return $definitions;
	}

	/** Return before accessing paid modules, which are absent from the Free package. */
	public static function upgrade_response( string $slug ): ?array {
		$definition = self::definitions()[ $slug ] ?? null;
		if ( ADBC_VERSION_TYPE === 'PREMIUM' || empty( $definition['requires_premium'] ) ) {
			return null;
		}
		return [
			'success' => false,
			'code' => 'adbc_upgrade_required',
			'ability' => 'adbc/' . str_replace( '_', '-', $slug ),
			'required_edition' => 'premium',
			'message' => sprintf(
				/* translators: %s: Name of the ability that requires Premium. */
				__( '%s requires Advanced Database Cleaner Premium. Upgrade to use this feature through your AI agent or WP-CLI. No action was performed.', 'advanced-database-cleaner' ),
				$definition['label']
			),
			'upgrade_url' => 'https://sigmaplugin.com/downloads/wordpress-advanced-database-cleaner/',
			'action_performed' => false,
		];
	}

	public static function register() {
		foreach ( self::definitions() as $slug => $definition ) {
			wp_register_ability( 'adbc/' . str_replace( '_', '-', $slug ), [
				'label' => $definition['label'],
				'description' => $definition['description'],
				'category' => 'adbc',
				'input_schema' => $definition['input_schema'],
				'output_schema' => [ 'type' => 'object', 'additionalProperties' => true ],
				'permission_callback' => [ ADBC_MCP_Access::class, 'can_invoke' ],
				'execute_callback' => static function ( $input = [] ) use ( $slug ) {
					return ADBC_MCP_Access::invoke( $slug, (array) $input );
				},
				'meta' => [
					'public' => true,
					'show_in_rest' => true,
					'annotations' => [ 'readonly' => $definition['read_only'], 'destructive' => $definition['destructive'], 'idempotent' => false ],
				],
			] );
		}
	}
	public static function register_category() {
		wp_register_ability_category( 'adbc', [ 'label' => 'Advanced Database Cleaner', 'description' => 'Inspect and maintain the WordPress database with ADBC.' ] );
	}

	/** WordPress accepts comma-separated strings as arrays; our tool contract requires lists. */
	public static function validate_lists( $input, array $schema, string $path = 'input' ): ?WP_Error {
		if ( 'array' === ( $schema['type'] ?? '' ) ) {
			if ( ! is_array( $input ) || ! array_is_list( $input ) ) {
				return new WP_Error( 'adbc_invalid_input', $path . ' must be an array.', [ 'status' => 400 ] );
			}
			foreach ( $input as $index => $value ) {
				$error = self::validate_lists( $value, $schema['items'] ?? [], $path . '[' . $index . ']' );
				if ( $error ) {
					return $error;
				}
			}
		} elseif ( is_array( $input ) ) {
			foreach ( $schema['properties'] ?? [] as $name => $child ) {
				if ( ! array_key_exists( $name, $input ) ) {
					continue;
				}
				$error = self::validate_lists( $input[ $name ], $child, $path . '.' . $name );
				if ( $error ) {
					return $error;
				}
			}
		}
		return null;
	}

	private static function schema( $kind, $destructive, $slug ) {
		$properties = [];
		$required = [];
		$task = [ 'type' => 'object', 'properties' => [ 'id' => [ 'type' => 'string' ], 'name' => [ 'type' => 'string' ], 'frequency' => [ 'type' => 'string' ], 'start_datetime' => [ 'type' => 'integer', 'description' => 'Unix timestamp in seconds.' ], 'type' => [ 'type' => 'string', 'enum' => [ 'general_cleanup' ] ], 'operations' => [ 'type' => 'object', 'description' => 'Cleanup item types mapped to no_keep_last, default, or {type: days|items, value: positive integer}.' ], 'active' => [ 'type' => 'boolean' ] ], 'required' => [ 'name', 'frequency', 'start_datetime', 'type', 'operations', 'active' ], 'additionalProperties' => false ];
		$strings = static function ( $keys ) use ( &$properties ) {
			foreach ( $keys as $key ) {
				$properties[ $key ] = [ 'type' => 'string' ];
			}
		};
		$filters = [ 'size', 'sizeUnit', 'tableStatus', 'prefixStatus', 'belongsTo', 'currentPage', 'itemsPerPage', 'sortBy', 'sortOrder', 'itemsType', 'expired', 'duplicated', 'unused', 'hasAction', 'search', 'searchIn', 'site', 'belongsToPluginSlug', 'belongsToThemeSlug', 'showManualCorrectionsOnly', 'autoload', 'startDate', 'endDate', 'frequency', 'interval', 'postTypesPostsCount', 'postTypesVisibility' ];
		switch ( $kind ) {
			case 'sql':
			case 'sql_read':
				$properties['sql'] = [ 'type' => 'string', 'minLength' => 1, 'maxLength' => 32768, 'description' => 'One UTF-8 SQL statement, at most 32 KiB. Only this WordPress database is accessible.' ];
				$required[] = 'sql';
				if ( 'sql_read' === $kind ) {
					$properties['row_limit'] = [ 'type' => 'integer', 'minimum' => 1, 'maximum' => 1000, 'default' => 100 ];
				}
				break;
			case 'filters':
			case 'cleanup_filters':
			case 'cleanup_filters_required':
				$strings( $filters );
				if ( 'cleanup_filters_required' === $kind ) {
					$required[] = 'itemsType';
				}
				break;
			case 'selected':
			case 'cleanup_selected':
			case 'correction':
				$item_type = 'delete_posts_by_post_type' === $slug ? 'post_types' : '';
				if ( preg_match( '/_(tables|options|transients|posts_meta|users_meta|cron_jobs|post_types)$/', $slug, $matches ) ) {
					$item_type = $matches[1];
				}
				$item_fields = [
					'tables' => 'name',
					'cron_jobs' => 'site_id, name, args, timestamp',
					'options' => 'site_id, id, name',
					'posts_meta' => 'site_id, id, name',
					'users_meta' => 'site_id, id, name',
					'post_types' => 'site_id, name',
					'transients' => 'site_id, id, name, found_in',
				];
				$item_schema = [ 'type' => 'object', 'properties' => [ 'items_type' => [ 'type' => 'string' ] ], 'required' => [ 'items_type' ], 'additionalProperties' => true ];
				if ( $item_type ) {
					$item_schema['properties']['items_type']['enum'] = [ $item_type ];
					$item_schema['required'] = array_merge( [ 'items_type' ], explode( ', ', $item_fields[ $item_type ] ) );
				}
				$description = 'Copy complete item objects from the matching list tool; keep items_type and the original identifiers. Maximum 1000 items.';
				if ( $item_type ) {
					$description .= ' Each item requires items_type=' . $item_type . ' and ' . $item_fields[ $item_type ] . '.';
				} else {
					$description .= ' Each item must have items_type equal to itemsType, site_id, and id; unused_relationships also needs term_taxonomy_id, and expired_transients needs name and found_in.';
				}
				$properties['selectedItems'] = [ 'type' => 'array', 'minItems' => 1, 'maxItems' => 1000, 'items' => $item_schema, 'description' => $description ];
				$required[] = 'selectedItems';
				if ( 'cleanup_selected' === $kind ) {
					$strings( [ 'itemsType' ] );
					$required[] = 'itemsType';
				}
				if ( 'correction' === $kind ) {
					$properties['manualCategorization'] = [ 'type' => 'object', 'properties' => [ 'type' => [ 'type' => 'string' ], 'slug' => [ 'type' => 'string' ], 'send_to_server' => [ 'type' => 'string', 'enum' => [ '0', '1' ] ] ], 'required' => [ 'type', 'slug' ] ];
					$required[] = 'manualCategorization';
				}
				break;
			case 'table':
				$strings( [ 'tableName' ] );
				$required[] = 'tableName';
				break;
			case 'table_filters':
				$strings( $filters );
				$strings( [ 'tableName' ] );
				$required[] = 'tableName';
				break;
			case 'column_value':
				$strings( [ 'itemsType', 'siteId', 'rowId', 'transientFoundIn' ] );
				$required = [ 'itemsType', 'siteId', 'rowId' ];
				break;
			case 'post_type':
				$strings( [ 'postTypeName', 'siteId', 'currentPage', 'itemsPerPage', 'search', 'sortBy', 'sortOrder' ] );
				$required = [ 'postTypeName', 'siteId' ];
				break;
			case 'cleanup_type':
				$strings( [ 'itemsType' ] );
				$required[] = 'itemsType';
				break;
			case 'cleanup_types':
				$properties['itemsTypes'] = [ 'type' => 'array', 'items' => [ 'type' => 'string' ] ];
				$required[] = 'itemsTypes';
				break;
			case 'keep_last':
				$properties['keepLast'] = [ 'type' => 'object' ];
				$required[] = 'keepLast';
				break;
			case 'task_id':
				$strings( [ 'id' ] );
				$required[] = 'id';
				break;
			case 'task_filter':
				$strings( [ 'status' ] );
				break;
			case 'task_create':
				$properties['task'] = $task;
				$required[] = 'task';
				break;
			case 'task_update':
				$strings( [ 'id' ] );
				$properties['task'] = $task;
				$required = [ 'id', 'task' ];
				break;
			case 'event_log':
				$strings( [ 'id', 'currentPage', 'itemsPerPage' ] );
				$required[] = 'id';
				break;
			case 'setting_key':
				$properties['key'] = [ 'type' => 'string', 'enum' => ADBC_MCP_Executor::OPERATIONAL_SETTINGS ];
				$required[] = 'key';
				break;
			case 'settings':
				$toggle = [ 'type' => 'string', 'enum' => [ '0', '1' ] ];
				$number = [ 'type' => [ 'integer', 'string' ] ];
				$properties['settings'] = [ 'type' => 'object', 'properties' => [ 'analytics_enabled' => $toggle, 'addons_activity_enabled' => $toggle, 'database_rows_batch' => $number, 'reduce_cpu_usage' => $toggle, 'cpu_work_time_ms' => $number, 'cpu_rest_time_ms' => $number, 'sql_or_native_cleanup_method' => [ 'type' => 'string', 'enum' => [ 'sql', 'native' ] ], 'prevent_taking_action_on_wp_items' => $toggle ], 'additionalProperties' => false, 'minProperties' => 1 ];
				$required[] = 'settings';
				break;
			case 'log_type':
				$strings( [ 'log_type' ] );
				$required[] = 'log_type';
				break;
			case 'scan_type':
				$strings( [ 'items_type' ] );
				$required[] = 'items_type';
				break;
			case 'chart_range':
				$strings( [ 'start_date', 'end_date' ] );
				$required = [ 'start_date', 'end_date' ];
				break;
			case 'chart_tables':
				$strings( [ 'start_date', 'end_date' ] );
				$properties['tables'] = [ 'type' => 'array', 'items' => [ 'type' => 'string' ] ];
				$required = [ 'start_date', 'end_date', 'tables' ];
				break;
			case 'activity':
				$strings( [ 'search', 'activity_type', 'offset', 'limit' ] );
				break;
			case 'license':
				$strings( [ 'license_key' ] );
				$required[] = 'license_key';
				break;
		}
		if ( isset( $properties['showManualCorrectionsOnly'] ) ) {
			$properties['showManualCorrectionsOnly'] = [ 'type' => 'boolean', 'description' => 'Show only items with a manual categorization correction. Premium or Pro only; Free ignores this filter.' ];
		}
		$schema = [ 'type' => 'object', 'properties' => $properties, 'required' => $required, 'additionalProperties' => false ];
		if ( ! $required ) {
			$schema['default'] = new stdClass();
		}
		return $schema;
	}
}
