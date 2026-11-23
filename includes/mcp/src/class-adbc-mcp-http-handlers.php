<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use ADBC\Vendor\Mcp\Server\ClientGateway;
use ADBC\Vendor\Mcp\Exception\ToolCallException;
use ADBC\Vendor\Mcp\Server\Handler\ToolHandlerInterface;
use ADBC\Vendor\Mcp\Server\Session\SessionStoreInterface;
use ADBC\Vendor\Symfony\Component\Uid\Uuid;
use ADBC\Vendor\Psr\Http\Message\ResponseInterface;
use ADBC\Vendor\Psr\Http\Message\ServerRequestInterface;
use ADBC\Vendor\Psr\Http\Server\MiddlewareInterface;
use ADBC\Vendor\Psr\Http\Server\RequestHandlerInterface;

final class ADBC_MCP_Host_Origin_Middleware implements MiddlewareInterface {
	public function __construct( private array $origins ) {
	}
	public function process( ServerRequestInterface $request, RequestHandlerInterface $handler ): ResponseInterface {
		$site = wp_parse_url( home_url( '/' ) );
		$expected_host = strtolower( $site['host'] ?? '' );
		$expected_port = $site['port'] ?? ( ( $site['scheme'] ?? '' ) === 'https' ? 443 : 80 );
		$host = wp_parse_url( 'http://' . $request->getHeaderLine( 'Host' ) );
		$actual_host = strtolower( $host['host'] ?? '' );
		$actual_port = $host['port'] ?? $expected_port;
		if ( '' === $expected_host || $actual_host !== $expected_host || (int) $actual_port !== (int) $expected_port ) {
			return new \ADBC\Vendor\Nyholm\Psr7\Response( 403, [ 'Content-Type' => 'text/plain' ], 'Invalid Host header.' );
		}
		$origin = $request->getHeaderLine( 'Origin' );
		if ( '' !== $origin && ! in_array( rtrim( $origin, '/' ), $this->origins, true ) ) {
			return new \ADBC\Vendor\Nyholm\Psr7\Response( 403, [ 'Content-Type' => 'text/plain' ], 'Invalid Origin header.' );
		}
		return $handler->handle( $request );
	}
}

final class ADBC_MCP_Session_Store implements SessionStoreInterface {
	public function exists( Uuid $id ): bool {
		return false !== $this->read( $id );
	}
	public function read( Uuid $id ): string|false {
		$row = ADBC_MCP_Store::get( 'sessions', (string) $id );
		return $row && is_string( $row['grant_id'] ?? null ) && $row['grant_id'] === ADBC_MCP_Context::grant_id() && ADBC_MCP_Store::valid_expiry( $row['expires_at'] ?? null ) && strtotime( $row['expires_at'] . ' UTC' ) >= time() && is_string( $row['data'] ?? null ) ? base64_decode( $row['data'], true ) : false;
	}
	public function write( Uuid $id, string $data ): bool {
		$grant = ADBC_MCP_Context::grant_id();
		if ( '' === $grant || ! ADBC_MCP_Store::grant( $grant ) ) {
			return false;
		}
		$old = ADBC_MCP_Store::get( 'sessions', (string) $id );
		if ( $old && ( $old['grant_id'] ?? null ) !== $grant ) {
			return false;
		}
		$new = [ 'id' => (string) $id, 'grant_id' => $grant, 'data' => base64_encode( $data ), 'expires_at' => gmdate( 'Y-m-d H:i:s', time() + 3600 ) ];
		return $old ? ADBC_MCP_Store::replace( 'sessions', $old, array_replace( $old, $new ) ) : ADBC_MCP_Store::add( 'sessions', $new );
	}
	public function destroy( Uuid $id ): bool {
		$row = ADBC_MCP_Store::get( 'sessions', (string) $id );
		return ! $row || ( ( $row['grant_id'] ?? null ) === ADBC_MCP_Context::grant_id() && ADBC_MCP_Store::delete( 'sessions', (string) $id, $row ) );
	}
	public function gc(): array {
		$ids = [];
		foreach ( ADBC_MCP_Store::records( 'sessions' ) as $row ) {
			if ( Uuid::isValid( $row['id'] ) && ADBC_MCP_Store::valid_expiry( $row['expires_at'] ?? null ) && strtotime( $row['expires_at'] . ' UTC' ) < time() && ADBC_MCP_Store::delete( 'sessions', $row['id'], $row ) ) {
				$ids[] = $row['id'];
			}
		}
		return array_map( static fn( $id ) => Uuid::fromString( $id ), $ids );
	}
}

final class ADBC_MCP_Tool_Handler implements ToolHandlerInterface {
	public function __construct( private string $slug ) {
	}
	public function execute( array $arguments, ClientGateway $gateway ): mixed {
		$ability = wp_get_ability( 'adbc/' . str_replace( '_', '-', $this->slug ) );
		if ( ! $ability ) {
			throw new ToolCallException( 'ADBC ability unavailable.' );
		}
		if ( array_key_exists( 'confirmation_token', $arguments ) ) {
			throw new ToolCallException( 'Confirmation tokens cannot be supplied by the AI client.' );
		}
		$definition = ADBC_MCP_Abilities::definitions()[ $this->slug ];
		$result = $ability->execute( $arguments );
		if ( is_wp_error( $result ) ) {
			throw new ToolCallException( $result->get_error_code() . ': ' . $result->get_error_message() );
		}
		if ( is_array( $result ) && 'adbc_upgrade_required' === ( $result['code'] ?? '' ) ) {
			return $result;
		}
		if ( ! $definition['destructive'] || 'confirm_destructive_action' === $this->slug ) {
			return $result;
		}
		if ( ! is_array( $result ) || empty( $result['preview'] ) || empty( $result['confirmation_request_id'] ) || empty( $result['ask_user'] ) ) {
			throw new ToolCallException( 'ADBC could not prepare the destructive action preview.' );
		}
		$result['next_tool'] = 'confirm-destructive-action';
		$result['message'] = 'No action was performed. Show ask_user and the impact details to the user, then stop. Call confirm-destructive-action only after a NEW chat message explicitly approves this specific preview.';
		return $result;
	}
}

final class ADBC_MCP_Confirm_Handler implements ToolHandlerInterface {
	public function execute( array $arguments, ClientGateway $gateway ): mixed {
		return ( new ADBC_MCP_Tool_Handler( 'confirm_destructive_action' ) )->execute( $arguments, $gateway );
	}
}
