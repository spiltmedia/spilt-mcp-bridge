<?php
/**
 * Cache management REST API endpoints.
 *
 * Handles LiteSpeed cache purge operations.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Spilt_MCP_Cache_API {

    public function register_routes() {
        register_rest_route( 'spilt-mcp/v1', '/cache/litespeed/purge', array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'purge_litespeed' ),
            'permission_callback' => 'spilt_mcp_admin_check',
        ) );
    }

    /**
     * POST: Purge LiteSpeed cache.
     */
    public function purge_litespeed( $request ) {
        $body = $request->get_json_params();
        $scope = isset( $body['scope'] ) ? $body['scope'] : 'all';

        // Check if LiteSpeed Cache plugin is active
        if ( ! defined( 'LSCWP_V' ) ) {
            return new WP_Error(
                'no_litespeed',
                'LiteSpeed Cache plugin is not active.',
                array( 'status' => 400 )
            );
        }

        if ( $scope === 'page' && ! empty( $body['post_id'] ) ) {
            // Purge a single post
            $post_id = (int) $body['post_id'];
            do_action( 'litespeed_purge_post', $post_id );
            return rest_ensure_response( array(
                'success' => true,
                'message' => "LiteSpeed cache purged for post {$post_id}.",
            ) );
        }

        // Purge all
        do_action( 'litespeed_purge_all' );

        return rest_ensure_response( array(
            'success' => true,
            'message' => 'LiteSpeed cache purged (all).',
        ) );
    }
}
