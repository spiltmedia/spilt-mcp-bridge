<?php
/**
 * Google Search Console proxy REST API endpoint.
 *
 * Reads GSC data through Google Site Kit if installed, otherwise
 * returns guidance on how to connect. Provides search performance,
 * index coverage, and sitemap status.
 *
 * GET /spilt-mcp/v1/gsc/performance            — search analytics data
 * GET /spilt-mcp/v1/gsc/index-status            — index coverage summary
 * GET /spilt-mcp/v1/gsc/sitemaps                — submitted sitemaps
 * GET /spilt-mcp/v1/gsc/status                  — connection status
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Spilt_MCP_GSC_Proxy_API {

    public function register_routes() {
        register_rest_route( 'spilt-mcp/v1', '/gsc/status', array(
            'methods'             => 'GET',
            'callback'            => array( $this, 'get_status' ),
            'permission_callback' => 'spilt_mcp_admin_check',
        ) );

        register_rest_route( 'spilt-mcp/v1', '/gsc/performance', array(
            'methods'             => 'GET',
            'callback'            => array( $this, 'get_performance' ),
            'permission_callback' => 'spilt_mcp_admin_check',
            'args'                => array(
                'days'       => array( 'default' => 28, 'sanitize_callback' => 'absint' ),
                'dimensions' => array( 'default' => 'query', 'sanitize_callback' => 'sanitize_text_field' ),
                'limit'      => array( 'default' => 50, 'sanitize_callback' => 'absint' ),
            ),
        ) );

        register_rest_route( 'spilt-mcp/v1', '/gsc/index-status', array(
            'methods'             => 'GET',
            'callback'            => array( $this, 'get_index_status' ),
            'permission_callback' => 'spilt_mcp_admin_check',
        ) );

        register_rest_route( 'spilt-mcp/v1', '/gsc/sitemaps', array(
            'methods'             => 'GET',
            'callback'            => array( $this, 'get_sitemaps' ),
            'permission_callback' => 'spilt_mcp_admin_check',
        ) );
    }

    /**
     * GET: Check if Site Kit is connected.
     */
    public function get_status( $request ) {
        $site_kit = $this->check_site_kit();

        if ( ! $site_kit['installed'] ) {
            return rest_ensure_response( array(
                'connected'  => false,
                'reason'     => 'Google Site Kit plugin is not installed.',
                'suggestion' => 'Install and activate google-site-kit to enable GSC data proxying.',
            ) );
        }

        if ( ! $site_kit['active'] ) {
            return rest_ensure_response( array(
                'connected'  => false,
                'reason'     => 'Google Site Kit is installed but not active.',
                'suggestion' => 'Activate the google-site-kit plugin.',
            ) );
        }

        if ( ! $site_kit['connected'] ) {
            return rest_ensure_response( array(
                'connected'  => false,
                'reason'     => 'Google Site Kit is active but not connected to a Google account.',
                'suggestion' => 'Complete Site Kit setup in wp-admin > Site Kit.',
            ) );
        }

        return rest_ensure_response( array(
            'connected'      => true,
            'search_console' => $site_kit['search_console'],
            'owner_email'    => $site_kit['owner'],
        ) );
    }

    /**
     * GET: Search performance data (queries, pages, clicks, impressions).
     */
    public function get_performance( $request ) {
        $check = $this->require_site_kit();
        if ( is_wp_error( $check ) ) return $check;

        $days       = min( (int) $request->get_param( 'days' ), 90 );
        $dimensions = $request->get_param( 'dimensions' );
        $limit      = min( (int) $request->get_param( 'limit' ), 200 );

        // Try Site Kit's internal data API
        $data = $this->query_site_kit( 'search-console', 'searchanalytics', array(
            'startDate'  => gmdate( 'Y-m-d', strtotime( "-{$days} days" ) ),
            'endDate'    => gmdate( 'Y-m-d', strtotime( '-1 day' ) ),
            'dimensions' => explode( ',', $dimensions ),
            'rowLimit'   => $limit,
        ) );

        if ( is_wp_error( $data ) ) {
            return $data;
        }

        return rest_ensure_response( array(
            'period'     => "{$days} days",
            'dimensions' => $dimensions,
            'rows'       => $data,
        ) );
    }

    /**
     * GET: Index coverage status.
     */
    public function get_index_status( $request ) {
        $check = $this->require_site_kit();
        if ( is_wp_error( $check ) ) return $check;

        // Site Kit stores some index data in options
        $data = get_option( 'googlesitekit_search-console_index_status', null );

        if ( ! $data ) {
            return rest_ensure_response( array(
                'available' => false,
                'note'      => 'Index coverage data not cached. View Site Kit dashboard to populate.',
            ) );
        }

        return rest_ensure_response( $data );
    }

    /**
     * GET: Submitted sitemaps status.
     */
    public function get_sitemaps( $request ) {
        $check = $this->require_site_kit();
        if ( is_wp_error( $check ) ) return $check;

        $data = $this->query_site_kit( 'search-console', 'sitemaps', array() );

        if ( is_wp_error( $data ) ) {
            return $data;
        }

        return rest_ensure_response( array(
            'sitemaps' => $data,
        ) );
    }

    /**
     * Check Site Kit installation and connection status.
     */
    private function check_site_kit() {
        if ( ! function_exists( 'get_plugins' ) ) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        $plugins  = get_plugins();
        $installed = false;
        $active    = false;
        $file      = '';

        foreach ( $plugins as $f => $data ) {
            if ( strpos( $f, 'google-site-kit' ) !== false ) {
                $installed = true;
                $file      = $f;
                break;
            }
        }

        if ( $installed ) {
            $active = is_plugin_active( $file );
        }

        $connected      = false;
        $search_console = false;
        $owner          = null;

        if ( $active ) {
            // Check Site Kit connection via its options
            $credentials = get_option( 'googlesitekit_credentials', array() );
            $connected   = ! empty( $credentials );

            // Check if Search Console module is active.
            // Search Console is Site Kit's core module — it is implicitly active
            // whenever Site Kit is connected, even if not listed in active_modules.
            $active_modules = get_option( 'googlesitekit_active_modules', array() );
            $search_console = $connected || in_array( 'search-console', $active_modules, true );

            $owner_data = get_option( 'googlesitekit_owner_id', null );
            if ( $owner_data ) {
                $user  = get_user_by( 'ID', $owner_data );
                $owner = $user ? $user->user_email : null;
            }
        }

        return array(
            'installed'      => $installed,
            'active'         => $active,
            'connected'      => $connected,
            'search_console' => $search_console,
            'owner'          => $owner,
        );
    }

    /**
     * Require Site Kit to be connected, or return WP_Error.
     */
    private function require_site_kit() {
        $status = $this->check_site_kit();

        if ( ! $status['connected'] ) {
            return new WP_Error(
                'site_kit_not_connected',
                'Google Site Kit is not connected. Install and set up Site Kit to use GSC endpoints.',
                array( 'status' => 424 )
            );
        }

        if ( ! $status['search_console'] ) {
            return new WP_Error(
                'search_console_not_active',
                'Search Console module is not active in Site Kit.',
                array( 'status' => 424 )
            );
        }

        return true;
    }

    /**
     * Query Site Kit's internal data store.
     */
    private function query_site_kit( $module, $datapoint, $params ) {
        // Use internal REST dispatch to avoid loopback HTTP requests
        $route   = "/google-site-kit/v1/modules/{$module}/data/{$datapoint}";
        $request = new WP_REST_Request( 'GET', $route );
        foreach ( $params as $key => $value ) {
            $request->set_param( $key, $value );
        }

        $response = rest_do_request( $request );

        if ( $response->is_error() ) {
            $error = $response->as_error();
            return new WP_Error( 'site_kit_query_failed', $error->get_error_message(), array( 'status' => $response->get_status() ) );
        }

        return $response->get_data();
    }
}
