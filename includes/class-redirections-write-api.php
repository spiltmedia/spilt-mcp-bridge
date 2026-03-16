<?php
/**
 * Rank Math redirections write/delete REST API endpoint.
 *
 * Extends the existing read-only redirections endpoint with create,
 * update, and delete operations. Supports bulk creation.
 *
 * POST   /spilt-mcp/v1/rankmath/redirections        — create one or many
 * PUT    /spilt-mcp/v1/rankmath/redirections/(?P<id>\d+) — update one
 * DELETE /spilt-mcp/v1/rankmath/redirections/(?P<id>\d+) — delete one
 * POST   /spilt-mcp/v1/rankmath/redirections/bulk    — bulk create
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Spilt_MCP_Redirections_Write_API {

    public function register_routes() {
        // Create single
        register_rest_route( 'spilt-mcp/v1', '/rankmath/redirections', array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'create_redirection' ),
            'permission_callback' => 'spilt_mcp_admin_check',
        ) );

        // Update / Delete single
        register_rest_route( 'spilt-mcp/v1', '/rankmath/redirections/(?P<id>\d+)', array(
            array(
                'methods'             => 'PUT',
                'callback'            => array( $this, 'update_redirection' ),
                'permission_callback' => 'spilt_mcp_admin_check',
            ),
            array(
                'methods'             => 'DELETE',
                'callback'            => array( $this, 'delete_redirection' ),
                'permission_callback' => 'spilt_mcp_admin_check',
            ),
        ) );

        // Bulk create
        register_rest_route( 'spilt-mcp/v1', '/rankmath/redirections/bulk', array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'bulk_create' ),
            'permission_callback' => 'spilt_mcp_admin_check',
        ) );
    }

    /**
     * POST: Create a single redirection.
     *
     * Body: {
     *   "from": "/old-page/",
     *   "to": "/new-page/",
     *   "type": 301,           // 301, 302, 307, 410, 451
     *   "status": "active"     // active, inactive
     * }
     */
    public function create_redirection( $request ) {
        $body = $request->get_json_params();

        $from   = isset( $body['from'] ) ? sanitize_text_field( $body['from'] ) : '';
        $to     = isset( $body['to'] ) ? esc_url_raw( $body['to'] ) : '';
        $type   = isset( $body['type'] ) ? absint( $body['type'] ) : 301;
        $status = isset( $body['status'] ) ? sanitize_text_field( $body['status'] ) : 'active';

        if ( empty( $from ) ) {
            return new WP_Error( 'missing_from', '"from" URL is required.', array( 'status' => 400 ) );
        }

        $valid_types = array( 301, 302, 307, 410, 451 );
        if ( ! in_array( $type, $valid_types, true ) ) {
            return new WP_Error( 'invalid_type', 'Type must be 301, 302, 307, 410, or 451.', array( 'status' => 400 ) );
        }

        // For 410/451, no "to" URL needed
        if ( ! in_array( $type, array( 410, 451 ), true ) && empty( $to ) ) {
            return new WP_Error( 'missing_to', '"to" URL is required for ' . $type . ' redirects.', array( 'status' => 400 ) );
        }

        $result = $this->insert_redirection( $from, $to, $type, $status );

        if ( is_wp_error( $result ) ) {
            return $result;
        }

        return rest_ensure_response( array(
            'success' => true,
            'id'      => $result,
            'from'    => $from,
            'to'      => $to,
            'type'    => $type,
            'status'  => $status,
        ) );
    }

    /**
     * PUT: Update an existing redirection.
     */
    public function update_redirection( $request ) {
        $id   = (int) $request['id'];
        $body = $request->get_json_params();

        global $wpdb;
        $table = $wpdb->prefix . 'rank_math_redirections';

        if ( $wpdb->get_var( "SHOW TABLES LIKE '{$table}'" ) !== $table ) {
            return new WP_Error( 'no_table', 'Rank Math redirections table not found.', array( 'status' => 404 ) );
        }

        $existing = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), ARRAY_A );
        if ( ! $existing ) {
            return new WP_Error( 'not_found', "Redirection {$id} not found.", array( 'status' => 404 ) );
        }

        $update_data = array();
        if ( isset( $body['from'] ) ) {
            $sources = array( array(
                'pattern'    => sanitize_text_field( $body['from'] ),
                'comparison' => 'exact',
            ) );
            $update_data['sources'] = wp_json_encode( $sources );
        }
        if ( isset( $body['to'] ) ) {
            $update_data['url_to'] = esc_url_raw( $body['to'] );
        }
        if ( isset( $body['type'] ) ) {
            $update_data['header_code'] = absint( $body['type'] );
        }
        if ( isset( $body['status'] ) ) {
            $update_data['status'] = $body['status'] === 'active' ? 'active' : 'inactive';
        }

        if ( empty( $update_data ) ) {
            return new WP_Error( 'no_changes', 'No fields to update.', array( 'status' => 400 ) );
        }

        $update_data['updated'] = current_time( 'mysql' );
        $wpdb->update( $table, $update_data, array( 'id' => $id ) );

        return rest_ensure_response( array(
            'success' => true,
            'id'      => $id,
            'updated' => array_keys( $update_data ),
        ) );
    }

    /**
     * DELETE: Remove a redirection.
     */
    public function delete_redirection( $request ) {
        $id = (int) $request['id'];

        global $wpdb;
        $table = $wpdb->prefix . 'rank_math_redirections';

        if ( $wpdb->get_var( "SHOW TABLES LIKE '{$table}'" ) !== $table ) {
            return new WP_Error( 'no_table', 'Rank Math redirections table not found.', array( 'status' => 404 ) );
        }

        $deleted = $wpdb->delete( $table, array( 'id' => $id ) );

        if ( ! $deleted ) {
            return new WP_Error( 'not_found', "Redirection {$id} not found.", array( 'status' => 404 ) );
        }

        return rest_ensure_response( array( 'success' => true, 'deleted_id' => $id ) );
    }

    /**
     * POST: Bulk create redirections.
     *
     * Body: {
     *   "redirections": [
     *     { "from": "/old/", "to": "/new/", "type": 301 },
     *     { "from": "/gone/", "type": 410 }
     *   ]
     * }
     */
    public function bulk_create( $request ) {
        $body         = $request->get_json_params();
        $redirections = isset( $body['redirections'] ) ? (array) $body['redirections'] : array();

        if ( empty( $redirections ) ) {
            return new WP_Error( 'no_data', 'Provide a "redirections" array.', array( 'status' => 400 ) );
        }

        $created = array();
        $errors  = array();

        foreach ( $redirections as $i => $redir ) {
            $from   = isset( $redir['from'] ) ? sanitize_text_field( $redir['from'] ) : '';
            $to     = isset( $redir['to'] ) ? esc_url_raw( $redir['to'] ) : '';
            $type   = isset( $redir['type'] ) ? absint( $redir['type'] ) : 301;
            $status = isset( $redir['status'] ) ? sanitize_text_field( $redir['status'] ) : 'active';

            if ( empty( $from ) ) {
                $errors[] = array( 'index' => $i, 'error' => 'Missing "from"' );
                continue;
            }

            $result = $this->insert_redirection( $from, $to, $type, $status );

            if ( is_wp_error( $result ) ) {
                $errors[] = array( 'index' => $i, 'from' => $from, 'error' => $result->get_error_message() );
            } else {
                $created[] = array( 'id' => $result, 'from' => $from, 'to' => $to, 'type' => $type );
            }
        }

        return rest_ensure_response( array(
            'success' => true,
            'created' => count( $created ),
            'failed'  => count( $errors ),
            'details' => $created,
            'errors'  => $errors,
        ) );
    }

    /**
     * Insert a redirection into the Rank Math table.
     */
    private function insert_redirection( $from, $to, $type, $status ) {
        global $wpdb;
        $table = $wpdb->prefix . 'rank_math_redirections';

        if ( $wpdb->get_var( "SHOW TABLES LIKE '{$table}'" ) !== $table ) {
            return new WP_Error( 'no_table', 'Rank Math redirections table not found.' );
        }

        // Check for duplicate
        $like = '%' . $wpdb->esc_like( $from ) . '%';
        $exists = $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$table} WHERE sources LIKE %s LIMIT 1",
            $like
        ) );

        if ( $exists ) {
            return new WP_Error( 'duplicate', "Redirection from '{$from}' already exists (ID: {$exists})." );
        }

        $sources = wp_json_encode( array( array(
            'pattern'    => $from,
            'comparison' => 'exact',
        ) ) );

        $wpdb->insert( $table, array(
            'sources'     => $sources,
            'url_to'      => $to,
            'header_code' => $type,
            'hits'        => 0,
            'status'      => $status === 'active' ? 'active' : 'inactive',
            'created'     => current_time( 'mysql' ),
            'updated'     => current_time( 'mysql' ),
        ) );

        return $wpdb->insert_id ?: new WP_Error( 'insert_failed', 'Failed to insert redirection.' );
    }
}
