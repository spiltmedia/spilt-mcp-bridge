<?php
/**
 * Bulk Rank Math meta REST API endpoint.
 *
 * Read or update Rank Math SEO fields across multiple posts in one call.
 *
 * GET  /spilt-mcp/v1/rankmath/meta/bulk?page=1&per_page=100
 * Returns focus keyword, meta description, SEO title, score for all posts.
 *
 * POST /spilt-mcp/v1/rankmath/meta/bulk
 * Body: {
 *   "posts": {
 *     "35593": { "rank_math_focus_keyword": "mobile-friendly sites", "rank_math_description": "..." },
 *     "35369": { "rank_math_focus_keyword": "SEO vs PPC" }
 *   }
 * }
 * -- or apply the same fields to many posts --
 * Body: {
 *   "post_ids": [35593, 35369, ...],
 *   "meta": { "rank_math_robots": ["noindex"] }
 * }
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Spilt_MCP_Bulk_RankMath_Meta_API {

    /**
     * Allowed Rank Math meta keys for write operations.
     */
    private $allowed_keys = array(
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

    /**
     * Keys to read in bulk.
     */
    private $read_keys = array(
        'rank_math_title',
        'rank_math_description',
        'rank_math_focus_keyword',
        'rank_math_seo_score',
        'rank_math_robots',
        'rank_math_canonical_url',
        'rank_math_primary_category',
    );

    public function register_routes() {
        register_rest_route( 'spilt-mcp/v1', '/rankmath/meta/bulk', array(
            array(
                'methods'             => 'GET',
                'callback'            => array( $this, 'get_bulk_meta' ),
                'permission_callback' => 'spilt_mcp_admin_check',
                'args'                => array(
                    'page'     => array( 'default' => 1, 'sanitize_callback' => 'absint' ),
                    'per_page' => array( 'default' => 100, 'sanitize_callback' => 'absint' ),
                    'filter'   => array( 'default' => '', 'sanitize_callback' => 'sanitize_text_field' ),
                ),
            ),
            array(
                'methods'             => 'POST',
                'callback'            => array( $this, 'update_bulk_meta' ),
                'permission_callback' => 'spilt_mcp_admin_check',
            ),
        ) );
    }

    /**
     * GET: Read Rank Math meta for all published posts.
     *
     * ?filter=missing_keyword  — only posts without focus keyword
     * ?filter=missing_desc     — only posts without meta description
     * ?filter=missing_any      — posts missing keyword OR description
     * ?filter=noindex           — posts with noindex robots
     */
    public function get_bulk_meta( $request ) {
        $page     = max( 1, (int) $request->get_param( 'page' ) );
        $per_page = min( 200, max( 1, (int) $request->get_param( 'per_page' ) ) );
        $filter   = $request->get_param( 'filter' );

        $query_args = array(
            'post_type'      => 'post',
            'post_status'    => 'publish',
            'posts_per_page' => $per_page,
            'paged'          => $page,
            'orderby'        => 'ID',
            'order'          => 'DESC',
        );

        // Apply meta filters
        if ( $filter === 'missing_keyword' ) {
            $query_args['meta_query'] = array(
                'relation' => 'OR',
                array( 'key' => 'rank_math_focus_keyword', 'compare' => 'NOT EXISTS' ),
                array( 'key' => 'rank_math_focus_keyword', 'value' => '' ),
            );
        } elseif ( $filter === 'missing_desc' ) {
            $query_args['meta_query'] = array(
                'relation' => 'OR',
                array( 'key' => 'rank_math_description', 'compare' => 'NOT EXISTS' ),
                array( 'key' => 'rank_math_description', 'value' => '' ),
            );
        } elseif ( $filter === 'noindex' ) {
            $query_args['meta_query'] = array(
                array( 'key' => 'rank_math_robots', 'value' => 'noindex', 'compare' => 'LIKE' ),
            );
        }

        $query = new WP_Query( $query_args );

        $results = array();
        foreach ( $query->posts as $post ) {
            $meta = array();
            foreach ( $this->read_keys as $key ) {
                $value = get_post_meta( $post->ID, $key, true );
                $meta[ $key ] = $value ?: null;
            }

            $results[] = array(
                'post_id' => $post->ID,
                'title'   => $post->post_title,
                'slug'    => $post->post_name,
                'url'     => get_permalink( $post->ID ),
                'meta'    => $meta,
            );
        }

        // Summary stats
        $has_kw   = 0;
        $has_desc = 0;
        foreach ( $results as $r ) {
            if ( ! empty( $r['meta']['rank_math_focus_keyword'] ) ) $has_kw++;
            if ( ! empty( $r['meta']['rank_math_description'] ) ) $has_desc++;
        }

        return rest_ensure_response( array(
            'page'        => $page,
            'per_page'    => $per_page,
            'total'       => (int) $query->found_posts,
            'total_pages' => (int) $query->max_num_pages,
            'filter'      => $filter ?: 'none',
            'summary'     => array(
                'with_focus_keyword'    => $has_kw,
                'with_meta_description' => $has_desc,
                'in_this_page'          => count( $results ),
            ),
            'posts'       => $results,
        ) );
    }

    /**
     * POST: Update Rank Math meta for multiple posts.
     *
     * Two formats supported:
     *
     * Format 1 — per-post fields:
     * { "posts": { "123": { "rank_math_focus_keyword": "seo tips" }, "456": { ... } } }
     *
     * Format 2 — same fields to many posts:
     * { "post_ids": [123, 456], "meta": { "rank_math_robots": "noindex" } }
     */
    public function update_bulk_meta( $request ) {
        $body    = $request->get_json_params();
        $updated = array();
        $errors  = array();

        // Format 1: per-post
        if ( isset( $body['posts'] ) && is_array( $body['posts'] ) ) {
            foreach ( $body['posts'] as $post_id => $fields ) {
                $post_id = absint( $post_id );
                if ( ! get_post( $post_id ) ) {
                    $errors[] = array( 'post_id' => $post_id, 'error' => 'Post not found' );
                    continue;
                }

                $post_updated = array();
                foreach ( $fields as $key => $value ) {
                    if ( in_array( $key, $this->allowed_keys, true ) ) {
                        if ( is_array( $value ) ) {
                            update_post_meta( $post_id, $key, $value );
                        } else {
                            update_post_meta( $post_id, $key, sanitize_text_field( $value ) );
                        }
                        $post_updated[] = $key;
                    }
                }

                if ( ! empty( $post_updated ) ) {
                    $updated[] = array( 'post_id' => $post_id, 'fields' => $post_updated );
                }
            }
        }

        // Format 2: same fields to many posts
        if ( isset( $body['post_ids'] ) && isset( $body['meta'] ) ) {
            $post_ids = array_map( 'absint', (array) $body['post_ids'] );
            $meta     = (array) $body['meta'];

            foreach ( $post_ids as $post_id ) {
                if ( ! get_post( $post_id ) ) {
                    $errors[] = array( 'post_id' => $post_id, 'error' => 'Post not found' );
                    continue;
                }

                $post_updated = array();
                foreach ( $meta as $key => $value ) {
                    if ( in_array( $key, $this->allowed_keys, true ) ) {
                        if ( is_array( $value ) ) {
                            update_post_meta( $post_id, $key, $value );
                        } else {
                            update_post_meta( $post_id, $key, sanitize_text_field( $value ) );
                        }
                        $post_updated[] = $key;
                    }
                }

                if ( ! empty( $post_updated ) ) {
                    $updated[] = array( 'post_id' => $post_id, 'fields' => $post_updated );
                }
            }
        }

        if ( empty( $updated ) && empty( $errors ) ) {
            return new WP_Error(
                'no_data',
                'Provide "posts" (per-post format) or "post_ids" + "meta" (bulk format).',
                array( 'status' => 400 )
            );
        }

        return rest_ensure_response( array(
            'success' => true,
            'updated' => count( $updated ),
            'failed'  => count( $errors ),
            'details' => $updated,
            'errors'  => $errors,
        ) );
    }
}
