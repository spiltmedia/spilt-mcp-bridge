<?php
/**
 * Rewrite rules inspector REST API endpoint.
 *
 * Dump the full WordPress rewrite rules array for permalink debugging,
 * flush rules, and test URL-to-query resolution.
 *
 * GET  /spilt-mcp/v1/rewrite-rules              — dump all rewrite rules
 * POST /spilt-mcp/v1/rewrite-rules/flush        — flush rewrite rules
 * GET  /spilt-mcp/v1/rewrite-rules/test         — test a URL against rules
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Spilt_MCP_Rewrite_API {

    public function register_routes() {
        register_rest_route( 'spilt-mcp/v1', '/rewrite-rules', array(
            'methods'             => 'GET',
            'callback'            => array( $this, 'get_rules' ),
            'permission_callback' => 'spilt_mcp_admin_check',
            'args'                => array(
                'filter' => array( 'default' => '', 'sanitize_callback' => 'sanitize_text_field' ),
            ),
        ) );

        register_rest_route( 'spilt-mcp/v1', '/rewrite-rules/flush', array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'flush_rules' ),
            'permission_callback' => 'spilt_mcp_admin_check',
        ) );

        register_rest_route( 'spilt-mcp/v1', '/rewrite-rules/test', array(
            'methods'             => 'GET',
            'callback'            => array( $this, 'test_url' ),
            'permission_callback' => 'spilt_mcp_admin_check',
            'args'                => array(
                'url' => array( 'required' => true, 'sanitize_callback' => 'sanitize_text_field' ),
            ),
        ) );
    }

    /**
     * GET: Dump all rewrite rules.
     */
    public function get_rules( $request ) {
        global $wp_rewrite;

        $rules  = $wp_rewrite->wp_rewrite_rules();
        $filter = $request->get_param( 'filter' );

        if ( ! is_array( $rules ) ) {
            $rules = get_option( 'rewrite_rules', array() );
        }

        // Filter if requested
        if ( ! empty( $filter ) ) {
            $filtered = array();
            foreach ( $rules as $pattern => $query ) {
                if ( stripos( $pattern, $filter ) !== false || stripos( $query, $filter ) !== false ) {
                    $filtered[ $pattern ] = $query;
                }
            }
            $rules = $filtered;
        }

        return rest_ensure_response( array(
            'total'              => count( $rules ),
            'permalink_structure' => get_option( 'permalink_structure' ),
            'front'              => $wp_rewrite->front,
            'using_permalinks'   => $wp_rewrite->using_permalinks(),
            'rules'              => $rules,
        ) );
    }

    /**
     * POST: Flush rewrite rules.
     */
    public function flush_rules( $request ) {
        $body = $request->get_json_params();
        $hard = isset( $body['hard'] ) ? (bool) $body['hard'] : false;

        flush_rewrite_rules( $hard );

        return rest_ensure_response( array(
            'success' => true,
            'hard'    => $hard,
            'note'    => $hard ? 'Hard flush: .htaccess rewritten.' : 'Soft flush: rewrite rules regenerated in DB.',
        ) );
    }

    /**
     * GET: Test a URL against rewrite rules to see what it resolves to.
     */
    public function test_url( $request ) {
        $url = $request->get_param( 'url' );

        // Strip the home URL to get the path
        $home = home_url( '/' );
        $path = $url;
        if ( strpos( $url, $home ) === 0 ) {
            $path = substr( $url, strlen( $home ) );
        }
        $path = ltrim( $path, '/' );

        // Try to match against rewrite rules
        global $wp_rewrite;
        $rules = $wp_rewrite->wp_rewrite_rules();
        if ( ! is_array( $rules ) ) {
            $rules = get_option( 'rewrite_rules', array() );
        }

        $matched_rule  = null;
        $matched_query = null;

        foreach ( $rules as $pattern => $query ) {
            if ( preg_match( "#^{$pattern}#", $path, $matches ) ) {
                $matched_rule = $pattern;
                // Substitute backreferences
                $matched_query = preg_replace_callback( '/\$matches\[(\d+)\]/', function( $m ) use ( $matches ) {
                    return isset( $matches[ (int) $m[1] ] ) ? $matches[ (int) $m[1] ] : '';
                }, $query );
                break;
            }
        }

        // Also check if url_to_postid resolves it
        $post_id = url_to_postid( $url );
        $post = $post_id ? get_post( $post_id ) : null;

        return rest_ensure_response( array(
            'url'           => $url,
            'path'          => $path,
            'matched_rule'  => $matched_rule,
            'matched_query' => $matched_query,
            'resolves_to'   => $post ? array(
                'post_id' => $post->ID,
                'title'   => $post->post_title,
                'type'    => $post->post_type,
                'status'  => $post->post_status,
            ) : null,
        ) );
    }
}
