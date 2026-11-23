<?php

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) )
	exit;

/**
 * ADBC All Routes class.
 * 
 * This class centralizes all route registrations and security checks for the plugin.
 */
class ADBC_Routes {

	/**
	 * Register all ADBC routes.
	 * 
	 * @return void
	 */
	public static function register_all_routes() {

		// Store prices and eligible license upgrades (administrator + REST nonce required).
		self::register_route( '/offers/plans', 'get_plans', 'POST', ADBC_Plan_Offers::class);

		// Settings routes.
		self::register_route( '/settings/update', 'update_settings', WP_REST_Server::EDITABLE, ADBC_Settings_Endpoints::class);
		self::register_route( '/settings/get', 'get_setting', WP_REST_Server::EDITABLE, ADBC_Settings_Endpoints::class);

		// Tables routes.
		self::register_route( '/tables/get-list', 'get_tables_list', WP_REST_Server::EDITABLE, ADBC_Tables_Endpoints::class);
		self::register_route( '/tables/get-names', 'get_tables_names', WP_REST_Server::READABLE, ADBC_Tables_Endpoints::class);
		self::register_route( '/tables/optimize', 'optimize_tables', WP_REST_Server::EDITABLE, ADBC_Tables_Endpoints::class);
		self::register_route( '/tables/repair', 'repair_tables', WP_REST_Server::EDITABLE, ADBC_Tables_Endpoints::class);
		self::register_route( '/tables/refresh-counts', 'refresh_counts_tables', WP_REST_Server::EDITABLE, ADBC_Tables_Endpoints::class);
		self::register_route( '/tables/convert-to-innodb', 'convert_to_innodb_tables', WP_REST_Server::EDITABLE, ADBC_Tables_Endpoints::class);
		self::register_route( '/tables/empty', 'empty_rows_tables', WP_REST_Server::EDITABLE, ADBC_Tables_Endpoints::class);
		self::register_route( '/tables/delete', 'delete_tables', WP_REST_Server::EDITABLE, ADBC_Tables_Endpoints::class);
		self::register_route( '/tables/count-not-scanned', 'count_total_not_scanned_tables', WP_REST_Server::READABLE, ADBC_Tables_Endpoints::class);
		self::register_route( '/tables/count-to-optimize', 'count_total_tables_to_optimize', WP_REST_Server::READABLE, ADBC_Tables_Endpoints::class);
		self::register_route( '/tables/count-to-repair', 'count_total_tables_to_repair', WP_REST_Server::READABLE, ADBC_Tables_Endpoints::class);
		self::register_route( '/tables/count-invalid-prefix', 'count_total_tables_with_invalid_prefix', WP_REST_Server::READABLE, ADBC_Tables_Endpoints::class);
		self::register_route( '/tables/get-rows', 'get_table_rows', WP_REST_Server::EDITABLE, ADBC_Tables_Endpoints::class);
		self::register_route( '/tables/get-structure', 'get_table_structure', WP_REST_Server::EDITABLE, ADBC_Tables_Endpoints::class);

		// Options routes.
		self::register_route( '/options/get-list', 'get_options_list', WP_REST_Server::EDITABLE, ADBC_Options_Endpoints::class);
		self::register_route( '/options/enable-autoload', 'set_autoload_to_yes_options', WP_REST_Server::EDITABLE, ADBC_Options_Endpoints::class);
		self::register_route( '/options/disable-autoload', 'set_autoload_to_no_options', WP_REST_Server::EDITABLE, ADBC_Options_Endpoints::class);
		self::register_route( '/options/delete', 'delete_options', WP_REST_Server::EDITABLE, ADBC_Options_Endpoints::class);
		self::register_route( '/options/count-large', 'count_big_options', WP_REST_Server::READABLE, ADBC_Options_Endpoints::class);
		self::register_route( '/options/count-not-scanned', 'count_total_not_scanned_options', WP_REST_Server::READABLE, ADBC_Options_Endpoints::class);
		self::register_route( '/options/get-autoload-health', 'get_autoload_health', WP_REST_Server::READABLE, ADBC_Options_Endpoints::class);
		self::register_route( '/options/autoload/status', 'status', WP_REST_Server::READABLE, ADBC_Autoload_Endpoints::class);
		self::register_route( '/options/autoload/preview', 'preview', 'POST', ADBC_Autoload_Endpoints::class);
		self::register_route( '/options/autoload/execute', 'execute', 'POST', ADBC_Autoload_Endpoints::class);
		self::register_route( '/options/autoload/commands/status', 'command_status', 'POST', ADBC_Autoload_Endpoints::class);

		// Transients routes.
		self::register_route( '/transients/get-list', 'get_transients_list', WP_REST_Server::EDITABLE, ADBC_Transients_Endpoints::class);
		self::register_route( '/transients/enable-autoload', 'set_autoload_to_yes_transients', WP_REST_Server::EDITABLE, ADBC_Transients_Endpoints::class);
		self::register_route( '/transients/disable-autoload', 'set_autoload_to_no_transients', WP_REST_Server::EDITABLE, ADBC_Transients_Endpoints::class);
		self::register_route( '/transients/delete', 'delete_transients', WP_REST_Server::EDITABLE, ADBC_Transients_Endpoints::class);
		self::register_route( '/transients/count-large', 'count_big_transients', WP_REST_Server::READABLE, ADBC_Transients_Endpoints::class);
		self::register_route( '/transients/count-not-scanned', 'count_total_not_scanned_transients', WP_REST_Server::READABLE, ADBC_Transients_Endpoints::class);
		self::register_route( '/transients/count-expired', 'count_expired_transients', WP_REST_Server::READABLE, ADBC_Transients_Endpoints::class);

		// Posts_meta routes.
		self::register_route( '/posts-meta/get-list', 'get_posts_meta_list', WP_REST_Server::EDITABLE, ADBC_Posts_Meta_Endpoints::class);
		self::register_route( '/posts-meta/delete', 'delete_posts_meta', WP_REST_Server::EDITABLE, ADBC_Posts_Meta_Endpoints::class);
		self::register_route( '/posts-meta/count-large', 'count_big_posts_meta', WP_REST_Server::READABLE, ADBC_Posts_Meta_Endpoints::class);
		self::register_route( '/posts-meta/count-not-scanned', 'count_total_not_scanned_posts_meta', WP_REST_Server::READABLE, ADBC_Posts_Meta_Endpoints::class);
		self::register_route( '/posts-meta/count-duplicates', 'count_duplicated_posts_meta', WP_REST_Server::READABLE, ADBC_Posts_Meta_Endpoints::class);
		self::register_route( '/posts-meta/count-unused', 'count_unused_posts_meta', WP_REST_Server::READABLE, ADBC_Posts_Meta_Endpoints::class);

		// Users_meta routes.
		self::register_route( '/users-meta/get-list', 'get_users_meta_list', WP_REST_Server::EDITABLE, ADBC_Users_Meta_Endpoints::class);
		self::register_route( '/users-meta/delete', 'delete_users_meta', WP_REST_Server::EDITABLE, ADBC_Users_Meta_Endpoints::class);
		self::register_route( '/users-meta/count-large', 'count_big_users_meta', WP_REST_Server::READABLE, ADBC_Users_Meta_Endpoints::class);
		self::register_route( '/users-meta/count-not-scanned', 'count_total_not_scanned_users_meta', WP_REST_Server::READABLE, ADBC_Users_Meta_Endpoints::class);
		self::register_route( '/users-meta/count-duplicates', 'count_duplicated_users_meta', WP_REST_Server::READABLE, ADBC_Users_Meta_Endpoints::class);
		self::register_route( '/users-meta/count-unused', 'count_unused_users_meta', WP_REST_Server::READABLE, ADBC_Users_Meta_Endpoints::class);

		// Post_types routes.
		self::register_route( '/post-types/get-list', 'get_post_types_list', WP_REST_Server::EDITABLE, ADBC_Post_Types_Endpoints::class);
		self::register_route( '/post-types/get-posts', 'list_posts_by_post_type', WP_REST_Server::EDITABLE, ADBC_Post_Types_Endpoints::class);
		self::register_route( '/post-types/delete-posts', 'delete_posts_by_post_type', WP_REST_Server::EDITABLE, ADBC_Post_Types_Endpoints::class);
		self::register_route( '/post-types/count-not-scanned', 'count_total_not_scanned_post_types', WP_REST_Server::READABLE, ADBC_Post_Types_Endpoints::class);
		self::register_route( '/post-types/count-large-non-public', 'count_total_large_non_public_post_types', WP_REST_Server::READABLE, ADBC_Post_Types_Endpoints::class);

		// Cron_jobs routes.
		self::register_route( '/cron-jobs/get-list', 'get_cron_jobs_list', WP_REST_Server::EDITABLE, ADBC_Cron_Jobs_Endpoints::class);
		self::register_route( '/cron-jobs/delete', 'delete_cron_jobs', WP_REST_Server::EDITABLE, ADBC_Cron_Jobs_Endpoints::class);
		self::register_route( '/cron-jobs/count-not-scanned', 'count_total_not_scanned_cron_jobs', WP_REST_Server::READABLE, ADBC_Cron_Jobs_Endpoints::class);
		self::register_route( '/cron-jobs/count-without-action', 'count_total_cron_jobs_with_no_action', WP_REST_Server::READABLE, ADBC_Cron_Jobs_Endpoints::class);

		// Common routes.
		self::register_route( '/database/get-column-value', 'get_column_value_from_table', WP_REST_Server::EDITABLE, ADBC_Common_Endpoints::class);
		self::register_route( '/notifications/dismiss', 'dismiss_notification', WP_REST_Server::EDITABLE, ADBC_Common_Endpoints::class);
		self::register_route( '/notices/delay-rating', 'delay_rating_notice', WP_REST_Server::EDITABLE, ADBC_Common_Endpoints::class);
		self::register_route( '/notices/delay-ltd-migration', 'delay_ltd_migration_notice', WP_REST_Server::EDITABLE, ADBC_Common_Endpoints::class);
		self::register_route( '/automation/get-frequencies', 'get_all_schedule_frequencies', WP_REST_Server::READABLE, ADBC_Common_Endpoints::class);
		self::register_route( '/analytics/get-size-preview', 'get_last_week_database_size_for_free_version', WP_REST_Server::READABLE, ADBC_Common_Endpoints::class);

		// Logs routes.
		self::register_route( '/logs/get-content', 'get_logs_content', WP_REST_Server::EDITABLE, ADBC_Logs_Endpoints::class);
		self::register_route( '/logs/clear', 'clear_logs_content', WP_REST_Server::EDITABLE, ADBC_Logs_Endpoints::class);

		// Info routes.
		self::register_route( '/system/get-info', 'get_system_information', WP_REST_Server::READABLE, ADBC_Info_Endpoints::class);

		// General cleanup routes.
		self::register_route( '/general-cleanup/get-summary', 'get_general_data', WP_REST_Server::EDITABLE, ADBC_General_Cleanup_Endpoints::class);
		self::register_route( '/general-cleanup/get-items', 'get_items', WP_REST_Server::EDITABLE, ADBC_General_Cleanup_Endpoints::class);
		self::register_route( '/general-cleanup/delete-items', 'delete_items', WP_REST_Server::EDITABLE, ADBC_General_Cleanup_Endpoints::class);
		self::register_route( '/general-cleanup/purge-items', 'purge_items', WP_REST_Server::EDITABLE, ADBC_General_Cleanup_Endpoints::class);
		self::register_route( '/general-cleanup/set-keep-last', 'set_keep_last', WP_REST_Server::EDITABLE, ADBC_General_Cleanup_Endpoints::class);
		self::register_route( '/general-cleanup/get-keep-last', 'get_keep_last', WP_REST_Server::READABLE, ADBC_General_Cleanup_Endpoints::class);
		self::register_route( '/general-cleanup/delete-keep-last', 'delete_keep_last', WP_REST_Server::EDITABLE, ADBC_General_Cleanup_Endpoints::class);
		self::register_route( '/general-cleanup/enable-auto-count', 'activate_auto_count', WP_REST_Server::EDITABLE, ADBC_General_Cleanup_Endpoints::class);
		self::register_route( '/general-cleanup/disable-auto-count', 'deactivate_auto_count', WP_REST_Server::EDITABLE, ADBC_General_Cleanup_Endpoints::class);

		// Automation routes.
		self::register_route( '/automation/get-tasks', 'list_tasks', WP_REST_Server::EDITABLE, ADBC_Automation_Endpoints::class);
		self::register_route( '/automation/create-task', 'create_task', WP_REST_Server::EDITABLE, ADBC_Automation_Endpoints::class);
		self::register_route( '/automation/update-task', 'update_task', WP_REST_Server::EDITABLE, ADBC_Automation_Endpoints::class);
		self::register_route( '/automation/delete-task', 'delete_task', WP_REST_Server::EDITABLE, ADBC_Automation_Endpoints::class);
		self::register_route( '/automation/get-task', 'get_task', WP_REST_Server::EDITABLE, ADBC_Automation_Endpoints::class);

	}

