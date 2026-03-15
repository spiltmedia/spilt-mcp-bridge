<?php
/**
 * Rank Math REST API endpoints.
 *
 * Exposes Rank Math SEO meta and schema data for reading/writing.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Spilt_MCP_RankMath_API {

    /**
     * Rank Math meta keys we expose.
     */
    private $meta_keys = array(
        'rank_math_title',
        'rank_math_description',
        'rank_math_focus_keyword',
        'rank_math_robots',
        'rank_math_canonical_url',
        'rank_math_og_title',
        'rank_math_og_description',
        'rank_math_og_image',
        'rank_math_twitter_title',
        'rank_math_twitter_description',
        'rank_math_primary_category',
    );

    public function register_routes() {
        // Meta read/write
        register_rest_route( 'spilt-mcp/v1', '/rankmath/meta/(?P<post_id>\d+)', array(
            array(
                'methods'             => 'GET',
                'callback'            => array( $this, 'get_meta' ),
                'permission_callback' => 'spilt_mcp_admin_check',
            ),
            array(
                'methods'             => 'POST',
                'callback'            => array( $this, 'update_meta' ),
                'permission_callback' => 'spilt_mcp_admin_check',
            ),
        ) );

        // Schema read/write/delete
        register_rest_route( 'spilt-mcp/v1', '/rankmath/schema/(?P<post_id>\d+)', array(
            array(
                'methods'             => 'GET',
                'callback'            => array( $this, 'get_schema' ),
                'permission_callback' => 'spilt_mcp_admin_check',
            ),
            array(
                'methods'             => 'POST',
                'callback'            => array( $this, 'update_schema' ),
                'permission_callback' => 'spilt_mcp_admin_check',
            ),
            array(
                'methods'             => 'DELETE',
                'callback'            => array( $this, 'delete_schema' ),
                'permission_callback' => 'spilt_mcp_admin_check',
            ),
        ) );

        // Redirections list
        register_rest_route( 'spilt-mcp/v1', '/rankmath/redirections', array(
            'methods'             => 'GET',
            'callback'            => array( $this, 'list_redirections' ),
            'permission_callback' => 'spilt_mcp_admin_check',
        ) );
    }

    /**
     * GET: Read all Rank Math meta for a post.
     */
    public function get_meta( $request ) {
        $post_id = (int) $request['post_id'];

        if ( ! get_post( $post_id ) ) {
            return new WP_Error( 'not_found', 'Post not found.', array( 'status' => 404 ) );
        }

        $meta = array();
        foreach ( $this->meta_keys as $key ) {
            $value = get_post_meta( $post_id, $key, true );
            if ( $value !== '' ) {
                $meta[ $key ] = $value;
            }
        }

        return rest_ensure_response( $meta );
    }

    /**
     * POST: Update Rank Math meta fields.
     */
    public function update_meta( $request ) {
        $post_id = (int) $request['post_id'];
        $body    = $request->get_json_params();

        if ( ! get_post( $post_id ) ) {
            return new WP_Error( 'not_found', 'Post not found.', array( 'status' => 404 ) );
        }

        $updated = array();
        foreach ( $body as $key => $value ) {
            // Only allow known Rank Math keys
            if ( strpos( $key, 'rank_math_' ) === 0 ) {
                update_post_meta( $post_id, $key, sanitize_text_field( $value ) );
                $updated[] = $key;
            }
        }

        if ( empty( $updated ) ) {
            return new WP_Error( 'no_fields', 'No valid Rank Math fields provided.', array( 'status' => 400 ) );
        }

        return rest_ensure_response( array(
            'success' => true,
            'updated' => $updated,
        ) );
    }

    /**
     * GET: Read Rank Math schema data.
     *
     * Uses Rank Math's internal DB class when available for accurate reads.
     * Falls back to direct meta query for legacy installs.
     */
    public function get_schema( $request ) {
        $post_id = (int) $request['post_id'];

        if ( ! get_post( $post_id ) ) {
            return new WP_Error( 'not_found', 'Post not found.', array( 'status' => 404 ) );
        }

        // Preferred: use Rank Math's own DB class (Rank Math Pro).
        if ( class_exists( '\\RankMath\\Schema\\DB' ) ) {
            $schemas = \RankMath\Schema\DB::get_schemas( $post_id );
            return rest_ensure_response( $schemas );
        }

        // Fallback: query both the modern meta key (rank_math_schema)
        // and legacy per-type keys (rank_math_schema_*).
        global $wpdb;
        $results = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT meta_id, meta_key, meta_value FROM {$wpdb->postmeta}
                 WHERE post_id = %d
                   AND ( meta_key = 'rank_math_schema' OR meta_key LIKE 'rank_math_schema_%%' )",
                $post_id
            ),
            ARRAY_A
        );

        $schemas = array();
        foreach ( $results as $row ) {
            $decoded = json_decode( $row['meta_value'], true );
            $key     = 'schema-' . $row['meta_id'];
            $schemas[ $key ] = $decoded ? $decoded : $row['meta_value'];
        }

        return rest_ensure_response( $schemas );
    }

    /**
     * POST: Update Rank Math schema data.
     *
     * Proxies through Rank Math's own updateSchemas REST endpoint so that
     * schemas are stored in the correct internal format and render on the
     * frontend.  Falls back to direct meta writes on non-Rank-Math sites.
     *
     * Body format (preferred — Rank Math native):
     * {
     *   "schemas": {
     *     "new-1": { "@type": "FAQPage", "metadata": { ... }, "mainEntity": [ ... ] }
     *   }
     * }
     *
     * Legacy format (still accepted):
     * { "rank_math_schema_FAQPage": { ... } }
     */
    public function update_schema( $request ) {
        $post_id = (int) $request['post_id'];
        $body    = $request->get_json_params();

        if ( ! get_post( $post_id ) ) {
            return new WP_Error( 'not_found', 'Post not found.', array( 'status' => 404 ) );
        }

        // Preferred path: proxy through Rank Math's native endpoint.
        if ( isset( $body['schemas'] ) && is_array( $body['schemas'] ) ) {
            return $this->update_schema_via_rankmath( $post_id, $body['schemas'] );
        }

        // Legacy path: accept rank_math_schema_* keyed objects and
        // convert them into the native format automatically.
        $schemas = array();
        $counter = 1;
        foreach ( $body as $key => $value ) {
            if ( strpos( $key, 'rank_math_schema_' ) === 0 || ( isset( $value['@type'] ) ) ) {
                $schema_id = 'new-' . $counter;
                // Ensure metadata exists (required by Rank Math Pro).
                if ( ! isset( $value['metadata'] ) && isset( $value['@type'] ) ) {
                    $value['metadata'] = array(
                        'title'     => $value['@type'],
                        'type'      => 'template',
                        'shortcode' => 's-' . sanitize_title( $value['@type'] ) . '-' . $post_id,
                        'isPrimary' => $counter === 1,
                    );
                }
                $schemas[ $schema_id ] = $value;
                $counter++;
            }
        }

        if ( empty( $schemas ) ) {
            return new WP_Error( 'no_fields', 'No valid schema data provided.', array( 'status' => 400 ) );
        }

        return $this->update_schema_via_rankmath( $post_id, $schemas );
    }

    /**
     * Proxy schema writes through Rank Math's own REST endpoint.
     *
     * This ensures schemas are stored in the correct internal format
     * and will render on the frontend without browser/nonce auth.
     */
    private function update_schema_via_rankmath( $post_id, $schemas ) {
        // Check if Rank Math's endpoint is available.
        $server = rest_get_server();
        $routes = $server->get_routes();

        if ( isset( $routes['/rankmath/v1/updateSchemas'] ) ) {
            $internal = new WP_REST_Request( 'POST', '/rankmath/v1/updateSchemas' );
            $internal->set_param( 'objectType', 'post' );
            $internal->set_param( 'objectID', $post_id );
            $internal->set_param( 'schemas', $schemas );

            $result = rest_do_request( $internal );

            if ( $result->is_error() ) {
                $error = $result->as_error();
                return new WP_Error(
                    'rankmath_error',
                    $error->get_error_message(),
                    array( 'status' => $result->get_status() )
                );
            }

            return rest_ensure_response( array(
                'success'  => true,
                'method'   => 'rankmath_native',
                'response' => $result->get_data(),
            ) );
        }

        // Fallback: direct meta write as PHP arrays.
        // Rank Math expects serialized PHP arrays in post meta,
        // NOT JSON strings. update_post_meta() handles serialization.
        $updated = array();
        foreach ( $schemas as $id => $value ) {
            $type    = isset( $value['@type'] ) ? $value['@type'] : $id;
            $key     = 'rank_math_schema_' . sanitize_key( $type );
            if ( is_array( $value ) ) {
                update_post_meta( $post_id, $key, $value );
            } else {
                update_post_meta( $post_id, $key, $value );
            }
            $updated[] = $key;
        }

        return rest_ensure_response( array(
            'success'  => true,
            'method'   => 'direct_meta',
            'updated'  => $updated,
        ) );
    }

    /**
     * DELETE: Remove Rank Math schema entries.
     *
     * ?keys=all                  — delete ALL schema entries (both modern and legacy).
     * ?keys=schema-123,schema-456 — delete by meta IDs (modern format).
     * ?keys=rank_math_schema_FAQ  — delete by meta key (legacy format).
     * ?type=FAQPage               — delete schemas matching a specific @type.
     */
    public function delete_schema( $request ) {
        $post_id = (int) $request['post_id'];

        if ( ! get_post( $post_id ) ) {
            return new WP_Error( 'not_found', 'Post not found.', array( 'status' => 404 ) );
        }

        $keys_param = $request->get_param( 'keys' );
        $type_param = $request->get_param( 'type' );

        if ( empty( $keys_param ) && empty( $type_param ) ) {
            return new WP_Error( 'no_params', 'Provide ?keys=key1,key2 or ?keys=all or ?type=FAQPage', array( 'status' => 400 ) );
        }

        global $wpdb;
        $deleted = array();

        // Delete by @type — reads all schemas, removes matching type, re-saves.
        if ( ! empty( $type_param ) ) {
            $schemas = $this->get_schema( $request )->get_data();
            $keep    = array();
            foreach ( $schemas as $id => $schema ) {
                if ( is_array( $schema ) && isset( $schema['@type'] ) && $schema['@type'] === $type_param ) {
                    $deleted[] = $id;
                } else {
                    $keep[ $id ] = $schema;
                }
            }
            // Re-save without deleted schemas via Rank Math.
            if ( ! empty( $deleted ) ) {
                $this->update_schema_via_rankmath( $post_id, $keep );
            }
            return rest_ensure_response( array( 'success' => true, 'deleted' => $deleted ) );
        }

        // Delete all.
        if ( $keys_param === 'all' ) {
            $results = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT meta_id, meta_key FROM {$wpdb->postmeta}
                     WHERE post_id = %d
                       AND ( meta_key = 'rank_math_schema' OR meta_key LIKE 'rank\_math\_schema\_%%' )",
                    $post_id
                ),
                ARRAY_A
            );
            foreach ( $results as $row ) {
                $wpdb->delete( $wpdb->postmeta, array( 'meta_id' => $row['meta_id'] ) );
                $deleted[] = $row['meta_key'] . ':' . $row['meta_id'];
            }
            return rest_ensure_response( array( 'success' => true, 'deleted' => $deleted ) );
        }

        // Delete by specific keys or meta IDs.
        $keys = array_map( 'trim', explode( ',', $keys_param ) );
        foreach ( $keys as $key ) {
            // Meta ID format: schema-12345.
            if ( preg_match( '/^schema-(\d+)$/', $key, $m ) ) {
                $wpdb->delete( $wpdb->postmeta, array( 'meta_id' => (int) $m[1] ) );
                $deleted[] = $key;
            } elseif ( strpos( $key, 'rank_math_schema' ) === 0 ) {
                delete_post_meta( $post_id, $key );
                $deleted[] = $key;
            }
        }

        return rest_ensure_response( array(
            'success' => true,
            'deleted' => $deleted,
        ) );
    }

    /**
     * GET: List Rank Math redirections.
     */
    public function list_redirections( $request ) {
        // Rank Math stores redirections in its own table
        global $wpdb;
        $table = $wpdb->prefix . 'rank_math_redirections';

        // Check if table exists
        if ( $wpdb->get_var( "SHOW TABLES LIKE '{$table}'" ) !== $table ) {
            return new WP_Error( 'no_table', 'Rank Math redirections table not found.', array( 'status' => 404 ) );
        }

        $per_page = (int) $request->get_param( 'per_page' ) ?: 20;
        $search   = $request->get_param( 'search' );

        $where = '1=1';
        if ( $search ) {
            $like  = '%' . $wpdb->esc_like( $search ) . '%';
            $where = $wpdb->prepare( "sources LIKE %s OR url_to LIKE %s", $like, $like );
        }

        $results = $wpdb->get_results(
            "SELECT id, sources, url_to, header_code, status
             FROM {$table}
             WHERE {$where}
             ORDER BY id DESC
             LIMIT {$per_page}",
            ARRAY_A
        );

        // Decode sources JSON
        foreach ( $results as &$row ) {
            $decoded = json_decode( $row['sources'], true );
            $row['sources'] = $decoded ? $decoded : $row['sources'];
        }

        return rest_ensure_response( $results );
    }
}
