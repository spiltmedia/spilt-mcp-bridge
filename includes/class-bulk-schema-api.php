<?php
/**
 * Bulk schema REST API endpoint.
 *
 * Push or read Rank Math schema across multiple posts in a single call.
 *
 * POST /spilt-mcp/v1/rankmath/schema/bulk
 * Body: {
 *   "post_ids": [123, 456, 789],       // specific IDs, or omit for all published
 *   "schema": {                          // schema to apply to every post
 *     "@type": "Article",
 *     "metadata": { "title": "Article", "type": "template", "isPrimary": true }
 *   }
 * }
 *
 * GET /spilt-mcp/v1/rankmath/schema/bulk?page=1&per_page=50
 * Returns schema status for all published posts.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Spilt_MCP_Bulk_Schema_API {

    public function register_routes() {
        register_rest_route( 'spilt-mcp/v1', '/rankmath/schema/bulk', array(
            array(
                'methods'             => 'GET',
                'callback'            => array( $this, 'get_bulk_schema' ),
                'permission_callback' => 'spilt_mcp_admin_check',
                'args'                => array(
                    'page'     => array( 'default' => 1, 'sanitize_callback' => 'absint' ),
                    'per_page' => array( 'default' => 100, 'sanitize_callback' => 'absint' ),
                ),
            ),
            array(
                'methods'             => 'POST',
                'callback'            => array( $this, 'push_bulk_schema' ),
                'permission_callback' => 'spilt_mcp_admin_check',
            ),
        ) );
    }

    /**
     * GET: Read schema status for all published posts.
     */
    public function get_bulk_schema( $request ) {
        $page     = max( 1, (int) $request->get_param( 'page' ) );
        $per_page = min( 200, max( 1, (int) $request->get_param( 'per_page' ) ) );

        $query = new WP_Query( array(
            'post_type'      => 'post',
            'post_status'    => 'publish',
            'posts_per_page' => $per_page,
            'paged'          => $page,
            'orderby'        => 'ID',
            'order'          => 'DESC',
            'fields'         => 'ids',
        ) );

        $results = array();
        foreach ( $query->posts as $post_id ) {
            $schemas = $this->get_schemas_for_post( $post_id );
            $post    = get_post( $post_id );
            $results[] = array(
                'post_id'      => $post_id,
                'title'        => $post->post_title,
                'slug'         => $post->post_name,
                'schema_types' => array_values( array_unique( wp_list_pluck( $schemas, '@type' ) ) ),
                'schema_count' => count( $schemas ),
                'has_article'  => $this->has_type( $schemas, 'Article' ),
                'has_faq'      => $this->has_type( $schemas, 'FAQPage' ),
            );
        }

        // Summary counts
        $with_article = count( array_filter( $results, function( $r ) { return $r['has_article']; } ) );
        $with_faq     = count( array_filter( $results, function( $r ) { return $r['has_faq']; } ) );
        $with_any     = count( array_filter( $results, function( $r ) { return $r['schema_count'] > 0; } ) );
        $without_any  = count( $results ) - $with_any;

        return rest_ensure_response( array(
            'page'        => $page,
            'per_page'    => $per_page,
            'total'       => (int) $query->found_posts,
            'total_pages' => (int) $query->max_num_pages,
            'summary'     => array(
                'with_article_schema' => $with_article,
                'with_faq_schema'     => $with_faq,
                'with_any_schema'     => $with_any,
                'without_schema'      => $without_any,
            ),
            'posts'       => $results,
        ) );
    }

    /**
     * POST: Push schema to multiple posts.
     *
     * Proxies through Rank Math's native updateSchemas endpoint per post.
     * Accepts either specific post_ids or applies to all published posts.
     */
    public function push_bulk_schema( $request ) {
        $body    = $request->get_json_params();
        $schema  = isset( $body['schema'] ) ? $body['schema'] : null;

        if ( ! $schema || ! isset( $schema['@type'] ) ) {
            return new WP_Error( 'missing_schema', 'Provide a "schema" object with "@type".', array( 'status' => 400 ) );
        }

        // Determine target posts
        if ( ! empty( $body['post_ids'] ) && is_array( $body['post_ids'] ) ) {
            $post_ids = array_map( 'absint', $body['post_ids'] );
        } else {
            // All published posts
            $post_ids = get_posts( array(
                'post_type'      => 'post',
                'post_status'    => 'publish',
                'posts_per_page' => -1,
                'fields'         => 'ids',
            ) );
        }

        if ( empty( $post_ids ) ) {
            return new WP_Error( 'no_posts', 'No posts found to update.', array( 'status' => 404 ) );
        }

        // Skip posts that already have this schema type (unless force=true)
        $force   = ! empty( $body['force'] );
        $success = 0;
        $skipped = 0;
        $failed  = 0;
        $errors  = array();

        foreach ( $post_ids as $post_id ) {
            // Check if post already has this schema type
            if ( ! $force ) {
                $existing = $this->get_schemas_for_post( $post_id );
                if ( $this->has_type( $existing, $schema['@type'] ) ) {
                    $skipped++;
                    continue;
                }
            }

            // Ensure metadata exists
            $schema_to_push = $schema;
            if ( ! isset( $schema_to_push['metadata'] ) ) {
                $schema_to_push['metadata'] = array(
                    'title'     => $schema['@type'],
                    'type'      => 'template',
                    'shortcode' => 's-' . sanitize_title( $schema['@type'] ) . '-' . $post_id,
                    'isPrimary' => true,
                );
            }

            // Push via Rank Math native endpoint
            $result = $this->push_schema_to_post( $post_id, $schema_to_push );

            if ( is_wp_error( $result ) ) {
                $failed++;
                $errors[] = array(
                    'post_id' => $post_id,
                    'error'   => $result->get_error_message(),
                );
            } else {
                $success++;
            }
        }

        return rest_ensure_response( array(
            'success'     => true,
            'schema_type' => $schema['@type'],
            'total_posts' => count( $post_ids ),
            'pushed'      => $success,
            'skipped'     => $skipped,
            'failed'      => $failed,
            'errors'      => $errors,
        ) );
    }

    /**
     * Get all schemas for a post.
     */
    private function get_schemas_for_post( $post_id ) {
        $schemas = array();

        // Try Rank Math's DB class first
        if ( class_exists( '\\RankMath\\Schema\\DB' ) ) {
            $rm_schemas = \RankMath\Schema\DB::get_schemas( $post_id );
            if ( is_array( $rm_schemas ) ) {
                return array_values( $rm_schemas );
            }
        }

        // Fallback: read from meta
        $all_meta = get_post_meta( $post_id );
        foreach ( $all_meta as $key => $value ) {
            if ( strpos( $key, 'rank_math_schema' ) === 0 ) {
                $decoded = maybe_unserialize( $value[0] );
                if ( is_array( $decoded ) && isset( $decoded['@type'] ) ) {
                    $schemas[] = $decoded;
                } elseif ( is_string( $decoded ) ) {
                    $json = json_decode( $decoded, true );
                    if ( $json && isset( $json['@type'] ) ) {
                        $schemas[] = $json;
                    }
                }
                // Modern format: rank_math_schema holds array of schemas
                if ( $key === 'rank_math_schema' && is_array( $decoded ) ) {
                    foreach ( $decoded as $s ) {
                        if ( is_array( $s ) && isset( $s['@type'] ) ) {
                            $schemas[] = $s;
                        }
                    }
                }
            }
        }

        return $schemas;
    }

    /**
     * Check if schemas contain a specific @type.
     */
    private function has_type( $schemas, $type ) {
        foreach ( $schemas as $s ) {
            if ( is_array( $s ) && isset( $s['@type'] ) && $s['@type'] === $type ) {
                return true;
            }
        }
        return false;
    }

    /**
     * Push a single schema to a post via Rank Math or direct meta.
     */
    private function push_schema_to_post( $post_id, $schema ) {
        $server = rest_get_server();
        $routes = $server->get_routes();

        $schema_id = 'new-1';
        $schemas   = array( $schema_id => $schema );

        // Preserve existing schemas — merge new one in
        $existing = $this->get_schemas_for_post( $post_id );
        if ( ! empty( $existing ) ) {
            $counter = 1;
            $merged  = array();
            foreach ( $existing as $ex ) {
                $merged[ 'existing-' . $counter ] = $ex;
                $counter++;
            }
            $merged[ $schema_id ] = $schema;
            $schemas = $merged;
        }

        if ( isset( $routes['/rankmath/v1/updateSchemas'] ) ) {
            $internal = new WP_REST_Request( 'POST', '/rankmath/v1/updateSchemas' );
            $internal->set_param( 'objectType', 'post' );
            $internal->set_param( 'objectID', $post_id );
            $internal->set_param( 'schemas', $schemas );

            $result = rest_do_request( $internal );

            if ( $result->is_error() ) {
                return $result->as_error();
            }
            return true;
        }

        // Fallback: direct meta write
        $type = $schema['@type'];
        $key  = 'rank_math_schema_' . sanitize_key( $type );
        update_post_meta( $post_id, $key, $schema );
        return true;
    }
}
