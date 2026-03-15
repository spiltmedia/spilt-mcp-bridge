<?php
/**
 * Plugin Name: Spilt MCP Bridge
 * Description: REST API extensions for WordPress MCP server — Elementor data, Rank Math meta, cache controls, robots.txt, post date overrides, media sideloading, post audits, and site preflight checks.
 * Version: 1.4.1
 * Author: Spilt Media
 * Requires at least: 5.6
 * Requires PHP: 7.4
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'SPILT_MCP_VERSION', '1.4.1' );
define( 'SPILT_MCP_PATH', plugin_dir_path( __FILE__ ) );

// Load endpoint classes
require_once SPILT_MCP_PATH . 'includes/class-elementor-api.php';
require_once SPILT_MCP_PATH . 'includes/class-rankmath-api.php';
require_once SPILT_MCP_PATH . 'includes/class-cache-api.php';
require_once SPILT_MCP_PATH . 'includes/class-robotstxt-api.php';
require_once SPILT_MCP_PATH . 'includes/class-post-dates-api.php';
require_once SPILT_MCP_PATH . 'includes/class-post-audit-api.php';
require_once SPILT_MCP_PATH . 'includes/class-sideload-media-api.php';
require_once SPILT_MCP_PATH . 'includes/class-bulk-dates-api.php';
require_once SPILT_MCP_PATH . 'includes/class-preflight-api.php';
require_once SPILT_MCP_PATH . 'includes/class-auto-updater.php';

// Initialize auto-updater — checks GitHub for new releases every 6 hours.
// All 29 client sites will see "Update Available" in WP Admin when we push a new release.
$spilt_mcp_manifest_url = 'https://raw.githubusercontent.com/spiltmedia/spilt-mcp-bridge/main/update-manifest.json';
new Spilt_MCP_Auto_Updater( __FILE__, $spilt_mcp_manifest_url );

/**
 * Register all REST API routes on init.
 */
add_action( 'rest_api_init', function () {
    $elementor = new Spilt_MCP_Elementor_API();
    $elementor->register_routes();

    $rankmath = new Spilt_MCP_RankMath_API();
    $rankmath->register_routes();

    $cache = new Spilt_MCP_Cache_API();
    $cache->register_routes();

    $robots = new Spilt_MCP_RobotsTxt_API();
    $robots->register_routes();

    $post_dates = new Spilt_MCP_Post_Dates_API();
    $post_dates->register_routes();

    $post_audit = new Spilt_MCP_Post_Audit_API();
    $post_audit->register_routes();

    $sideload = new Spilt_MCP_Sideload_Media_API();
    $sideload->register_routes();

    $bulk_dates = new Spilt_MCP_Bulk_Dates_API();
    $bulk_dates->register_routes();

    $preflight = new Spilt_MCP_Preflight_API();
    $preflight->register_routes();

    // Health endpoint
    register_rest_route( 'spilt-mcp/v1', '/health', array(
        'methods'             => 'GET',
        'callback'            => 'spilt_mcp_health',
        'permission_callback' => 'spilt_mcp_admin_check',
    ) );
} );

/**
 * Permission check: require manage_options capability.
 */
function spilt_mcp_admin_check( $request ) {
    return current_user_can( 'manage_options' );
}

/**
 * Health endpoint callback.
 */
function spilt_mcp_health( $request ) {
    global $wp_version;

    $theme = wp_get_theme();
    $plugins = get_option( 'active_plugins', array() );

    return rest_ensure_response( array(
        'wordpress_version' => $wp_version,
        'php_version'       => phpversion(),
        'active_theme'      => $theme->get( 'Name' ),
        'theme_version'     => $theme->get( 'Version' ),
        'active_plugins'    => count( $plugins ),
        'mcp_bridge'        => SPILT_MCP_VERSION,
        'elementor_active'  => defined( 'ELEMENTOR_VERSION' ),
        'rankmath_active'   => defined( 'RANK_MATH_VERSION' ),
        'litespeed_active'  => defined( 'LSCWP_V' ),
    ) );
}
