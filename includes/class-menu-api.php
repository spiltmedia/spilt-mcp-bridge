<?php
/**
 * Menu management REST API endpoint.
 *
 * Read and update WordPress navigation menus.
 *
 * GET  /spilt-mcp/v1/menus                       — list all menus with locations
 * GET  /spilt-mcp/v1/menus/(?P<id>\d+)           — get a menu's full item tree
 * POST /spilt-mcp/v1/menus/(?P<id>\d+)/items     — add item(s) to a menu
 * PUT  /spilt-mcp/v1/menus/items/(?P<id>\d+)     — update a menu item
 * DELETE /spilt-mcp/v1/menus/items/(?P<id>\d+)   — delete a menu item
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Spilt_MCP_Menu_API {

    public function register_routes() {
        // List all menus
        register_rest_route( 'spilt-mcp/v1', '/menus', array(
            'methods'             => 'GET',
            'callback'            => array( $this, 'list_menus' ),
            'permission_callback' => 'spilt_mcp_admin_check',
        ) );

        // Get single menu with items
        register_rest_route( 'spilt-mcp/v1', '/menus/(?P<id>\d+)', array(
            'methods'             => 'GET',
            'callback'            => array( $this, 'get_menu' ),
            'permission_callback' => 'spilt_mcp_admin_check',
        ) );

        // Add items to menu
        register_rest_route( 'spilt-mcp/v1', '/menus/(?P<id>\d+)/items', array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'add_items' ),
            'permission_callback' => 'spilt_mcp_admin_check',
        ) );

        // Update menu item
        register_rest_route( 'spilt-mcp/v1', '/menus/items/(?P<id>\d+)', array(
            array(
                'methods'             => 'PUT',
                'callback'            => array( $this, 'update_item' ),
                'permission_callback' => 'spilt_mcp_admin_check',
            ),
            array(
                'methods'             => 'DELETE',
                'callback'            => array( $this, 'delete_item' ),
                'permission_callback' => 'spilt_mcp_admin_check',
            ),
        ) );
    }

    /**
     * GET: List all menus and their assigned locations.
     */
    public function list_menus( $request ) {
        $menus     = wp_get_nav_menus();
        $locations = get_nav_menu_locations();
        $registered = get_registered_nav_menus();

        $result = array();
        foreach ( $menus as $menu ) {
            $assigned_locations = array();
            foreach ( $locations as $loc_slug => $menu_id ) {
                if ( $menu_id === $menu->term_id ) {
                    $assigned_locations[] = array(
                        'slug'  => $loc_slug,
                        'label' => isset( $registered[ $loc_slug ] ) ? $registered[ $loc_slug ] : $loc_slug,
                    );
                }
            }

            $items = wp_get_nav_menu_items( $menu->term_id );

            $result[] = array(
                'id'         => $menu->term_id,
                'name'       => $menu->name,
                'slug'       => $menu->slug,
                'item_count' => is_array( $items ) ? count( $items ) : 0,
                'locations'  => $assigned_locations,
            );
        }

        return rest_ensure_response( array(
            'menus'               => $result,
            'registered_locations' => $registered,
        ) );
    }

    /**
     * GET: Get a single menu with its full item tree.
     */
    public function get_menu( $request ) {
        $menu_id = (int) $request['id'];
        $menu    = wp_get_nav_menu_object( $menu_id );

        if ( ! $menu ) {
            return new WP_Error( 'not_found', "Menu {$menu_id} not found.", array( 'status' => 404 ) );
        }

        $items = wp_get_nav_menu_items( $menu_id );
        $tree  = $this->build_tree( $items ?: array() );

        return rest_ensure_response( array(
            'id'     => $menu->term_id,
            'name'   => $menu->name,
            'items'  => $tree,
        ) );
    }

    /**
     * POST: Add items to a menu.
     *
     * Body: {
     *   "items": [
     *     { "title": "Blog", "url": "/blog/", "type": "custom", "parent": 0, "position": 5 },
     *     { "title": "About", "object_id": 42, "type": "page" }
     *   ]
     * }
     */
    public function add_items( $request ) {
        $menu_id = (int) $request['id'];
        $body    = $request->get_json_params();
        $items   = isset( $body['items'] ) ? (array) $body['items'] : array();

        if ( ! wp_get_nav_menu_object( $menu_id ) ) {
            return new WP_Error( 'not_found', "Menu {$menu_id} not found.", array( 'status' => 404 ) );
        }

        if ( empty( $items ) ) {
            return new WP_Error( 'no_items', 'Provide an "items" array.', array( 'status' => 400 ) );
        }

        $created = array();
        $errors  = array();

        foreach ( $items as $item ) {
            $title     = isset( $item['title'] ) ? sanitize_text_field( $item['title'] ) : '';
            $url       = isset( $item['url'] ) ? esc_url_raw( $item['url'] ) : '';
            $type      = isset( $item['type'] ) ? sanitize_text_field( $item['type'] ) : 'custom';
            $object_id = isset( $item['object_id'] ) ? absint( $item['object_id'] ) : 0;
            $parent    = isset( $item['parent'] ) ? absint( $item['parent'] ) : 0;
            $position  = isset( $item['position'] ) ? absint( $item['position'] ) : 0;

            $item_data = array(
                'menu-item-title'     => $title,
                'menu-item-url'       => $url,
                'menu-item-status'    => 'publish',
                'menu-item-parent-id' => $parent,
                'menu-item-position'  => $position,
            );

            if ( $type === 'custom' ) {
                $item_data['menu-item-type'] = 'custom';
            } elseif ( $type === 'page' ) {
                $item_data['menu-item-type']      = 'post_type';
                $item_data['menu-item-object']     = 'page';
                $item_data['menu-item-object-id']  = $object_id;
            } elseif ( $type === 'post' ) {
                $item_data['menu-item-type']      = 'post_type';
                $item_data['menu-item-object']     = 'post';
                $item_data['menu-item-object-id']  = $object_id;
            } elseif ( $type === 'category' ) {
                $item_data['menu-item-type']      = 'taxonomy';
                $item_data['menu-item-object']     = 'category';
                $item_data['menu-item-object-id']  = $object_id;
            }

            $result = wp_update_nav_menu_item( $menu_id, 0, $item_data );

            if ( is_wp_error( $result ) ) {
                $errors[] = array( 'title' => $title, 'error' => $result->get_error_message() );
            } else {
                $created[] = array( 'menu_item_id' => $result, 'title' => $title );
            }
        }

        return rest_ensure_response( array(
            'success' => true,
            'created' => count( $created ),
            'failed'  => count( $errors ),
            'items'   => $created,
            'errors'  => $errors,
        ) );
    }

    /**
     * PUT: Update a menu item.
     */
    public function update_item( $request ) {
        $item_id = (int) $request['id'];
        $body    = $request->get_json_params();

        $item = get_post( $item_id );
        if ( ! $item || $item->post_type !== 'nav_menu_item' ) {
            return new WP_Error( 'not_found', "Menu item {$item_id} not found.", array( 'status' => 404 ) );
        }

        // Get current menu for this item
        $menus = wp_get_object_terms( $item_id, 'nav_menu' );
        $menu_id = ! empty( $menus ) ? $menus[0]->term_id : 0;

        $item_data = array();
        if ( isset( $body['title'] ) )    $item_data['menu-item-title']     = sanitize_text_field( $body['title'] );
        if ( isset( $body['url'] ) )      $item_data['menu-item-url']       = esc_url_raw( $body['url'] );
        if ( isset( $body['parent'] ) )   $item_data['menu-item-parent-id'] = absint( $body['parent'] );
        if ( isset( $body['position'] ) ) $item_data['menu-item-position']  = absint( $body['position'] );
        $item_data['menu-item-status'] = 'publish';

        $result = wp_update_nav_menu_item( $menu_id, $item_id, $item_data );

        if ( is_wp_error( $result ) ) {
            return $result;
        }

        return rest_ensure_response( array( 'success' => true, 'menu_item_id' => $result ) );
    }

    /**
     * DELETE: Remove a menu item.
     */
    public function delete_item( $request ) {
        $item_id = (int) $request['id'];
        $result  = wp_delete_post( $item_id, true );

        if ( ! $result ) {
            return new WP_Error( 'delete_failed', "Could not delete menu item {$item_id}.", array( 'status' => 500 ) );
        }

        return rest_ensure_response( array( 'success' => true, 'deleted_id' => $item_id ) );
    }

    /**
     * Build a hierarchical tree from flat menu items.
     */
    private function build_tree( $items ) {
        $flat = array();
        foreach ( $items as $item ) {
            $flat[] = array(
                'id'        => (int) $item->ID,
                'title'     => $item->title,
                'url'       => $item->url,
                'type'      => $item->type,
                'object'    => $item->object,
                'object_id' => (int) $item->object_id,
                'parent'    => (int) $item->menu_item_parent,
                'position'  => (int) $item->menu_order,
                'target'    => $item->target,
                'classes'   => array_filter( $item->classes ),
                'children'  => array(),
            );
        }

        // Build tree
        $tree    = array();
        $by_id   = array();
        foreach ( $flat as &$node ) {
            $by_id[ $node['id'] ] = &$node;
        }
        foreach ( $flat as &$node ) {
            if ( $node['parent'] && isset( $by_id[ $node['parent'] ] ) ) {
                $by_id[ $node['parent'] ]['children'][] = &$node;
            } else {
                $tree[] = &$node;
            }
        }

        return $tree;
    }
}
