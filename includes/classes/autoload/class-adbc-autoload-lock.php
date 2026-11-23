<?php

if ( ! defined( 'ABSPATH' ) )
	exit;

/** Same advisory lock identity as WPVitals; process death releases it automatically. */
final class ADBC_Autoload_Lock {
	private $name;
	private $connection;
	private static $held = false;

	public function __construct() {
		global $wpdb;
		$this->name = substr( 'adbc_wpv_mutation_' . hash( 'sha256', DB_NAME . ':' . $wpdb->base_prefix ), 0, 64 );
		if ( self::$held || (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 0)', $this->name ) ) !== '1' ) {
			throw new ADBC_Autoload_Exception( 'autoload_busy', __( 'Another maintenance operation is running. Please wait and try again.', 'advanced-database-cleaner' ), 409 );
		}
		$this->connection = (string) $wpdb->get_var( 'SELECT CONNECTION_ID()' );
		self::$held = true;
		try {
			$this->assert_owned();
			// The merge adapter installs WPVitals pending-recovery/index checks here.
			do_action( 'adbc_autoload_before_mutation' );
		} catch (Throwable $error) {
			$this->release();
			throw $error;
		}
	}

	public function assert_owned() {
		global $wpdb;
		$owned = $wpdb->get_var( $wpdb->prepare( 'SELECT CONNECTION_ID() = %s AND IS_USED_LOCK(%s) = CONNECTION_ID()', $this->connection, $this->name ) );
		if ( (string) $owned !== '1' )
			throw new ADBC_Autoload_Exception( 'autoload_lock_lost', __( 'The maintenance connection was lost. Recovery data has been preserved.', 'advanced-database-cleaner' ) );
	}

	/** Never let wpdb reconnect and replay a write after losing the lock/transaction. */
	public function query( $sql ) {
		global $wpdb;
		$this->assert_owned();
		$sql = $wpdb->remove_placeholder_escape( $sql );
		if ( ! $wpdb->dbh instanceof mysqli || (string) mysqli_thread_id( $wpdb->dbh ) !== $this->connection ) {
			throw new ADBC_Autoload_Exception( 'autoload_connection_unavailable', __( 'The database connection cannot safely run autoload maintenance.', 'advanced-database-cleaner' ) );
		}
		try {
			$answer = mysqli_query( $wpdb->dbh, $sql );
			$affected = $answer === false ? null : mysqli_affected_rows( $wpdb->dbh );
		} catch (Throwable $error) {
			$answer = false;
			$affected = null;
		}
		if ( class_exists( 'ADBC_Change_Log' ) )
			ADBC_Change_Log::record_query( $sql, $affected, $answer === false );
		if ( $answer === false )
			throw new ADBC_Autoload_Exception( 'autoload_database_write_failed', __( 'An autoload database update failed. Recovery data has been preserved.', 'advanced-database-cleaner' ) );
		return $affected;
	}

	public function release() {
		global $wpdb;
		if ( $this->connection === null )
			return;
		try {
			if ( (string) $wpdb->get_var( 'SELECT CONNECTION_ID()' ) === $this->connection )
				$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $this->name ) );
		} finally {
			$this->connection = null;
			self::$held = false;
		}
	}
}
