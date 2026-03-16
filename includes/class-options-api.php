<?php
/**
 * WordPress options read/write REST API endpoint.
 *
 * Exposes a whitelisted set of WordPress options for reading and updating.
 * Only safe, commonly needed options are exposed — no arbitrary option access.
 *
 * GET  /spilt-mcp/v1/options
 * POST /spilt-mcp/v1/options
 * GET  /spilt-mcp/v1/options?keys=blogname,blogdescription
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Spilt_MCP_Options_API {

    /**
     * Whitelisted options — safe to read and write.
     */
    private $readable = array(
        'blogname',
        'blogdescription',
        'siteurl',
        'home',
        'admin_email',
        'timezone_string',
        'gmt_offset',
        'date_format',
        'time_format',
        'posts_per_page',
        'permalink_structure',
        'page_on_front',
        'page_for_posts',
        'show_on_front',
        'blog_public',
        'default_comment_status',
        'default_ping_status',
        'thumbnail_size_w',
        'thumbnail_size_h',
        'medium_size_w',
        'medium_size_h',
        'large_size_w',
        'large_size_h',
        'uploads_use_yearmonth_folders',
        'WPLANG',
        'stylesheet',
        'template',
        'active_plugins',
    );

    /**
     * Writable subset — options that are safe to change programmatically.
     */
    private $writable = array(
        'blogname',
        'blogdescription',
        'timezone_string',
        'gmt_offset',
        'date_format',
        'time_format',
        'posts_per_page',
        'page_on_front',
        'page_for_posts',
        'show_on_front',
        'default_comment_status',
        'default_ping_status',
        'blog_public',
    );

    public function register_routes() {
        register_rest_route( 'spilt-mcp/v1', '/options', array(
            array(
                'methods'             => 'GET',
                'callback'            => array( $this, 'get_options' ),
                'permission_callback' => 'spilt_mcp_admin_check',
                'args'                => array(
                    'keys' => array( 'default' => '', 'sanitize_callback' => 'sanitize_text_field' ),
                ),
            ),
            array(
                'methods'             => 'POST',
                'callback'            => array( $this, 'update_options' ),
                'permission_callback' => 'spilt_mcp_admin_check',
            ),
        ) );
    }

    /**
     * GET: Read whitelisted WordPress options.
     */
    public function get_options( $request ) {
        $keys_param = $request->get_param( 'keys' );

        if ( ! empty( $keys_param ) ) {
            $keys = array_map( 'trim', explode( ',', $keys_param ) );
            $keys = array_intersect( $keys, $this->readable );
        } else {
            $keys = $this->readable;
        }

        $options = array();
        foreach ( $keys as $key ) {
            $value = get_option( $key );
            // Format active_plugins nicely
            if ( $key === 'active_plugins' && is_array( $value ) ) {
                $options[ $key ] = array_map( function( $p ) {
                    return basename( dirname( $p ) ) . '/' . basename( $p );
                }, $value );
            } else {
                $options[ $key ] = $value;
            }
        }

        // Add computed values
        $options['_site_url']   = site_url();
        $options['_home_url']   = home_url();
        $options['_wp_version'] = get_bloginfo( 'version' );

        return rest_ensure_response( $options );
    }

    /**
     * POST: Update whitelisted WordPress options.
     *
     * Body: { "blogname": "New Site Title", "blogdescription": "New tagline" }
     */
    public function update_options( $request ) {
        $body    = $request->get_json_params();
        $updated = array();
        $denied  = array();

        foreach ( $body as $key => $value ) {
            if ( in_array( $key, $this->writable, true ) ) {
                update_option( $key, sanitize_text_field( $value ) );
                $updated[] = $key;
            } elseif ( in_array( $key, $this->readable, true ) ) {
                $denied[] = array( 'key' => $key, 'reason' => 'Read-only option' );
            } else {
                $denied[] = array( 'key' => $key, 'reason' => 'Not in whitelist' );
            }
        }

        if ( empty( $updated ) ) {
            return new WP_Error( 'no_updates', 'No writable options provided.', array( 'status' => 400 ) );
        }

        return rest_ensure_response( array(
            'success' => true,
            'updated' => $updated,
            'denied'  => $denied,
        ) );
    }
}
