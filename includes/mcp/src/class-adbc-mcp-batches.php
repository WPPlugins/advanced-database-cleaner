<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** One bounded unit of work. The worker supplies the time and item budget. */
final class ADBC_MCP_Batches {
	public static function is_table_operation( string $ability, array $args ): bool {
		return in_array( $ability, [ 'delete_tables', 'empty_rows_tables', 'convert_to_innodb_tables', 'optimize_tables', 'repair_tables', 'refresh_counts_tables' ], true ) || ( 'purge_items' === $ability && in_array( $args['itemsType'] ?? '', [ 'tables_to_optimize', 'tables_to_repair' ], true ) );
	}

	public static function step( string $ability, array $args, array $cursor ): array {
		if ( 'purge_items' === $ability ) {
			return self::purge( $args, $cursor );
		}
		if ( 'delete_posts_by_post_type' === $ability ) {
			return self::posts( $args, $cursor );
		}
		$index = (int) ( $cursor['index'] ?? 0 );
		$selected = $args['selectedItems'] ?? null;
		if ( is_array( $selected ) ) {
			if ( ! isset( $selected[ $index ] ) ) {
				return self::result( $cursor, true );
			}
			$args['selectedItems'] = [ $selected[ $index ] ];
		}
		$response = ADBC_MCP_Executor::run_endpoint( $ability, $args );
		self::check( $response, $ability );
		$cursor['index'] = $index + 1;
		return self::result( $cursor, ! is_array( $selected ) || $index + 1 >= count( $selected ), 1, 'delete_items' === $ability ? (int) ( $response['data'] ?? 0 ) : 0 );
	}

	private static function purge( array $args, array $cursor ): array {
		$type = $args['itemsType'] ?? '';
		$handler = is_string( $type ) ? ADBC_Cleanup_Type_Registry::handler( $type ) : null;
		if ( ! $handler ) {
			throw new RuntimeException( 'Invalid cleanup category.' );
		}
		if ( in_array( $type, [ 'tables_to_optimize', 'tables_to_repair' ], true ) ) {
			// Snapshot names once; never repeat maintenance on a table in this job.
			if ( ! isset( $cursor['tables'] ) ) {
				$cursor['tables'] = array_values( 'tables_to_optimize' === $type ? array_map( static fn( $table ) => $table->table_name, ADBC_Tables::get_tables_to_optimize() ) : ADBC_Tables::get_tables_to_repair()[1] );
			}
			$index = (int) ( $cursor['index'] ?? 0 );
			if ( ! isset( $cursor['tables'][ $index ] ) ) {
				return self::result( $cursor, true );
			}
			$ability = 'tables_to_optimize' === $type ? 'optimize_tables' : 'repair_tables';
			$response = ADBC_MCP_Executor::run_endpoint( $ability, [ 'selectedItems' => [ [ 'items_type' => 'tables', 'name' => $cursor['tables'][ $index ] ] ] ] );
			self::check( $response, $ability );
			$cursor['index'] = $index + 1;
			return self::result( $cursor, $index + 1 >= count( $cursor['tables'] ), 1 );
		}
		if ( ! method_exists( $handler, 'purge_batch' ) ) {
			throw new RuntimeException( 'This cleanup category does not support bounded processing.' );
		}
		// Walk the network by ID without loading all site objects for every row.
		global $wpdb;
		$next = (int) ( $cursor['site_id'] ?? 0 );
		$site = is_multisite() ? (int) $wpdb->get_var( $wpdb->prepare( "SELECT blog_id FROM {$wpdb->blogs} WHERE blog_id >= %d ORDER BY blog_id LIMIT 1", max( 1, $next ) ) ) : ( $next > get_current_blog_id() ? 0 : get_current_blog_id() );
		if ( $wpdb->last_error ) {
			throw new RuntimeException( 'Could not inspect the next cleanup site.' );
		}
		if ( ! $site ) {
			return self::end_pass( $cursor );
		}
		$cursor['site_id'] = $site;
		$response = $handler->purge_batch( $site, 1 );
		$processed = (int) $response['processed'];
		if ( $processed ) {
			$cursor['pass_had_work'] = true;
		} else {
			$cursor['site_id'] = $site + 1;
		}
		return self::result( $cursor, false, $processed, (int) $response['deleted'] );
	}

	private static function posts( array $args, array $cursor ): array {
		$selected = $args['selectedItems'] ?? [];
		$index = (int) ( $cursor['index'] ?? 0 );
		if ( ! isset( $selected[ $index ] ) ) {
			return self::end_pass( $cursor );
		}
		$item = $selected[ $index ];
		if ( ! is_array( $item ) || ! is_string( $item['name'] ?? null ) || ! ADBC_Sites::instance()->get_prefix_from_site_id( (int) ( $item['site_id'] ?? 0 ) ) ) {
			throw new RuntimeException( 'Invalid post type target.' );
		}
		// An exhausted type no longer passes the existing endpoint's existence check.
		global $wpdb;
		try {
			ADBC_Sites::instance()->switch_to_blog_id( $item['site_id'] );
			$exists = $wpdb->get_var( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type = %s LIMIT 1", $item['name'] ) );
			if ( $wpdb->last_error ) {
				throw new RuntimeException( 'Could not inspect the post type.' );
			}
		} finally {
			ADBC_Sites::instance()->restore_blog();
		}
		if ( ! $exists ) {
			$cursor['index'] = $index + 1;
			return self::result( $cursor, false );
		}
		$request = new WP_REST_Request( 'POST' );
		$request->set_header( 'content-type', 'application/json' );
		$request->set_body( wp_json_encode( [ 'actionType' => 'delete_post_types', 'selectedItems' => [ $item ] ] ) );
		$response = ADBC_Post_Types_Endpoints::delete_posts_by_post_type_batch( $request, 1 );
		if ( is_wp_error( $response ) ) {
			throw new RuntimeException( $response->get_error_message() );
		}
		$cursor['pass_had_work'] = true;
		return self::result( $cursor, false, (int) $response['processed'], (int) $response['deleted'] );
	}

	private static function end_pass( array $cursor ): array {
		$done = empty( $cursor['pass_had_work'] );
		$cursor['index'] = 0;
		$cursor['site_id'] = 0;
		$cursor['pass_had_work'] = false;
		return self::result( $cursor, $done );
	}

	private static function result( array $cursor, bool $done, int $processed = 0, int $affected = 0 ): array {
		return [ 'cursor' => $cursor, 'done' => $done, 'processed' => $processed, 'affected_rows' => $affected ];
	}

	private static function check( $response, string $ability ): void {
		if ( is_wp_error( $response ) ) {
			throw new RuntimeException( $response->get_error_message() );
		}
		// These existing endpoints return the number of targets that failed.
		if ( ! in_array( $ability, [ 'delete_items', 'get_tables_names' ], true ) && is_numeric( $response['data'] ?? null ) && (int) $response['data'] > 0 ) {
			throw new RuntimeException( 'ADBC could not process the selected target.' );
		}
	}
}
