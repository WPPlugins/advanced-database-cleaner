<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use ADBC\Vendor\PhpMyAdmin\SqlParser\Lexer;
use ADBC\Vendor\PhpMyAdmin\SqlParser\Parser;
use ADBC\Vendor\PhpMyAdmin\SqlParser\TokenType;
use ADBC\Vendor\PhpMyAdmin\SqlParser\Statements\SelectStatement;
use ADBC\Vendor\PhpMyAdmin\SqlParser\Statements\InsertStatement;
use ADBC\Vendor\PhpMyAdmin\SqlParser\Statements\UpdateStatement;
use ADBC\Vendor\PhpMyAdmin\SqlParser\Statements\DeleteStatement;

final class ADBC_MCP_SQL_Error extends RuntimeException {
	public function __construct( public string $error_code, string $message, public int $status = 400 ) {
		parent::__construct( $message );
	}
}

/** Parse SQL before opening a connection. Unknown syntax is never passed through. */
final class ADBC_MCP_SQL_Policy {
	public const MAX_SQL_BYTES = 32768;
	public const MAX_ROWS = 1000;
	private const FUNCTIONS = [ 'ABS', 'AVG', 'CAST', 'CEIL', 'CEILING', 'CHAR_LENGTH', 'CHARACTER_LENGTH', 'COALESCE', 'CONCAT', 'CONCAT_WS', 'CONVERT', 'COUNT', 'DATE', 'DATE_ADD', 'DATE_FORMAT', 'DATE_SUB', 'DATEDIFF', 'DAY', 'DAYOFMONTH', 'FLOOR', 'GREATEST', 'IF', 'IFNULL', 'INSTR', 'JSON_EXTRACT', 'JSON_LENGTH', 'JSON_UNQUOTE', 'LCASE', 'LEAST', 'LEFT', 'LENGTH', 'LOCATE', 'LOWER', 'LTRIM', 'MAX', 'MIN', 'MOD', 'MONTH', 'NULLIF', 'OCTET_LENGTH', 'REPLACE', 'RIGHT', 'ROUND', 'RTRIM', 'SUBSTR', 'SUBSTRING', 'SUM', 'TRIM', 'UCASE', 'UPPER', 'YEAR' ];
	private const FORBIDDEN = [ 'WITH', 'UNION', 'EXCEPT', 'INTERSECT', 'INTO', 'OUTFILE', 'DUMPFILE', 'PROCEDURE', 'FOR UPDATE', 'FOR SHARE', 'LOCK IN SHARE MODE', 'SQL_CALC_FOUND_ROWS', 'SQL_BUFFER_RESULT', 'SQL_CACHE', 'MAX_STATEMENT_TIME', 'HIGH_PRIORITY', 'LOW_PRIORITY', 'IGNORE', 'RETURNING', 'PARTITION', 'OVER', 'WINDOW', 'CURRENT_USER', 'SESSION_USER', 'SYSTEM_USER', 'CURRENT_DATE', 'CURRENT_TIME', 'CURRENT_TIMESTAMP', 'LOCALTIME', 'LOCALTIMESTAMP' ];

	public static function fail( string $message, string $code = 'adbc_sql_unsupported', int $status = 400 ): never {
		throw new ADBC_MCP_SQL_Error( $code, $message, $status );
	}

	public static function tokens( string $sql ): array {
		require_once dirname( __DIR__ ) . '/vendor/scoped-autoload.php';
		\ADBC\Vendor\PhpMyAdmin\SqlParser\Context::setMode( 0 );
		$lexer = new Lexer( $sql );
		if ( $lexer->errors ) {
			self::fail( 'SQL could not be tokenized. Check its syntax.' );
		}
		$tokens = [];
		foreach ( $lexer->list->tokens as $token ) {
			if ( ! $token->type instanceof TokenType ) {
				self::fail( 'The bundled SQL parser returned an unsupported token.', 'adbc_sql_unavailable', 503 );
			}
			if ( TokenType::Comment === $token->type ) {
				if ( preg_match( '~^/\*(?:[!+]|M!)~i', $token->token ) ) {
					self::fail( 'Executable comments and optimizer hints are not supported.' );
				}
				continue;
			}
			if ( in_array( $token->type, [ TokenType::Whitespace, TokenType::Delimiter ], true ) && '' === trim( $token->token ) ) {
				continue;
			}
			if ( '' !== $token->token ) {
				$tokens[] = $token;
			}
		}
		return $tokens;
	}

