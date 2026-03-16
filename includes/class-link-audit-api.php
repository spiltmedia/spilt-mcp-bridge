<?php
/**
 * Internal link health check REST API endpoint.
 *
 * Scans all published posts for internal links and validates each target
 * actually exists as a published post/page. Returns broken links, orphaned
 * posts (no incoming links), and link distribution stats.
 *
 * GET /spilt-mcp/v1/link-audit
 * GET /spilt-mcp/v1/link-audit?check=broken        — only broken internal links
 * GET /spilt-mcp/v1/link-audit?check=orphans        — posts with zero incoming internal links
 * GET /spilt-mcp/v1/link-audit?check=all            — full audit (default)
 * GET /spilt-mcp/v1/link-audit?post_id=35593        — audit a single post's outbound links
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Spilt_MCP_Link_Audit_API {

    public function register_routes() {
        register_rest_route( 'spilt-mcp/v1', '/link-audit', array(
            'methods'             => 'GET',
            'callback'            => array( $this, 'audit_links' ),
            'permission_callback' => 'spilt_mcp_admin_check',
            'args'                => array(
                'check'   => array( 'default' => 'all', 'sanitize_callback' => 'sanitize_text_field' ),
                'post_id' => array( 'default' => 0, 'sanitize_callback' => 'absint' ),
            ),
        ) );
    }

    /**
     * GET: Audit internal links across all published posts.
     */
    public function audit_links( $request ) {
        $check   = $request->get_param( 'check' );
        $post_id = (int) $request->get_param( 'post_id' );

        $site_url  = untrailingslashit( home_url() );
        $site_host = wp_parse_url( $site_url, PHP_URL_HOST );

        // Build lookup of all published post/page URLs → post_id
        $all_urls = $this->build_url_index();

        // Determine which posts to scan
        if ( $post_id > 0 ) {
            $posts = get_posts( array(
                'include'   => array( $post_id ),
                'post_type' => array( 'post', 'page' ),
            ) );
        } else {
            $posts = get_posts( array(
                'post_type'      => array( 'post', 'page' ),
                'post_status'    => 'publish',
                'posts_per_page' => -1,
            ) );
        }

        $broken_links       = array();
        $all_internal_links  = array();  // target_url => array of source post_ids
        $outbound_counts     = array();  // source post_id => count of internal links

        foreach ( $posts as $post ) {
            $content = apply_filters( 'the_content', $post->post_content );
            $links   = $this->extract_internal_links( $content, $site_host, $site_url );

            $outbound_counts[ $post->ID ] = count( $links );

            foreach ( $links as $link_url ) {
                // Normalize URL for lookup
                $normalized = $this->normalize_url( $link_url, $site_url );

                // Track incoming links
                if ( ! isset( $all_internal_links[ $normalized ] ) ) {
                    $all_internal_links[ $normalized ] = array();
                }
                $all_internal_links[ $normalized ][] = $post->ID;

                // Check if target exists
                if ( ! isset( $all_urls[ $normalized ] ) ) {
                    // Also check without trailing slash
                    $alt = rtrim( $normalized, '/' );
                    if ( ! isset( $all_urls[ $alt ] ) && ! isset( $all_urls[ $alt . '/' ] ) ) {
                        $broken_links[] = array(
                            'source_post_id' => $post->ID,
                            'source_title'   => $post->post_title,
                            'source_url'     => get_permalink( $post->ID ),
                            'broken_target'  => $link_url,
                            'normalized'     => $normalized,
                        );
                    }
                }
            }
        }

        // Find orphan posts (published posts with zero incoming internal links)
        $orphans = array();
        if ( $check === 'all' || $check === 'orphans' ) {
            foreach ( $posts as $post ) {
                if ( $post->post_type !== 'post' ) continue;  // Only check blog posts
                $post_url    = get_permalink( $post->ID );
                $normalized  = $this->normalize_url( $post_url, $site_url );
                $incoming    = isset( $all_internal_links[ $normalized ] )
                    ? count( $all_internal_links[ $normalized ] )
                    : 0;

                if ( $incoming === 0 ) {
                    $orphans[] = array(
                        'post_id'        => $post->ID,
                        'title'          => $post->post_title,
                        'url'            => $post_url,
                        'outbound_links' => isset( $outbound_counts[ $post->ID ] ) ? $outbound_counts[ $post->ID ] : 0,
                    );
                }
            }
        }

        // Build response based on check type
        $response = array(
            'total_posts_scanned'   => count( $posts ),
            'total_internal_links'  => array_sum( $outbound_counts ),
            'unique_link_targets'   => count( $all_internal_links ),
        );

        if ( $check === 'all' || $check === 'broken' ) {
            $response['broken_links'] = array(
                'count' => count( $broken_links ),
                'links' => $broken_links,
            );
        }

        if ( $check === 'all' || $check === 'orphans' ) {
            $response['orphan_posts'] = array(
                'count' => count( $orphans ),
                'posts' => $orphans,
            );
        }

        // Link distribution (posts sorted by fewest outbound links)
        if ( $check === 'all' && $post_id === 0 ) {
            $low_links = array();
            foreach ( $posts as $post ) {
                if ( $post->post_type !== 'post' ) continue;
                $count = isset( $outbound_counts[ $post->ID ] ) ? $outbound_counts[ $post->ID ] : 0;
                if ( $count < 2 ) {
                    $low_links[] = array(
                        'post_id'        => $post->ID,
                        'title'          => $post->post_title,
                        'url'            => get_permalink( $post->ID ),
                        'internal_links' => $count,
                    );
                }
            }
            usort( $low_links, function( $a, $b ) {
                return $a['internal_links'] - $b['internal_links'];
            } );
            $response['low_internal_links'] = array(
                'count' => count( $low_links ),
                'posts' => $low_links,
            );
        }

        return rest_ensure_response( $response );
    }

    /**
     * Build a lookup of all published URL paths → post IDs.
     */
    private function build_url_index() {
        $site_url = untrailingslashit( home_url() );
        $index    = array();

        $posts = get_posts( array(
            'post_type'      => array( 'post', 'page' ),
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'fields'         => 'ids',
        ) );

        foreach ( $posts as $pid ) {
            $url        = get_permalink( $pid );
            $normalized = $this->normalize_url( $url, $site_url );
            $index[ $normalized ] = $pid;
            // Also index without trailing slash
            $index[ rtrim( $normalized, '/' ) ] = $pid;
        }

        return $index;
    }

    /**
     * Extract all internal href targets from HTML.
     */
    private function extract_internal_links( $html, $site_host, $site_url ) {
        $links = array();
        if ( preg_match_all( '/<a\s[^>]*href=["\']([^"\'#]+)["\'][^>]*>/i', $html, $matches ) ) {
            foreach ( $matches[1] as $url ) {
                $host = wp_parse_url( $url, PHP_URL_HOST );
                if ( $host === $site_host || ( ! $host && strpos( $url, '/' ) === 0 ) ) {
                    // Skip anchors, mail, tel, javascript
                    if ( preg_match( '/^(mailto:|tel:|javascript:)/', $url ) ) continue;
                    $links[] = $url;
                }
            }
        }
        return array_unique( $links );
    }

    /**
     * Normalize a URL to a consistent path for comparison.
     */
    private function normalize_url( $url, $site_url ) {
        // Remove query string and fragment
        $url = strtok( $url, '?#' );

        // Make absolute if relative
        if ( strpos( $url, '/' ) === 0 ) {
            $url = $site_url . $url;
        }

        // Remove scheme + host to get just the path
        $path = wp_parse_url( $url, PHP_URL_PATH );

        // Ensure trailing slash
        return trailingslashit( $path ?: '/' );
    }
}
