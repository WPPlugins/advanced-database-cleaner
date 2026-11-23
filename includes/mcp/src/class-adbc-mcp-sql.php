<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use ADBC\Vendor\PhpMyAdmin\SqlParser\Components\Limit;
use ADBC\Vendor\PhpMyAdmin\SqlParser\Statements\InsertStatement;
use ADBC\Vendor\PhpMyAdmin\SqlParser\Statements\UpdateStatement;

/** Short SQL requests on a private connection: no wpdb retries or shared transactions. */
final class ADBC_MCP_SQL {
	public const RESULT_BYTES = 262144;
	public const CELL_BYTES = 4096;
	private mysqli $db;
	private bool $transaction = false;
	private bool $commit_attempted = false;
	private string $database;

	private function __construct() {
		global $wpdb;
		if ( ! extension_loaded( 'mysqli' ) ) {
			ADBC_MCP_SQL_Policy::fail( 'SQL abilities require mysqli.', 'adbc_sql_unavailable', 503 );
		}
		$this->database = $wpdb->dbname;
		$this->db = mysqli_init();
		$this->db->options( MYSQLI_OPT_CONNECT_TIMEOUT, 3 );
		$this->db->options( MYSQLI_OPT_READ_TIMEOUT, 8 );
		$host = $wpdb->parse_db_host( $wpdb->dbhost );
		if ( ! $host ) {
			ADBC_MCP_SQL_Policy::fail( 'The WordPress database host is unsupported.', 'adbc_sql_unavailable', 503 );
		}
		[ $hostname, $port, $socket, $ipv6 ] = $host;
		if ( $ipv6 && extension_loaded( 'mysqlnd' ) ) {
			$hostname = '[' . $hostname . ']';
		}
		try {
			// Set the connection charset before selecting a database with Unicode names.
			if ( ! @$this->db->real_connect( $hostname, $wpdb->dbuser, $wpdb->dbpassword, null, $port, $socket, defined( 'MYSQL_CLIENT_FLAGS' ) ? MYSQL_CLIENT_FLAGS : 0 ) ) {
				throw new RuntimeException();
			}
			$wpdb->set_charset( $this->db );
			if ( ! $this->db->select_db( $this->database ) ) {
				throw new RuntimeException();
			}
			// Parsing and server interpretation must agree on identifier/string quoting.
			$modes = explode( ',', (string) $this->scalar( 'SELECT @@SESSION.sql_mode' ) );
			$modes = array_diff( $modes, [ 'ANSI_QUOTES', 'NO_BACKSLASH_ESCAPES', 'IGNORE_SPACE' ] );
			$this->command( 'SET SESSION sql_mode=' . $this->literal( implode( ',', $modes ) ) );
			if ( str_contains( $this->db->server_info, 'MariaDB' ) ) {
				$this->command( 'SET SESSION max_statement_time=5' );
			} else {
				$this->command( 'SET SESSION max_execution_time=5000' );
			}
			$this->command( 'SET SESSION innodb_lock_wait_timeout=3' );
			$this->command( 'SET SESSION lock_wait_timeout=3' );
		} catch (Throwable $e) {
			try {
				@$this->db->close();
			} catch (Throwable $close_error) {
			}
			ADBC_MCP_SQL_Policy::fail( 'Could not open a database connection with SQL time and lock limits.', 'adbc_sql_unavailable', 503 );
		}
	}

	private function close(): void {
		try {
			if ( $this->transaction ) {
				$rolled_back = $this->db->rollback();
				ADBC_Change_Log::note( [ 'outcome' => $this->commit_attempted || ! $rolled_back ? 'unknown' : 'rolled_back', 'affected_rows' => $this->commit_attempted || ! $rolled_back ? null : 0 ] );
			}
		} catch (Throwable $e) {
			ADBC_Change_Log::note( [ 'outcome' => 'unknown', 'affected_rows' => null ] );
		}
		try {
			$this->db->close();
		} catch (Throwable $e) {
		}
	}
	private static function identifier( string $name ): string {
		return '`' . str_replace( '`', '``', $name ) . '`';
	}
	private function literal( string $value ): string {
		return "'" . $this->db->real_escape_string( $value ) . "'";
	}
	private function command( string $sql ): mixed {
		try {
			$answer = $this->db->query( $sql );
		} catch (mysqli_sql_exception $e) {
			ADBC_Change_Log::record_query( $sql, null, true );
			$this->db_error( $e->getCode() );
		}
		if ( false === $answer ) {
			ADBC_Change_Log::record_query( $sql, null, true );
			$this->db_error( $this->db->errno );
		}
		ADBC_Change_Log::record_query( $sql, (int) $this->db->affected_rows );
		return $answer;
	}
	private function db_error( int $errno ): never {
		$lost = in_array( $errno, [ 2006, 2013, 2055 ], true );
		if ( $lost && ( $this->transaction || $this->commit_attempted ) ) {
			ADBC_MCP_SQL_Policy::fail( 'The database connection was lost. The execution outcome is unknown. Verify the data with a read query before requesting another preview; do not retry automatically.', 'adbc_sql_outcome_unknown', 503 );
		}
		ADBC_MCP_SQL_Policy::fail( 'The database rejected or interrupted this SQL request (error ' . $errno . '). No automatic retry was performed.', 'adbc_sql_database_error', 400 );
	}
	private function rows( string $sql ): array {
		$result = $this->command( $sql );
		if ( ! $result instanceof mysqli_result ) {
			ADBC_MCP_SQL_Policy::fail( 'The database did not return rows.', 'adbc_sql_database_error' );
		}
		try {
			return $result->fetch_all( MYSQLI_ASSOC );
		} finally {
			$result->free();
		}
	}
	private function scalar( string $sql ): mixed {
		$rows = $this->rows( $sql );
		return $rows ? reset( $rows[0] ) : null;
	}

