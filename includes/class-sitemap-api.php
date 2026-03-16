<?php
/**
 * Sitemap management REST API endpoint.
 *
 * Read sitemap index, validate URLs, find excluded posts, force regeneration.
 *
 * GET  /spilt-mcp/v1/sitemap                      — read sitemap index + stats
 * GET  /spilt-mcp/v1/sitemap/validate              — validate all sitemap URLs resolve
 * GET  /spilt-mcp/v1/sitemap/excluded              — find published posts excluded from sitemap
 * POST /spilt-mcp/v1/sitemap/regenerate            — force sitemap cache clear
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Spilt_MCP_Sitemap_API {

    public function register_routes() {
        register_rest_route( 'spilt-mcp/v1', '/sitemap', array(
            'methods'             => 'GET',
            'callback'            => array( $this, 'get_sitemap_info' ),
            'permission_callback' => 'spilt_mcp_admin_check',
        ) );

        register_rest_route( 'spilt-mcp/v1', '/sitemap/validate', array(
            'methods'             => 'GET',
            'callback'            => array( $this, 'validate_sitemap' ),
            'permission_callback' => 'spilt_mcp_admin_check',
        ) );

        register_rest_route( 'spilt-mcp/v1', '/sitemap/excluded', array(
            'methods'             => 'GET',
            'callback'            => array( $this, 'find_excluded' ),
            'permission_callback' => 'spilt_mcp_admin_check',
        ) );

        register_rest_route( 'spilt-mcp/v1', '/sitemap/regenerate', array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'regenerate' ),
            'permission_callback' => 'spilt_mcp_admin_check',
        ) );
    }

    /**
     * GET: Read sitemap index and return stats.
     */
    public function get_sitemap_info( $request ) {
        $sitemap_url = home_url( '/sitemap_index.xml' );

        // Try Rank Math sitemap first, then WordPress default, then Yoast
        $urls_to_try = array(
            home_url( '/sitemap_index.xml' ),
            home_url( '/sitemap.xml' ),
            home_url( '/wp-sitemap.xml' ),
        );

        $sitemap_content = null;
        $used_url        = null;

        foreach ( $urls_to_try as $url ) {
            $response = wp_remote_get( $url, array( 'timeout' => 10 ) );
            if ( ! is_wp_error( $response ) && wp_remote_retrieve_response_code( $response ) === 200 ) {
                $sitemap_content = wp_remote_retrieve_body( $response );
                $used_url        = $url;
                break;
            }
        }

        if ( ! $sitemap_content ) {
            return new WP_Error( 'no_sitemap', 'Could not find or fetch sitemap.', array( 'status' => 404 ) );
        }

        // Parse sitemap index
        $sitemaps = array();
        if ( preg_match_all( '/<loc>([^<]+)<\/loc>/', $sitemap_content, $matches ) ) {
            foreach ( $matches[1] as $loc ) {
                $sitemaps[] = $loc;
            }
        }

        // Count total URLs across all sub-sitemaps
        $total_urls   = 0;
        $sub_sitemaps = array();

        foreach ( $sitemaps as $sm_url ) {
            $sm_response = wp_remote_get( $sm_url, array( 'timeout' => 10 ) );
            $url_count   = 0;

            if ( ! is_wp_error( $sm_response ) && wp_remote_retrieve_response_code( $sm_response ) === 200 ) {
                $sm_body   = wp_remote_retrieve_body( $sm_response );
                $url_count = preg_match_all( '/<loc>/', $sm_body );
            }

            $total_urls += $url_count;
            $sub_sitemaps[] = array(
                'url'       => $sm_url,
                'url_count' => $url_count,
            );
        }

        // Count published posts and pages for comparison
        $published_posts = wp_count_posts( 'post' );
        $published_pages = wp_count_posts( 'page' );

        return rest_ensure_response( array(
            'sitemap_url'       => $used_url,
            'sub_sitemaps'      => count( $sub_sitemaps ),
            'total_urls'        => $total_urls,
            'published_posts'   => (int) $published_posts->publish,
            'published_pages'   => (int) $published_pages->publish,
            'coverage_gap'      => ( (int) $published_posts->publish + (int) $published_pages->publish ) - $total_urls,
            'sitemaps'          => $sub_sitemaps,
        ) );
    }

    /**
     * GET: Find published posts excluded from sitemap (noindex or manually excluded).
     */
    public function find_excluded( $request ) {
        $posts = get_posts( array(
            'post_type'      => array( 'post', 'page' ),
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'fields'         => 'ids',
        ) );

        $excluded = array();
        foreach ( $posts as $pid ) {
            $robots = get_post_meta( $pid, 'rank_math_robots', true );
            $is_noindex = false;

            if ( is_array( $robots ) && in_array( 'noindex', $robots, true ) ) {
                $is_noindex = true;
            } elseif ( is_string( $robots ) && strpos( $robots, 'noindex' ) !== false ) {
                $is_noindex = true;
            }

            if ( $is_noindex ) {
                $post = get_post( $pid );
                $excluded[] = array(
                    'post_id' => $pid,
                    'title'   => $post->post_title,
                    'url'     => get_permalink( $pid ),
                    'type'    => $post->post_type,
                    'robots'  => $robots,
                );
            }
        }

        return rest_ensure_response( array(
            'total_excluded' => count( $excluded ),
            'posts'          => $excluded,
        ) );
    }

    /**
     * GET: Validate sitemap URLs — check each URL actually resolves.
     * Only validates a sample (first sub-sitemap) to avoid timeout.
     */
    public function validate_sitemap( $request ) {
        $urls_to_try = array(
            home_url( '/sitemap_index.xml' ),
            home_url( '/sitemap.xml' ),
            home_url( '/wp-sitemap.xml' ),
        );

        // Find and parse first post sitemap
        foreach ( $urls_to_try as $url ) {
            $response = wp_remote_get( $url, array( 'timeout' => 10 ) );
            if ( ! is_wp_error( $response ) && wp_remote_retrieve_response_code( $response ) === 200 ) {
                $body = wp_remote_retrieve_body( $response );
                // Find post sitemap
                if ( preg_match( '/<loc>([^<]*post-sitemap[^<]*)<\/loc>/', $body, $m ) ) {
                    $post_sitemap_url = $m[1];
                    break;
                }
                // Try first sub-sitemap
                if ( preg_match( '/<loc>([^<]+)<\/loc>/', $body, $m ) ) {
                    $post_sitemap_url = $m[1];
                    break;
                }
            }
        }

        if ( empty( $post_sitemap_url ) ) {
            return new WP_Error( 'no_sitemap', 'Could not find post sitemap.', array( 'status' => 404 ) );
        }

        // Fetch and parse the post sitemap
        $sm_response = wp_remote_get( $post_sitemap_url, array( 'timeout' => 15 ) );
        if ( is_wp_error( $sm_response ) ) {
            return new WP_Error( 'fetch_failed', 'Could not fetch sitemap.', array( 'status' => 500 ) );
        }

        $sm_body = wp_remote_retrieve_body( $sm_response );
        preg_match_all( '/<loc>([^<]+)<\/loc>/', $sm_body, $matches );
        $sitemap_urls = $matches[1];

        // Validate each URL exists as a published post/page
        $valid   = 0;
        $invalid = array();

        foreach ( $sitemap_urls as $sm_url ) {
            $post_id = url_to_postid( $sm_url );
            if ( $post_id ) {
                $post = get_post( $post_id );
                if ( $post && $post->post_status === 'publish' ) {
                    $valid++;
                    continue;
                }
            }
            $invalid[] = $sm_url;
        }

        return rest_ensure_response( array(
            'sitemap_checked' => $post_sitemap_url,
            'total_urls'      => count( $sitemap_urls ),
            'valid'           => $valid,
            'invalid_count'   => count( $invalid ),
            'invalid_urls'    => $invalid,
        ) );
    }

    /**
     * POST: Force sitemap regeneration.
     */
    public function regenerate( $request ) {
        $cleared = array();

        // Rank Math sitemap cache
        if ( class_exists( '\\RankMath\\Sitemap\\Cache' ) ) {
            \RankMath\Sitemap\Cache::invalidate_storage();
            $cleared[] = 'rank_math_sitemap';
        }

        // WordPress core sitemap (5.5+)
        if ( function_exists( 'wp_get_sitemap_providers' ) ) {
            delete_transient( 'wp_sitemaps_index' );
            $cleared[] = 'wp_core_sitemap';
        }

        // Clear rewrite rules (forces sitemap URLs to re-register)
        flush_rewrite_rules( false );
        $cleared[] = 'rewrite_rules';

        return rest_ensure_response( array(
            'success' => true,
            'cleared' => $cleared,
            'message' => 'Sitemap caches cleared. Next request will regenerate.',
        ) );
    }
}
