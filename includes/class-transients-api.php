<?php
/**
 * Transient management REST API endpoint.
 *
 * Read, set, and delete WordPress transients.
 *
 * GET    /spilt-mcp/v1/transients               — list/search transients
 * GET    /spilt-mcp/v1/transients/(?P<key>.+)   — read a specific transient
 * POST   /spilt-mcp/v1/transients               — set a transient
 * DELETE /spilt-mcp/v1/transients/(?P<key>.+)   — delete a transient
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Spilt_MCP_Transients_API {

    public function register_routes() {
        register_rest_route( 'spilt-mcp/v1', '/transients', array(
            array(
                'methods'             => 'GET',
                'callback'            => array( $this, 'list_transients' ),
                'permission_callback' => 'spilt_mcp_admin_check',
                'args'                => array(
                    'search' => array( 'default' => '', 'sanitize_callback' => 'sanitize_text_field' ),
                    'limit'  => array( 'default' => 100, 'sanitize_callback' => 'absint' ),
                ),
            ),
            array(
                'methods'             => 'POST',
                'callback'            => array( $this, 'set_transient' ),
                'permission_callback' => 'spilt_mcp_admin_check',
            ),
        ) );

        register_rest_route( 'spilt-mcp/v1', '/transients/(?P<key>.+)', array(
            array(
                'methods'             => 'GET',
                'callback'            => array( $this, 'get_transient' ),
                'permission_callback' => 'spilt_mcp_admin_check',
            ),
            array(
                'methods'             => 'DELETE',
                'callback'            => array( $this, 'delete_transient' ),
                'permission_callback' => 'spilt_mcp_admin_check',
            ),
        ) );
    }

    /**
     * GET: List transients, optionally filtered by search term.
     */
    public function list_transients( $request ) {
        global $wpdb;

        $search = $request->get_param( 'search' );
        $limit  = min( (int) $request->get_param( 'limit' ), 500 );

        $where = "WHERE option_name LIKE '_transient_%' AND option_name NOT LIKE '_transient_timeout_%'";

        if ( ! empty( $search ) ) {
            $like = '%' . $wpdb->esc_like( $search ) . '%';
            $where .= $wpdb->prepare( " AND option_name LIKE %s", '%' . $wpdb->esc_like( '_transient_' . $search ) . '%' );
        }

        $results = $wpdb->get_results(
            "SELECT option_name, LENGTH(option_value) as value_size FROM {$wpdb->options} {$where} ORDER BY option_name LIMIT {$limit}",
            ARRAY_A
        );

        $transients = array();
        foreach ( $results as $row ) {
            $name = str_replace( '_transient_', '', $row['option_name'] );

            // Check if it has a timeout
            $timeout = get_option( '_transient_timeout_' . $name );
            $expires = null;
            $expired = false;

            if ( $timeout ) {
                $expires = gmdate( 'Y-m-d H:i:s', (int) $timeout );
                $expired = time() > (int) $timeout;
            }

            $transients[] = array(
                'name'       => $name,
                'size_bytes' => (int) $row['value_size'],
                'size'       => size_format( (int) $row['value_size'] ),
                'expires'    => $expires,
                'expired'    => $expired,
                'persistent' => $timeout === false,
            );
        }

        // Total count
        $total = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE '_transient_%' AND option_name NOT LIKE '_transient_timeout_%'"
        );

        return rest_ensure_response( array(
            'total'      => $total,
            'showing'    => count( $transients ),
            'transients' => $transients,
        ) );
    }

    /**
     * GET: Read a specific transient's value.
     */
    public function get_transient( $request ) {
        $key   = sanitize_text_field( $request['key'] );
        $value = get_transient( $key );

        if ( $value === false ) {
            return new WP_Error( 'not_found', "Transient '{$key}' not found or expired.", array( 'status' => 404 ) );
        }

        $timeout = get_option( '_transient_timeout_' . $key );

        return rest_ensure_response( array(
            'name'    => $key,
            'value'   => $value,
            'type'    => gettype( $value ),
            'expires' => $timeout ? gmdate( 'Y-m-d H:i:s', (int) $timeout ) : null,
        ) );
    }

    /**
     * POST: Set a transient.
     *
     * Body: { "key": "my_cache", "value": "data here", "expiration": 3600 }
     */
    public function set_transient( $request ) {
        $body       = $request->get_json_params();
        $key        = isset( $body['key'] ) ? sanitize_text_field( $body['key'] ) : '';
        $value      = isset( $body['value'] ) ? $body['value'] : null;
        $expiration = isset( $body['expiration'] ) ? absint( $body['expiration'] ) : 0;

        if ( empty( $key ) || $value === null ) {
            return new WP_Error( 'missing_params', 'Provide "key" and "value".', array( 'status' => 400 ) );
        }

        $result = set_transient( $key, $value, $expiration );

        return rest_ensure_response( array(
            'success'    => $result,
            'key'        => $key,
            'expiration' => $expiration ?: 'never',
        ) );
    }

    /**
     * DELETE: Delete a transient.
     */
    public function delete_transient( $request ) {
        $key    = sanitize_text_field( $request['key'] );
        $result = delete_transient( $key );

        if ( ! $result ) {
            return new WP_Error( 'not_found', "Transient '{$key}' not found.", array( 'status' => 404 ) );
        }

        return rest_ensure_response( array( 'success' => true, 'deleted' => $key ) );
    }
}