	private static function dispatch( callable $operation ) {
		$service = null;
		try {
			$service = new self();
			return $operation( $service );
		} catch (ADBC_MCP_SQL_Error $e) {
			return new WP_Error( $e->error_code, $e->getMessage(), [ 'status' => $e->status, 'execution_status' => 'adbc_sql_outcome_unknown' === $e->error_code ? 'unknown' : 'not_committed' ] );
		} catch (Throwable $e) {
			return new WP_Error( 'adbc_sql_failed', 'SQL processing failed. No automatic retry was performed.', [ 'status' => 500 ] );
		} finally {
			if ( $service ) {
				$service->close();
			}
		}
	}

	private function table( string $name, bool $write = false ): array {
		$info = $this->rows( 'SELECT TABLE_TYPE, ENGINE, TABLE_ROWS, DATA_LENGTH, INDEX_LENGTH, AVG_ROW_LENGTH FROM information_schema.TABLES WHERE TABLE_SCHEMA=' . $this->literal( $this->database ) . ' AND TABLE_NAME=' . $this->literal( $name ) );
		if ( ! $info || 'BASE TABLE' !== $info[0]['TABLE_TYPE'] ) {
			ADBC_MCP_SQL_Policy::fail( 'The target must be an existing base table in this WordPress database.' );
		}
		$this->validate_table_expressions( $name, $write );
		if ( ! $write ) {
			return $info[0];
		}
		if ( 'InnoDB' !== $info[0]['ENGINE'] ) {
			ADBC_MCP_SQL_Policy::fail( 'SQL writes require an InnoDB table so failures can be rolled back.' );
		}
		$this->verify_write_metadata( $name );
		$columns = $this->rows( 'SHOW FULL COLUMNS FROM ' . self::identifier( $name ) );
		$indexes = $this->rows( 'SHOW INDEX FROM ' . self::identifier( $name ) );
		$primary = [];
		foreach ( $indexes as $index ) {
			if ( 'PRIMARY' === $index['Key_name'] ) {
				$primary[ (int) $index['Seq_in_index'] ] = $index['Column_name'];
			}
		}
		ksort( $primary );
		if ( ! $primary || count( $columns ) > 256 ) {
			ADBC_MCP_SQL_Policy::fail( 'SQL writes require a primary key and at most 256 columns.' );
		}
		$triggers = $this->scalar( 'SELECT COUNT(*) FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=' . $this->literal( $this->database ) . ' AND EVENT_OBJECT_TABLE=' . $this->literal( $name ) );
		if ( $triggers ) {
			ADBC_MCP_SQL_Policy::fail( 'Writes to tables with triggers cannot be previewed accurately.' );
		}
		$create = $this->rows( 'SHOW CREATE TABLE ' . self::identifier( $name ) );
		$ddl = self::schema_definition( (string) array_values( $create[0] )[1] );
		return $info[0] + [ 'columns' => $columns, 'primary' => array_values( $primary ), 'schema_hash' => hash( 'sha256', $ddl ) ];
	}
	/** Empty privilege-filtered metadata is not proof that a table has no side effects. */
	private function verify_write_metadata( string $table ): void {
		$account = explode( '@', (string) $this->scalar( 'SELECT CURRENT_USER()' ) );
		$host = array_pop( $account );
		$grantee = $this->literal( "'" . implode( '@', $account ) . "'@'" . $host . "'" );
		$trigger = $this->scalar( "SELECT COUNT(*) FROM (SELECT GRANTEE FROM information_schema.USER_PRIVILEGES WHERE PRIVILEGE_TYPE='TRIGGER' UNION ALL SELECT GRANTEE FROM information_schema.SCHEMA_PRIVILEGES WHERE PRIVILEGE_TYPE='TRIGGER' AND " . $this->literal( $this->database ) . " LIKE TABLE_SCHEMA UNION ALL SELECT GRANTEE FROM information_schema.TABLE_PRIVILEGES WHERE PRIVILEGE_TYPE='TRIGGER' AND TABLE_SCHEMA=" . $this->literal( $this->database ) . ' AND TABLE_NAME=' . $this->literal( $table ) . ") adbc_permissions WHERE GRANTEE=$grantee" );
		if ( ! $trigger ) {
			ADBC_MCP_SQL_Policy::fail( 'SQL writes require database TRIGGER metadata privileges for this table; hidden triggers cannot be ruled out.', 'adbc_sql_metadata_unavailable', 403 );
		}
		if ( ! $this->scalar( "SELECT COUNT(*) FROM information_schema.USER_PRIVILEGES WHERE PRIVILEGE_TYPE='PROCESS' AND GRANTEE=$grantee" ) ) {
			ADBC_MCP_SQL_Policy::fail( 'SQL writes require database PROCESS metadata privileges to rule out hidden cross-schema cascades.', 'adbc_sql_metadata_unavailable', 403 );
		}
		$maria = str_contains( $this->db->server_info, 'MariaDB' );
		$old_mysql = ! $maria && version_compare( $this->db->server_info, '8.0', '<' );
		// Older MySQL exposes filename-encoded catalog names but cannot convert
		// identifiers using that internal charset. Do not guess their encoding.
		if ( $old_mysql && ( ! preg_match( '/^[A-Za-z0-9_]+$/D', $this->database ) || ! preg_match( '/^[A-Za-z0-9_]+$/D', $table ) ) ) {
			ADBC_MCP_SQL_Policy::fail( 'SQL writes on MySQL before 8.0 require database and table names containing only letters, numbers, and underscores so cascading foreign keys can be checked safely. Reads remain available.', 'adbc_sql_metadata_unavailable', 403 );
		}
		$legacy = $maria || $old_mysql;
		$catalog = $legacy ? 'INNODB_SYS_FOREIGN' : 'INNODB_FOREIGN';
		$parent = $maria ? 'CONCAT(CAST(CONVERT(' . $this->literal( $this->database ) . " USING filename) AS BINARY),'/',CAST(CONVERT(" . $this->literal( $table ) . ' USING filename) AS BINARY))' : $this->literal( $this->database . '/' . $table );
		try {
			$cascades = $this->scalar( 'SELECT COUNT(*) FROM information_schema.' . $catalog . ' WHERE LOWER(CONVERT(REF_NAME USING utf8))=LOWER(CONVERT(' . $parent . ' USING utf8)) AND (TYPE & 15)<>0' );
		} catch (ADBC_MCP_SQL_Error $e) {
			ADBC_MCP_SQL_Policy::fail( 'SQL writes require access to the complete InnoDB foreign-key catalog (usually the database PROCESS privilege). Hidden cascades cannot be ruled out.', 'adbc_sql_metadata_unavailable', 403 );
		}
		if ( $cascades ) {
			ADBC_MCP_SQL_Policy::fail( 'Writes to tables with cascading foreign-key actions cannot be previewed accurately.' );
		}
	}
	/** Ignore only the table's allocation counter, never text inside a default/comment. */
	private static function schema_definition( string $ddl ): string {
		$tokens = ( new \ADBC\Vendor\PhpMyAdmin\SqlParser\Lexer( $ddl ) )->list->tokens;
		$depth = 0;
		$answer = '';
		foreach ( $tokens as $i => $token ) {
			if ( '(' === $token->token ) {
				$depth++;
			} elseif ( ')' === $token->token ) {
				$depth--;
			}
			if ( 0 === $depth && 'AUTO_INCREMENT' === $token->keyword ) {
				// SHOW CREATE uses AUTO_INCREMENT=<number>, without spaces in this option.
				if ( '=' === ( $tokens[ $i + 1 ]->token ?? null ) && \ADBC\Vendor\PhpMyAdmin\SqlParser\TokenType::Number === ( $tokens[ $i + 2 ]->type ?? null ) ) {
					$skip = $i + 2;
					continue;
				}
			}
			if ( isset( $skip ) && $i <= $skip ) {
				continue;
			}
			$answer .= $token->token;
		}
		return $answer;
	}
	/** Generated/default/check expressions must not hide a UDF or sequence side effect. */
	private function validate_table_expressions( string $table, bool $write ): void {
		$maria = str_contains( $this->db->server_info, 'MariaDB' );
		$filter = 'TABLE_SCHEMA=' . $this->literal( $this->database ) . ' AND TABLE_NAME=' . $this->literal( $table );
		$expressions = $this->expression_rows( "SELECT LEFT(GENERATION_EXPRESSION,32769) AS expression,OCTET_LENGTH(GENERATION_EXPRESSION) AS bytes FROM information_schema.COLUMNS WHERE $filter AND GENERATION_EXPRESSION<>''" );
		if ( $write ) {
			$defaults = $this->expression_rows( "SELECT LEFT(COLUMN_DEFAULT,32769) AS expression,OCTET_LENGTH(COLUMN_DEFAULT) AS bytes FROM information_schema.COLUMNS WHERE $filter AND COLUMN_DEFAULT IS NOT NULL AND " . ( $maria ? '1' : "EXTRA LIKE '%DEFAULT_GENERATED%'" ) );
			$expressions = array_merge( $expressions, $defaults );
			if ( $maria || version_compare( $this->db->server_info, '8.0', '>=' ) ) {
				$checks = $this->expression_rows( 'SELECT LEFT(c.CHECK_CLAUSE,32769) AS expression,OCTET_LENGTH(c.CHECK_CLAUSE) AS bytes FROM information_schema.CHECK_CONSTRAINTS c JOIN information_schema.TABLE_CONSTRAINTS t ON t.CONSTRAINT_SCHEMA=c.CONSTRAINT_SCHEMA AND t.CONSTRAINT_NAME=c.CONSTRAINT_NAME WHERE t.CONSTRAINT_SCHEMA=' . $this->literal( $this->database ) . ' AND t.TABLE_NAME=' . $this->literal( $table ) . " AND t.CONSTRAINT_TYPE='CHECK'" );
				$expressions = array_merge( $expressions, $checks );
			}
		}
		foreach ( $expressions as $expression ) {
			if ( (int) $expression['bytes'] > 32700 ) {
				ADBC_MCP_SQL_Policy::fail( 'This table has an oversized generated/default/check expression.' );
			}
			if ( preg_match( '/^current_timestamp(?:\(\d*\))?$/i', (string) $expression['expression'] ) ) {
				continue;
			}
			try {
				ADBC_MCP_SQL_Policy::parse( 'SELECT ' . $expression['expression'], false );
			} catch (ADBC_MCP_SQL_Error $e) {
				ADBC_MCP_SQL_Policy::fail( 'This table has an unsupported generated/default/check expression. ' . $e->getMessage() );
			}
		}
	}
	private function expression_rows( string $sql ): array {
		$size = $this->rows( 'SELECT COUNT(*) AS n,COALESCE(SUM(bytes),0) AS bytes FROM (' . $sql . ') adbc_expressions' )[0];
		if ( (int) $size['n'] > 1024 || (int) $size['bytes'] > self::RESULT_BYTES ) {
			ADBC_MCP_SQL_Policy::fail( 'This table has oversized generated/default/check expressions.' );
		}
		return $this->rows( $sql );
	}

