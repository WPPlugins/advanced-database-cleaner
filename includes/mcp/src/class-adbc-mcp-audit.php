<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/** MCP administration uses the shared maintenance change log. */
final class ADBC_MCP_Audit {
    public static function recent( int $limit = 100 ): array {
        return ADBC_Change_Log::recent( $limit );
    }
    public static function clear(): bool {
        return ADBC_Change_Log::clear();
    }
}
