<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use ADBC\Vendor\League\OAuth2\Server\AuthorizationServer;
use ADBC\Vendor\League\OAuth2\Server\ResourceServer;
use ADBC\Vendor\League\OAuth2\Server\CryptKey;
use ADBC\Vendor\League\OAuth2\Server\Grant\AuthCodeGrant;
use ADBC\Vendor\League\OAuth2\Server\Grant\RefreshTokenGrant;
use ADBC\Vendor\League\OAuth2\Server\Exception\OAuthServerException;
use ADBC\Vendor\Nyholm\Psr7\Response;
use ADBC\Vendor\Nyholm\Psr7\ServerRequest;
use ADBC\Vendor\Psr\Http\Message\ResponseInterface;

final class ADBC_MCP_OAuth {
	public const ISSUER_PATH = '/adbc-mcp';
	private static string $exchange_grant = '';
	public static function issuer(): string {
		return home_url( self::ISSUER_PATH );
	}
	public static function resource_metadata_url(): string {
		return home_url( '/.well-known/oauth-protected-resource' . self::ISSUER_PATH );
	}
	public static function resource(): string {
		return rest_url( 'advanced-db-cleaner/v1/mcp' );
	}
	public static function authorization_url(): string {
		return admin_url( 'admin-post.php?action=adbc_mcp_authorize' );
	}
	public static function token_url(): string {
		return rest_url( 'advanced-db-cleaner/v1/mcp/oauth/token' );
	}
	public static function transport_secure(): bool {
		return is_ssl() || 'local' === wp_get_environment_type();
	}
	public static function set_exchange_grant( string $id ): void {
		self::$exchange_grant = $id;
	}
	public static function exchange_grant(): string {
		return self::$exchange_grant;
	}

	private static function key_pair( bool $retried = false ): array {
		$stored = ADBC_MCP_Settings::get( 'keys' );
		$key = hash( 'sha256', wp_salt( 'auth' ) . 'adbc-mcp-key', true );
		if ( false !== $stored ) {
			$valid = is_array( $stored );
			foreach ( [ 'cipher', 'nonce', 'tag', 'public' ] as $field ) {
				if ( ! $valid || ! isset( $stored[ $field ] ) || ! is_string( $stored[ $field ] ) || '' === $stored[ $field ] || strlen( $stored[ $field ] ) > 16384 ) {
					$valid = false;
					break;
				}
			}
			$cipher = $valid ? base64_decode( $stored['cipher'], true ) : false;
			$nonce = $valid ? base64_decode( $stored['nonce'], true ) : false;
			$tag = $valid ? base64_decode( $stored['tag'], true ) : false;
			if ( false !== $cipher && is_string( $nonce ) && 12 === strlen( $nonce ) && is_string( $tag ) && 16 === strlen( $tag ) ) {
				$private = openssl_decrypt( $cipher, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $nonce, $tag );
				$resource = $private ? @openssl_pkey_get_private( $private ) : false;
				$details = $resource ? openssl_pkey_get_details( $resource ) : false;
				if ( is_array( $details ) && OPENSSL_KEYTYPE_RSA === $details['type'] && $details['bits'] >= 2048 && hash_equals( $details['key'], $stored['public'] ) ) {
					return [ $private, $stored['public'] ];
				}
			}
			throw new RuntimeException( 'MCP signing key cannot be decrypted; restore the WordPress salts.' );
		}
		[ $private, $public ] = self::generate_key_pair();
		$nonce = random_bytes( 12 );
		$cipher = openssl_encrypt( $private, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $nonce, $tag );
		if ( false === $cipher ) {
			throw new RuntimeException( 'Could not encrypt the MCP signing key.' );
		}
		if ( ! ADBC_MCP_Settings::add( 'keys', [ 'cipher' => base64_encode( $cipher ), 'nonce' => base64_encode( $nonce ), 'tag' => base64_encode( $tag ), 'public' => $public ] ) ) {
			if ( $retried ) {
				throw new RuntimeException( 'Could not load the MCP signing key.' );
			}
			wp_cache_delete( ADBC_MCP_Settings::CONFIG, 'options' );
			wp_cache_delete( 'notoptions', 'options' );
			return self::key_pair( true );
		}
		return [ $private, $public ];
	}

