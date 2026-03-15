<?php
/**
 * Post dates REST API endpoint.
 *
 * Allows overriding post modified dates via direct database update,
 * bypassing the WP REST API's read-only restriction on `modified` fields.
 *
 * Used for backfill publishing: set modified_gmt to a date close to
 * the original publish date so sitemaps and HTTP headers look natural.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Spilt_MCP_Post_Dates_API {

    public function register_routes() {
        register_rest_route( 'spilt-mcp/v1', '/post-dates/(?P<post_id>\d+)', array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'update_dates' ),
            'permission_callback' => 'spilt_mcp_admin_check',
            'args'                => array(
                'post_id' => array(
                    'validate_callback' => function ( $param ) {
                        return is_numeric( $param );
                    },
                ),
            ),
        ) );

        register_rest_route( 'spilt-mcp/v1', '/post-dates/(?P<post_id>\d+)', array(
            'methods'             => 'GET',
            'callback'            => array( $this, 'get_dates' ),
            'permission_callback' => 'spilt_mcp_admin_check',
            'args'                => array(
                'post_id' => array(
                    'validate_callback' => function ( $param ) {
                        return is_numeric( $param );
                    },
                ),
            ),
        ) );
    }

    /**
     * POST: Override post dates via direct DB update.
     *
     * Accepts any combination of:
     *   - date          (local time, e.g. "2024-01-08T09:00:00")
     *   - date_gmt      (UTC)
     *   - modified       (local time)
     *   - modified_gmt   (UTC)
     *
     * If only local is provided, GMT is calculated automatically (and vice versa).
     */
    public function update_dates( $request ) {
        global $wpdb;

        $post_id = (int) $request['post_id'];
        $post    = get_post( $post_id );

        if ( ! $post ) {
            return new WP_Error(
                'post_not_found',
                "Post {$post_id} not found.",
                array( 'status' => 404 )
            );
        }

        $body   = $request->get_json_params();
        $update = array();

        // Handle publish date
        if ( ! empty( $body['date'] ) ) {
            $local = $this->normalize_datetime( $body['date'] );
            $update['post_date'] = $local;
            $update['post_date_gmt'] = ! empty( $body['date_gmt'] )
                ? $this->normalize_datetime( $body['date_gmt'] )
                : get_gmt_from_date( $local );
        } elseif ( ! empty( $body['date_gmt'] ) ) {
            $gmt = $this->normalize_datetime( $body['date_gmt'] );
            $update['post_date_gmt'] = $gmt;
            $update['post_date']     = get_date_from_gmt( $gmt );
        }

        // Handle modified date
        if ( ! empty( $body['modified'] ) ) {
            $local = $this->normalize_datetime( $body['modified'] );
            $update['post_modified'] = $local;
            $update['post_modified_gmt'] = ! empty( $body['modified_gmt'] )
                ? $this->normalize_datetime( $body['modified_gmt'] )
                : get_gmt_from_date( $local );
        } elseif ( ! empty( $body['modified_gmt'] ) ) {
            $gmt = $this->normalize_datetime( $body['modified_gmt'] );
            $update['post_modified_gmt'] = $gmt;
            $update['post_modified']     = get_date_from_gmt( $gmt );
        }

        if ( empty( $update ) ) {
            return new WP_Error(
                'no_dates',
                'No date fields provided. Send date, date_gmt, modified, and/or modified_gmt.',
                array( 'status' => 400 )
            );
        }

        // Direct DB update — bypasses wp_update_post which overrides modified
        $result = $wpdb->update(
            $wpdb->posts,
            $update,
            array( 'ID' => $post_id ),
            array_fill( 0, count( $update ), '%s' ),
            array( '%d' )
        );

        if ( $result === false ) {
            return new WP_Error(
                'db_error',
                'Database update failed: ' . $wpdb->last_error,
                array( 'status' => 500 )
            );
        }

        // Clear object cache so subsequent API reads reflect the change
        clean_post_cache( $post_id );

        // Re-fetch to confirm
        $updated_post = get_post( $post_id );

        return rest_ensure_response( array(
            'success'      => true,
            'post_id'      => $post_id,
            'date'         => $updated_post->post_date,
            'date_gmt'     => $updated_post->post_date_gmt,
            'modified'     => $updated_post->post_modified,
            'modified_gmt' => $updated_post->post_modified_gmt,
        ) );
    }

    /**
     * GET: Read current post dates.
     */
    public function get_dates( $request ) {
        $post_id = (int) $request['post_id'];
        $post    = get_post( $post_id );

        if ( ! $post ) {
            return new WP_Error(
                'post_not_found',
                "Post {$post_id} not found.",
                array( 'status' => 404 )
            );
        }

        return rest_ensure_response( array(
            'post_id'      => $post_id,
            'title'        => $post->post_title,
            'date'         => $post->post_date,
            'date_gmt'     => $post->post_date_gmt,
            'modified'     => $post->post_modified,
            'modified_gmt' => $post->post_modified_gmt,
            'status'       => $post->post_status,
        ) );
    }

    /**
     * Normalize datetime string to MySQL format (Y-m-d H:i:s).
     * Accepts ISO 8601 (2024-01-08T09:00:00) or MySQL format.
     */
    private function normalize_datetime( $datetime_str ) {
        // Replace the T separator with a space
        $normalized = str_replace( 'T', ' ', $datetime_str );
        // Strip any timezone suffix (Z, +00:00, etc.)
        $normalized = preg_replace( '/[+-]\d{2}:\d{2}$|Z$/', '', $normalized );
        return $normalized;
    }
}
