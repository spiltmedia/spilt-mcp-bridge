<?php
/**
 * Self-hosted auto-updater for Spilt MCP Bridge.
 *
 * Hooks into WordPress's native plugin update system. Checks a remote
 * JSON manifest for the latest version and serves the zip URL to
 * WordPress's built-in updater — so updates appear in Dashboard > Updates
 * just like any WordPress.org plugin.
 *
 * Manifest URL: A JSON file hosted on GitHub (raw) or any public URL.
 * Manifest format:
 * {
 *   "version": "1.3.0",
 *   "download_url": "https://github.com/spiltmedia/spilt-mcp-bridge/releases/download/v1.3.0/spilt-mcp-bridge.zip",
 *   "requires": "5.6",
 *   "requires_php": "7.4",
 *   "tested": "6.7",
 *   "last_updated": "2026-03-15",
 *   "changelog": "Added post-dates, post-audit, sideload-media, bulk-dates, and preflight endpoints."
 * }
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Spilt_MCP_Auto_Updater {

    /**
     * Remote URL to the JSON update manifest.
     * Change this to your actual hosted manifest URL.
     */
    private $manifest_url;

    /**
     * Plugin basename (e.g., "spilt-mcp-bridge/spilt-mcp-bridge.php").
     */
    private $plugin_basename;

    /**
     * Plugin slug (directory name).
     */
    private $plugin_slug;

    /**
     * Current plugin version.
     */
    private $current_version;

    /**
     * Cache key for transient storage.
     */
    private $cache_key = 'spilt_mcp_update_check';

    /**
     * Cache duration in seconds (check every 6 hours).
     */
    private $cache_ttl = 21600;

    /**
     * @param string $plugin_file    Full path to main plugin file.
     * @param string $manifest_url   URL to the remote JSON manifest.
     */
    public function __construct( $plugin_file, $manifest_url ) {
        $this->plugin_basename  = plugin_basename( $plugin_file );
        $this->plugin_slug      = dirname( $this->plugin_basename );
        $this->current_version  = SPILT_MCP_VERSION;
        $this->manifest_url     = $manifest_url;

        // Hook into WordPress update system
        add_filter( 'pre_set_site_transient_update_plugins', array( $this, 'check_for_update' ) );
        add_filter( 'plugins_api', array( $this, 'plugin_info' ), 10, 3 );
        add_action( 'upgrader_process_complete', array( $this, 'clear_cache' ), 10, 2 );

        // Add "Check for updates" link on plugins page
        add_filter( 'plugin_action_links_' . $this->plugin_basename, array( $this, 'add_action_links' ) );
    }

    /**
     * Check remote manifest for a newer version.
     * Injects update info into WordPress's update_plugins transient.
     */
    public function check_for_update( $transient ) {
        if ( empty( $transient->checked ) ) {
            return $transient;
        }

        $remote = $this->get_remote_manifest();

        if ( ! $remote ) {
            return $transient;
        }

        if ( version_compare( $this->current_version, $remote->version, '<' ) ) {
            $transient->response[ $this->plugin_basename ] = (object) array(
                'slug'        => $this->plugin_slug,
                'plugin'      => $this->plugin_basename,
                'new_version' => $remote->version,
                'url'         => isset( $remote->homepage ) ? $remote->homepage : '',
                'package'     => $remote->download_url,
                'icons'       => array(),
                'banners'     => array(),
                'requires'    => isset( $remote->requires ) ? $remote->requires : '5.6',
                'requires_php' => isset( $remote->requires_php ) ? $remote->requires_php : '7.4',
                'tested'      => isset( $remote->tested ) ? $remote->tested : '',
            );
        } else {
            // Tell WordPress we checked and there's no update
            $transient->no_update[ $this->plugin_basename ] = (object) array(
                'slug'        => $this->plugin_slug,
                'plugin'      => $this->plugin_basename,
                'new_version' => $this->current_version,
                'url'         => '',
                'package'     => '',
            );
        }

        return $transient;
    }

    /**
     * Provide plugin info for the "View Details" modal in WP Admin.
     */
    public function plugin_info( $result, $action, $args ) {
        if ( $action !== 'plugin_information' || ! isset( $args->slug ) || $args->slug !== $this->plugin_slug ) {
            return $result;
        }

        $remote = $this->get_remote_manifest();

        if ( ! $remote ) {
            return $result;
        }

        return (object) array(
            'name'          => 'Spilt MCP Bridge',
            'slug'          => $this->plugin_slug,
            'version'       => $remote->version,
            'author'        => '<a href="https://spiltmedia.com">Spilt Media</a>',
            'homepage'      => isset( $remote->homepage ) ? $remote->homepage : 'https://spiltmedia.com',
            'download_link' => $remote->download_url,
            'requires'      => isset( $remote->requires ) ? $remote->requires : '5.6',
            'requires_php'  => isset( $remote->requires_php ) ? $remote->requires_php : '7.4',
            'tested'        => isset( $remote->tested ) ? $remote->tested : '',
            'last_updated'  => isset( $remote->last_updated ) ? $remote->last_updated : '',
            'sections'      => array(
                'description' => 'REST API extensions for WordPress MCP server. Exposes Elementor data, Rank Math meta, cache controls, robots.txt management, post date overrides, media sideloading, post audits, and site preflight checks.',
                'changelog'   => isset( $remote->changelog ) ? $remote->changelog : 'See GitHub releases for details.',
            ),
        );
    }

    /**
     * Fetch and cache the remote manifest.
     */
    private function get_remote_manifest() {
        $cached = get_transient( $this->cache_key );

        if ( $cached !== false ) {
            return $cached;
        }

        $response = wp_remote_get( $this->manifest_url, array(
            'timeout'   => 10,
            'sslverify' => true,
            'headers'   => array(
                'Accept' => 'application/json',
            ),
        ) );

        if ( is_wp_error( $response ) || wp_remote_retrieve_response_code( $response ) !== 200 ) {
            // Cache the failure for 1 hour so we don't hammer the server
            set_transient( $this->cache_key, null, 3600 );
            return null;
        }

        $body = json_decode( wp_remote_retrieve_body( $response ) );

        if ( ! $body || ! isset( $body->version ) || ! isset( $body->download_url ) ) {
            set_transient( $this->cache_key, null, 3600 );
            return null;
        }

        set_transient( $this->cache_key, $body, $this->cache_ttl );

        return $body;
    }

    /**
     * Clear cached manifest after an update completes.
     */
    public function clear_cache( $upgrader, $options ) {
        if ( isset( $options['plugins'] ) && in_array( $this->plugin_basename, $options['plugins'], true ) ) {
            delete_transient( $this->cache_key );
        }
    }

    /**
     * Add "Check for updates" action link on the plugins page.
     */
    public function add_action_links( $links ) {
        $check_link = '<a href="' . esc_url( admin_url( 'update-core.php?force-check=1' ) ) . '">Check for updates</a>';
        array_unshift( $links, $check_link );
        return $links;
    }
}