	/** PHP's compiled PHP_BINDIR may not be the installed directory (notably WAMP). */
	private static function generate_key_pair(): array {
		$base = [ 'private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA ];
		$configurations = [ $base ];
		$fallback = dirname( __DIR__ ) . '/openssl.cnf';
		if ( is_readable( $fallback ) ) {
			$configurations[] = $base + [ 'config' => $fallback ];
		}
		foreach ( $configurations as $config ) {
			// Missing system configuration is recoverable. Do not emit a PHP warning
			// into the REST response before trying the bundled configuration.
			$resource = @openssl_pkey_new( $config );
			if ( ! $resource || ! @openssl_pkey_export( $resource, $private, null, $config ) ) {
				continue;
			}
			$details = openssl_pkey_get_details( $resource );
			if ( is_array( $details ) && ! empty( $details['key'] ) && OPENSSL_KEYTYPE_RSA === $details['type'] && $details['bits'] >= 2048 ) {
				return [ $private, $details['key'] ];
			}
		}
		throw new RuntimeException( 'Could not generate or export the MCP signing key. Check PHP OpenSSL configuration and the bundled mcp/openssl.cnf file.' );
	}
	public static function ensure_keys(): void {
		self::key_pair();
	}

	private static function load_oauth(): void {
		require_once dirname( __DIR__ ) . '/vendor/scoped-autoload.php';
		require_once __DIR__ . '/class-adbc-mcp-oauth-repositories.php';
	}

	private static function server( ?array $authorization_client = null ): AuthorizationServer {
		self::load_oauth();
		[ $private ] = self::key_pair();
		$tokens = new ADBC_MCP_OAuth_Tokens();
		$server = new AuthorizationServer( new ADBC_MCP_OAuth_Clients( $authorization_client ), $tokens, new ADBC_MCP_OAuth_Scopes(), new CryptKey( $private, null, false ), hash( 'sha256', wp_salt( 'secure_auth' ) . 'adbc-mcp-oauth' ) );
		$server->setDefaultScope( 'adbc:use' );
		$server->enableGrantType( new AuthCodeGrant( $tokens, $tokens, new DateInterval( 'PT5M' ) ), new DateInterval( 'PT15M' ) );
		$server->enableGrantType( new RefreshTokenGrant( $tokens ), new DateInterval( 'PT15M' ) );
		return $server;
	}

	public static function psr_request( WP_REST_Request $request ): ServerRequest {
		$headers = [];
		foreach ( $request->get_headers() as $name => $value ) {
			$headers[ str_replace( '_', '-', $name ) ] = $value;
		}
		$psr = new ServerRequest( $request->get_method(), rest_url( ltrim( $request->get_route(), '/' ) ), $headers );
		$psr = $psr->withBody( \ADBC\Vendor\Nyholm\Psr7\Stream::create( (string) $request->get_body() ) );
		return $psr->withParsedBody( self::body_params( $request ) )->withQueryParams( $request->get_query_params() );
	}
	private static function body_params( WP_REST_Request $request ): array {
		$params = $request->get_body_params();
		if ( ! $params && str_starts_with( strtolower( (string) $request->get_header( 'content-type' ) ), 'application/x-www-form-urlencoded' ) ) {
			parse_str( (string) $request->get_body(), $params );
		}
		return is_array( $params ) ? $params : [];
	}

	public static function metadata( $kind ): array {
		if ( 'resource' === $kind ) {
			return [ 'resource' => self::resource(), 'authorization_servers' => [ self::issuer() ], 'bearer_methods_supported' => [ 'header' ], 'scopes_supported' => [ 'adbc:use' ] ];
		}
		return [ 'issuer' => self::issuer(), 'authorization_endpoint' => self::authorization_url(), 'token_endpoint' => self::token_url(), 'response_types_supported' => [ 'code' ], 'grant_types_supported' => [ 'authorization_code', 'refresh_token' ], 'code_challenge_methods_supported' => [ 'S256' ], 'token_endpoint_auth_methods_supported' => [ 'none' ], 'scopes_supported' => [ 'adbc:use' ], 'client_id_metadata_document_supported' => true, 'authorization_response_iss_parameter_supported' => true ];
	}

	public static function authorize(): void {
		if ( ! ADBC_MCP_Store::ready() || ! ADBC_MCP_Settings::mcp_enabled() || ! self::transport_secure() ) {
			wp_die( 'ADBC MCP requires HTTPS and must be enabled.', '', 503 );
		}
		if ( ! ADBC_MCP_Http::valid_host() ) {
			wp_die( 'Invalid Host header.', '', 403 );
		}
		if ( ! is_user_logged_in() ) {
			auth_redirect();
		}
		if ( ! ADBC_MCP_Executor::can_execute() ) {
			wp_die( 'Administrator permission required.', '', 403 );
		}
		$params = 'POST' === $_SERVER['REQUEST_METHOD'] ? wp_unslash( $_POST ) : wp_unslash( $_GET );
		// Preserve opaque state, including UTF-8. Control characters would be
		// altered by HTML form parsing or wp_redirect, breaking client validation.
		if ( is_array( $params ) && isset( $params['state'] ) && ( ! is_string( $params['state'] ) || strlen( $params['state'] ) > 1024 || preg_match( '/[\x00-\x1f\x7f]/', $params['state'] ) || 1 !== preg_match( '//u', $params['state'] ) ) ) {
			wp_die( 'Invalid OAuth state.', '', 400 );
		}
		if ( ! is_array( $params ) || ( $params['resource'] ?? '' ) !== self::resource() || ( $params['code_challenge_method'] ?? '' ) !== 'S256' || ! isset( $params['code_challenge'] ) || ! is_string( $params['code_challenge'] ) || ! preg_match( '/^[A-Za-z0-9_-]{43,128}$/', $params['code_challenge'] ) ) {
			wp_die( 'Invalid OAuth resource or PKCE challenge.', '', 400 );
		}
		foreach ( self::consent_params( $params ) as $value ) {
			if ( ! is_string( $value ) || strlen( $value ) > 4096 ) {
				wp_die( 'Invalid OAuth parameter.', '', 400 );
			}
		}
		if ( 'POST' === $_SERVER['REQUEST_METHOD'] && ! in_array( $params['decision'] ?? '', [ 'approve', 'deny' ], true ) ) {
			wp_die( 'Invalid consent decision.', '', 400 );
		}
		if ( 'POST' === $_SERVER['REQUEST_METHOD'] && ( ! is_string( $params['_adbc_client_fingerprint'] ?? null ) || ! preg_match( '/^[a-f0-9]{64}$/', $params['_adbc_client_fingerprint'] ) || ! is_string( $params['_wpnonce'] ?? null ) || ! wp_verify_nonce( $params['_wpnonce'], self::consent_request_action( $params, $params['_adbc_client_fingerprint'] ) ) ) ) {
			wp_die( 'The consent request changed or expired. Start authentication again.', '', 403 );
		}
		$client = self::authorization_client( $params['client_id'] ?? '' );
		if ( is_wp_error( $client ) ) {
			wp_die( esc_html( $client->get_error_message() ), '', $client->get_error_data()['status'] ?? 400 );
		}
		$uri = self::authorization_url();
		try {
			$server = self::server( $client );
			$request = ( new ServerRequest( 'GET', $uri ) )->withQueryParams( $params );
			$authorization = $server->validateAuthorizationRequest( $request );
		} catch (Throwable $error) {
			wp_die( esc_html( $error->getMessage() ), '', 400 );
		}
		if ( 'POST' !== $_SERVER['REQUEST_METHOD'] ) {
			self::render_consent( $authorization->getClient()->getName(), $params, $client );
			exit;
		}
		if ( ! is_string( $params['_wpnonce'] ?? null ) || ! wp_verify_nonce( $params['_wpnonce'], self::consent_action( $params, $client ) ) ) {
			wp_die( 'The consent request changed or expired. Start authentication again.', '', 403 );
		}
		$decision = $params['decision'] ?? '';
		if ( ! in_array( $decision, [ 'approve', 'deny' ], true ) ) {
			wp_die( 'Invalid consent decision.', '', 400 );
		}
		$authorization->setUser( new ADBC_MCP_OAuth_User( get_current_user_id() ) );
		if ( 'deny' === $decision ) {
			$authorization->setAuthorizationApproved( false );
			try {
				$server->completeAuthorizationRequest( $authorization, new Response() );
			} catch (OAuthServerException $error) {
				// The library constructs the error redirect from the validated client
				// callback and preserves state. No grant or authorization code is issued.
				if ( 'access_denied' === $error->getErrorType() && $error->hasRedirect() ) {
					self::redirect_authorization_response( $error->generateHttpResponse( new Response() ) );
				}
			} catch (Throwable $error) {
				ADBC_Logging::log_exception( __METHOD__, $error );
			}
			wp_die( 'Could not return the authorization decision to your AI client.', '', 500 );
		}
		$name = ADBC_MCP_Connections::clean_name( $params['connection_name'] ?? '' );
		if ( is_wp_error( $name ) ) {
			wp_die( esc_html( $name->get_error_message() ), '', 400 );
		}
		$write = $params['write_enabled'] ?? '0';
		if ( ! is_string( $write ) || ! in_array( $write, [ '0', '1' ], true ) ) {
			wp_die( 'Invalid write access choice.', '', 400 );
		}
		$stored = self::persist_authorized_client( $client );
		if ( is_wp_error( $stored ) ) {
			wp_die( esc_html( $stored->get_error_message() ), '', $stored->get_error_data()['status'] ?? 400 );
		}
		$grant = wp_generate_uuid4();
		$ok = ADBC_MCP_Store::add( 'grants', [ 'id' => $grant, 'client_id' => $authorization->getClient()->getIdentifier(), 'user_id' => get_current_user_id(), 'auth_type' => 'oauth', 'connection_name' => $name, 'write_enabled' => '1' === $write, 'created_at' => current_time( 'mysql', true ) ] );
		if ( ! $ok ) {
			wp_die( 'Could not create connection.', '', 500 );
		}
		self::set_exchange_grant( $grant );
		$authorization->setAuthorizationApproved( true );
		try {
			$response = $server->completeAuthorizationRequest( $authorization, new Response() );
		} catch (Throwable $error) {
			ADBC_MCP_Store::delete( 'grants', $grant );
			wp_die( 'Authorization failed.', '', 500 );
		}
		ADBC_MCP_Context::set_grant_id( $grant );
		self::redirect_authorization_response( $response );
	}

	private static function redirect_authorization_response( ResponseInterface $response ): never {
		$location = $response->getHeaderLine( 'Location' );
		if ( '' === $location ) {
			wp_die( 'Missing OAuth callback.', '', 500 );
		}
		nocache_headers();
		wp_redirect( add_query_arg( 'iss', self::issuer(), $location ), 302, 'ADBC MCP' );
		exit;
	}

	/** Unknown URL clients remain request-local until the administrator approves. */
	public static function authorization_client( $id ) {
		if ( ! ADBC_MCP_Executor::can_execute() || ! is_string( $id ) || '' === $id || strlen( $id ) > 190 ) {
			return new WP_Error( 'adbc_invalid_client', 'Invalid OAuth client.', [ 'status' => 400 ] );
		}
		$old = ADBC_MCP_Store::get( 'clients', $id );
		if ( $old && empty( $old['enabled'] ) ) {
			return new WP_Error( 'adbc_client_disabled', 'This OAuth application is disabled in ADBC.', [ 'status' => 403 ] );
		}
		if ( $old && 'metadata' !== $old['kind'] ) {
			return new WP_Error( 'adbc_invalid_client', 'Use an HTTPS OAuth client metadata identifier.', [ 'status' => 400 ] );
		}
		$metadata = ADBC_MCP_Admin::fetch_metadata( $id );
		if ( is_wp_error( $metadata ) ) {
			return $metadata;
		}
		return array_replace( $old ?? [ 'id' => $id, 'enabled' => 1, 'kind' => 'metadata', 'metadata_url' => $id, 'created_at' => current_time( 'mysql', true ), '_revision' => 0 ], [ 'name' => $metadata['client_name'], 'redirect_uris' => wp_json_encode( $metadata['redirect_uris'] ) ] );
	}
	public static function consent_params( array $params ): array {
		return array_intersect_key( $params, array_flip( [ 'response_type', 'client_id', 'redirect_uri', 'scope', 'state', 'code_challenge', 'code_challenge_method', 'resource' ] ) );
	}
	/** The editable name/access choice is deliberately excluded; OAuth identity is bound. */
	public static function consent_action( array $params, array $client ): string {
		return self::consent_request_action( $params, self::client_fingerprint( $client ) );
	}
	public static function client_fingerprint( array $client ): string {
		$identity = [];
		foreach ( [ 'id', 'name', 'kind', 'redirect_uris' ] as $field ) {
			$identity[ $field ] = $client[ $field ] ?? null;
		}
		return hash( 'sha256', wp_json_encode( $identity ) );
	}
	private static function consent_request_action( array $params, string $fingerprint ): string {
		$canonical = [];
		foreach ( [ 'response_type', 'client_id', 'redirect_uri', 'scope', 'state', 'code_challenge', 'code_challenge_method', 'resource' ] as $key ) {
			$canonical[ $key ] = $params[ $key ] ?? null;
		}
		$canonical['client'] = $fingerprint;
		return 'adbc_mcp_consent_' . hash( 'sha256', wp_json_encode( $canonical ) );
	}
	private static function persist_authorized_client( array $client ) {
		$old = ADBC_MCP_Store::get( 'clients', $client['id'] );
		if ( $old ) {
			if ( empty( $old['enabled'] ) || ( $client['_revision'] > 0 && $old['_revision'] !== $client['_revision'] ) || $old['kind'] !== $client['kind'] ) {
				return new WP_Error( 'adbc_client_changed', 'The OAuth application changed. Start authentication again.', [ 'status' => 409 ] );
			}
			$changes = [ 'name' => $client['name'], 'redirect_uris' => $client['redirect_uris'] ];
			if ( 0 === $client['_revision'] && ( $old['name'] !== $client['name'] || $old['redirect_uris'] !== $client['redirect_uris'] ) ) {
				return new WP_Error( 'adbc_client_changed', 'The OAuth application changed. Start authentication again.', [ 'status' => 409 ] );
			}
			$saved = $changes === array_intersect_key( $old, $changes ) || ADBC_MCP_Store::replace( 'clients', $old, array_replace( $old, $changes ) );
		} else {
			if ( $client['_revision'] > 0 ) {
				return new WP_Error( 'adbc_client_changed', 'The OAuth application was removed. Start authentication again.', [ 'status' => 409 ] );
			}
			$saved = ADBC_MCP_Store::add( 'clients', $client );
		}
		if ( ! $saved ) {
			return new WP_Error( 'adbc_client_unavailable', 'Could not save the OAuth application. Check the ADBC client limit and try again.', [ 'status' => 503 ] );
		}
		if ( 'metadata' === $client['kind'] ) {
			ADBC_MCP_Admin::cache_metadata( $client['id'], [ 'client_name' => $client['name'], 'redirect_uris' => json_decode( $client['redirect_uris'], true ) ] );
		}
		return true;
	}
	private static function render_consent( string $client_name, array $params, array $client ): void {
		nocache_headers();
		header( 'Content-Type: text/html; charset=UTF-8' );
		header( 'Referrer-Policy: no-referrer' );
		header( 'X-Frame-Options: DENY' );
		header( "Content-Security-Policy: frame-ancestors 'none'" );
		$user = wp_get_current_user();
		$account_name = $user->display_name ?: $user->user_login;
		if ( $account_name !== $user->user_login ) {
			$account_name .= ' (' . $user->user_login . ')';
		}
		$role_names = wp_roles()->get_names();
		$account_roles = array_map( static fn( $role ) => translate_user_role( $role_names[ $role ] ?? $role ), $user->roles );
		$account_role = is_multisite() && is_super_admin( $user->ID )
			? __( 'Super Admin', 'advanced-database-cleaner' )
			: ( implode( ', ', $account_roles ) ?: __( 'Custom permissions', 'advanced-database-cleaner' ) );
		$site_name = get_bloginfo( 'name' );
		$asset_url = untrailingslashit( ADBC_PLUGIN_ABSOLUTE_URL ) . '/assets';
		$stylesheet_path = ADBC_PLUGIN_DIR_PATH . 'assets/css/app.css';
		$stylesheet_version = is_file( $stylesheet_path ) ? (string) filemtime( $stylesheet_path ) : ADBC_PLUGIN_VERSION;
		$stylesheet_url = add_query_arg( 'ver', $stylesheet_version, $asset_url . '/css/app.css' );
		$consent_action = self::consent_action( $params, $client );
		include dirname( __DIR__ ) . '/views/oauth-consent.php';
	}

	public static function token( WP_REST_Request $request ) {
		if ( ! ADBC_MCP_Store::ready() || ! ADBC_MCP_Settings::mcp_enabled() || ! self::transport_secure() ) {
			return new WP_Error( 'adbc_mcp_disabled', 'ADBC MCP requires HTTPS and must be enabled.', [ 'status' => 503 ] );
		}
		if ( ! ADBC_MCP_Http::valid_host() ) {
			return new WP_Error( 'adbc_invalid_host', 'Invalid Host header.', [ 'status' => 403 ] );
		}
		$params = self::body_params( $request );
		if ( ( $params['resource'] ?? $request->get_param( 'resource' ) ) !== self::resource() ) {
			return new WP_Error( 'adbc_invalid_resource', 'Exact MCP resource parameter required.', [ 'status' => 400 ] );
		}
		try {
			return ADBC_MCP_Http::raw( self::server()->respondToAccessTokenRequest( self::psr_request( $request ), new Response() ) );
		} catch (OAuthServerException $error) {
			return ADBC_MCP_Http::raw( $error->generateHttpResponse( new Response() ) );
		} catch (Throwable $error) {
			ADBC_Logging::log_exception( __METHOD__, $error );
			return new WP_Error( 'adbc_oauth_unavailable', 'OAuth token service is unavailable.', [ 'status' => 503 ] );
		}
	}

	public static function authenticate( WP_REST_Request $request ) {
		$header = (string) $request->get_header( 'authorization' );
		if ( preg_match( '/^Bearer\s+\S+$/i', $header ) ) {
			try {
				self::load_oauth();
				[ , $public ] = self::key_pair();
				$validated = ( new ResourceServer( new ADBC_MCP_OAuth_Tokens(), new CryptKey( $public, null, false ) ) )->validateAuthenticatedRequest( self::psr_request( $request ) );
				$id = $validated->getAttribute( 'oauth_access_token_id' );
				$row = ( new ADBC_MCP_OAuth_Tokens() )->access_row( $id );
				if ( ! $row || ! hash_equals( self::resource(), $row['resource'] ) || ! hash_equals( self::resource(), $validated->getAttribute( 'oauth_client_id' ) ) || (int) $validated->getAttribute( 'oauth_user_id' ) !== (int) $row['user_id'] || ! in_array( 'adbc:use', $validated->getAttribute( 'oauth_scopes' ), true ) ) {
					return false;
				}
				$grant = ADBC_MCP_Store::grant( $row['grant_id'] );
				if ( ! $grant || $grant['client_id'] !== $row['client_id'] || (int) $grant['user_id'] !== (int) $row['user_id'] ) {
					return false;
				}
				wp_set_current_user( (int) $row['user_id'] );
				ADBC_MCP_Context::set_grant_id( $row['grant_id'] );
				return ADBC_MCP_Executor::can_execute();
			} catch (Throwable $error) {
				return false;
			}
		}
		if ( ! preg_match( '/^Basic\s+/i', $header ) || ! function_exists( 'rest_get_authenticated_app_password' ) ) {
			return false;
		}
		$app = rest_get_authenticated_app_password();
		if ( ! is_string( $app ) || '' === $app || ! ADBC_MCP_Executor::can_execute() ) {
			return false;
		}
		$grant = ADBC_MCP_Store::grant( self::app_grant_id( get_current_user_id(), $app ) );
		if ( ! $grant ) {
			return false;
		}
		ADBC_MCP_Context::set_grant_id( $grant['id'] );
		return true;
	}
	public static function app_grant_id( int $user, string $uuid ): string {
		return hash( 'sha256', 'application-password:' . $user . ':' . $uuid );
	}
}
