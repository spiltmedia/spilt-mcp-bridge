<?php
/**
 * REST API route index/self-discovery endpoint.
 *
 * Lists every registered REST API route on the site, grouped by namespace.
 * Useful for discovering what endpoints are available.
 *
 * GET /spilt-mcp/v1/route-index                 — list all REST routes
 * GET /spilt-mcp/v1/route-index?namespace=spilt-mcp/v1  — filter by namespace
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Spilt_MCP_Route_Index_API {

    public function register_routes() {
        register_rest_route( 'spilt-mcp/v1', '/route-index', array(
            'methods'             => 'GET',
            'callback'            => array( $this, 'get_index' ),
            'permission_callback' => 'spilt_mcp_admin_check',
            'args'                => array(
                'namespace' => array( 'default' => '', 'sanitize_callback' => 'sanitize_text_field' ),
            ),
        ) );
    }

    /**
     * GET: List all registered REST API routes.
     */
    public function get_index( $request ) {
        $server     = rest_get_server();
        $all_routes = $server->get_routes();
        $ns_filter  = $request->get_param( 'namespace' );

        $namespaces = $server->get_namespaces();
        $routes     = array();

        foreach ( $all_routes as $path => $handlers ) {
            // Determine namespace
            $ns = '';
            foreach ( $namespaces as $namespace ) {
                if ( strpos( $path, '/' . $namespace ) === 0 ) {
                    $ns = $namespace;
                    break;
                }
            }

            if ( ! empty( $ns_filter ) && $ns !== $ns_filter ) {
                continue;
            }

            $methods = array();
            foreach ( $handlers as $handler ) {
                if ( isset( $handler['methods'] ) ) {
                    $methods = array_merge( $methods, array_keys( $handler['methods'] ) );
                }
            }
            $methods = array_unique( $methods );

            $routes[] = array(
                'path'      => $path,
                'namespace' => $ns,
                'methods'   => array_values( $methods ),
            );
        }

        // Group by namespace for overview
        $by_namespace = array();
        foreach ( $routes as $route ) {
            $ns = $route['namespace'] ?: '(root)';
            if ( ! isset( $by_namespace[ $ns ] ) ) {
                $by_namespace[ $ns ] = 0;
            }
            $by_namespace[ $ns ]++;
        }

        return rest_ensure_response( array(
            'total_routes'   => count( $routes ),
            'namespaces'     => $by_namespace,
            'filter'         => $ns_filter ?: null,
            'routes'         => $routes,
        ) );
    }
}
