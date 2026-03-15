<?php
/**
 * Post audit REST API endpoint.
 *
 * Single-call health check for any published post. Returns content stats,
 * featured image status, Rank Math SEO fields, schema types, internal
 * link count, and word count — replacing 3-4 separate API calls.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Spilt_MCP_Post_Audit_API {

    public function register_routes() {
        register_rest_route( 'spilt-mcp/v1', '/post-audit/(?P<post_id>\d+)', array(
            'methods'             => 'GET',
            'callback'            => array( $this, 'audit_post' ),
            'permission_callback' => 'spilt_mcp_admin_check',
            'args'                => array(
                'post_id' => array(
                    'validate_callback' => function ( $param ) {
                        return is_numeric( $param );
                    },
                ),
            ),
        ) );
    }

    /**
     * GET: Full audit of a single post.
     */
    public function audit_post( $request ) {
        $post_id = (int) $request['post_id'];
        $post    = get_post( $post_id );

        if ( ! $post ) {
            return new WP_Error(
                'post_not_found',
                "Post {$post_id} not found.",
                array( 'status' => 404 )
            );
        }

        $content_raw      = $post->post_content;
        $content_rendered  = apply_filters( 'the_content', $content_raw );
        $content_text      = wp_strip_all_tags( $content_rendered );
        $word_count        = str_word_count( $content_text );
        $content_length    = strlen( $content_rendered );

        // Featured image
        $thumb_id  = get_post_thumbnail_id( $post_id );
        $thumb_url = $thumb_id ? wp_get_attachment_url( $thumb_id ) : null;
        $thumb_alt = $thumb_id ? get_post_meta( $thumb_id, '_wp_attachment_image_alt', true ) : null;

        // Internal links
        $site_host      = wp_parse_url( home_url(), PHP_URL_HOST );
        $internal_links = $this->count_internal_links( $content_rendered, $site_host );
        $external_links = $this->count_external_links( $content_rendered, $site_host );

        // Headings breakdown
        $headings = $this->extract_headings( $content_rendered );

        // Rank Math fields
        $rm_focus_keyword  = get_post_meta( $post_id, 'rank_math_focus_keyword', true );
        $rm_description    = get_post_meta( $post_id, 'rank_math_description', true );
        $rm_title          = get_post_meta( $post_id, 'rank_math_title', true );
        $rm_seo_score      = get_post_meta( $post_id, 'rank_math_seo_score', true );
        $rm_robots         = get_post_meta( $post_id, 'rank_math_robots', true );

        // Schema (Rank Math stores as rank_math_schema_{Type})
        $schema_types = $this->detect_schema_types( $post_id, $content_raw );

        // Categories and tags
        $categories = wp_get_post_categories( $post_id, array( 'fields' => 'names' ) );
        $tags       = wp_get_post_tags( $post_id, array( 'fields' => 'names' ) );

        // FAQ count (count <!-- wp:heading --> with h3 inside FAQ section, or FAQ schema questions)
        $faq_count = $this->count_faq_items( $content_raw );

        // Keyword in key locations
        $keyword_placement = array();
        if ( $rm_focus_keyword ) {
            $kw_lower = strtolower( $rm_focus_keyword );
            $keyword_placement = array(
                'in_title'       => stripos( $post->post_title, $rm_focus_keyword ) !== false,
                'in_first_para'  => $this->keyword_in_first_paragraph( $content_raw, $kw_lower ),
                'in_h2'          => $this->keyword_in_heading( $content_raw, $kw_lower, 'h2' ),
                'in_meta_desc'   => $rm_description ? stripos( $rm_description, $rm_focus_keyword ) !== false : false,
                'in_slug'        => stripos( $post->post_name, str_replace( ' ', '-', $kw_lower ) ) !== false,
            );
        }

        // Build pass/fail checklist
        $checks = array(
            'content_not_empty'    => $content_length > 0,
            'word_count_ok'        => $word_count >= 1600 && $word_count <= 2000,
            'has_featured_image'   => ! empty( $thumb_id ),
            'has_focus_keyword'    => ! empty( $rm_focus_keyword ),
            'has_meta_description' => ! empty( $rm_description ),
            'has_internal_links'   => $internal_links >= 2,
            'has_faq_section'      => $faq_count >= 5,
            'has_schema'           => ! empty( $schema_types ),
        );
        $pass_count = count( array_filter( $checks ) );
        $total_checks = count( $checks );

        return rest_ensure_response( array(
            'post_id'          => $post_id,
            'title'            => $post->post_title,
            'slug'             => $post->post_name,
            'status'           => $post->post_status,
            'url'              => get_permalink( $post_id ),
            'dates'            => array(
                'published'    => $post->post_date,
                'modified'     => $post->post_modified,
                'published_gmt' => $post->post_date_gmt,
                'modified_gmt'  => $post->post_modified_gmt,
            ),
            'content'          => array(
                'rendered_length' => $content_length,
                'word_count'      => $word_count,
                'headings'        => $headings,
                'faq_count'       => $faq_count,
                'internal_links'  => $internal_links,
                'external_links'  => $external_links,
            ),
            'featured_image'   => array(
                'has_image'    => ! empty( $thumb_id ),
                'media_id'     => $thumb_id ? (int) $thumb_id : null,
                'url'          => $thumb_url,
                'alt_text'     => $thumb_alt,
            ),
            'seo'              => array(
                'focus_keyword'    => $rm_focus_keyword ?: null,
                'meta_description' => $rm_description ?: null,
                'seo_title'        => $rm_title ?: null,
                'seo_score'        => $rm_seo_score ? (int) $rm_seo_score : null,
                'robots'           => $rm_robots ?: null,
                'keyword_placement' => $keyword_placement,
            ),
            'schema_types'     => $schema_types,
            'taxonomy'         => array(
                'categories'   => $categories,
                'tags'         => is_array( $tags ) ? $tags : array(),
            ),
            'checks'           => $checks,
            'score'            => "{$pass_count}/{$total_checks}",
        ) );
    }

    /**
     * Count internal links (same domain).
     */
    private function count_internal_links( $html, $site_host ) {
        $count = 0;
        if ( preg_match_all( '/<a\s[^>]*href=["\']([^"\']+)["\'][^>]*>/i', $html, $matches ) ) {
            foreach ( $matches[1] as $url ) {
                $host = wp_parse_url( $url, PHP_URL_HOST );
                if ( $host === $site_host || ( ! $host && strpos( $url, '/' ) === 0 ) ) {
                    $count++;
                }
            }
        }
        return $count;
    }

    /**
     * Count external links (different domain).
     */
    private function count_external_links( $html, $site_host ) {
        $count = 0;
        if ( preg_match_all( '/<a\s[^>]*href=["\']([^"\']+)["\'][^>]*>/i', $html, $matches ) ) {
            foreach ( $matches[1] as $url ) {
                $host = wp_parse_url( $url, PHP_URL_HOST );
                if ( $host && $host !== $site_host ) {
                    $count++;
                }
            }
        }
        return $count;
    }

    /**
     * Extract heading structure (h2, h3 text and hierarchy).
     */
    private function extract_headings( $html ) {
        $headings = array();
        if ( preg_match_all( '/<h([2-6])[^>]*>(.*?)<\/h\1>/is', $html, $matches, PREG_SET_ORDER ) ) {
            foreach ( $matches as $m ) {
                $headings[] = array(
                    'level' => (int) $m[1],
                    'text'  => wp_strip_all_tags( $m[2] ),
                );
            }
        }
        return $headings;
    }

    /**
     * Count FAQ Q&A items.
     * Looks for H3s after an H2 containing "FAQ" or "Frequently Asked".
     */
    private function count_faq_items( $content ) {
        // Find FAQ section and count subsequent H3s
        $faq_pos = stripos( $content, 'Frequently Asked Questions' );
        if ( $faq_pos === false ) {
            $faq_pos = stripos( $content, 'FAQ' );
        }
        if ( $faq_pos === false ) {
            return 0;
        }

        $after_faq = substr( $content, $faq_pos );
        return preg_match_all( '/<h3[^>]*>/i', $after_faq );
    }

    /**
     * Check if keyword appears in the first paragraph.
     */
    private function keyword_in_first_paragraph( $content, $keyword ) {
        if ( preg_match( '/<p[^>]*>(.*?)<\/p>/is', $content, $match ) ) {
            return stripos( wp_strip_all_tags( $match[1] ), $keyword ) !== false;
        }
        return false;
    }

    /**
     * Check if keyword appears in any heading of a given level.
     */
    private function keyword_in_heading( $content, $keyword, $tag ) {
        $level = str_replace( 'h', '', $tag );
        if ( preg_match_all( "/<h{$level}[^>]*>(.*?)<\/h{$level}>/is", $content, $matches ) ) {
            foreach ( $matches[1] as $heading_text ) {
                if ( stripos( wp_strip_all_tags( $heading_text ), $keyword ) !== false ) {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * Detect schema types from Rank Math meta and inline JSON-LD.
     */
    private function detect_schema_types( $post_id, $content ) {
        $types = array();

        // Check Rank Math schema meta keys
        $all_meta = get_post_meta( $post_id );
        foreach ( $all_meta as $key => $value ) {
            if ( strpos( $key, 'rank_math_schema_' ) === 0 ) {
                $type = str_replace( 'rank_math_schema_', '', $key );
                if ( $type && $type !== 'Article' ) {
                    $types[] = $type;
                }
                // Check inside the JSON for @type
                $decoded = maybe_unserialize( $value[0] );
                if ( is_array( $decoded ) && isset( $decoded['@type'] ) ) {
                    $types[] = $decoded['@type'];
                } elseif ( is_string( $decoded ) ) {
                    $json = json_decode( $decoded, true );
                    if ( $json && isset( $json['@type'] ) ) {
                        $types[] = $json['@type'];
                    }
                }
            }
        }

        // Check for inline JSON-LD in content
        if ( preg_match_all( '/"@type"\s*:\s*"([^"]+)"/', $content, $matches ) ) {
            $types = array_merge( $types, $matches[1] );
        }

        return array_values( array_unique( array_filter( $types ) ) );
    }
}
