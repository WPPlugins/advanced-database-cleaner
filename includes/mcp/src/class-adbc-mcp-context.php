<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** The authenticated connection for the current request or queued job. */
final class ADBC_MCP_Context {
	private static $grant_id = '';
	private static $channel = 'php';
	private static $access_revision = null;

	public static function set_grant_id( $grant_id, $channel = 'mcp' ) {
		self::$grant_id = is_string( $grant_id ) ? $grant_id : '';
		self::$channel = $channel;
		self::$access_revision = null;
	}

	public static function grant_id() {
		return self::$grant_id;
	}

	public static function clear() {
		self::$grant_id = '';
		self::$channel = 'php';
		self::$access_revision = null;
	}
	public static function channel(): string {
		return self::$channel;
	}
	public static function snapshot(): array {
		return [ 'grant_id' => self::$grant_id, 'channel' => self::$channel, 'access_revision' => self::$access_revision ];
	}
	public static function restore( array $context ): void {
		self::set_grant_id( $context['grant_id'], $context['channel'] );
		self::$access_revision = $context['access_revision'] ?? null;
	}
	public static function access_revision(): ?int {
		return self::$access_revision;
	}
	public static function set_access_revision( ?int $revision ): void {
		self::$access_revision = $revision;
	}
}
