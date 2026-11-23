<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ADBC_MCP_Bootstrap {
	private static $features;
	/** One bounded configuration read, without initializing constants, storage or uploads. */
	public static function features() {
		if ( null === self::$features ) {
			self::$features = [ 'mcp' => false, 'abilities' => false ];
			if ( self::support_status()['available'] ) {
				require_once __DIR__ . '/class-adbc-mcp-settings.php';
				$config = ADBC_MCP_Settings::config();
				$abilities = true === ( $config['abilities_enabled'] ?? false );
				self::$features = [ 'mcp' => ! empty( $config['enabled'] ) && $abilities, 'abilities' => $abilities ];
			}
		}
		return self::$features;
	}
	public static function is_service_request() {
		$uri = isset( $_SERVER['REQUEST_URI'] ) && is_string( $_SERVER['REQUEST_URI'] ) ? $_SERVER['REQUEST_URI'] : '';
		$prefix = function_exists( 'rest_get_url_prefix' ) ? rest_get_url_prefix() : 'wp-json';
		return is_admin() || wp_doing_cron() || ( defined( 'WP_CLI' ) && WP_CLI ) || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || isset( $_GET['rest_route'] ) || false !== strpos( $uri, '/' . $prefix . '/' );
	}
	public static function should_load() {
		$features = self::features();
		return self::is_service_request() || $features['mcp'] || $features['abilities'];
	}
	/** Keep this availability check compatible with the ordinary plugin's PHP minimum. */
	public static function support_status() {
		global $wp_version;
		$base = dirname( __DIR__ );
		$abilities = function_exists( 'wp_register_ability' ) && function_exists( 'wp_register_ability_category' );
		$openssl = extension_loaded( 'openssl' );
		$requirements = version_compare( (string) $wp_version, '6.9', '>=' ) && version_compare( PHP_VERSION, '8.2', '>=' ) && $abilities && $openssl;
		$dependencies = is_readable( $base . '/vendor/scoped-autoload.php' ) && is_readable( $base . '/vendor/woocommerce/action-scheduler/action-scheduler.php' );
		return [
			'available' => $requirements && $dependencies,
			'requirements_met' => $requirements,
			'wordpress_version' => (string) $wp_version,
			'php_version' => PHP_VERSION,
			'openssl_available' => $openssl,
			'abilities_available' => $abilities,
			'dependencies_available' => $dependencies,
		];
	}
	public static function init() {
		if ( ! self::support_status()['available'] || ! self::should_load() ) {
			return;
		}
		foreach ( [ 'settings', 'audit', 'store', 'connections', 'context', 'access', 'confirmation', 'abilities', 'executor', 'sql-policy', 'sql', 'batches', 'jobs' ] as $part ) {
			require_once __DIR__ . '/class-adbc-mcp-' . $part . '.php';
		}
		$features = self::features();
		if ( $features['abilities'] ) {
			add_action( 'wp_abilities_api_categories_init', [ ADBC_MCP_Abilities::class, 'register_category' ] );
			add_action( 'wp_abilities_api_init', [ ADBC_MCP_Abilities::class, 'register' ] );
		}
		add_action( 'init', [ ADBC_MCP_Store::class, 'install' ], 5 );
		ADBC_MCP_Jobs::register();
		ADBC_MCP_Access::register();
		if ( $features['abilities'] && defined( 'WP_CLI' ) && WP_CLI ) {
			require_once __DIR__ . '/class-adbc-mcp-cli.php';
			WP_CLI::add_command( 'adbc confirm', [ ADBC_MCP_CLI::class, 'confirm' ] );
		}
		// Route registration, settings and discovery do not need the vendor stack.
		foreach ( [ 'oauth', 'http', 'admin' ] as $part ) {
			require_once __DIR__ . '/class-adbc-mcp-' . $part . '.php';
		}
		add_action( 'rest_api_init', [ ADBC_MCP_Http::class, 'register' ] );
		add_action( 'rest_api_init', [ ADBC_MCP_Admin::class, 'register' ] );
		add_action( 'admin_post_adbc_mcp_authorize', [ ADBC_MCP_OAuth::class, 'authorize' ] );
		add_action( 'admin_post_nopriv_adbc_mcp_authorize', [ ADBC_MCP_OAuth::class, 'authorize' ] );
		if ( $features['mcp'] && false !== strpos( (string) ( $_SERVER['REQUEST_URI'] ?? '' ), '/.well-known/oauth-' ) ) {
			add_action( 'parse_request', [ self::class, 'well_known' ], 1 );
		}
	}
	public static function well_known() {
		if ( ! ADBC_MCP_Settings::mcp_enabled() ) {
			return;
		}
		$path = wp_parse_url( $_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH );
		if ( ! is_string( $path ) ) {
			return;
		}
		$is_resource = $path === wp_parse_url( ADBC_MCP_OAuth::resource_metadata_url(), PHP_URL_PATH );
		$resource = wp_parse_url( ADBC_MCP_OAuth::resource() );
		// Only pretty REST URLs have a path unique to this resource.
		if ( ! empty( $resource['path'] ) && empty( $resource['query'] ) ) {
			$is_resource = $is_resource || $path === '/.well-known/oauth-protected-resource' . $resource['path'];
		}
		// RFC 8414 puts the well-known prefix before the full issuer path.
		$server_prefix = '/.well-known/oauth-authorization-server';
		$is_server = $path === $server_prefix . wp_parse_url( ADBC_MCP_OAuth::issuer(), PHP_URL_PATH )
			|| $path === wp_parse_url( home_url( $server_prefix . ADBC_MCP_OAuth::ISSUER_PATH ), PHP_URL_PATH );
		if ( ! $is_resource && ! $is_server ) {
			return;
		}
		if ( ! ADBC_MCP_Http::valid_host() ) {
			status_header( 403 );
			exit;
		}
		$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
		if ( ! in_array( $method, [ 'GET', 'OPTIONS' ], true ) ) {
			header( 'Allow: GET, OPTIONS' );
			status_header( 405 );
			exit;
		}
		// Discovery is public and contains no credentials or database values.
		// Browser-based MCP clients must be able to read it before authentication.
		header_remove( 'Access-Control-Allow-Credentials' );
		header( 'Access-Control-Allow-Origin: *' );
		header( 'Access-Control-Allow-Methods: GET, OPTIONS' );
		header( 'Access-Control-Allow-Headers: Accept' );
		if ( 'OPTIONS' === $method ) {
			status_header( 204 );
			exit;
		}
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Cache-Control: no-store' );
		echo wp_json_encode( ADBC_MCP_OAuth::metadata( $is_resource ? 'resource' : 'server' ) );
		exit;
	}
}
