<?php
/**
 * Widget and sidebar management REST API endpoint.
 *
 * List sidebars, list widgets, add/update/remove widgets from sidebars.
 *
 * GET    /spilt-mcp/v1/widgets                  — list all sidebars and their widgets
 * GET    /spilt-mcp/v1/widgets/available         — list all registered widget types
 * POST   /spilt-mcp/v1/widgets                  — add a widget to a sidebar
 * PUT    /spilt-mcp/v1/widgets/(?P<id>.+)       — update a widget's settings
 * DELETE /spilt-mcp/v1/widgets/(?P<id>.+)       — remove a widget from its sidebar
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Spilt_MCP_Widgets_API {

    public function register_routes() {
        register_rest_route( 'spilt-mcp/v1', '/widgets', array(
            array(
                'methods'             => 'GET',
                'callback'            => array( $this, 'list_widgets' ),
                'permission_callback' => 'spilt_mcp_admin_check',
            ),
            array(
                'methods'             => 'POST',
                'callback'            => array( $this, 'add_widget' ),
                'permission_callback' => 'spilt_mcp_admin_check',
            ),
        ) );

        register_rest_route( 'spilt-mcp/v1', '/widgets/available', array(
            'methods'             => 'GET',
            'callback'            => array( $this, 'available_widgets' ),
            'permission_callback' => 'spilt_mcp_admin_check',
        ) );

        register_rest_route( 'spilt-mcp/v1', '/widgets/(?P<id>.+)', array(
            array(
                'methods'             => 'PUT',
                'callback'            => array( $this, 'update_widget' ),
                'permission_callback' => 'spilt_mcp_admin_check',
            ),
            array(
                'methods'             => 'DELETE',
                'callback'            => array( $this, 'remove_widget' ),
                'permission_callback' => 'spilt_mcp_admin_check',
            ),
        ) );
    }

    /**
     * GET: List all sidebars and their active widgets.
     */
    public function list_widgets( $request ) {
        global $wp_registered_sidebars;

        $sidebars_widgets = wp_get_sidebars_widgets();
        $result = array();

        foreach ( $wp_registered_sidebars as $sidebar_id => $sidebar ) {
            $widgets = array();

            if ( isset( $sidebars_widgets[ $sidebar_id ] ) ) {
                foreach ( $sidebars_widgets[ $sidebar_id ] as $widget_id ) {
                    $widgets[] = $this->get_widget_data( $widget_id );
                }
            }

            $result[] = array(
                'id'           => $sidebar_id,
                'name'         => $sidebar['name'],
                'description'  => $sidebar['description'],
                'widget_count' => count( $widgets ),
                'widgets'      => $widgets,
            );
        }

        // Include inactive widgets
        $inactive = array();
        if ( isset( $sidebars_widgets['wp_inactive_widgets'] ) ) {
            foreach ( $sidebars_widgets['wp_inactive_widgets'] as $widget_id ) {
                $inactive[] = $this->get_widget_data( $widget_id );
            }
        }

        return rest_ensure_response( array(
            'sidebars' => $result,
            'inactive' => $inactive,
        ) );
    }

    /**
     * GET: List all registered widget types.
     */
    public function available_widgets( $request ) {
        global $wp_widget_factory;

        $available = array();
        foreach ( $wp_widget_factory->widgets as $class => $widget ) {
            $available[] = array(
                'id_base'     => $widget->id_base,
                'name'        => $widget->name,
                'description' => isset( $widget->widget_options['description'] ) ? $widget->widget_options['description'] : '',
                'class'       => $class,
            );
        }

        return rest_ensure_response( array(
            'total'   => count( $available ),
            'widgets' => $available,
        ) );
    }

    /**
     * POST: Add a widget to a sidebar.
     *
     * Body: {
     *   "sidebar": "sidebar-1",
     *   "id_base": "text",
     *   "settings": { "title": "My Widget", "text": "Hello world" },
     *   "position": 0
     * }
     */
    public function add_widget( $request ) {
        $body     = $request->get_json_params();
        $sidebar  = isset( $body['sidebar'] ) ? sanitize_text_field( $body['sidebar'] ) : '';
        $id_base  = isset( $body['id_base'] ) ? sanitize_text_field( $body['id_base'] ) : '';
        $settings = isset( $body['settings'] ) ? (array) $body['settings'] : array();
        $position = isset( $body['position'] ) ? absint( $body['position'] ) : null;

        if ( empty( $sidebar ) || empty( $id_base ) ) {
            return new WP_Error( 'missing_params', 'Provide "sidebar" and "id_base".', array( 'status' => 400 ) );
        }

        // Get current instances for this widget type
        $all_instances = get_option( "widget_{$id_base}", array() );

        // Find next instance number
        $next = 2; // WordPress widgets start at 2
        if ( ! empty( $all_instances ) ) {
            $next = max( array_keys( $all_instances ) ) + 1;
        }

        // Save the instance settings
        $all_instances[ $next ] = $settings;
        update_option( "widget_{$id_base}", $all_instances );

        // Add to sidebar
        $widget_id = $id_base . '-' . $next;
        $sidebars_widgets = wp_get_sidebars_widgets();

        if ( ! isset( $sidebars_widgets[ $sidebar ] ) ) {
            $sidebars_widgets[ $sidebar ] = array();
        }

        if ( $position !== null && $position < count( $sidebars_widgets[ $sidebar ] ) ) {
            array_splice( $sidebars_widgets[ $sidebar ], $position, 0, array( $widget_id ) );
        } else {
            $sidebars_widgets[ $sidebar ][] = $widget_id;
        }

        wp_set_sidebars_widgets( $sidebars_widgets );

        return rest_ensure_response( array(
            'success'   => true,
            'widget_id' => $widget_id,
            'sidebar'   => $sidebar,
            'settings'  => $settings,
        ) );
    }

    /**
     * PUT: Update a widget's settings.
     *
     * Body: { "settings": { "title": "Updated Title" } }
     */
    public function update_widget( $request ) {
        $widget_id = $request['id'];
        $body      = $request->get_json_params();
        $settings  = isset( $body['settings'] ) ? (array) $body['settings'] : array();

        if ( empty( $settings ) ) {
            return new WP_Error( 'no_settings', 'Provide "settings" to update.', array( 'status' => 400 ) );
        }

        $parsed = $this->parse_widget_id( $widget_id );
        if ( ! $parsed ) {
            return new WP_Error( 'invalid_id', "Invalid widget ID: {$widget_id}", array( 'status' => 400 ) );
        }

        $all_instances = get_option( "widget_{$parsed['id_base']}", array() );

        if ( ! isset( $all_instances[ $parsed['number'] ] ) ) {
            return new WP_Error( 'not_found', "Widget instance not found.", array( 'status' => 404 ) );
        }

        // Merge settings
        $all_instances[ $parsed['number'] ] = array_merge( $all_instances[ $parsed['number'] ], $settings );
        update_option( "widget_{$parsed['id_base']}", $all_instances );

        return rest_ensure_response( array(
            'success'   => true,
            'widget_id' => $widget_id,
            'settings'  => $all_instances[ $parsed['number'] ],
        ) );
    }

    /**
     * DELETE: Remove a widget from its sidebar (moves to inactive).
     */
    public function remove_widget( $request ) {
        $widget_id = $request['id'];

        $sidebars_widgets = wp_get_sidebars_widgets();
        $found_in = null;

        foreach ( $sidebars_widgets as $sidebar => $widgets ) {
            if ( is_array( $widgets ) && in_array( $widget_id, $widgets, true ) ) {
                $found_in = $sidebar;
                $sidebars_widgets[ $sidebar ] = array_values( array_diff( $widgets, array( $widget_id ) ) );
                break;
            }
        }

        if ( ! $found_in ) {
            return new WP_Error( 'not_found', "Widget {$widget_id} not found in any sidebar.", array( 'status' => 404 ) );
        }

        // Move to inactive
        if ( ! isset( $sidebars_widgets['wp_inactive_widgets'] ) ) {
            $sidebars_widgets['wp_inactive_widgets'] = array();
        }
        $sidebars_widgets['wp_inactive_widgets'][] = $widget_id;

        wp_set_sidebars_widgets( $sidebars_widgets );

        return rest_ensure_response( array(
            'success'      => true,
            'widget_id'    => $widget_id,
            'removed_from' => $found_in,
        ) );
    }

    /**
     * Get widget data by ID.
     */
    private function get_widget_data( $widget_id ) {
        $parsed = $this->parse_widget_id( $widget_id );
        if ( ! $parsed ) {
            return array( 'id' => $widget_id, 'type' => 'unknown' );
        }

        $instances = get_option( "widget_{$parsed['id_base']}", array() );
        $settings  = isset( $instances[ $parsed['number'] ] ) ? $instances[ $parsed['number'] ] : array();

        return array(
            'id'       => $widget_id,
            'id_base'  => $parsed['id_base'],
            'number'   => $parsed['number'],
            'settings' => $settings,
        );
    }

    /**
     * Parse a widget ID like "text-3" into id_base and number.
     */
    private function parse_widget_id( $widget_id ) {
        if ( preg_match( '/^(.+)-(\d+)$/', $widget_id, $m ) ) {
            return array( 'id_base' => $m[1], 'number' => (int) $m[2] );
        }
        return null;
    }
}
