<?php
/**
 * Theme customizer / theme_mods REST API endpoint.
 *
 * Read and write theme modification settings (site identity, colors,
 * layout options, custom CSS, etc.).
 *
 * GET  /spilt-mcp/v1/theme-mods                — read all theme mods
 * POST /spilt-mcp/v1/theme-mods                — update theme mods
 * GET  /spilt-mcp/v1/theme-mods/custom-css     — read custom CSS
 * POST /spilt-mcp/v1/theme-mods/custom-css     — write custom CSS
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Spilt_MCP_Theme_Mods_API {

    public function register_routes() {
        register_rest_route( 'spilt-mcp/v1', '/theme-mods', array(
            array(
                'methods'             => 'GET',
                'callback'            => array( $this, 'get_mods' ),
                'permission_callback' => 'spilt_mcp_admin_check',
            ),
            array(
                'methods'             => 'POST',
                'callback'            => array( $this, 'set_mods' ),
                'permission_callback' => 'spilt_mcp_admin_check',
            ),
        ) );

        register_rest_route( 'spilt-mcp/v1', '/theme-mods/custom-css', array(
            array(
                'methods'             => 'GET',
                'callback'            => array( $this, 'get_custom_css' ),
                'permission_callback' => 'spilt_mcp_admin_check',
            ),
            array(
                'methods'             => 'POST',
                'callback'            => array( $this, 'set_custom_css' ),
                'permission_callback' => 'spilt_mcp_admin_check',
            ),
        ) );
    }

    /**
     * GET: Read all theme mods for the active theme.
     */
    public function get_mods( $request ) {
        $theme = wp_get_theme();
        $mods  = get_theme_mods();

        // Clean up internal keys
        if ( is_array( $mods ) ) {
            unset( $mods[0] ); // WordPress sometimes adds a 0 key
        }

        // Get site identity separately for convenience
        $site_icon_id = get_option( 'site_icon' );
        $custom_logo  = get_theme_mod( 'custom_logo' );

        $identity = array(
            'site_title'       => get_bloginfo( 'name' ),
            'tagline'          => get_bloginfo( 'description' ),
            'site_icon_id'     => (int) $site_icon_id,
            'site_icon_url'    => $site_icon_id ? wp_get_attachment_url( $site_icon_id ) : null,
            'custom_logo_id'   => (int) $custom_logo,
            'custom_logo_url'  => $custom_logo ? wp_get_attachment_url( $custom_logo ) : null,
        );

        return rest_ensure_response( array(
            'theme'         => $theme->get( 'Name' ),
            'theme_slug'    => $theme->get_stylesheet(),
            'site_identity' => $identity,
            'mods'          => $mods ?: new stdClass(),
        ) );
    }

    /**
     * POST: Update theme mods.
     *
     * Body: { "custom_logo": 1234, "header_text": false, "background_color": "ffffff" }
     */
    public function set_mods( $request ) {
        $body    = $request->get_json_params();
        $updated = array();

        foreach ( $body as $key => $value ) {
            set_theme_mod( sanitize_text_field( $key ), $value );
            $updated[] = $key;
        }

        if ( empty( $updated ) ) {
            return new WP_Error( 'no_mods', 'Provide theme mod key-value pairs.', array( 'status' => 400 ) );
        }

        return rest_ensure_response( array(
            'success' => true,
            'updated' => $updated,
        ) );
    }

    /**
     * GET: Read custom CSS (Additional CSS from Customizer).
     */
    public function get_custom_css( $request ) {
        $css = wp_get_custom_css();

        // Also check for custom CSS post
        $post = wp_get_custom_css_post();

        return rest_ensure_response( array(
            'css'      => $css,
            'length'   => strlen( $css ),
            'post_id'  => $post ? $post->ID : null,
            'modified' => $post ? $post->post_modified : null,
        ) );
    }

    /**
     * POST: Write custom CSS.
     *
     * Body: { "css": "body { background: #fff; }" }
     * Or:   { "css": "body { background: #fff; }", "append": true }
     */
    public function set_custom_css( $request ) {
        $body   = $request->get_json_params();
        $css    = isset( $body['css'] ) ? $body['css'] : null;
        $append = isset( $body['append'] ) ? (bool) $body['append'] : false;

        if ( $css === null ) {
            return new WP_Error( 'no_css', 'Provide "css" content.', array( 'status' => 400 ) );
        }

        if ( $append ) {
            $existing = wp_get_custom_css();
            $css = $existing . "\n\n" . $css;
        }

        $result = wp_update_custom_css_post( $css );

        if ( is_wp_error( $result ) ) {
            return $result;
        }

        return rest_ensure_response( array(
            'success' => true,
            'length'  => strlen( $css ),
            'post_id' => $result->ID,
            'append'  => $append,
        ) );
    }
}