	public static function context( WP_REST_Request $request ) {
		return self::dispatch( static function ( self $service ) {
			global $wpdb;
			return [ 'success' => true, 'database' => $service->database, 'prefix' => $wpdb->prefix, 'base_prefix' => $wpdb->base_prefix, 'server_version' => $service->db->server_info, 'charset' => $service->db->character_set_name(), 'sql_mode' => $service->scalar( 'SELECT @@SESSION.sql_mode' ), 'operations' => [ 'SELECT', 'EXPLAIN SELECT', 'INSERT VALUES', 'UPDATE WHERE', 'DELETE WHERE' ], 'limits' => [ 'sql_bytes' => ADBC_MCP_SQL_Policy::MAX_SQL_BYTES, 'default_rows' => 100, 'max_rows' => 1000, 'result_bytes' => self::RESULT_BYTES, 'cell_bytes' => self::CELL_BYTES, 'read_timeout_seconds' => 5, 'lock_timeout_seconds' => 3, 'network_read_timeout_seconds' => 8 ], 'write_requirements' => 'One InnoDB base table with a primary key; no triggers or cascading foreign-key actions. Writes first return a preview and require explicit chat confirmation. SQL bypasses WordPress hooks and ADBC cleanup/retention settings. MySQL has no hard DML execution deadline. Verify unknown outcomes before retrying.' ];
		} );
	}
	public static function query( WP_REST_Request $request ) {
		return self::read( $request, false );
	}
	public static function explain( WP_REST_Request $request ) {
		return self::read( $request, true );
	}
	private static function read( WP_REST_Request $request, bool $explain ) {
		$started = microtime( true );
		try {
			$plan = ADBC_MCP_SQL_Policy::parse( $request->get_param( 'sql' ), false );
		} catch (ADBC_MCP_SQL_Error $e) {
			return new WP_Error( $e->error_code, $e->getMessage(), [ 'status' => $e->status ] );
		}
		$limit = $request->get_param( 'row_limit' ) ?? 100;
		if ( ! is_int( $limit ) || $limit < 1 || $limit > 1000 ) {
			return new WP_Error( 'adbc_sql_invalid', 'row_limit must be an integer from 1 to 1000.', [ 'status' => 400 ] );
		}
		$result = self::dispatch( static function ( self $service ) use ( $plan, $limit, $explain ) {
			// Hold metadata locks through validation and retrieval; never run user reads in a writable transaction.
			$service->command( 'START TRANSACTION READ ONLY' );
			foreach ( $plan['tables'] as $table ) {
				$service->command( 'SELECT 1 FROM ' . self::identifier( $table ) . ' LIMIT 0' )->free();
				$service->table( $table );
			}
			if ( $explain ) {
				return $service->bounded_explain( $plan['sql'] );
			}
			return $service->bounded_select( $plan['statement'], $limit );
		} );
		if ( ! is_wp_error( $result ) ) {
			$result['elapsed_ms'] = (int) round( ( microtime( true ) - $started ) * 1000 );
		}
		return $result;
	}
	/** Ordinary EXPLAIN returns server metadata, never the user's table values. */
	private function bounded_explain( string $sql ): array {
		try {
			$cursor = $this->db->query( 'EXPLAIN ' . $sql, MYSQLI_USE_RESULT );
		} catch (mysqli_sql_exception $e) {
			$this->db_error( $e->getCode() );
		}
		if ( ! $cursor ) {
			$this->db_error( $this->db->errno );
		}
		$rows = [];
		$columns = array_column( $cursor->fetch_fields(), 'name' );
		$bytes = strlen( wp_json_encode( $columns ) );
		$truncated = false;
		$cells = 0;
		try {
			while ( $row = $cursor->fetch_assoc() ) {
				foreach ( $row as &$value ) {
					if ( is_string( $value ) && strlen( $value ) > self::CELL_BYTES ) {
						$value = mb_strcut( $value, 0, self::CELL_BYTES, 'UTF-8' );
						$cells++;
					}
				}
				unset( $value );
				$size = strlen( wp_json_encode( $row ) ) + 1;
				if ( $bytes + $size > self::RESULT_BYTES - 2048 || count( $rows ) >= 1000 ) {
					$truncated = true;
					break;
				}
				$bytes += $size;
				$rows[] = $row;
			}
			if ( $this->db->errno ) {
				$this->db_error( $this->db->errno );
			}
		} catch (mysqli_sql_exception $e) {
			$this->db_error( $e->getCode() );
		} finally {
			$cursor->free();
		}
		if ( $this->db->errno ) {
			$this->db_error( $this->db->errno );
		}
		return [ 'success' => true, 'columns' => $columns, 'rows' => $rows, 'returned_rows' => count( $rows ), 'truncated' => $truncated || $cells > 0, 'truncated_cells' => $cells ];
	}

