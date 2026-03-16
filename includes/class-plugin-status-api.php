<?php
/**
 * Plugin and theme status REST API endpoint.
 *
 * List all installed plugins/themes with versions, update availability,
 * and active/inactive status.
 *
 * GET  /spilt-mcp/v1/plugins                    — list all plugins
 * GET  /spilt-mcp/v1/themes                     — list all themes
 * GET  /spilt-mcp/v1/plugins/updates            — check for plugin updates
 * POST /spilt-mcp/v1/plugins/activate           — activate a plugin
 * POST /spilt-mcp/v1/plugins/deactivate         — deactivate a plugin
 * POST /spilt-mcp/v1/plugins/update             — update a plugin
 * POST /spilt-mcp/v1/themes/switch              — switch active theme
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Spilt_MCP_Plugin_Status_API {

    public function register_routes() {
        register_rest_route( 'spilt-mcp/v1', '/plugins', array(
            'methods'             => 'GET',
            'callback'            => array( $this, 'list_plugins' ),
            'permission_callback' => 'spilt_mcp_admin_check',
        ) );

        register_rest_route( 'spilt-mcp/v1', '/themes', array(
            'methods'             => 'GET',
            'callback'            => array( $this, 'list_themes' ),
            'permission_callback' => 'spilt_mcp_admin_check',
        ) );

        register_rest_route( 'spilt-mcp/v1', '/plugins/updates', array(
            'methods'             => 'GET',
            'callback'            => array( $this, 'check_updates' ),
            'permission_callback' => 'spilt_mcp_admin_check',
        ) );

        register_rest_route( 'spilt-mcp/v1', '/plugins/activate', array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'activate_plugin' ),
            'permission_callback' => 'spilt_mcp_admin_check',
        ) );

        register_rest_route( 'spilt-mcp/v1', '/plugins/deactivate', array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'deactivate_plugin' ),
            'permission_callback' => 'spilt_mcp_admin_check',
        ) );

        register_rest_route( 'spilt-mcp/v1', '/plugins/update', array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'update_plugin' ),
            'permission_callback' => 'spilt_mcp_admin_check',
        ) );

        register_rest_route( 'spilt-mcp/v1', '/themes/switch', array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'switch_theme' ),
            'permission_callback' => 'spilt_mcp_admin_check',
        ) );
    }

    /**
     * GET: List all installed plugins.
     */
    public function list_plugins( $request ) {
        if ( ! function_exists( 'get_plugins' ) ) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        $all_plugins    = get_plugins();
        $active_plugins = get_option( 'active_plugins', array() );
        $update_info    = get_site_transient( 'update_plugins' );

        $plugins = array();
        foreach ( $all_plugins as $file => $data ) {
            $has_update    = false;
            $new_version   = null;

            if ( $update_info && isset( $update_info->response[ $file ] ) ) {
                $has_update  = true;
                $new_version = $update_info->response[ $file ]->new_version;
            }

            $plugins[] = array(
                'file'        => $file,
                'name'        => $data['Name'],
                'version'     => $data['Version'],
                'author'      => $data['Author'],
                'description' => wp_strip_all_tags( $data['Description'] ),
                'active'      => in_array( $file, $active_plugins, true ),
                'has_update'  => $has_update,
                'new_version' => $new_version,
                'plugin_uri'  => $data['PluginURI'],
            );
        }

        // Sort: active first, then by name
        usort( $plugins, function( $a, $b ) {
            if ( $a['active'] !== $b['active'] ) {
                return $b['active'] - $a['active'];
            }
            return strcasecmp( $a['name'], $b['name'] );
        } );

        $active_count = count( array_filter( $plugins, function( $p ) { return $p['active']; } ) );
        $update_count = count( array_filter( $plugins, function( $p ) { return $p['has_update']; } ) );

        return rest_ensure_response( array(
            'total'            => count( $plugins ),
            'active'           => $active_count,
            'inactive'         => count( $plugins ) - $active_count,
            'updates_available' => $update_count,
            'plugins'          => $plugins,
        ) );
    }

    /**
     * GET: List all installed themes.
     */
    public function list_themes( $request ) {
        $all_themes   = wp_get_themes();
        $active_theme = wp_get_theme();
        $update_info  = get_site_transient( 'update_themes' );

        $themes = array();
        foreach ( $all_themes as $slug => $theme ) {
            $has_update  = false;
            $new_version = null;

            if ( $update_info && isset( $update_info->response[ $slug ] ) ) {
                $has_update  = true;
                $new_version = $update_info->response[ $slug ]['new_version'];
            }

            $is_active = ( $slug === $active_theme->get_stylesheet() );
            $is_parent = ( $slug === $active_theme->get_template() && ! $is_active );

            $themes[] = array(
                'slug'        => $slug,
                'name'        => $theme->get( 'Name' ),
                'version'     => $theme->get( 'Version' ),
                'author'      => $theme->get( 'Author' ),
                'active'      => $is_active,
                'parent'      => $is_parent,
                'template'    => $theme->get( 'Template' ),
                'has_update'  => $has_update,
                'new_version' => $new_version,
            );
        }

        // Sort: active first, then parent, then by name
        usort( $themes, function( $a, $b ) {
            if ( $a['active'] !== $b['active'] ) return $b['active'] - $a['active'];
            if ( $a['parent'] !== $b['parent'] ) return $b['parent'] - $a['parent'];
            return strcasecmp( $a['name'], $b['name'] );
        } );

        return rest_ensure_response( array(
            'total'            => count( $themes ),
            'active_theme'     => $active_theme->get( 'Name' ),
            'active_version'   => $active_theme->get( 'Version' ),
            'updates_available' => count( array_filter( $themes, function( $t ) { return $t['has_update']; } ) ),
            'themes'           => $themes,
        ) );
    }

    /**
     * GET: Force-check for plugin updates and return results.
     */
    public function check_updates( $request ) {
        if ( ! function_exists( 'get_plugins' ) ) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        // Force refresh
        delete_site_transient( 'update_plugins' );
        wp_update_plugins();

        $update_info = get_site_transient( 'update_plugins' );
        $updates     = array();

        if ( $update_info && ! empty( $update_info->response ) ) {
            $all_plugins = get_plugins();
            foreach ( $update_info->response as $file => $info ) {
                $current = isset( $all_plugins[ $file ] ) ? $all_plugins[ $file ]['Version'] : 'unknown';
                $updates[] = array(
                    'file'            => $file,
                    'name'            => isset( $all_plugins[ $file ] ) ? $all_plugins[ $file ]['Name'] : $file,
                    'current_version' => $current,
                    'new_version'     => $info->new_version,
                    'package'         => $info->package ?? null,
                );
            }
        }

        return rest_ensure_response( array(
            'updates_available' => count( $updates ),
            'checked'           => current_time( 'mysql' ),
            'updates'           => $updates,
        ) );
    }

    /**
     * POST: Activate a plugin.
     *
     * Body: { "plugin": "akismet/akismet.php" }
     */
    public function activate_plugin( $request ) {
        if ( ! function_exists( 'activate_plugin' ) ) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        $body   = $request->get_json_params();
        $plugin = isset( $body['plugin'] ) ? sanitize_text_field( $body['plugin'] ) : '';

        if ( empty( $plugin ) ) {
            return new WP_Error( 'missing_plugin', 'Provide "plugin" file path (e.g. "akismet/akismet.php").', array( 'status' => 400 ) );
        }

        $all = get_plugins();
        if ( ! isset( $all[ $plugin ] ) ) {
            return new WP_Error( 'not_found', "Plugin '{$plugin}' is not installed.", array( 'status' => 404 ) );
        }

        if ( is_plugin_active( $plugin ) ) {
            return rest_ensure_response( array( 'success' => true, 'note' => 'Already active.' ) );
        }

        $result = activate_plugin( $plugin );

        if ( is_wp_error( $result ) ) {
            return $result;
        }

        return rest_ensure_response( array(
            'success' => true,
            'plugin'  => $plugin,
            'name'    => $all[ $plugin ]['Name'],
        ) );
    }

    /**
     * POST: Deactivate a plugin.
     *
     * Body: { "plugin": "akismet/akismet.php" }
     */
    public function deactivate_plugin( $request ) {
        if ( ! function_exists( 'deactivate_plugins' ) ) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        $body   = $request->get_json_params();
        $plugin = isset( $body['plugin'] ) ? sanitize_text_field( $body['plugin'] ) : '';

        if ( empty( $plugin ) ) {
            return new WP_Error( 'missing_plugin', 'Provide "plugin" file path.', array( 'status' => 400 ) );
        }

        // Don't let the plugin deactivate itself
        if ( $plugin === plugin_basename( SPILT_MCP_PATH . 'spilt-mcp-bridge.php' ) ) {
            return new WP_Error( 'self_deactivate', 'Cannot deactivate the MCP Bridge plugin via API.', array( 'status' => 403 ) );
        }

        if ( ! is_plugin_active( $plugin ) ) {
            return rest_ensure_response( array( 'success' => true, 'note' => 'Already inactive.' ) );
        }

        deactivate_plugins( $plugin );

        return rest_ensure_response( array(
            'success' => true,
            'plugin'  => $plugin,
        ) );
    }

    /**
     * POST: Update a plugin to its latest version.
     *
     * Body: { "plugin": "akismet/akismet.php" }
     */
    public function update_plugin( $request ) {
        if ( ! function_exists( 'get_plugins' ) ) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';

        $body   = $request->get_json_params();
        $plugin = isset( $body['plugin'] ) ? sanitize_text_field( $body['plugin'] ) : '';

        if ( empty( $plugin ) ) {
            return new WP_Error( 'missing_plugin', 'Provide "plugin" file path.', array( 'status' => 400 ) );
        }

        // Force update check
        delete_site_transient( 'update_plugins' );
        wp_update_plugins();
        $update_info = get_site_transient( 'update_plugins' );

        if ( ! isset( $update_info->response[ $plugin ] ) ) {
            return new WP_Error( 'no_update', "No update available for '{$plugin}'.", array( 'status' => 404 ) );
        }

        $skin     = new Automatic_Upgrader_Skin();
        $upgrader = new Plugin_Upgrader( $skin );
        $result   = $upgrader->upgrade( $plugin );

        if ( is_wp_error( $result ) ) {
            return $result;
        }

        if ( $result === false ) {
            return new WP_Error( 'update_failed', 'Plugin update failed. Check filesystem permissions.', array( 'status' => 500 ) );
        }

        // Get new version
        $all = get_plugins();
        $new_version = isset( $all[ $plugin ] ) ? $all[ $plugin ]['Version'] : 'unknown';

        return rest_ensure_response( array(
            'success'     => true,
            'plugin'      => $plugin,
            'new_version' => $new_version,
        ) );
    }

    /**
     * POST: Switch active theme.
     *
     * Body: { "theme": "hello-elementor-child" }
     */
    public function switch_theme( $request ) {
        $body  = $request->get_json_params();
        $theme = isset( $body['theme'] ) ? sanitize_text_field( $body['theme'] ) : '';

        if ( empty( $theme ) ) {
            return new WP_Error( 'missing_theme', 'Provide "theme" slug.', array( 'status' => 400 ) );
        }

        $theme_obj = wp_get_theme( $theme );
        if ( ! $theme_obj->exists() ) {
            return new WP_Error( 'not_found', "Theme '{$theme}' is not installed.", array( 'status' => 404 ) );
        }

        $old_theme = wp_get_theme()->get_stylesheet();
        switch_theme( $theme );

        return rest_ensure_response( array(
            'success'        => true,
            'old_theme'      => $old_theme,
            'new_theme'      => $theme,
            'new_theme_name' => $theme_obj->get( 'Name' ),
        ) );
    }
}
