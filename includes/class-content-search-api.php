<?php
/**
 * Content search/grep REST API endpoint.
 *
 * Server-side search across all post content. Like grep for WordPress.
 *
 * GET /spilt-mcp/v1/content/search?q=pattern
 * GET /spilt-mcp/v1/content/search?q=broken+link&post_type=post&context=80
 * GET /spilt-mcp/v1/content/search?q=/regex/i&regex=1
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Spilt_MCP_Content_Search_API {

    public function register_routes() {
        register_rest_route( 'spilt-mcp/v1', '/content/search', array(
            'methods'             => 'GET',
            'callback'            => array( $this, 'search_content' ),
            'permission_callback' => 'spilt_mcp_admin_check',
            'args'                => array(
                'q'         => array( 'required' => true, 'sanitize_callback' => 'sanitize_text_field' ),
                'regex'     => array( 'default' => false ),
                'post_type' => array( 'default' => 'post', 'sanitize_callback' => 'sanitize_text_field' ),
                'status'    => array( 'default' => 'publish', 'sanitize_callback' => 'sanitize_text_field' ),
                'context'   => array( 'default' => 80, 'sanitize_callback' => 'absint' ),
                'field'     => array( 'default' => 'content', 'sanitize_callback' => 'sanitize_text_field' ),
                'per_page'  => array( 'default' => 50, 'sanitize_callback' => 'absint' ),
            ),
        ) );
    }

    public function search_content( $request ) {
        $query      = $request->get_param( 'q' );
        $is_regex   = filter_var( $request->get_param( 'regex' ), FILTER_VALIDATE_BOOLEAN );
        $post_type  = $request->get_param( 'post_type' );
        $status     = $request->get_param( 'status' );
        $ctx_len    = min( 200, max( 20, (int) $request->get_param( 'context' ) ) );
        $field      = $request->get_param( 'field' );  // content, title, meta, all
        $per_page   = min( 200, max( 1, (int) $request->get_param( 'per_page' ) ) );

        // Validate regex
        if ( $is_regex && @preg_match( $query, '' ) === false ) {
            return new WP_Error( 'invalid_regex', 'Invalid regex. Include delimiters.', array( 'status' => 400 ) );
        }

        // For non-regex content searches, use SQL LIKE for efficiency
        $query_args = array(
            'post_type'      => explode( ',', $post_type ),
            'post_status'    => explode( ',', $status ),
            'posts_per_page' => -1,
        );

        // Use SQL LIKE pre-filter for non-regex content search
        if ( ! $is_regex && ( $field === 'content' || $field === 'all' ) ) {
            global $wpdb;
            $like = '%' . $wpdb->esc_like( $query ) . '%';
            $post_types_in = "'" . implode( "','", array_map( 'esc_sql', explode( ',', $post_type ) ) ) . "'";
            $statuses_in   = "'" . implode( "','", array_map( 'esc_sql', explode( ',', $status ) ) ) . "'";

            $sql = $wpdb->prepare(
                "SELECT ID FROM {$wpdb->posts}
                 WHERE post_type IN ({$post_types_in})
                   AND post_status IN ({$statuses_in})
                   AND post_content LIKE %s
                 ORDER BY ID DESC",
                $like
            );
            $post_ids = $wpdb->get_col( $sql );

            if ( empty( $post_ids ) ) {
                return rest_ensure_response( array(
                    'query'         => $query,
                    'total_matches' => 0,
                    'matches'       => array(),
                ) );
            }
            $query_args['post__in'] = $post_ids;
        }

        $posts   = get_posts( $query_args );
        $matches = array();

        foreach ( $posts as $post ) {
            $post_matches = array();

            // Search in content
            if ( $field === 'content' || $field === 'all' ) {
                $hits = $this->find_in_text( $post->post_content, $query, $is_regex, $ctx_len );
                if ( $hits ) {
                    $post_matches['content'] = $hits;
                }
            }

            // Search in title
            if ( $field === 'title' || $field === 'all' ) {
                $hits = $this->find_in_text( $post->post_title, $query, $is_regex, $ctx_len );
                if ( $hits ) {
                    $post_matches['title'] = $hits;
                }
            }

            // Search in Rank Math meta
            if ( $field === 'meta' || $field === 'all' ) {
                $meta_keys = array( 'rank_math_title', 'rank_math_description', 'rank_math_focus_keyword' );
                foreach ( $meta_keys as $mk ) {
                    $val = get_post_meta( $post->ID, $mk, true );
                    if ( $val ) {
                        $hits = $this->find_in_text( $val, $query, $is_regex, $ctx_len );
                        if ( $hits ) {
                            $post_matches[ $mk ] = $hits;
                        }
                    }
                }
            }

            if ( ! empty( $post_matches ) ) {
                $total_hits = 0;
                foreach ( $post_matches as $loc => $hits ) {
                    $total_hits += $hits['count'];
                }

                $matches[] = array(
                    'post_id'    => $post->ID,
                    'title'      => $post->post_title,
                    'slug'       => $post->post_name,
                    'url'        => get_permalink( $post->ID ),
                    'status'     => $post->post_status,
                    'total_hits' => $total_hits,
                    'locations'  => $post_matches,
                );

                if ( count( $matches ) >= $per_page ) {
                    break;
                }
            }
        }

        // Sort by most hits
        usort( $matches, function( $a, $b ) {
            return $b['total_hits'] - $a['total_hits'];
        } );

        return rest_ensure_response( array(
            'query'         => $query,
            'regex'         => $is_regex,
            'field'         => $field,
            'total_matches' => count( $matches ),
            'matches'       => $matches,
        ) );
    }

    /**
     * Find pattern in text and return count + context snippets.
     */
    private function find_in_text( $text, $query, $is_regex, $ctx_len ) {
        if ( $is_regex ) {
            $count = preg_match_all( $query, $text, $m, PREG_OFFSET_CAPTURE );
            if ( ! $count ) return null;
            $offsets = array();
            foreach ( $m[0] as $match ) {
                $offsets[] = array( 'pos' => $match[1], 'len' => strlen( $match[0] ) );
            }
        } else {
            $count  = substr_count( $text, $query );
            if ( ! $count ) return null;
            $offsets = array();
            $offset  = 0;
            while ( ( $pos = strpos( $text, $query, $offset ) ) !== false ) {
                $offsets[] = array( 'pos' => $pos, 'len' => strlen( $query ) );
                $offset    = $pos + strlen( $query );
            }
        }

        // Build snippets (max 5)
        $snippets = array();
        foreach ( array_slice( $offsets, 0, 5 ) as $o ) {
            $start = max( 0, $o['pos'] - $ctx_len );
            $end   = min( strlen( $text ), $o['pos'] + $o['len'] + $ctx_len );
            $snip  = substr( $text, $start, $end - $start );
            if ( $start > 0 ) $snip = '...' . $snip;
            if ( $end < strlen( $text ) ) $snip .= '...';
            $snippets[] = $snip;
        }

        return array(
            'count'    => $count,
            'snippets' => $snippets,
        );
    }
}