	/** The server clips cells before transport; PHP never receives an entire LONGTEXT/BLOB. */
	private function bounded_select( $statement, int $limit ): array {
		$statement = clone $statement;
		$original_limit = $statement->limit ? (int) $statement->limit->rowCount : null;
		$offset = $statement->limit ? (int) $statement->limit->offset : 0;
		$wanted = null === $original_limit ? $limit : min( $limit, $original_limit );
		$statement->limit = new Limit( min( $wanted + 1, $original_limit ?? $wanted + 1 ), $offset );
		$sql = $statement->build();
		try {
			$probe = $this->db->prepare( $sql );
		} catch (mysqli_sql_exception $e) {
			$this->db_error( $e->getCode() );
		}
		if ( ! $probe ) {
			$this->db_error( $this->db->errno );
		}
		$meta = $probe->result_metadata();
		$fields = $meta ? $meta->fetch_fields() : [];
		if ( $meta ) {
			$meta->free();
		}
		$probe->close();
		$names = array_column( $fields, 'name' );
		if ( ! $names || count( $names ) > 64 || count( array_unique( array_map( 'strtolower', $names ) ) ) !== count( $names ) || max( array_map( 'strlen', $names ) ) > 128 ) {
			ADBC_MCP_SQL_Policy::fail( 'Select at most 64 columns with unique names of at most 128 bytes. Use aliases for duplicate columns.' );
		}
		$projection = array_map( static fn( $name ) => 'LEFT(CAST(adbc_result.' . self::identifier( $name ) . ' AS BINARY),4097) AS ' . self::identifier( $name ), $names );
		$order = [];
		foreach ( $statement->order ?? [] as $part ) {
			$expr = $part->expr;
			$name = null;
			if ( ctype_digit( (string) $expr->expr ) ) {
				$name = $names[ (int) $expr->expr - 1 ] ?? null;
			}
			foreach ( $fields as $i => $field ) {
				if ( ! $name && ( ( ! $expr->table && ( $expr->column ?? $expr->expr ) === $field->name ) || ( $expr->column === $field->orgname && ( ! $expr->table || $expr->table === $field->table || $expr->table === $field->orgtable ) ) || ( isset( $statement->expr[ $i ] ) && (string) $expr->expr === (string) $statement->expr[ $i ]->expr ) ) ) {
					$name = $field->name;
				}
			}
			if ( ! $name ) {
				if ( in_array( 'DISTINCT', $statement->options->options ?? [], true ) ) {
					ADBC_MCP_SQL_Policy::fail( 'For DISTINCT queries, ORDER BY must refer to a selected column or alias.' );
				}
				$name = 'adbc_order_' . count( $order );
				while ( in_array( $name, $names, true ) ) {
					$name .= '_';
				}
				$statement->expr[] = new \ADBC\Vendor\PhpMyAdmin\SqlParser\Components\Expression( $expr->expr, $name );
			}
			$order[] = 'adbc_result.' . self::identifier( $name ) . ' ' . $part->type->value;
		}
		$bounded = 'SELECT ' . implode( ',', $projection ) . ' FROM (' . $statement->build() . ') AS adbc_result' . ( $order ? ' ORDER BY ' . implode( ',', $order ) : '' );
		try {
			$cursor = $this->db->query( $bounded, MYSQLI_USE_RESULT );
		} catch (mysqli_sql_exception $e) {
			$this->db_error( $e->getCode() );
		}
		if ( ! $cursor ) {
			$this->db_error( $this->db->errno );
		}
		$rows = [];
		$bytes = strlen( wp_json_encode( $names ) );
		$truncated = false;
		$truncated_cells = 0;
		try {
			while ( $row = $cursor->fetch_assoc() ) {
				if ( count( $rows ) >= $wanted ) {
					$truncated = true;
					break;
				}
				foreach ( $row as $column => &$value ) {
					if ( null === $value ) {
						continue;
					}
					$cut = strlen( $value ) > self::CELL_BYTES;
					// The extra byte can end part-way through a UTF-8 character.
					if ( $cut && 63 !== $fields[ array_search( $column, $names, true ) ]->charsetnr ) {
						$value = mb_strcut( $value, 0, self::CELL_BYTES, 'UTF-8' );
					}
					if ( ! preg_match( '//u', $value ) ) {
						$value = [ 'encoding' => 'base64', 'value' => base64_encode( substr( $value, 0, 3072 ) ), 'truncated' => strlen( $value ) > 3072 ];
						$cut = $value['truncated'];
					} elseif ( $cut ) {
						$value = mb_strcut( $value, 0, self::CELL_BYTES, 'UTF-8' );
					}
					if ( $cut ) {
						$truncated_cells++;
					}
				}
				unset( $value );
				$size = strlen( wp_json_encode( $row ) ) + 1;
				if ( $bytes + $size > self::RESULT_BYTES - 2048 ) {
					$truncated = true;
					break;
				}
				$bytes += $size;
				$rows[] = $row;
			}
			if ( $this->db->errno ) {
				$this->db_error( $this->db->errno );
			}
		} catch (mysqli_sql_exception $e) {
			$this->db_error( $e->getCode() );
		} finally {
			$cursor->free();
		}
		if ( $this->db->errno ) {
			$this->db_error( $this->db->errno );
		}
		return [ 'success' => true, 'columns' => $names, 'rows' => $rows, 'returned_rows' => count( $rows ), 'truncated' => $truncated || $truncated_cells > 0, 'truncated_cells' => $truncated_cells, 'row_limit' => $wanted, 'offset' => $offset ];
	}

