<?php
/**
 * Robots.txt REST API endpoints.
 *
 * Reads the current robots.txt output and allows overriding it via a WP option.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Spilt_MCP_RobotsTxt_API {

    public function register_routes() {
        register_rest_route( 'spilt-mcp/v1', '/robotstxt', array(
            array(
                'methods'             => 'GET',
                'callback'            => array( $this, 'get_robotstxt' ),
                'permission_callback' => 'spilt_mcp_admin_check',
            ),
            array(
                'methods'             => 'POST',
                'callback'            => array( $this, 'update_robotstxt' ),
                'permission_callback' => 'spilt_mcp_admin_check',
            ),
        ) );
    }

    /**
     * GET: Read current robots.txt content.
     */
    public function get_robotstxt( $request ) {
        // Check for custom override first
        $custom = get_option( 'spilt_mcp_robots_txt', '' );

        if ( $custom ) {
            return rest_ensure_response( array(
                'content' => $custom,
                'source'  => 'custom',
            ) );
        }

        // Generate default WordPress robots.txt
        ob_start();
        do_action( 'do_robotstxt' );
        $output = ob_get_clean();

        // Also get the filtered content
        $default  = "User-agent: *\n";
        $default .= "Disallow: /wp-admin/\n";
        $default .= "Allow: /wp-admin/admin-ajax.php\n";

        // Apply WP's robots_txt filter
        $site_url = parse_url( site_url(), PHP_URL_HOST );
        $filtered = apply_filters( 'robots_txt', $default, true );

        return rest_ensure_response( array(
            'content' => $filtered,
            'source'  => 'wordpress_default',
        ) );
    }

    /**
     * POST: Save custom robots.txt content.
     */
    public function update_robotstxt( $request ) {
        $body    = $request->get_json_params();
        $content = isset( $body['content'] ) ? $body['content'] : '';

        if ( empty( $content ) ) {
            // Remove custom override, revert to WP default
            delete_option( 'spilt_mcp_robots_txt' );
            return rest_ensure_response( array(
                'success' => true,
                'message' => 'Custom robots.txt removed. Reverted to WordPress default.',
            ) );
        }

        update_option( 'spilt_mcp_robots_txt', $content );

        return rest_ensure_response( array(
            'success' => true,
            'message' => 'robots.txt updated.',
        ) );
    }
}

/**
 * Hook into robots_txt filter to serve custom content when set.
 */
add_filter( 'robots_txt', function ( $output, $public ) {
    $custom = get_option( 'spilt_mcp_robots_txt', '' );
    if ( $custom ) {
        return $custom;
    }
    return $output;
}, 999, 2 );
