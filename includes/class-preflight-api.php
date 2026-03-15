<?php
/**
 * Site preflight REST API endpoint.
 *
 * Pre-publish checklist that verifies a WordPress site is properly configured
 * for blog publishing: permalink structure, blog category, AI bot access,
 * required plugins, and SEO plugin configuration.
 *
 * Run once per client during onboarding, re-run if site config changes.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Spilt_MCP_Preflight_API {

    public function register_routes() {
        register_rest_route( 'spilt-mcp/v1', '/preflight', array(
            'methods'             => 'GET',
            'callback'            => array( $this, 'run_preflight' ),
            'permission_callback' => 'spilt_mcp_admin_check',
        ) );
    }

    /**
     * GET: Run full site preflight check.
     */
    public function run_preflight( $request ) {
        $checks  = array();
        $warnings = array();
        $errors   = array();

        // 1. Permalink structure
        $permalink_structure = get_option( 'permalink_structure', '' );
        $is_blog_prefix      = strpos( $permalink_structure, '/blog/' ) !== false;
        $checks['permalink_structure'] = array(
            'value'  => $permalink_structure,
            'pass'   => $is_blog_prefix,
            'expect' => '/blog/%postname%/',
        );
        if ( ! $is_blog_prefix ) {
            $warnings[] = "Permalink structure is '{$permalink_structure}' — expected '/blog/%postname%/'. Blog URLs may not follow the /blog/slug/ pattern.";
        }

        // 2. Blog category exists
        $blog_cat = get_term_by( 'slug', 'blog', 'category' );
        $checks['blog_category'] = array(
            'exists'  => ! empty( $blog_cat ),
            'pass'    => ! empty( $blog_cat ),
            'term_id' => $blog_cat ? $blog_cat->term_id : null,
        );
        if ( ! $blog_cat ) {
            $warnings[] = "No 'blog' category found. Create one or verify category setup.";
        }

        // 3. All categories (for reference)
        $all_categories = get_categories( array( 'hide_empty' => false ) );
        $category_list  = array();
        foreach ( $all_categories as $cat ) {
            $category_list[] = array(
                'id'    => $cat->term_id,
                'name'  => $cat->name,
                'slug'  => $cat->slug,
                'count' => $cat->count,
            );
        }

        // 4. Robots.txt AI bot access
        $robots_content = '';
        $robots_file    = ABSPATH . 'robots.txt';
        if ( file_exists( $robots_file ) ) {
            $robots_content = file_get_contents( $robots_file );
        } else {
            // WordPress generates virtual robots.txt
            $robots_content = $this->get_virtual_robots();
        }

        $ai_bots = array(
            'GPTBot'          => true,
            'ChatGPT-User'    => true,
            'PerplexityBot'   => true,
            'ClaudeBot'       => true,
            'Google-Extended'  => true,
        );

        $bot_access = array();
        foreach ( $ai_bots as $bot => $required ) {
            $blocked = $this->is_bot_blocked( $robots_content, $bot );
            $bot_access[ $bot ] = array(
                'blocked' => $blocked,
                'pass'    => ! $blocked,
            );
            if ( $blocked ) {
                $errors[] = "{$bot} is blocked in robots.txt. AI search engines cannot crawl blog content.";
            }
        }
        $checks['ai_bot_access'] = $bot_access;

        // 5. Required plugins
        $active_plugins = get_option( 'active_plugins', array() );
        $plugin_names   = array();
        foreach ( $active_plugins as $p ) {
            $plugin_names[] = basename( dirname( $p ) );
        }

        $required_plugins = array(
            'seo-by-rank-math' => 'Rank Math SEO',
            'litespeed-cache'  => 'LiteSpeed Cache',
        );
        $optional_plugins = array(
            'spilt-mcp-bridge' => 'Spilt MCP Bridge',
        );

        $plugin_checks = array();
        foreach ( $required_plugins as $slug => $label ) {
            $found = in_array( $slug, $plugin_names, true );
            $plugin_checks[ $slug ] = array(
                'label'    => $label,
                'active'   => $found,
                'required' => true,
                'pass'     => $found,
            );
            if ( ! $found ) {
                $errors[] = "{$label} ({$slug}) is not active. Required for SEO and caching.";
            }
        }
        foreach ( $optional_plugins as $slug => $label ) {
            $found = in_array( $slug, $plugin_names, true );
            $plugin_checks[ $slug ] = array(
                'label'    => $label,
                'active'   => $found,
                'required' => false,
                'pass'     => $found,
            );
            if ( ! $found ) {
                $warnings[] = "{$label} ({$slug}) is not active. Recommended for API management.";
            }
        }
        $checks['plugins'] = $plugin_checks;

        // 6. Rank Math configuration
        $rm_config = array();
        if ( defined( 'RANK_MATH_VERSION' ) ) {
            $rm_general  = get_option( 'rank-math-options-general', array() );
            $rm_titles   = get_option( 'rank-math-options-titles', array() );

            $rm_config['version']         = RANK_MATH_VERSION;
            $rm_config['schema_type']     = isset( $rm_titles['pt_post_default_rich_snippet'] ) ? $rm_titles['pt_post_default_rich_snippet'] : 'unknown';
            $rm_config['article_type']    = isset( $rm_titles['pt_post_default_article_type'] ) ? $rm_titles['pt_post_default_article_type'] : 'unknown';

            // Check if schema is set to Article (should be BlogPosting)
            if ( isset( $rm_config['article_type'] ) && $rm_config['article_type'] !== 'BlogPosting' ) {
                $warnings[] = "Rank Math default article type is '{$rm_config['article_type']}' — consider changing to 'BlogPosting' for blog posts.";
            }
        }
        $checks['rankmath'] = $rm_config;

        // 7. WordPress REST API accessible
        $checks['rest_api'] = array(
            'url'  => rest_url( 'wp/v2/posts' ),
            'pass' => true,  // If we're here, REST API works
        );

        // 8. Blog page configured
        $page_for_posts = get_option( 'page_for_posts', 0 );
        $show_on_front  = get_option( 'show_on_front', 'posts' );
        $checks['blog_page'] = array(
            'show_on_front'  => $show_on_front,
            'page_for_posts' => $page_for_posts ? (int) $page_for_posts : null,
            'pass'           => $show_on_front === 'page' && $page_for_posts > 0,
        );

        // 9. Theme and page builder
        $theme = wp_get_theme();
        $checks['theme'] = array(
            'name'             => $theme->get( 'Name' ),
            'version'          => $theme->get( 'Version' ),
            'elementor_active' => defined( 'ELEMENTOR_VERSION' ),
            'elementor_version' => defined( 'ELEMENTOR_VERSION' ) ? ELEMENTOR_VERSION : null,
        );

        // 10. Post count summary
        $post_counts = wp_count_posts( 'post' );
        $checks['post_counts'] = array(
            'publish' => (int) $post_counts->publish,
            'draft'   => (int) $post_counts->draft,
            'pending' => (int) $post_counts->pending,
            'future'  => (int) $post_counts->future,
            'trash'   => (int) $post_counts->trash,
        );

        // Score
        $all_pass = array_merge(
            array( $checks['permalink_structure']['pass'] ),
            array( $checks['blog_category']['pass'] ),
            array_column( $bot_access, 'pass' ),
            array_column( $plugin_checks, 'pass' ),
            array( $checks['blog_page']['pass'] )
        );
        $pass_count = count( array_filter( $all_pass ) );
        $total      = count( $all_pass );

        return rest_ensure_response( array(
            'site_url'    => home_url(),
            'score'       => "{$pass_count}/{$total}",
            'errors'      => $errors,
            'warnings'    => $warnings,
            'checks'      => $checks,
            'categories'  => $category_list,
        ) );
    }

    /**
     * Check if a bot is blocked in robots.txt content.
     */
    private function is_bot_blocked( $robots_content, $bot_name ) {
        if ( empty( $robots_content ) ) {
            return false; // No robots.txt = everything allowed
        }

        // Look for User-agent: BotName followed by Disallow: /
        $lines    = explode( "\n", $robots_content );
        $in_block = false;

        foreach ( $lines as $line ) {
            $line = trim( $line );

            if ( preg_match( '/^User-agent:\s*(.+)/i', $line, $m ) ) {
                $agent   = trim( $m[1] );
                $in_block = ( strcasecmp( $agent, $bot_name ) === 0 );
            } elseif ( $in_block && preg_match( '/^Disallow:\s*\/\s*$/i', $line ) ) {
                return true; // Blocked
            } elseif ( $in_block && preg_match( '/^(User-agent|Allow|Sitemap):/i', $line ) ) {
                // New directive block or allow — stop checking this block
                if ( preg_match( '/^User-agent:/i', $line ) ) {
                    $in_block = false;
                }
            }
        }

        return false;
    }

    /**
     * Get WordPress virtual robots.txt content.
     */
    private function get_virtual_robots() {
        ob_start();
        do_action( 'do_robots' );
        $content = ob_get_clean();
        // Also try the newer filter
        if ( empty( $content ) ) {
            $content = apply_filters( 'robots_txt', "User-agent: *\nDisallow:\n", true );
        }
        return $content;
    }
}