	private function write_plan( array $input ): array {
		$plan = ADBC_MCP_SQL_Policy::parse( $input['sql'] ?? null, true );
		$table = $plan['tables'][0];
		if ( preg_match( '/(?:^|_)adbc_mcp_/i', $table ) ) {
			ADBC_MCP_SQL_Policy::fail( 'ADBC MCP tables and reserved names are protected.', 'adbc_mcp_storage_protected', 409 );
		}
		$plan['table'] = $table;
		$plan['meta'] = $this->table( $table, true );
		$s = $plan['statement'];
		$columns = array_column( $plan['meta']['columns'], 'Field' );
		$seen = [];
		foreach ( $s->set ?? [] as $set ) {
			$name = trim( $set->column, '`' );
			if ( isset( $seen[ strtolower( $name ) ] ) || ! in_array( $name, $columns, true ) || in_array( $name, $plan['meta']['primary'], true ) ) {
				ADBC_MCP_SQL_Policy::fail( 'UPDATE columns must be distinct, exist, and cannot change the primary key.' );
			}
			$seen[ strtolower( $name ) ] = true;
			if ( in_array( strtolower( $name ), [ 'option_name', 'meta_key', 'hook', 'slug' ], true ) ) {
				$tokens = ADBC_MCP_SQL_Policy::tokens( $set->value );
				if ( count( $tokens ) !== 1 || \ADBC\Vendor\PhpMyAdmin\SqlParser\TokenType::String !== $tokens[0]->type ) {
					ADBC_MCP_SQL_Policy::fail( 'Updates to record names, hooks, and slugs require string literals.' );
				}
			}
			if ( str_contains( strtolower( $table ), 'actionscheduler_' ) && in_array( strtolower( $name ), [ 'group_id', 'action_id', 'claim_id' ], true ) && ! preg_match( '/^\d+$/', trim( $set->value ) ) ) {
				ADBC_MCP_SQL_Policy::fail( 'Scheduler record references require integer literals.' );
			}
		}
		if ( $s instanceof InsertStatement ) {
			if ( count( array_unique( $s->into->columns ) ) !== count( $s->into->columns ) || array_diff( $s->into->columns, $columns ) ) {
				ADBC_MCP_SQL_Policy::fail( 'INSERT must use distinct existing columns.' );
			}
			foreach ( $s->values as $tuple ) {
				if ( count( $tuple->raw ) !== count( $s->into->columns ) ) {
					ADBC_MCP_SQL_Policy::fail( 'Every VALUES tuple must match the column list.' );
				}
			}
		}
		return $plan;
	}
	private function protected_expression( string $table, array $columns, array $overrides = [] ): string {
		$checks = [];
		// SQL identifiers are case-insensitive even when PHP array keys are not.
		// Retain the actual spelling when building SQL, but classify it canonically.
		$names = [];
		foreach ( $columns as $column ) {
			$names[ strtolower( $column ) ] = $column;
		}
		$expression = static fn( $column ) => $overrides[ $names[ $column ] ] ?? self::identifier( $table ) . '.' . self::identifier( $names[ $column ] );
		foreach ( [ 'option_name', 'meta_key' ] as $column ) {
			if ( ! isset( $names[ $column ] ) ) {
				continue;
			}
			$expr = $expression( $column );
			foreach ( [ 'adbc_mcp_', '_transient_adbc_mcp_', '_transient_timeout_adbc_mcp_', '_site_transient_adbc_mcp_', '_site_transient_timeout_adbc_mcp_' ] as $prefix ) {
				$checks[] = '(' . $expr . ') LIKE ' . $this->literal( str_replace( '_', '\\_', $prefix ) . '%' );
			}
			if ( 'option_name' === $column ) {
				$checks[] = '(' . $expr . ") = 'cron'";
			}
		}
		if ( isset( $names['hook'] ) ) {
			$checks[] = '(' . $expression( 'hook' ) . ") LIKE 'adbc\\_mcp\\_%'";
		}
		if ( str_ends_with( strtolower( $table ), 'actionscheduler_groups' ) && isset( $names['slug'] ) ) {
			$checks[] = '(' . $expression( 'slug' ) . ")='adbc-mcp'";
		}
		if ( preg_match( '/^(.*)actionscheduler_(actions|logs|claims)$/i', $table, $matches ) ) {
			$kind = strtolower( $matches[2] );
			$groups = $this->scheduler_table( $matches[1], 'groups' );
			if ( 'actions' === $kind && isset( $names['group_id'] ) ) {
				$checks[] = 'EXISTS(SELECT 1 FROM ' . $groups . " adbc_g WHERE adbc_g.group_id=(" . $expression( 'group_id' ) . ") AND adbc_g.slug='adbc-mcp')";
			}
			$reference = 'logs' === $kind ? 'action_id' : 'claim_id';
			if ( isset( $names[ $reference ] ) ) {
				$actions = $this->scheduler_table( $matches[1], 'actions' );
				$checks[] = 'EXISTS(SELECT 1 FROM ' . $actions . ' adbc_a LEFT JOIN ' . $groups . ' adbc_g ON adbc_g.group_id=adbc_a.group_id WHERE adbc_a.' . $reference . '=(' . $expression( $reference ) . ") AND (adbc_a.hook LIKE 'adbc\\_mcp\\_%' OR adbc_g.slug='adbc-mcp'))";
			}
		}
		return $checks ? '(' . implode( ' OR ', $checks ) . ')' : '0';
	}
	/** Resolve the real sibling spelling; reject ambiguous case-sensitive catalogs. */
	private function scheduler_table( string $prefix, string $kind ): string {
		$rows = $this->rows( 'SELECT TABLE_NAME,TABLE_TYPE FROM information_schema.TABLES WHERE TABLE_SCHEMA=' . $this->literal( $this->database ) . ' AND LOWER(TABLE_NAME)=LOWER(' . $this->literal( $prefix . 'actionscheduler_' . $kind ) . ')' );
		if ( 1 !== count( $rows ) || 'BASE TABLE' !== $rows[0]['TABLE_TYPE'] ) {
			ADBC_MCP_SQL_Policy::fail( 'The scheduler base-table references cannot be resolved unambiguously. No SQL write is allowed.', 'adbc_sql_metadata_unavailable', 403 );
		}
		$this->command( 'SELECT 1 FROM ' . self::identifier( $rows[0]['TABLE_NAME'] ) . ' LIMIT 0' )->free();
		$this->validate_table_expressions( $rows[0]['TABLE_NAME'], false );
		return self::identifier( $rows[0]['TABLE_NAME'] );
	}
	private function snapshot_rows( array $plan, ?array $keys = null, bool $lock = false ): array {
		$meta = $plan['meta'];
		$columns = array_column( $meta['columns'], 'Field' );
		$s = $plan['statement'];
		$parts = [];
		foreach ( $meta['primary'] as $i => $column ) {
			$parts[] = 'HEX(CAST(' . self::identifier( $column ) . ' AS BINARY)) AS k' . $i;
		}
		$hashes = array_map( static fn( $column ) => "SHA2(CONCAT(IF(" . self::identifier( $column ) . " IS NULL,'N','V'),IFNULL(CAST(" . self::identifier( $column ) . " AS BINARY),'')),256)", $columns );
		$parts[] = 'SHA2(CONCAT(' . implode( ',', $hashes ) . '),256) AS fingerprint';
		$protected = $this->protected_expression( $plan['table'], $columns );
		$overrides = [];
		foreach ( $s->set ?? [] as $set ) {
			$overrides[ trim( $set->column, '`' ) ] = $set->value;
		}
		if ( $overrides ) {
			$protected .= ' OR ' . $this->protected_expression( $plan['table'], $columns, $overrides );
		}
		$parts[] = '(' . $protected . ') AS protected';
		$where = $keys !== null ? $this->key_condition( $meta, $keys ) : implode( ' ', array_map( static fn( $c ) => $c->build(), $s->where ) );
		$order = $keys === null && $s->order ? implode( ',', array_map( static fn( $c ) => $c->build(), $s->order ) ) : implode( ',', array_map( [ self::class, 'identifier' ], $meta['primary'] ) );
		$limit = $keys === null && $s->limit ? min( 1001, (int) $s->limit->rowCount ) : 1001;
		$rows = $this->rows( 'SELECT ' . implode( ',', $parts ) . ' FROM ' . self::identifier( $plan['table'] ) . ' WHERE (' . $where . ') ORDER BY ' . $order . ' LIMIT ' . $limit . ( $lock ? ' FOR UPDATE' : '' ) );
		if ( count( $rows ) > 1000 ) {
			ADBC_MCP_SQL_Policy::fail( 'This SQL targets more than 1000 rows. Narrow WHERE or use an explicit LIMIT; no rows were changed.', 'adbc_sql_row_limit' );
		}
		foreach ( $rows as $row ) {
			if ( $row['protected'] ) {
				ADBC_MCP_SQL_Policy::fail( 'ADBC MCP records, reserved names, and the WordPress cron record cannot be modified through SQL.', 'adbc_mcp_storage_protected', 409 );
			}
		}
		usort( $rows, static fn( $a, $b ) => strcmp( wp_json_encode( $a ), wp_json_encode( $b ) ) );
		if ( strlen( wp_json_encode( $rows ) ) > self::RESULT_BYTES ) {
			ADBC_MCP_SQL_Policy::fail( 'This target snapshot is too large. Narrow the write selection.', 'adbc_sql_row_limit' );
		}
		return $rows;
	}
	private function key_condition( array $meta, array $keys ): string {
		if ( ! $keys ) {
			return '0';
		}
		$conditions = [];
		foreach ( $keys as $row ) {
			$parts = [];
			foreach ( $meta['primary'] as $i => $column ) {
				$hex = $row[ 'k' . $i ] ?? null;
				if ( ! is_string( $hex ) || strlen( $hex ) % 2 || ( '' !== $hex && ! ctype_xdigit( $hex ) ) ) {
					ADBC_MCP_SQL_Policy::fail( 'The approved snapshot is invalid.', 'adbc_sql_snapshot_changed', 409 );
				}
				$value = 'UNHEX(' . $this->literal( $hex ) . ')';
				$type = $meta['columns'][ array_search( $column, array_column( $meta['columns'], 'Field' ), true ) ]['Type'];
				if ( preg_match( '/^(?:tinyint|smallint|mediumint|int|bigint|decimal|numeric)\b/i', $type ) ) {
					preg_match( '/^(?:decimal|numeric)\(\d+,(\d+)\)/i', $type, $scale );
					$value = 'CAST(' . $value . ' AS DECIMAL(65,' . ( $scale[1] ?? 0 ) . '))';
				}
				$parts[] = self::identifier( $column ) . '=' . $value;
			}
			$conditions[] = '(' . implode( ' AND ', $parts ) . ')';
		}
		return '(' . implode( ' OR ', $conditions ) . ')';
	}
	private function check_insert( array $plan ): void {
		$s = $plan['statement'];
		$columns = array_column( $plan['meta']['columns'], 'Field' );
		$names = [];
		foreach ( $columns as $column ) {
			$names[ strtolower( $column ) ] = $column;
		}
		foreach ( [ 'option_name', 'meta_key', 'hook', 'slug' ] as $logical ) {
			if ( ! isset( $names[ $logical ] ) ) {
				continue;
			}
			$column = $names[ $logical ];
			$index = array_search( $column, $s->into->columns, true );
			if ( false === $index ) {
				ADBC_MCP_SQL_Policy::fail( 'INSERT must supply the record name explicitly for this table.' );
			}
			foreach ( $s->values as $tuple ) {
				$tokens = ADBC_MCP_SQL_Policy::tokens( $tuple->raw[ $index ] );
				if ( count( $tokens ) !== 1 || \ADBC\Vendor\PhpMyAdmin\SqlParser\TokenType::String !== $tokens[0]->type ) {
					ADBC_MCP_SQL_Policy::fail( 'Inserted option names and meta keys must be string literals.' );
				}
				if ( $this->scalar( 'SELECT ' . $this->protected_expression( $plan['table'], [ $column ], [ $column => $tuple->raw[ $index ] ] ) ) ) {
					ADBC_MCP_SQL_Policy::fail( 'ADBC MCP reserved names cannot be inserted through SQL.', 'adbc_mcp_storage_protected', 409 );
				}
			}
		}
		if ( str_contains( strtolower( $plan['table'] ), 'actionscheduler_' ) ) {
			foreach ( $s->values as $tuple ) {
				$values = [];
				foreach ( [ 'group_id', 'action_id', 'claim_id' ] as $logical ) {
					if ( ! isset( $names[ $logical ] ) ) {
						continue;
					}
					$column = $names[ $logical ];
					$index = array_search( $column, $s->into->columns, true );
					if ( false === $index ) {
						continue;
					}
					if ( ! preg_match( '/^\d+$/', trim( $tuple->raw[ $index ] ) ) ) {
						ADBC_MCP_SQL_Policy::fail( 'Scheduler record references require integer literals.' );
					}
					$values[ $column ] = $tuple->raw[ $index ];
				}
				if ( $values && $this->scalar( 'SELECT ' . $this->protected_expression( $plan['table'], array_keys( $values ), $values ) ) ) {
					ADBC_MCP_SQL_Policy::fail( 'ADBC MCP scheduler records are protected.', 'adbc_mcp_storage_protected', 409 );
				}
			}
		}
	}

