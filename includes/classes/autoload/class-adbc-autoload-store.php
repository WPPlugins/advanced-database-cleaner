<?php

if ( ! defined( 'ABSPATH' ) )
	exit;

final class ADBC_Autoload_Exception extends RuntimeException {
	public $error_code;
	public $status;
	public $details;
	public function __construct( $code, $message, $status = 503, array $details = [] ) {
		parent::__construct( $message );
		$this->error_code = $code;
		$this->status = $status;
		$this->details = $details;
	}
}

/** File-only storage. Recovery is authoritative and is never rotated or size-limited. */
final class ADBC_Autoload_Store {
	private $blog_id;
	private $root;

	public function __construct( $blog_id ) {
		$this->blog_id = (int) $blog_id;
		$this->root = rtrim( ADBC_UPLOADS_DIR_PATH, '/\\' );
	}

	public function path( $kind ) {
		if ( ! in_array( $kind, [ 'state', 'recovery' ], true ) || $this->blog_id < 1 )
			$this->fail();
		return $this->root . '/autoload/' . $kind . '-' . $this->blog_id . '.json';
	}

	private function fail() {
		throw new ADBC_Autoload_Exception( 'autoload_storage_unavailable', __( 'Autoload recovery files could not be read or saved. Check permissions on the ADBC uploads folder.', 'advanced-database-cleaner' ) );
	}

	private function directory( $create ) {
		$dir = $this->root . '/autoload';
		clearstatcache();
		if ( is_link( $this->root ) || is_link( $dir ) || ( file_exists( $dir ) && ! is_dir( $dir ) ) )
			$this->fail();
		if ( ! is_dir( $dir ) ) {
			if ( ! $create )
				return false;
			if ( ! is_dir( $this->root ) || ! @mkdir( $dir, 0700 ) )
				$this->fail();
		}
		$root = realpath( $this->root );
		$resolved = realpath( $dir );
		if ( ! $root || ! $resolved || dirname( $resolved ) !== $root )
			$this->fail();
		if ( $create ) {
			$protections = [
				'index.php' => "<?php exit;\n",
				'.htaccess' => "<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nOrder deny,allow\nDeny from all\n</IfModule>\n",
				'web.config' => '<?xml version="1.0"?><configuration><system.webServer><security><authorization><remove users="*" roles="" verbs=""/><add accessType="Deny" users="*"/></authorization></security></system.webServer></configuration>',
			];
			foreach ( $protections as $name => $content ) {
				$path = $dir . '/' . $name;
				if ( is_link( $path ) || ( file_exists( $path ) && ( ! is_file( $path ) || @file_get_contents( $path ) !== $content ) ) )
					$this->fail();
				if ( ! file_exists( $path ) && @file_put_contents( $path, $content, LOCK_EX ) !== strlen( $content ) )
					$this->fail();
			}
		}
		return true;
	}

	private function safe( $path ) {
		clearstatcache( true, $path );
		if ( dirname( $path ) !== $this->root . '/autoload' || is_link( $path ) || ( file_exists( $path ) && ! is_file( $path ) ) )
			$this->fail();
	}

	private function same( $handle, $path ) {
		$this->safe( $path );
		$a = fstat( $handle );
		$b = @lstat( $path );
		if ( ! $a || ! $b || ( $b['mode'] & 0170000 ) !== 0100000 || $a['dev'] !== $b['dev'] || $a['ino'] !== $b['ino'] )
			$this->fail();
	}

	private function locked( callable $work, $create ) {
		if ( ! $this->directory( $create ) )
			return $work();
		// Database mutation locking also serializes writers. The file lock protects atomic replacement/read pairs.
		$path = $this->root . '/autoload/storage-lock.php';
		$this->safe( $path );
		if ( ! $create && ! file_exists( $path ) )
			return $work();
		$handle = @fopen( $path, $create ? 'c+b' : 'rb' );
		if ( ! $handle )
			$this->fail();
		try {
			$this->same( $handle, $path );
			if ( ! flock( $handle, $create ? LOCK_EX : LOCK_SH ) )
				$this->fail();
			$this->same( $handle, $path );
			if ( $create && fstat( $handle )['size'] === 0 ) {
				if ( fwrite( $handle, "<?php exit;\n" ) !== 12 || ! fflush( $handle ) )
					$this->fail();
			}
			return $work();
		} finally {
			flock( $handle, LOCK_UN );
			fclose( $handle );
		}
	}