	/**
	 * Register a route.
	 * 
	 * @param string $route Route.
	 * @param string $callback_method Callback method.
	 * @param string $method Method (WP_REST_Server::READABLE or WP_REST_Server::EDITABLE).
	 * @param string $endpoint_class Endpoint class name.
	 * @return void
	 */
	public static function register_route( $route, $callback_method, $method, $endpoint_class ) {

		register_rest_route( ADBC_REST_API_NAMESPACE, $route, [
			'methods' => $method,
			'callback' => [ $endpoint_class, $callback_method ],
			'permission_callback' => [ self::class, 'rest_security_check' ],
		] );

	}

	/**
	 * Check if the user is allowed to perform the requested operation.
	 * 
	 * @return bool|WP_Error True if the user is allowed, WP_Error otherwise. 
	 */
	public static function rest_security_check() {

		// If the nonce is not set, return false.
		if ( ! isset( $_SERVER['HTTP_X_WP_NONCE'] ) )
			return new WP_Error( ADBC_Rest::UNAUTHORIZED, __( 'Security check failed! Invalid nonce.', 'advanced-database-cleaner' ), [ 'status' => ADBC_Rest::UNAUTHORIZED ] );

		// If the nonce is not valid, return false.
		$nonce = sanitize_key( $_SERVER['HTTP_X_WP_NONCE'] );

		if ( ! wp_verify_nonce( $nonce, 'wp_rest' ) )
			return new WP_Error( ADBC_Rest::UNAUTHORIZED, __( 'Security check failed! Invalid nonce.', 'advanced-database-cleaner' ), [ 'status' => ADBC_Rest::UNAUTHORIZED ] );

		// Check if the user is logged in.
		if ( ! is_user_logged_in() )
			return new WP_Error( ADBC_Rest::UNAUTHORIZED, __( 'Unauthorized! You should be logged in.', 'advanced-database-cleaner' ), [ 'status' => ADBC_Rest::UNAUTHORIZED ] );

		// Check if the user has the right permissions.
		if ( ! current_user_can( 'manage_options' ) )
			return new WP_Error( ADBC_Rest::UNAUTHORIZED, __( 'Unauthorized! Insufficient permissions.', 'advanced-database-cleaner' ), [ 'status' => ADBC_Rest::UNAUTHORIZED ] );

		return true;

	}
}