	public static function preview( array $input ) {
		$started = microtime( true );
		$result = self::dispatch( static function ( self $service ) use ( $input ) {
			// Enforce preview-only access even for internal protection queries.
			$service->command( 'START TRANSACTION READ ONLY' );
			$plan = $service->write_plan( $input );
			$insert = $plan['statement'] instanceof InsertStatement;
			if ( $insert ) {
				$service->check_insert( $plan );
			}
			$rows = $insert ? [] : $service->snapshot_rows( $plan );
			$count = $insert ? count( $plan['statement']->values ) : count( $rows );
			$snapshot = [ 'sql' => $plan['sql'], 'sql_hash' => hash( 'sha256', $plan['sql'] ), 'schema_hash' => $plan['meta']['schema_hash'], 'table' => $plan['table'], 'operation' => $plan['operation'], 'rows' => $rows, 'targeted_rows' => $count ];
			$snapshot['digest'] = hash( 'sha256', wp_json_encode( $snapshot ) );
			$size = (int) $plan['meta']['DATA_LENGTH'] + (int) $plan['meta']['INDEX_LENGTH'];
			$estimated = (int) $plan['meta']['AVG_ROW_LENGTH'] * $count;
			$message = 'SQL can permanently change or delete data and bypasses WordPress hooks, ADBC cleanup/retention settings, and core-item protection. Operation: ' . $plan['operation'] . '. Table: ' . $plan['table'] . ' (' . size_format( $size ) . ', approximately ' . (int) $plan['meta']['TABLE_ROWS'] . ' existing rows). Exact targeted rows: ' . $count . '. Estimated affected data: ' . size_format( $estimated ) . ' (an estimate, not reclaimed disk space). SQL: ' . $plan['sql'] . '. Do you explicitly approve this specific SQL action? Please reply in chat.';
			return [ 'sql' => $plan['sql'], 'operation' => $plan['operation'], 'table' => $plan['table'], 'affected_rows' => $count, 'targeted_rows' => $count, 'table_size_bytes' => $size, 'table_size' => size_format( $size ), 'estimated_table_rows' => (int) $plan['meta']['TABLE_ROWS'], 'estimated_affected_data_bytes' => $estimated, 'size_is_estimated' => true, 'confirmation_message' => $message, '_sql_snapshot' => $snapshot ];
		} );
		if ( ! is_wp_error( $result ) ) {
			$result['elapsed_ms'] = (int) round( ( microtime( true ) - $started ) * 1000 );
		}
		return $result;
	}
	public static function execute_confirmed( array $input, array $targets ) {
		return ADBC_MCP_Access::with_access( 'execute_sql', static fn() => self::execute_confirmed_internal( $input, $targets ) );
	}
	private static function assert_write_access(): void {
		$access = ADBC_MCP_Access::check( 'execute_sql' );
		if ( is_wp_error( $access ) ) {
			ADBC_MCP_SQL_Policy::fail( $access->get_error_message(), $access->get_error_code(), $access->get_error_data()['status'] ?? 403 );
		}
	}
	private static function execute_confirmed_internal( array $input, array $targets ) {
		return ADBC_Change_Log::run( 'execute_sql', [ 'tables' => [ $targets['_sql_snapshot']['table'] ?? '' ] ], static function () use ($input, $targets) {
			$started = microtime( true );
			$result = self::dispatch( static function ( self $service ) use ( $input, $targets, $started ) {
				$snapshot = $targets['_sql_snapshot'] ?? null;
				if ( ! is_array( $snapshot ) ) {
					ADBC_MCP_SQL_Policy::fail( 'An approved SQL snapshot is required.', 'adbc_sql_preview_required', 409 );
				}
				$plan = $service->write_plan( $input );
				self::assert_write_access();
				if ( ! hash_equals( $snapshot['sql_hash'], hash( 'sha256', $plan['sql'] ) ) || ! hash_equals( $snapshot['schema_hash'], $plan['meta']['schema_hash'] ) ) {
					ADBC_MCP_SQL_Policy::fail( 'The SQL or table structure changed. Request a new preview.', 'adbc_sql_snapshot_changed', 409 );
				}
				$service->command( 'START TRANSACTION' );
				$service->transaction = true;
				$insert = $plan['statement'] instanceof InsertStatement;
				if ( $insert ) {
					$service->command( 'SELECT 1 FROM ' . self::identifier( $plan['table'] ) . ' LIMIT 0 FOR UPDATE' )->free();
					$service->check_insert( $plan );
					$sql = $plan['sql'];
				} else {
					$current = $service->snapshot_rows( $plan, $snapshot['rows'], true );
					if ( $current !== $snapshot['rows'] ) {
						ADBC_MCP_SQL_Policy::fail( 'Previewed rows changed or disappeared. Request a new preview.', 'adbc_sql_snapshot_changed', 409 );
					}
					$predicate = $service->key_condition( $plan['meta'], $snapshot['rows'] );
					if ( $plan['statement'] instanceof UpdateStatement ) {
						$sql = 'UPDATE ' . self::identifier( $plan['table'] ) . ' SET ' . implode( ',', array_map( static fn( $set ) => $set->build(), $plan['statement']->set ) ) . ' WHERE ' . $predicate;
					} else {
						$sql = 'DELETE FROM ' . self::identifier( $plan['table'] ) . ' WHERE ' . $predicate;
					}
					if ( $plan['statement']->order ) {
						$sql .= ' ORDER BY ' . implode( ',', array_map( static fn( $part ) => $part->build(), $plan['statement']->order ) );
					}
				}
				// Locking the table's rows also holds an MDL lock until the transaction ends.
				$locked_meta = $service->table( $plan['table'], true );
				if ( ! hash_equals( $snapshot['schema_hash'], $locked_meta['schema_hash'] ) ) {
					ADBC_MCP_SQL_Policy::fail( 'The table structure changed. Request a new preview.', 'adbc_sql_snapshot_changed', 409 );
				}
				self::assert_write_access();
				$service->command( $sql );
				$affected = $service->db->affected_rows;
				$insert_id = $service->db->insert_id;
				if ( $affected > 1000 || $service->db->warning_count ) {
					ADBC_MCP_SQL_Policy::fail( 'The write exceeded its limit or produced database warnings. It was rolled back.', 'adbc_sql_write_rejected', 409 );
				}
				self::assert_write_access();
				$service->commit_attempted = true;
				$service->command( 'COMMIT' );
				$service->transaction = false;
				$answer = [ 'success' => true, 'execution_status' => 'committed', 'operation' => $plan['operation'], 'table' => $plan['table'], 'targeted_rows' => $snapshot['targeted_rows'], 'affected_rows' => $affected, 'insert_id' => $insert ? $insert_id : null, 'elapsed_ms' => (int) round( ( microtime( true ) - $started ) * 1000 ) ];
				try {
					if ( ! wp_cache_flush() ) {
						$answer['warning'] = 'The SQL committed, but the WordPress object cache could not be flushed.';
					}
				} catch (Throwable $e) {
					$answer['warning'] = 'The SQL committed, but the WordPress object cache could not be flushed.';
				}
				return $answer;
			} );
			$snapshot = $targets['_sql_snapshot'] ?? [];
			ADBC_Change_Log::note( [ 'targeted_rows' => $snapshot['targeted_rows'] ?? null ] );
			if ( ! is_wp_error( $result ) ) {
				ADBC_Change_Log::note( [ 'outcome' => 'committed', 'affected_rows' => $result['affected_rows'], 'processed_items' => $result['affected_rows'], 'insert_id' => $result['insert_id'] ] );
			} elseif ( 'adbc_sql_outcome_unknown' === $result->get_error_code() ) {
				ADBC_Change_Log::note( [ 'outcome' => 'unknown', 'affected_rows' => null ] );
			}
			return $result;
		}, [ 'method' => 'sql' ] );
	}
}
