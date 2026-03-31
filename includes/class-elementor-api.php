<?php
/**
 * Elementor REST API endpoints.
 *
 * Exposes _elementor_data for reading/writing and CSS cache flushing.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Spilt_MCP_Elementor_API {

    public function register_routes() {
        // Get Elementor data for a post
        register_rest_route( 'spilt-mcp/v1', '/elementor/data/(?P<post_id>\d+)', array(
            array(
                'methods'             => 'GET',
                'callback'            => array( $this, 'get_data' ),
                'permission_callback' => 'spilt_mcp_admin_check',
                'args'                => array(
                    'post_id' => array(
                        'required'          => true,
                        'validate_callback' => function ( $param ) {
                            return is_numeric( $param );
                        },
                    ),
                ),
            ),
            array(
                'methods'             => 'POST',
                'callback'            => array( $this, 'update_data' ),
                'permission_callback' => 'spilt_mcp_admin_check',
                'args'                => array(
                    'post_id' => array(
                        'required'          => true,
                        'validate_callback' => function ( $param ) {
                            return is_numeric( $param );
                        },
                    ),
                ),
            ),
        ) );

        // Flush Elementor CSS (global)
        register_rest_route( 'spilt-mcp/v1', '/elementor/flush-css', array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'flush_css_global' ),
            'permission_callback' => 'spilt_mcp_admin_check',
        ) );

        // Flush Elementor CSS (per post)
        register_rest_route( 'spilt-mcp/v1', '/elementor/flush-css/(?P<post_id>\d+)', array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'flush_css_post' ),
            'permission_callback' => 'spilt_mcp_admin_check',
        ) );

        // Find and replace in Elementor data
        register_rest_route( 'spilt-mcp/v1', '/elementor/find-replace/(?P<post_id>\d+)', array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'find_replace' ),
            'permission_callback' => 'spilt_mcp_admin_check',
        ) );
    }

    /**
     * GET: Return parsed _elementor_data JSON.
     * Reads directly from DB to bypass stale WordPress/object cache layers.
     */
    public function get_data( $request ) {
        $post_id = (int) $request['post_id'];

        if ( ! get_post( $post_id ) ) {
            return new WP_Error( 'not_found', 'Post not found.', array( 'status' => 404 ) );
        }

        // Read directly from DB to avoid stale object cache.
        global $wpdb;
        $data = $wpdb->get_var( $wpdb->prepare(
            "SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = '_elementor_data' LIMIT 1",
            $post_id
        ) );

        if ( empty( $data ) ) {
            return new WP_Error( 'no_data', 'No Elementor data found for this post.', array( 'status' => 404 ) );
        }

        // _elementor_data is stored as a JSON string
        $decoded = json_decode( $data, true );
        if ( json_last_error() !== JSON_ERROR_NONE ) {
            return new WP_Error( 'parse_error', 'Failed to parse Elementor data.', array( 'status' => 500 ) );
        }

        return rest_ensure_response( $decoded );
    }

    /**
     * POST: Update _elementor_data and flush CSS.
     */
    public function update_data( $request ) {
        $post_id = (int) $request['post_id'];
        $body    = $request->get_json_params();

        if ( ! get_post( $post_id ) ) {
            return new WP_Error( 'not_found', 'Post not found.', array( 'status' => 404 ) );
        }

        $data = isset( $body['data'] ) ? $body['data'] : null;
        if ( ! $data || ! is_array( $data ) ) {
            return new WP_Error( 'invalid_data', 'Request must include "data" as a JSON array.', array( 'status' => 400 ) );
        }

        // Encode and save via direct SQL to avoid WordPress object cache issues.
        $encoded = wp_json_encode( $data );
        $this->_direct_meta_write( $post_id, '_elementor_data', $encoded );

        // Clear all caches before CSS flush so Elementor reads our fresh data.
        wp_cache_delete( $post_id, 'post_meta' );
        clean_post_cache( $post_id );

        // Flush CSS for this post
        $this->_flush_post_css( $post_id );

        return rest_ensure_response( array(
            'success' => true,
            'message' => 'Elementor data updated and CSS regenerated.',
        ) );
    }

    /**
     * POST: Flush Elementor CSS globally.
     */
    public function flush_css_global( $request ) {
        if ( ! defined( 'ELEMENTOR_VERSION' ) ) {
            return new WP_Error( 'no_elementor', 'Elementor is not active.', array( 'status' => 400 ) );
        }

        \Elementor\Plugin::$instance->files_manager->clear_cache();

        return rest_ensure_response( array(
            'success' => true,
            'message' => 'Elementor CSS cache flushed globally.',
        ) );
    }

    /**
     * POST: Flush Elementor CSS for a specific post.
     */
    public function flush_css_post( $request ) {
        $post_id = (int) $request['post_id'];
        $this->_flush_post_css( $post_id );

        return rest_ensure_response( array(
            'success' => true,
            'message' => "Elementor CSS flushed for post {$post_id}.",
        ) );
    }

    /**
     * POST: Find and replace text in Elementor data.
     */
    public function find_replace( $request ) {
        $post_id = (int) $request['post_id'];
        $body    = $request->get_json_params();

        if ( ! get_post( $post_id ) ) {
            return new WP_Error( 'not_found', 'Post not found.', array( 'status' => 404 ) );
        }

        $find    = isset( $body['find'] ) ? $body['find'] : '';
        $replace = isset( $body['replace'] ) ? $body['replace'] : '';

        if ( empty( $find ) ) {
            return new WP_Error( 'no_find', 'The "find" parameter is required.', array( 'status' => 400 ) );
        }

        // Read current data directly from DB to avoid cache.
        global $wpdb;
        $raw = $wpdb->get_var( $wpdb->prepare(
            "SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = '_elementor_data' LIMIT 1",
            $post_id
        ) );

        if ( empty( $raw ) ) {
            return new WP_Error( 'no_data', 'No Elementor data found.', array( 'status' => 404 ) );
        }

        // Count and replace.
        $count   = substr_count( $raw, $find );
        $updated = str_replace( $find, $replace, $raw );

        if ( $count === 0 ) {
            return rest_ensure_response( array(
                'success'      => true,
                'replacements' => 0,
                'message'      => "No occurrences of '{$find}' found.",
            ) );
        }

        // Write directly via SQL.
        $wpdb->update(
            $wpdb->postmeta,
            array( 'meta_value' => $updated ),
            array( 'post_id' => $post_id, 'meta_key' => '_elementor_data' ),
            array( '%s' ),
            array( '%d', '%s' )
        );

        // Clear caches.
        wp_cache_delete( $post_id, 'post_meta' );
        clean_post_cache( $post_id );

        // Flush CSS.
        $this->_flush_post_css( $post_id );

        return rest_ensure_response( array(
            'success'      => true,
            'replacements' => $count,
            'message'      => "Replaced {$count} occurrence(s) of '{$find}' with '{$replace}'.",
        ) );
    }

    /**
     * Internal: Write meta value directly via SQL to bypass WP object cache.
     */
    private function _direct_meta_write( $post_id, $meta_key, $meta_value ) {
        global $wpdb;

        $exists = $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = %s",
            $post_id, $meta_key
        ) );

        // Note: Do NOT use wp_slash() here — $wpdb->insert/update already
        // handles MySQL escaping via prepare(). Adding wp_slash() would
        // double-escape the data and corrupt JSON values.
        if ( $exists ) {
            $wpdb->update(
                $wpdb->postmeta,
                array( 'meta_value' => $meta_value ),
                array( 'post_id' => $post_id, 'meta_key' => $meta_key ),
                array( '%s' ),
                array( '%d', '%s' )
            );
        } else {
            $wpdb->insert(
                $wpdb->postmeta,
                array(
                    'post_id'    => $post_id,
                    'meta_key'   => $meta_key,
                    'meta_value' => $meta_value,
                ),
                array( '%d', '%s', '%s' )
            );
        }
    }

    /**
     * Internal: flush CSS for a single post.
     */
    private function _flush_post_css( $post_id ) {
        if ( ! defined( 'ELEMENTOR_VERSION' ) ) {
            return;
        }

        $post_css = \Elementor\Core\Files\CSS\Post::create( $post_id );
        $post_css->update();
    }
}
