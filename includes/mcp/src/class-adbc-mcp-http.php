<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use ADBC\Vendor\Mcp\Server\Transport\StreamableHttpTransport;
use ADBC\Vendor\Mcp\Schema\Tool;
use ADBC\Vendor\Mcp\Schema\ToolAnnotations;
use ADBC\Vendor\Psr\Http\Message\ResponseInterface;

final class ADBC_MCP_Http {
	private static $response;
	public static function valid_host(): bool {
		$incoming = $_SERVER['HTTP_HOST'] ?? '';
		if ( '' === $incoming ) {
			return true;
		}
		$site = wp_parse_url( home_url( '/' ) );
		$actual = wp_parse_url( 'http://' . $incoming );
		if ( ! is_array( $site ) || ! is_array( $actual ) || strtolower( $actual['host'] ?? '' ) !== strtolower( $site['host'] ?? '' ) ) {
			return false;
		}
		$port = $site['port'] ?? ( 'https' === ( $site['scheme'] ?? '' ) ? 443 : 80 );
		return (int) ( $actual['port'] ?? $port ) === (int) $port;
	}
	private static function origins(): array {
		$site = home_url( '/' );
		$parts = wp_parse_url( $site );
		$origins = [ ( $parts['scheme'] ?? 'https' ) . '://' . ( $parts['host'] ?? '' ) . ( isset( $parts['port'] ) ? ':' . $parts['port'] : '' ) ];
		$grant = ADBC_MCP_Store::grant( ADBC_MCP_Context::grant_id() );
		if ( $grant && 'oauth' === $grant['auth_type'] ) {
			$client = ADBC_MCP_Store::get( 'clients', $grant['client_id'] );
			$json = $client && ! empty( $client['enabled'] ) ? $client['redirect_uris'] : '';
			foreach ( json_decode( (string) $json, true ) ?: [] as $uri ) {
				$p = wp_parse_url( $uri );
				if ( isset( $p['scheme'], $p['host'] ) ) {
					$origins[] = $p['scheme'] . '://' . $p['host'] . ( isset( $p['port'] ) ? ':' . $p['port'] : '' );
				}
			}
		}
		return array_values( array_unique( $origins ) );
	}
	private static function preflight( WP_REST_Request $request ) {
		$origin = rtrim( (string) $request->get_header( 'origin' ), '/' );
		if ( '' === $origin ) {
			return self::raw( new \ADBC\Vendor\Nyholm\Psr7\Response( 204 ) );
		}
		$site = wp_parse_url( home_url( '/' ) );
		$allowed = [ ( $site['scheme'] ?? 'https' ) . '://' . ( $site['host'] ?? '' ) . ( isset( $site['port'] ) ? ':' . $site['port'] : '' ) ];
		foreach ( ADBC_MCP_Store::records( 'clients', 500 ) as $client ) {
			if ( empty( $client['enabled'] ) ) {
				continue;
			}
			$json = $client['redirect_uris'];
			foreach ( json_decode( $json, true ) ?: [] as $uri ) {
				$p = wp_parse_url( $uri );
				if ( isset( $p['scheme'], $p['host'] ) ) {
					$allowed[] = $p['scheme'] . '://' . $p['host'] . ( isset( $p['port'] ) ? ':' . $p['port'] : '' );
				}
			}
		}
		if ( ! in_array( $origin, $allowed, true ) ) {
			return self::raw( new \ADBC\Vendor\Nyholm\Psr7\Response( 403 ) );
		}
		return self::raw( new \ADBC\Vendor\Nyholm\Psr7\Response( 204, [ 'Access-Control-Allow-Origin' => $origin, 'Access-Control-Allow-Methods' => 'GET, POST, DELETE, OPTIONS', 'Access-Control-Allow-Headers' => 'Authorization, Content-Type, Accept, Mcp-Session-Id, Mcp-Protocol-Version, Last-Event-Id', 'Access-Control-Max-Age' => '600', 'Vary' => 'Origin' ] ) );
	}
	public static function raw( $response ) {
		self::$response = $response;
		return new WP_REST_Response( null, $response->getStatusCode() );
	}
	public static function register(): void {
		register_rest_route( 'advanced-db-cleaner/v1', '/mcp', [ 'methods' => [ 'GET', 'POST', 'DELETE', 'OPTIONS' ], 'callback' => [ self::class, 'mcp' ], 'permission_callback' => '__return_true' ] );
		register_rest_route( 'advanced-db-cleaner/v1', '/mcp/oauth/token', [ 'methods' => 'POST', 'callback' => [ ADBC_MCP_OAuth::class, 'token' ], 'permission_callback' => '__return_true' ] );
		add_filter( 'rest_pre_serve_request', [ self::class, 'serve_psr_response' ], 10, 4 );
		// WordPress answers OPTIONS before the route callback; apply our origin check first.
		add_filter( 'rest_pre_dispatch', [ self::class, 'dispatch_preflight' ], 9, 3 );
	}
	public static function dispatch_preflight( $result, $server, $request ) {
		if ( 'OPTIONS' === $request->get_method() && '/advanced-db-cleaner/v1/mcp' === $request->get_route() ) {
			return self::mcp( $request );
		}
		return $result;
	}
	public static function serve_psr_response( $served, $result, $request, $server ) {
		if ( '/advanced-db-cleaner/v1/mcp' === $request->get_route() ) {
			// Also seal errors WordPress rejects before invoking our callback.
			foreach ( [ 'Access-Control-Allow-Origin', 'Access-Control-Allow-Credentials', 'Access-Control-Allow-Methods', 'Access-Control-Allow-Headers', 'Access-Control-Expose-Headers' ] as $header ) {
				header_remove( $header );
			}
			header( 'Cache-Control: no-store', true );
		}
		$result = self::$response;
		if ( ! $result instanceof \ADBC\Vendor\Psr\Http\Message\ResponseInterface ) {
			return $served;
		}
		self::$response = null;
		status_header( $result->getStatusCode() );
		foreach ( $result->getHeaders() as $name => $values ) {
			if ( in_array( strtolower( $name ), [ 'content-type', 'cache-control', 'www-authenticate', 'mcp-session-id', 'mcp-protocol-version', 'allow', 'vary', 'access-control-allow-origin', 'access-control-allow-methods', 'access-control-allow-headers', 'access-control-expose-headers', 'access-control-allow-credentials', 'access-control-max-age', 'x-accel-buffering' ], true ) ) {
				header( $name . ': ' . implode( ', ', $values ), true );
			}
		}
		header( 'Cache-Control: no-store', true );
		try {
			echo (string) $result->getBody();
		} finally {
			ADBC_MCP_Context::clear();
		}
		return true;
	}
	public static function mcp( WP_REST_Request $request ) {
		ADBC_MCP_Context::clear();
		if ( ! ADBC_MCP_Store::ready() || ! ADBC_MCP_Settings::mcp_enabled() ) {
			return new WP_Error( 'adbc_mcp_disabled', 'ADBC MCP is disabled.', [ 'status' => 503 ] );
		}
		if ( ! ADBC_MCP_OAuth::transport_secure() ) {
			return new WP_Error( 'adbc_https_required', 'HTTPS is required for ADBC MCP.', [ 'status' => 403 ] );
		}
		if ( ! self::valid_host() ) {
			return new WP_Error( 'adbc_invalid_host', 'Invalid Host header.', [ 'status' => 403 ] );
		}
		require_once dirname( __DIR__ ) . '/vendor/scoped-autoload.php';
		if ( 'OPTIONS' === $request->get_method() ) {
			return self::preflight( $request );
		}
		if ( ! ADBC_MCP_OAuth::authenticate( $request ) ) {
			$challenge = 'Bearer resource_metadata="' . esc_url_raw( ADBC_MCP_OAuth::resource_metadata_url() ) . '"';
			return self::raw( ( new \ADBC\Vendor\Nyholm\Psr7\Response( 401 ) )->withHeader( 'WWW-Authenticate', $challenge )->withHeader( 'Cache-Control', 'no-store' ) );
		}
		if ( 'POST' === $request->get_method() && strlen( $request->get_body() ) > 1048576 ) {
			return new WP_Error( 'adbc_request_too_large', 'MCP request exceeds 1 MiB.', [ 'status' => 413 ] );
		}
		try {
			require_once __DIR__ . '/class-adbc-mcp-http-handlers.php';
			$builder = \ADBC\Vendor\Mcp\Server::builder()
				->setServerInfo( 'adbc', ADBC_PLUGIN_VERSION, 'Advanced Database Cleaner tools' )
				// Some clients stop after the first tools/list page; advertise this catalog together.
				->setPaginationLimit( count( ADBC_MCP_Abilities::definitions() ) )
				->setInstructions( 'Destructive ADBC tools return an impact preview only. Show its ask_user message, affected targets, size, and row counts to the user, then stop and wait for a NEW explicit user chat reply. Only after that reply approves this specific preview may you call confirm-destructive-action with its confirmation_request_id and approved_in_chat=true. The initial deletion request is not confirmation. Never confirm silently.' )
				->setSession( new ADBC_MCP_Session_Store() )
				->setRequestState( hash_hmac( 'sha256', 'adbc-mcp-request-state', wp_salt( 'auth' ), true ), 300 );
			foreach ( ADBC_MCP_Abilities::definitions() as $slug => $definition ) {
				$input_schema = $definition['input_schema'];
				unset( $input_schema['properties']['confirmation_token'] );
				$builder->add( new Tool( str_replace( '_', '-', $slug ), $definition['label'], $input_schema, $definition['description'], new ToolAnnotations( null, $definition['read_only'], $definition['destructive'], false, false ) ), new ADBC_MCP_Tool_Handler( $slug ) );
			}
			$origins = self::origins();
			$middleware = [ new ADBC_MCP_Host_Origin_Middleware( $origins ), new \ADBC\Vendor\Mcp\Server\Transport\Http\Middleware\CorsMiddleware( $origins ) ];
			$response = $builder->build()->run( new StreamableHttpTransport( ADBC_MCP_OAuth::psr_request( $request ), middleware: $middleware, maxBodyBytes: 1048576 ) );
			if ( $response->getStatusCode() >= 200 && $response->getStatusCode() < 300 && 'POST' === $request->get_method() ) {
				try {
					$body = $request->get_json_params();
					$info = 'initialize' === ( $body['method'] ?? '' ) ? ( $body['params']['clientInfo'] ?? null ) : ( $body['params']['_meta']['io.modelcontextprotocol/clientInfo'] ?? null );
					ADBC_MCP_Connections::record_activity( ADBC_MCP_Context::grant_id(), $info );
				} catch (Throwable $error) { /* Display bookkeeping must not interrupt a completed tool call. */
				}
			}
			return self::raw( $response );
		} catch (Throwable $error) {
			ADBC_Logging::log_exception( __METHOD__, $error );
			ADBC_MCP_Context::clear();
			return new WP_Error( 'adbc_mcp_error', 'MCP request failed. See the ADBC log.', [ 'status' => 500 ] );
		}
	}
}
