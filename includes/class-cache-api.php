<?php
/**
 * Cache management REST API endpoints.
 *
 * Handles generic cache clear operations and LiteSpeed-specific
 * cache purge and preset application.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Spilt_MCP_Cache_API {

    public function register_routes() {

        // Generic WP object cache purge
        register_rest_route( 'spilt-mcp/v1', '/cache/purge', array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'purge_cache' ),
            'permission_callback' => 'spilt_mcp_admin_check',
        ) );

        // LiteSpeed cache purge (triggers LS action hooks from within WP context)
        register_rest_route( 'spilt-mcp/v1', '/cache/litespeed/purge', array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'purge_litespeed' ),
            'permission_callback' => 'spilt_mcp_admin_check',
        ) );

        // LiteSpeed preset apply (calls LS PHP class directly — no SQL)
        register_rest_route( 'spilt-mcp/v1', '/cache/litespeed/preset', array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'apply_litespeed_preset' ),
            'permission_callback' => 'spilt_mcp_admin_check',
        ) );
    }

    /**
     * POST /cache/purge
     * Clear generic WP object cache layers.
     */
    public function purge_cache( $request ) {
        $body  = $request->get_json_params();
        $scope = isset( $body['scope'] ) ? $body['scope'] : 'all';

        if ( $scope === 'page' && ! empty( $body['post_id'] ) ) {
            $post_id = (int) $body['post_id'];
            clean_post_cache( $post_id );
            do_action( 'spilt_mcp_purge_page_cache', $post_id, $body );
            return rest_ensure_response( array(
                'success' => true,
                'message' => "Page cache cleared for post {$post_id}.",
            ) );
        }

        wp_cache_flush();
        do_action( 'spilt_mcp_purge_cache', $body );

        return rest_ensure_response( array(
            'success' => true,
            'message' => 'Cache cleared.',
        ) );
    }

    /**
     * POST /cache/litespeed/purge
     * Purge all LiteSpeed cache by calling LS action hooks from within WP context.
     */
    public function purge_litespeed( $request ) {
        if ( ! defined( 'LSCWP_V' ) ) {
            return new WP_Error( 'ls_not_active', 'LiteSpeed Cache plugin is not active.', array( 'status' => 400 ) );
        }

        // Fire the standard LiteSpeed purge-all action hooks
        do_action( 'litespeed_purge_all' );
        do_action( 'litespeed_api_purge_all' );

        // Also flush WP object cache
        wp_cache_flush();

        return rest_ensure_response( array(
            'success' => true,
            'message' => 'LiteSpeed cache purged.',
            'version' => LSCWP_V,
        ) );
    }

    /**
     * POST /cache/litespeed/preset
     * Apply a LiteSpeed preset (default: essentials) using LiteSpeed's own PHP classes.
     *
     * Body: { "preset": "essentials" }   (or basic / standard / advanced)
     */
    public function apply_litespeed_preset( $request ) {
        $body   = $request->get_json_params();
        $preset = isset( $body['preset'] ) ? sanitize_text_field( $body['preset'] ) : 'essentials';

        if ( ! defined( 'LSCWP_V' ) ) {
            return new WP_Error( 'ls_not_active', 'LiteSpeed Cache plugin is not active.', array( 'status' => 400 ) );
        }

        $applied     = false;
        $method_used = '';

        // LiteSpeed v6+: LiteSpeed\Preset singleton
        if ( class_exists( 'LiteSpeed\\Preset' ) ) {
            $preset_cls = LiteSpeed\Preset::cls();
            foreach ( array( 'apply', 'preset_apply', 'load_preset', 'apply_preset' ) as $method ) {
                if ( method_exists( $preset_cls, $method ) ) {
                    call_user_func( array( $preset_cls, $method ), $preset );
                    $applied     = true;
                    $method_used = 'LiteSpeed\\Preset::' . $method . '(' . $preset . ')';
                    break;
                }
            }
        }

        // Fallback: fire the action hook LiteSpeed may listen to
        if ( ! $applied ) {
            do_action( 'litespeed_load_preset', $preset );
            $summary = get_option( 'litespeed.preset._summary', '' );
            if ( is_string( $summary ) && strpos( $summary, '"' . $preset . '"' ) !== false ) {
                $applied     = true;
                $method_used = 'do_action:litespeed_load_preset';
            }
        }

        if ( ! $applied ) {
            return new WP_Error(
                'ls_preset_no_method',
                'LiteSpeed is active but no preset method was found. Version: ' . LSCWP_V,
                array( 'status' => 500 )
            );
        }

        // Read back the preset summary so the caller can confirm
        $summary_raw = get_option( 'litespeed.preset._summary', '' );
        $summary     = is_string( $summary_raw ) ? json_decode( $summary_raw, true ) : $summary_raw;

        return rest_ensure_response( array(
            'success'        => true,
            'preset'         => $preset,
            'method_used'    => $method_used,
            'preset_summary' => $summary,
            'ls_version'     => LSCWP_V,
        ) );
    }
}
