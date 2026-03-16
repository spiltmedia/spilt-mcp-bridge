<?php
/**
 * Plugin Name: Spilt MCP Bridge
 * Description: REST API extensions for WordPress MCP server — full browserless wp-admin control. Elementor, Rank Math, cache, robots.txt, post dates, media, audits, bulk operations, link audits, content search/replace, redirections, menus, sitemap, options, maintenance, cron, plugins/themes, users, webhooks, and GSC proxy.
 * Version: 2.0.0
 * Author: Spilt Media
 * Author URI: https://spiltmedia.com
 * Plugin URI: https://spiltmedia.com
 * Requires at least: 5.6
 * Requires PHP: 7.4
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'SPILT_MCP_VERSION', '2.0.0' );
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

// v1.5.0: Bulk operations and link audit
require_once SPILT_MCP_PATH . 'includes/class-bulk-audit-api.php';
require_once SPILT_MCP_PATH . 'includes/class-bulk-schema-api.php';
require_once SPILT_MCP_PATH . 'includes/class-bulk-rankmath-meta-api.php';
require_once SPILT_MCP_PATH . 'includes/class-link-audit-api.php';

// v2.0.0: Full browserless wp-admin control
require_once SPILT_MCP_PATH . 'includes/class-content-search-replace-api.php';
require_once SPILT_MCP_PATH . 'includes/class-content-search-api.php';
require_once SPILT_MCP_PATH . 'includes/class-redirections-write-api.php';
require_once SPILT_MCP_PATH . 'includes/class-media-audit-api.php';
require_once SPILT_MCP_PATH . 'includes/class-options-api.php';
require_once SPILT_MCP_PATH . 'includes/class-menu-api.php';
require_once SPILT_MCP_PATH . 'includes/class-sitemap-api.php';
require_once SPILT_MCP_PATH . 'includes/class-maintenance-api.php';
require_once SPILT_MCP_PATH . 'includes/class-cron-api.php';
require_once SPILT_MCP_PATH . 'includes/class-plugin-status-api.php';
require_once SPILT_MCP_PATH . 'includes/class-user-audit-api.php';
require_once SPILT_MCP_PATH . 'includes/class-webhooks-api.php';
require_once SPILT_MCP_PATH . 'includes/class-gsc-proxy-api.php';
require_once SPILT_MCP_PATH . 'includes/class-debug-log-api.php';
require_once SPILT_MCP_PATH . 'includes/class-filesystem-api.php';
require_once SPILT_MCP_PATH . 'includes/class-sql-api.php';
require_once SPILT_MCP_PATH . 'includes/class-widgets-api.php';
require_once SPILT_MCP_PATH . 'includes/class-theme-mods-api.php';
require_once SPILT_MCP_PATH . 'includes/class-comments-api.php';
require_once SPILT_MCP_PATH . 'includes/class-rewrite-api.php';
require_once SPILT_MCP_PATH . 'includes/class-transients-api.php';
require_once SPILT_MCP_PATH . 'includes/class-route-index-api.php';

// Initialize auto-updater — checks GitHub for new releases every 6 hours.
// All 29 client sites will see "Update Available" in WP Admin when we push a new release.
$spilt_mcp_manifest_url = 'https://raw.githubusercontent.com/spiltmedia/spilt-mcp-bridge/main/update-manifest.json';
new Spilt_MCP_Auto_Updater( __FILE__, $spilt_mcp_manifest_url );

// Initialize webhook listeners on every page load (not just REST calls)
add_action( 'init', function () {
    $webhooks = new Spilt_MCP_Webhooks_API();
    $webhooks->init_hooks();
} );

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

    // v1.5.0: Bulk operations
    $bulk_audit = new Spilt_MCP_Bulk_Audit_API();
    $bulk_audit->register_routes();

    $bulk_schema = new Spilt_MCP_Bulk_Schema_API();
    $bulk_schema->register_routes();

    $bulk_rm_meta = new Spilt_MCP_Bulk_RankMath_Meta_API();
    $bulk_rm_meta->register_routes();

    $link_audit = new Spilt_MCP_Link_Audit_API();
    $link_audit->register_routes();

    // v2.0.0: Full browserless control
    $content_sr = new Spilt_MCP_Content_Search_Replace_API();
    $content_sr->register_routes();

    $content_search = new Spilt_MCP_Content_Search_API();
    $content_search->register_routes();

    $redir_write = new Spilt_MCP_Redirections_Write_API();
    $redir_write->register_routes();

    $media_audit = new Spilt_MCP_Media_Audit_API();
    $media_audit->register_routes();

    $options = new Spilt_MCP_Options_API();
    $options->register_routes();

    $menus = new Spilt_MCP_Menu_API();
    $menus->register_routes();

    $sitemap = new Spilt_MCP_Sitemap_API();
    $sitemap->register_routes();

    $maintenance = new Spilt_MCP_Maintenance_API();
    $maintenance->register_routes();

    $cron = new Spilt_MCP_Cron_API();
    $cron->register_routes();

    $plugin_status = new Spilt_MCP_Plugin_Status_API();
    $plugin_status->register_routes();

    $user_audit = new Spilt_MCP_User_Audit_API();
    $user_audit->register_routes();

    $webhooks = new Spilt_MCP_Webhooks_API();
    $webhooks->register_routes();

    $gsc = new Spilt_MCP_GSC_Proxy_API();
    $gsc->register_routes();

    $debug_log = new Spilt_MCP_Debug_Log_API();
    $debug_log->register_routes();

    $filesystem = new Spilt_MCP_Filesystem_API();
    $filesystem->register_routes();

    $sql = new Spilt_MCP_SQL_API();
    $sql->register_routes();

    $widgets = new Spilt_MCP_Widgets_API();
    $widgets->register_routes();

    $theme_mods = new Spilt_MCP_Theme_Mods_API();
    $theme_mods->register_routes();

    $comments = new Spilt_MCP_Comments_API();
    $comments->register_routes();

    $rewrite = new Spilt_MCP_Rewrite_API();
    $rewrite->register_routes();

    $transients = new Spilt_MCP_Transients_API();
    $transients->register_routes();

    $route_index = new Spilt_MCP_Route_Index_API();
    $route_index->register_routes();

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
