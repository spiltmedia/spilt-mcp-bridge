<?php
/**
 * Debug log reader REST API endpoint.
 *
 * Read and clear the WordPress debug.log file remotely.
 *
 * GET    /spilt-mcp/v1/debug-log               — read last N lines of debug.log
 * GET    /spilt-mcp/v1/debug-log/status         — check if WP_DEBUG is on, log file size
 * DELETE /spilt-mcp/v1/debug-log                — clear/truncate the debug log
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Spilt_MCP_Debug_Log_API {

    public function register_routes() {
        register_rest_route( 'spilt-mcp/v1', '/debug-log', array(
            array(
                'methods'             => 'GET',
                'callback'            => array( $this, 'read_log' ),
                'permission_callback' => 'spilt_mcp_admin_check',
                'args'                => array(
                    'lines' => array( 'default' => 100, 'sanitize_callback' => 'absint' ),
                    'filter' => array( 'default' => '', 'sanitize_callback' => 'sanitize_text_field' ),
                ),
            ),
            array(
                'methods'             => 'DELETE',
                'callback'            => array( $this, 'clear_log' ),
                'permission_callback' => 'spilt_mcp_admin_check',
            ),
        ) );

        register_rest_route( 'spilt-mcp/v1', '/debug-log/status', array(
            'methods'             => 'GET',
            'callback'            => array( $this, 'get_status' ),
            'permission_callback' => 'spilt_mcp_admin_check',
        ) );
    }

    /**
     * GET: Read the last N lines of debug.log.
     */
    public function read_log( $request ) {
        $log_path = $this->get_log_path();

        if ( ! file_exists( $log_path ) ) {
            return rest_ensure_response( array(
                'exists' => false,
                'lines'  => array(),
                'note'   => 'debug.log does not exist. Enable WP_DEBUG and WP_DEBUG_LOG in wp-config.php.',
            ) );
        }

        $max_lines = min( (int) $request->get_param( 'lines' ), 500 );
        $filter    = $request->get_param( 'filter' );

        // Read from end of file efficiently
        $lines = $this->tail( $log_path, $max_lines * 2 ); // Read extra to account for filtering

        // Filter if requested
        if ( ! empty( $filter ) ) {
            $lines = array_filter( $lines, function( $line ) use ( $filter ) {
                return stripos( $line, $filter ) !== false;
            } );
        }

        // Trim to requested count
        $lines = array_slice( $lines, -$max_lines );

        // Parse entries to extract timestamps and levels
        $parsed = array();
        foreach ( $lines as $line ) {
            $entry = array( 'raw' => $line );

            // Try to extract timestamp: [DD-Mon-YYYY HH:MM:SS UTC]
            if ( preg_match( '/^\[([^\]]+)\]\s*(.*)/', $line, $m ) ) {
                $entry['timestamp'] = $m[1];
                $entry['message']   = $m[2];

                // Detect level
                if ( stripos( $m[2], 'Fatal error' ) !== false ) {
                    $entry['level'] = 'fatal';
                } elseif ( stripos( $m[2], 'Warning' ) !== false ) {
                    $entry['level'] = 'warning';
                } elseif ( stripos( $m[2], 'Notice' ) !== false || stripos( $m[2], 'Deprecated' ) !== false ) {
                    $entry['level'] = 'notice';
                } else {
                    $entry['level'] = 'info';
                }
            }

            $parsed[] = $entry;
        }

        return rest_ensure_response( array(
            'exists'    => true,
            'file_size' => size_format( filesize( $log_path ) ),
            'total'     => count( $parsed ),
            'filter'    => $filter ?: null,
            'entries'   => $parsed,
        ) );
    }

    /**
     * GET: Debug configuration status.
     */
    public function get_status( $request ) {
        $log_path = $this->get_log_path();
        $exists   = file_exists( $log_path );

        return rest_ensure_response( array(
            'wp_debug'         => defined( 'WP_DEBUG' ) && WP_DEBUG,
            'wp_debug_log'     => defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG,
            'wp_debug_display' => defined( 'WP_DEBUG_DISPLAY' ) && WP_DEBUG_DISPLAY,
            'script_debug'     => defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG,
            'log_exists'       => $exists,
            'log_path'         => $log_path,
            'log_size'         => $exists ? size_format( filesize( $log_path ) ) : null,
            'log_size_bytes'   => $exists ? filesize( $log_path ) : 0,
            'log_modified'     => $exists ? gmdate( 'Y-m-d H:i:s', filemtime( $log_path ) ) : null,
            'php_error_log'    => ini_get( 'error_log' ),
            'php_version'      => phpversion(),
            'memory_limit'     => ini_get( 'memory_limit' ),
            'max_execution'    => ini_get( 'max_execution_time' ),
        ) );
    }

    /**
     * DELETE: Clear the debug log.
     */
    public function clear_log( $request ) {
        $log_path = $this->get_log_path();

        if ( ! file_exists( $log_path ) ) {
            return new WP_Error( 'no_log', 'debug.log does not exist.', array( 'status' => 404 ) );
        }

        $old_size = filesize( $log_path );
        file_put_contents( $log_path, '' );

        return rest_ensure_response( array(
            'success'      => true,
            'cleared_size' => size_format( $old_size ),
        ) );
    }

    /**
     * Get the debug log file path.
     */
    private function get_log_path() {
        if ( defined( 'WP_DEBUG_LOG' ) && is_string( WP_DEBUG_LOG ) ) {
            return WP_DEBUG_LOG;
        }
        return WP_CONTENT_DIR . '/debug.log';
    }

    /**
     * Read last N lines from a file without loading the whole thing.
     */
    private function tail( $file, $lines = 200 ) {
        $handle = fopen( $file, 'r' );
        if ( ! $handle ) return array();

        $buffer   = '';
        $chunk    = 4096;
        $position = filesize( $file );

        while ( $position > 0 && substr_count( $buffer, "\n" ) < $lines + 1 ) {
            $read_size = min( $chunk, $position );
            $position -= $read_size;
            fseek( $handle, $position );
            $buffer = fread( $handle, $read_size ) . $buffer;
        }

        fclose( $handle );

        $all = explode( "\n", trim( $buffer ) );
        return array_slice( $all, -$lines );
    }
}