	public function read( $kind ) {
		return $this->locked( function () use ($kind) {
			$path = $this->path( $kind );
			$this->safe( $path );
			if ( ! file_exists( $path ) )
				return $this->empty_data( $kind );
			$handle = @fopen( $path, 'rb' );
			if ( ! $handle )
				$this->fail();
			try {
				$this->same( $handle, $path );
				$json = stream_get_contents( $handle );
			} finally {
				fclose( $handle );
			}
			$data = is_string( $json ) ? json_decode( $json, true ) : null;
			if ( ! is_array( $data ) || ( $data['version'] ?? null ) !== 1 || ( $data['blog_id'] ?? null ) !== $this->blog_id || ! is_array( $data['data'] ?? null ) || ! is_string( $data['signature'] ?? null ) )
				$this->corrupt();
			$payload = wp_json_encode( $data['data'] );
			if ( ! is_string( $payload ) || ! hash_equals( hash_hmac( 'sha256', $kind . '|' . $this->blog_id . '|' . $payload, wp_salt( 'auth' ) ), $data['signature'] ) )
				$this->corrupt();
			$expected = $this->empty_data( $kind );
			if ( array_diff_key( $expected, $data['data'] ) )
				$this->corrupt();
			return $data['data'];
		}, false );
	}

	private function corrupt() {
		throw new ADBC_Autoload_Exception( 'autoload_recovery_unreadable', __( 'Autoload recovery data could not be verified. The existing files have been preserved; restore the original files and WordPress security keys before retrying.', 'advanced-database-cleaner' ) );
	}

	private function empty_data( $kind ) {
		return $kind === 'state' ? [ 'receipts' => [], 'fix_block' => null ] : [ 'checkpoint' => null, 'pending' => null, 'completion' => null ];
	}

	public function write( $kind, array $data ) {
		$this->locked( function () use ($kind, $data) {
			$path = $this->path( $kind );
			$this->safe( $path );
			$payload = wp_json_encode( $data );
			if ( ! is_string( $payload ) )
				$this->fail();
			$json = wp_json_encode( [ 'version' => 1, 'blog_id' => $this->blog_id, 'data' => $data, 'signature' => hash_hmac( 'sha256', $kind . '|' . $this->blog_id . '|' . $payload, wp_salt( 'auth' ) ) ] );
			if ( ! is_string( $json ) )
				$this->fail();
			$temp = $path . '.' . bin2hex( random_bytes( 12 ) ) . '.tmp';
			$handle = @fopen( $temp, 'x+b' );
			if ( ! $handle )
				$this->fail();
			try {
				$this->same( $handle, $temp );
				@chmod( $temp, 0600 );
				$length = strlen( $json );
				$offset = 0;
				while ( $offset < $length ) {
					$written = fwrite( $handle, substr( $json, $offset, 1048576 ) );
					if ( ! $written )
						$this->fail();
					$offset += $written;
				}
				if ( ! fflush( $handle ) || ( function_exists( 'fsync' ) && ! fsync( $handle ) ) )
					$this->fail();
				$this->same( $handle, $temp );
				fclose( $handle );
				$handle = null;
				$this->safe( $path );
				if ( ! @rename( $temp, $path ) )
					$this->fail();
			} finally {
				if ( is_resource( $handle ) )
					fclose( $handle );
				if ( is_file( $temp ) && ! is_link( $temp ) )
					@unlink( $temp );
			}
		}, true );
	}

	public function remove_recovery() {
		$this->locked( function () {
			$path = $this->path( 'recovery' );
			$this->safe( $path );
			if ( file_exists( $path ) && ! @unlink( $path ) )
				$this->fail();
		}, true );
	}
}
