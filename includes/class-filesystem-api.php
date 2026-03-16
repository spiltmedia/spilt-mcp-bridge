<?php
/**
 * File system access REST API endpoint.
 *
 * Read and write theme/plugin files, inspect wp-config, browse directories.
 * Restricted to wp-content directory for safety (no writing to wp-core).
 *
 * GET  /spilt-mcp/v1/filesystem/read           — read a file
 * POST /spilt-mcp/v1/filesystem/write           — write/update a file
 * GET  /spilt-mcp/v1/filesystem/browse          — list directory contents
 * GET  /spilt-mcp/v1/filesystem/wp-config       — read wp-config (sanitized, no credentials)
 * GET  /spilt-mcp/v1/filesystem/htaccess        — read .htaccess
 * POST /spilt-mcp/v1/filesystem/htaccess        — write .htaccess
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Spilt_MCP_Filesystem_API {

    public function register_routes() {
        register_rest_route( 'spilt-mcp/v1', '/filesystem/read', array(
            'methods'             => 'GET',
            'callback'            => array( $this, 'read_file' ),
            'permission_callback' => 'spilt_mcp_admin_check',
            'args'                => array(
                'path' => array( 'required' => true, 'sanitize_callback' => 'sanitize_text_field' ),
            ),
        ) );

        register_rest_route( 'spilt-mcp/v1', '/filesystem/write', array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'write_file' ),
            'permission_callback' => 'spilt_mcp_admin_check',
        ) );

        register_rest_route( 'spilt-mcp/v1', '/filesystem/browse', array(
            'methods'             => 'GET',
            'callback'            => array( $this, 'browse_dir' ),
            'permission_callback' => 'spilt_mcp_admin_check',
            'args'                => array(
                'path' => array( 'default' => '', 'sanitize_callback' => 'sanitize_text_field' ),
            ),
        ) );

        register_rest_route( 'spilt-mcp/v1', '/filesystem/wp-config', array(
            'methods'             => 'GET',
            'callback'            => array( $this, 'read_wp_config' ),
            'permission_callback' => 'spilt_mcp_admin_check',
        ) );

        register_rest_route( 'spilt-mcp/v1', '/filesystem/htaccess', array(
            array(
                'methods'             => 'GET',
                'callback'            => array( $this, 'read_htaccess' ),
                'permission_callback' => 'spilt_mcp_admin_check',
            ),
            array(
                'methods'             => 'POST',
                'callback'            => array( $this, 'write_htaccess' ),
                'permission_callback' => 'spilt_mcp_admin_check',
            ),
        ) );
    }

    /**
     * GET: Read a file from wp-content.
     */
    public function read_file( $request ) {
        $rel_path = $request->get_param( 'path' );
        $result   = $this->resolve_safe_path( $rel_path, 'wp-content' );

        if ( is_wp_error( $result ) ) return $result;

        if ( ! file_exists( $result ) ) {
            return new WP_Error( 'not_found', 'File not found.', array( 'status' => 404 ) );
        }

        if ( ! is_file( $result ) ) {
            return new WP_Error( 'not_file', 'Path is a directory, not a file. Use /browse instead.', array( 'status' => 400 ) );
        }

        // Limit file size to 1MB
        $size = filesize( $result );
        if ( $size > 1048576 ) {
            return new WP_Error( 'too_large', 'File is larger than 1MB. Size: ' . size_format( $size ), array( 'status' => 413 ) );
        }

        $content = file_get_contents( $result );

        return rest_ensure_response( array(
            'path'     => $rel_path,
            'size'     => size_format( $size ),
            'modified' => gmdate( 'Y-m-d H:i:s', filemtime( $result ) ),
            'content'  => $content,
        ) );
    }

    /**
     * POST: Write a file in wp-content.
     *
     * Body: { "path": "themes/hello-elementor-child/functions.php", "content": "<?php ..." }
     */
    public function write_file( $request ) {
        $body    = $request->get_json_params();
        $rel_path = isset( $body['path'] ) ? sanitize_text_field( $body['path'] ) : '';
        $content  = isset( $body['content'] ) ? $body['content'] : null;

        if ( empty( $rel_path ) || $content === null ) {
            return new WP_Error( 'missing_params', 'Provide "path" and "content".', array( 'status' => 400 ) );
        }

        $result = $this->resolve_safe_path( $rel_path, 'wp-content' );
        if ( is_wp_error( $result ) ) return $result;

        // Block writing to certain sensitive files
        $basename = basename( $result );
        $blocked  = array( 'wp-config.php', '.htaccess', '.htpasswd' );
        if ( in_array( $basename, $blocked, true ) ) {
            return new WP_Error( 'blocked', "Writing to {$basename} is not allowed via this endpoint.", array( 'status' => 403 ) );
        }

        // Create directory if needed
        $dir = dirname( $result );
        if ( ! is_dir( $dir ) ) {
            wp_mkdir_p( $dir );
        }

        // Backup existing file
        $backup = null;
        if ( file_exists( $result ) ) {
            $backup = $result . '.bak.' . gmdate( 'YmdHis' );
            copy( $result, $backup );
        }

        $written = file_put_contents( $result, $content );

        if ( $written === false ) {
            return new WP_Error( 'write_failed', 'Could not write to file.', array( 'status' => 500 ) );
        }

        return rest_ensure_response( array(
            'success' => true,
            'path'    => $rel_path,
            'bytes'   => $written,
            'backup'  => $backup ? basename( $backup ) : null,
        ) );
    }

    /**
     * GET: Browse a directory inside wp-content.
     */
    public function browse_dir( $request ) {
        $rel_path = $request->get_param( 'path' );

        if ( empty( $rel_path ) ) {
            $dir = WP_CONTENT_DIR;
            $rel_path = '';
        } else {
            $dir = $this->resolve_safe_path( $rel_path, 'wp-content' );
            if ( is_wp_error( $dir ) ) return $dir;
        }

        if ( ! is_dir( $dir ) ) {
            return new WP_Error( 'not_dir', 'Not a directory.', array( 'status' => 404 ) );
        }

        $items = array();
        $handle = opendir( $dir );
        if ( $handle ) {
            while ( ( $entry = readdir( $handle ) ) !== false ) {
                if ( $entry === '.' || $entry === '..' ) continue;

                $full = $dir . '/' . $entry;
                $item = array(
                    'name' => $entry,
                    'type' => is_dir( $full ) ? 'directory' : 'file',
                );

                if ( is_file( $full ) ) {
                    $item['size']     = size_format( filesize( $full ) );
                    $item['modified'] = gmdate( 'Y-m-d H:i:s', filemtime( $full ) );
                }

                $items[] = $item;
            }
            closedir( $handle );
        }

        // Sort: directories first, then files, alphabetically
        usort( $items, function( $a, $b ) {
            if ( $a['type'] !== $b['type'] ) {
                return $a['type'] === 'directory' ? -1 : 1;
            }
            return strcasecmp( $a['name'], $b['name'] );
        } );

        return rest_ensure_response( array(
            'path'  => $rel_path ?: '/',
            'items' => $items,
            'count' => count( $items ),
        ) );
    }

    /**
     * GET: Read wp-config.php with credentials redacted.
     */
    public function read_wp_config( $request ) {
        $config_path = ABSPATH . 'wp-config.php';

        if ( ! file_exists( $config_path ) ) {
            // Some setups have it one level up
            $config_path = dirname( ABSPATH ) . '/wp-config.php';
        }

        if ( ! file_exists( $config_path ) ) {
            return new WP_Error( 'not_found', 'wp-config.php not found.', array( 'status' => 404 ) );
        }

        $content = file_get_contents( $config_path );

        $sensitive = array(
            'DB_PASSWORD', 'DB_USER', 'DB_HOST',
            'AUTH_KEY', 'SECURE_AUTH_KEY', 'LOGGED_IN_KEY', 'NONCE_KEY',
            'AUTH_SALT', 'SECURE_AUTH_SALT', 'LOGGED_IN_SALT', 'NONCE_SALT',
        );

        // Extract defined constants for easy reading
        $constants = array();
        if ( preg_match_all( "/define\s*\(\s*['\"]([^'\"]+)['\"]\s*,\s*(.+?)\s*\)/", $content, $matches, PREG_SET_ORDER ) ) {
            foreach ( $matches as $m ) {
                $val = trim( $m[2], "'\"\t\n\r " );
                if ( in_array( $m[1], $sensitive, true ) ) {
                    $val = '***REDACTED***';
                }
                $constants[ $m[1] ] = $val;
            }
        }

        // Redact sensitive values from raw content using a callback
        $redacted = preg_replace_callback(
            "/(?<=define\s*\(\s*['\"])(" . implode( '|', array_map( 'preg_quote', $sensitive ) ) . ")(?=['\"])\s*,\s*['\"][^'\"]*['\"]/",
            function( $m ) {
                return $m[1] . "', '***REDACTED***'";
            },
            $content
        );

        // Safer approach: just don't return raw content, only parsed constants
        return rest_ensure_response( array(
            'constants' => $constants,
        ) );
    }

    /**
     * GET: Read .htaccess.
     */
    public function read_htaccess( $request ) {
        $path = ABSPATH . '.htaccess';

        if ( ! file_exists( $path ) ) {
            return rest_ensure_response( array(
                'exists'  => false,
                'content' => null,
                'note'    => 'No .htaccess file found. Server may be using nginx.',
            ) );
        }

        return rest_ensure_response( array(
            'exists'   => true,
            'content'  => file_get_contents( $path ),
            'size'     => size_format( filesize( $path ) ),
            'modified' => gmdate( 'Y-m-d H:i:s', filemtime( $path ) ),
        ) );
    }

    /**
     * POST: Write .htaccess (with backup).
     *
     * Body: { "content": "# BEGIN WordPress\n..." }
     */
    public function write_htaccess( $request ) {
        $body    = $request->get_json_params();
        $content = isset( $body['content'] ) ? $body['content'] : null;

        if ( $content === null ) {
            return new WP_Error( 'missing_content', 'Provide "content".', array( 'status' => 400 ) );
        }

        $path = ABSPATH . '.htaccess';

        // Backup existing
        $backup = null;
        if ( file_exists( $path ) ) {
            $backup = ABSPATH . '.htaccess.bak.' . gmdate( 'YmdHis' );
            copy( $path, $backup );
        }

        $written = file_put_contents( $path, $content );

        if ( $written === false ) {
            return new WP_Error( 'write_failed', 'Could not write .htaccess.', array( 'status' => 500 ) );
        }

        return rest_ensure_response( array(
            'success' => true,
            'bytes'   => $written,
            'backup'  => $backup ? basename( $backup ) : null,
        ) );
    }

    /**
     * Resolve a relative path within a safe root, preventing directory traversal.
     */
    private function resolve_safe_path( $rel_path, $root = 'wp-content' ) {
        // Remove leading slashes
        $rel_path = ltrim( $rel_path, '/' );

        // Block directory traversal
        if ( strpos( $rel_path, '..' ) !== false ) {
            return new WP_Error( 'traversal', 'Directory traversal not allowed.', array( 'status' => 403 ) );
        }

        if ( $root === 'wp-content' ) {
            $base = WP_CONTENT_DIR;
        } else {
            $base = ABSPATH;
        }

        $full_path = $base . '/' . $rel_path;
        $real_base = realpath( $base );

        // Walk up to the nearest existing ancestor to validate
        $check_path = $full_path;
        while ( ! file_exists( $check_path ) && $check_path !== dirname( $check_path ) ) {
            $check_path = dirname( $check_path );
        }

        $real_check = realpath( $check_path );

        // Verify the resolved path is within the base directory
        if ( ! $real_base || ! $real_check || strpos( $real_check, $real_base ) !== 0 ) {
            return new WP_Error( 'outside_root', 'Path resolves outside allowed directory.', array( 'status' => 403 ) );
        }

        return $full_path;
    }
}
