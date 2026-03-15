<?php
/**
 * Bulk post dates REST API endpoint.
 *
 * Update modified/published dates for multiple posts in a single request.
 * Designed for backfill operations where 50-100 posts need date overrides.
 * Uses direct database updates to bypass WP's modified date override.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Spilt_MCP_Bulk_Dates_API {

    /**
     * Maximum posts per request to prevent timeouts.
     */
    const MAX_BATCH_SIZE = 100;

    public function register_routes() {
        register_rest_route( 'spilt-mcp/v1', '/post-dates/bulk', array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'bulk_update' ),
            'permission_callback' => 'spilt_mcp_admin_check',
        ) );
    }

    /**
     * POST: Bulk update dates for multiple posts.
     *
     * Body:
     * {
     *   "posts": [
     *     {
     *       "id": 12345,
     *       "date": "2024-01-08T09:00:00",
     *       "modified": "2024-01-17T14:32:00"
     *     },
     *     {
     *       "id": 12346,
     *       "date": "2024-01-15T09:00:00",
     *       "modified": "2024-01-22T11:15:00"
     *     }
     *   ]
     * }
     *
     * Each post entry can have: date, date_gmt, modified, modified_gmt.
     * If only local is provided, GMT is auto-calculated.
     */
    public function bulk_update( $request ) {
        global $wpdb;

        $body  = $request->get_json_params();
        $posts = isset( $body['posts'] ) ? $body['posts'] : array();

        if ( empty( $posts ) || ! is_array( $posts ) ) {
            return new WP_Error(
                'missing_posts',
                'Provide a "posts" array with at least one entry.',
                array( 'status' => 400 )
            );
        }

        if ( count( $posts ) > self::MAX_BATCH_SIZE ) {
            return new WP_Error(
                'batch_too_large',
                'Maximum ' . self::MAX_BATCH_SIZE . ' posts per request. You sent ' . count( $posts ) . '.',
                array( 'status' => 400 )
            );
        }

        $results  = array();
        $success  = 0;
        $failed   = 0;

        foreach ( $posts as $entry ) {
            $post_id = isset( $entry['id'] ) ? (int) $entry['id'] : 0;

            if ( ! $post_id ) {
                $results[] = array(
                    'id'      => $post_id,
                    'success' => false,
                    'error'   => 'Missing or invalid post ID.',
                );
                $failed++;
                continue;
            }

            $post = get_post( $post_id );
            if ( ! $post ) {
                $results[] = array(
                    'id'      => $post_id,
                    'success' => false,
                    'error'   => 'Post not found.',
                );
                $failed++;
                continue;
            }

            $update = array();

            // Handle publish date
            if ( ! empty( $entry['date'] ) ) {
                $local = $this->normalize_datetime( $entry['date'] );
                $update['post_date'] = $local;
                $update['post_date_gmt'] = ! empty( $entry['date_gmt'] )
                    ? $this->normalize_datetime( $entry['date_gmt'] )
                    : get_gmt_from_date( $local );
            } elseif ( ! empty( $entry['date_gmt'] ) ) {
                $gmt = $this->normalize_datetime( $entry['date_gmt'] );
                $update['post_date_gmt'] = $gmt;
                $update['post_date']     = get_date_from_gmt( $gmt );
            }

            // Handle modified date
            if ( ! empty( $entry['modified'] ) ) {
                $local = $this->normalize_datetime( $entry['modified'] );
                $update['post_modified'] = $local;
                $update['post_modified_gmt'] = ! empty( $entry['modified_gmt'] )
                    ? $this->normalize_datetime( $entry['modified_gmt'] )
                    : get_gmt_from_date( $local );
            } elseif ( ! empty( $entry['modified_gmt'] ) ) {
                $gmt = $this->normalize_datetime( $entry['modified_gmt'] );
                $update['post_modified_gmt'] = $gmt;
                $update['post_modified']     = get_date_from_gmt( $gmt );
            }

            if ( empty( $update ) ) {
                $results[] = array(
                    'id'      => $post_id,
                    'success' => false,
                    'error'   => 'No date fields provided.',
                );
                $failed++;
                continue;
            }

            $db_result = $wpdb->update(
                $wpdb->posts,
                $update,
                array( 'ID' => $post_id ),
                array_fill( 0, count( $update ), '%s' ),
                array( '%d' )
            );

            if ( $db_result === false ) {
                $results[] = array(
                    'id'      => $post_id,
                    'success' => false,
                    'error'   => 'Database update failed: ' . $wpdb->last_error,
                );
                $failed++;
                continue;
            }

            clean_post_cache( $post_id );
            $updated = get_post( $post_id );

            $results[] = array(
                'id'           => $post_id,
                'success'      => true,
                'date'         => $updated->post_date,
                'date_gmt'     => $updated->post_date_gmt,
                'modified'     => $updated->post_modified,
                'modified_gmt' => $updated->post_modified_gmt,
            );
            $success++;
        }

        return rest_ensure_response( array(
            'total'    => count( $posts ),
            'success'  => $success,
            'failed'   => $failed,
            'results'  => $results,
        ) );
    }

    /**
     * Normalize datetime string to MySQL format.
     */
    private function normalize_datetime( $datetime_str ) {
        $normalized = str_replace( 'T', ' ', $datetime_str );
        $normalized = preg_replace( '/[+-]\d{2}:\d{2}$|Z$/', '', $normalized );
        return $normalized;
    }
}