	public static function parse( $sql, bool $write ): array {
		if ( ! is_string( $sql ) || '' === trim( $sql ) || strlen( $sql ) > self::MAX_SQL_BYTES || str_contains( $sql, "\0" ) || ! preg_match( '//u', $sql ) ) {
			self::fail( 'Supply UTF-8 SQL text of at most 32 KiB.', 'adbc_sql_invalid' );
		}
		$tokens = self::tokens( $sql );
		$selects = 0;
		foreach ( $tokens as $i => $token ) {
			if ( ';' === $token->token && $i !== count( $tokens ) - 1 ) {
				self::fail( 'Only one SQL statement is allowed.' );
			}
			if ( TokenType::Symbol === $token->type && ( $token->flags & 1 ) ) {
				self::fail( 'SQL variables and parameters are not supported. Supply literal values.' );
			}
			if ( ':=' === $token->token || '?' === $token->token ) {
				self::fail( 'SQL assignments and parameters are not supported.' );
			}
			$keyword = $token->keyword;
			$word = strtoupper( (string) $token->value );
			if ( in_array( $token->type, [ TokenType::None, TokenType::Keyword ], true ) && ( 'NEXT VALUE FOR' === $word || ( 'NEXT' === $word && 'VALUE' === strtoupper( (string) ( $tokens[ $i + 1 ]->value ?? '' ) ) && 'FOR' === strtoupper( (string) ( $tokens[ $i + 2 ]->value ?? '' ) ) ) ) ) {
				self::fail( 'Sequence operations can change data and are not supported.' );
			}
			if ( 'SELECT' === $keyword ) {
				$selects++;
			}
			if ( in_array( $keyword, self::FORBIDDEN, true ) && ! ( $write && 'INTO' === $keyword && 'INSERT' === ( $tokens[0]->keyword ?? null ) ) ) {
				self::fail( 'Unsupported SQL clause: ' . $keyword . '.' );
			}
			// A word followed by '(' is a function, except these grammar constructs.
			if ( '(' === ( $tokens[ $i + 1 ]->token ?? null ) && in_array( $token->type, [ TokenType::None, TokenType::Keyword, TokenType::Symbol ], true ) ) {
				$name = strtoupper( (string) $token->value );
				$grammar = [ 'IN', 'NOT IN', 'EXISTS', 'VALUES', 'WHERE', 'ON', 'AND', 'OR', 'NOT', 'HAVING', 'BY', 'SELECT' ];
				$is_insert_table = $write && 'INSERT' === ( $tokens[0]->keyword ?? null ) && in_array( $tokens[ $i - 1 ]->keyword ?? '', [ 'INTO', 'INSERT' ], true );
				$is_insert_table = $is_insert_table || ( $write && 'INSERT' === ( $tokens[0]->keyword ?? null ) && '.' === ( $tokens[ $i - 1 ]->token ?? null ) && in_array( $tokens[ $i - 3 ]->keyword ?? '', [ 'INTO', 'INSERT' ], true ) );
				$is_grammar = in_array( $name, $grammar, true ) && TokenType::Keyword === $token->type && '.' !== ( $tokens[ $i - 1 ]->token ?? null );
				if ( ! $is_insert_table && ! $is_grammar && ( ! in_array( $name, self::FUNCTIONS, true ) || '.' === ( $tokens[ $i - 1 ]->token ?? null ) || TokenType::Symbol === $token->type ) ) {
					self::fail( 'Function calls must use an approved, unqualified built-in function: ' . $name . '.' );
				}
			}
		}
		if ( $selects > ( $write ? 0 : 1 ) ) {
			self::fail( 'Subqueries are not supported.' );
		}
		$parser = new Parser( $sql );
		if ( $parser->errors || count( $parser->statements ) !== 1 ) {
			self::fail( 'SQL must contain one complete, supported statement.', 'adbc_sql_invalid' );
		}
		$s = $parser->statements[0];
		$operation = match ( true ) { $s instanceof SelectStatement => 'SELECT', $s instanceof InsertStatement => 'INSERT', $s instanceof UpdateStatement => 'UPDATE', $s instanceof DeleteStatement => 'DELETE', default => ''};
		if ( ! $operation || ( $write ? 'SELECT' === $operation : 'SELECT' !== $operation ) ) {
			self::fail( $write ? 'Use INSERT VALUES, UPDATE, or DELETE in execute-sql.' : 'Use a SELECT statement in this read-only tool.' );
		}
		if ( $s->options && array_diff( $s->options->options, [ 'DISTINCT', 'ALL' ] ) ) {
			self::fail( 'Statement modifiers are not supported.' );
		}
		if ( $s instanceof SelectStatement && ( $s->union || $s->into || $s->procedure || $s->endOptions || $s->partition || $s->indexHints ) ) {
			self::fail( 'This SELECT uses an unsupported clause.' );
		}
		$references = $s instanceof InsertStatement ? [ $s->into->dest ] : ( $s instanceof UpdateStatement ? $s->tables : $s->from );
		foreach ( $s->join ?? [] as $join ) {
			$references[] = $join->expr;
		}
		global $wpdb;
		$tables = [];
		foreach ( $references ?? [] as $ref ) {
			if ( ! $ref->table || ! empty( $ref->subquery ) || ( $ref->database && $ref->database !== $wpdb->dbname ) || ( $ref->column ?? null ) ) {
				self::fail( 'Only base tables in the current WordPress database are supported.' );
			}
			$tables[] = $ref->table;
		}
		if ( $write ) {
			if ( count( $references ?? [] ) !== 1 || ! empty( $s->join ) || ! empty( $s->using ) || ! empty( $s->columns ) || ! empty( $s->partition ) || ! empty( $references[0]->alias ) ) {
				self::fail( 'Writes must target one table without joins or aliases.' );
			}
			if ( $s instanceof InsertStatement && ( ! $s->values || $s->set || $s->select || $s->with || $s->onDuplicateSet || ! $s->into->columns ) ) {
				self::fail( 'Use INSERT with an explicit column list and VALUES tuples.' );
			}
			if ( ! ( $s instanceof InsertStatement ) && ! $s->where ) {
				self::fail( 'UPDATE and DELETE require WHERE.' );
			}
			if ( $s->limit ?? null ) {
				if ( $s->limit->offset || ! is_numeric( $s->limit->rowCount ) || $s->limit->rowCount > self::MAX_ROWS ) {
					self::fail( 'Write LIMIT must be between 0 and 1000, without an offset.' );
				}
			}
			if ( $s instanceof InsertStatement && count( $s->values ) > self::MAX_ROWS ) {
				self::fail( 'A write may affect at most 1000 rows.', 'adbc_sql_row_limit' );
			}
		}
		return [ 'statement' => $s, 'operation' => $operation, 'tables' => array_values( array_unique( $tables ) ), 'sql' => rtrim( trim( $s->build() ), ';' ) ];
	}
}
