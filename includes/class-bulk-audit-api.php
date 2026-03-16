<?php
/**
 * Bulk post audit REST API endpoint.
 *
 * Returns audit data for ALL published posts in a single paginated call.
 * Replaces 150+ individual /post-audit/{id} requests with one bulk call.
 *
 * GET /spilt-mcp/v1/post-audit/bulk?page=1&per_page=50
 *
 * Response includes: word count, SEO fields, schema types, internal link
 * count, featured image status, and pass/fail checks for every post.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Spilt_MCP_Bulk_Audit_API {

    public function register_routes() {
        register_rest_route( 'spilt-mcp/v1', '/post-audit/bulk', array(
            'methods'             => 'GET',
            'callback'            => array( $this, 'bulk_audit' ),
            'permission_callback' => 'spilt_mcp_admin_check',
            'args'                => array(
                'page'     => array(
                    'default'           => 1,
                    'sanitize_callback' => 'absint',
                ),
                'per_page' => array(
                    'default'           => 50,
                    'sanitize_callback' => 'absint',
                ),
                'post_type' => array(
                    'default'           => 'post',
                    'sanitize_callback' => 'sanitize_text_field',
                ),
            ),
        ) );
    }

    /**
     * GET: Bulk audit all published posts.
     */
    public function bulk_audit( $request ) {
        $page      = max( 1, (int) $request->get_param( 'page' ) );
        $per_page  = min( 100, max( 1, (int) $request->get_param( 'per_page' ) ) );
        $post_type = $request->get_param( 'post_type' );
        $site_host = wp_parse_url( home_url(), PHP_URL_HOST );

        $query = new WP_Query( array(
            'post_type'      => $post_type,
            'post_status'    => 'publish',
            'posts_per_page' => $per_page,
            'paged'          => $page,
            'orderby'        => 'ID',
            'order'          => 'DESC',
        ) );

        $results = array();

        foreach ( $query->posts as $post ) {
            $post_id          = $post->ID;
            $content_rendered = apply_filters( 'the_content', $post->post_content );
            $content_text     = wp_strip_all_tags( $content_rendered );
            $word_count       = str_word_count( $content_text );

            // Featured image
            $thumb_id  = get_post_thumbnail_id( $post_id );
            $thumb_url = $thumb_id ? wp_get_attachment_url( $thumb_id ) : null;

            // Links
            $internal_links = $this->count_links( $content_rendered, $site_host, true );
            $external_links = $this->count_links( $content_rendered, $site_host, false );

            // Rank Math fields
            $rm_focus_kw   = get_post_meta( $post_id, 'rank_math_focus_keyword', true );
            $rm_desc       = get_post_meta( $post_id, 'rank_math_description', true );
            $rm_title      = get_post_meta( $post_id, 'rank_math_title', true );
            $rm_seo_score  = get_post_meta( $post_id, 'rank_math_seo_score', true );

            // Schema types
            $schema_types = $this->detect_schema_types( $post_id );

            // FAQ count
            $faq_count = $this->count_faq_items( $post->post_content );

            // Categories and tags
            $categories = wp_get_post_categories( $post_id, array( 'fields' => 'names' ) );
            $tags       = wp_get_post_tags( $post_id, array( 'fields' => 'names' ) );

            // Pass/fail checks
            $checks = array(
                'content_not_empty'    => strlen( $content_rendered ) > 0,
                'word_count_ok'        => $word_count >= 1600 && $word_count <= 2000,
                'has_featured_image'   => ! empty( $thumb_id ),
                'has_focus_keyword'    => ! empty( $rm_focus_kw ),
                'has_meta_description' => ! empty( $rm_desc ),
                'has_internal_links'   => $internal_links >= 2,
                'has_faq_section'      => $faq_count >= 5,
                'has_schema'           => ! empty( $schema_types ),
            );
            $pass_count   = count( array_filter( $checks ) );
            $total_checks = count( $checks );

            $results[] = array(
                'post_id'        => $post_id,
                'title'          => $post->post_title,
                'slug'           => $post->post_name,
                'url'            => get_permalink( $post_id ),
                'published'      => $post->post_date,
                'modified'       => $post->post_modified,
                'word_count'     => $word_count,
                'internal_links' => $internal_links,
                'external_links' => $external_links,
                'faq_count'      => $faq_count,
                'featured_image' => $thumb_url,
                'seo'            => array(
                    'focus_keyword'    => $rm_focus_kw ?: null,
                    'meta_description' => $rm_desc ?: null,
                    'seo_title'        => $rm_title ?: null,
                    'seo_score'        => $rm_seo_score ? (int) $rm_seo_score : null,
                ),
                'schema_types'   => $schema_types,
                'categories'     => $categories,
                'tags'           => is_array( $tags ) ? $tags : array(),
                'checks'         => $checks,
                'score'          => "{$pass_count}/{$total_checks}",
            );
        }

        $response = rest_ensure_response( array(
            'page'       => $page,
            'per_page'   => $per_page,
            'total'      => (int) $query->found_posts,
            'total_pages' => (int) $query->max_num_pages,
            'posts'      => $results,
        ) );

        return $response;
    }

    /**
     * Count internal or external links.
     */
    private function count_links( $html, $site_host, $internal = true ) {
        $count = 0;
        if ( preg_match_all( '/<a\s[^>]*href=["\']([^"\']+)["\'][^>]*>/i', $html, $matches ) ) {
            foreach ( $matches[1] as $url ) {
                $host = wp_parse_url( $url, PHP_URL_HOST );
                $is_internal = ( $host === $site_host || ( ! $host && strpos( $url, '/' ) === 0 ) );
                if ( $internal ? $is_internal : ( $host && ! $is_internal ) ) {
                    $count++;
                }
            }
        }
        return $count;
    }

    /**
     * Detect schema types from Rank Math meta.
     */
    private function detect_schema_types( $post_id ) {
        $types    = array();
        $all_meta = get_post_meta( $post_id );

        foreach ( $all_meta as $key => $value ) {
            if ( strpos( $key, 'rank_math_schema' ) === 0 && $key !== 'rank_math_schema' ) {
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
            // Modern single key: rank_math_schema (JSON string with multiple schemas)
            if ( $key === 'rank_math_schema' ) {
                $decoded = maybe_unserialize( $value[0] );
                if ( is_array( $decoded ) ) {
                    foreach ( $decoded as $schema ) {
                        if ( is_array( $schema ) && isset( $schema['@type'] ) ) {
                            $types[] = $schema['@type'];
                        }
                    }
                }
            }
        }

        return array_values( array_unique( array_filter( $types ) ) );
    }

    /**
     * Count FAQ items after FAQ heading.
     */
    private function count_faq_items( $content ) {
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
}
