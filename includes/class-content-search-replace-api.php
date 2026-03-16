<?php
/**
 * Bulk content search/replace REST API endpoint.
 *
 * Server-side find/replace across all published post content.
 * Supports plain text and regex. Dry-run mode previews changes.
 *
 * POST /spilt-mcp/v1/content/search-replace
 * {
 *   "find": "{SITE}/appointment/",
 *   "replace": "https://example.com/appointment/",
 *   "regex": false,
 *   "dry_run": true,
 *   "post_type": "post",
 *   "post_ids": [123, 456]          // optional — limit to specific posts
 * }
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Spilt_MCP_Content_Search_Replace_API {

    public function register_routes() {
        register_rest_route( 'spilt-mcp/v1', '/content/search-replace', array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'search_replace' ),
            'permission_callback' => 'spilt_mcp_admin_check',
        ) );
    }

    public function search_replace( $request ) {
        $body      = $request->get_json_params();
        $find      = isset( $body['find'] ) ? $body['find'] : '';
        $replace   = isset( $body['replace'] ) ? $body['replace'] : '';
        $is_regex  = ! empty( $body['regex'] );
        $dry_run   = isset( $body['dry_run'] ) ? (bool) $body['dry_run'] : true;
        $post_type = isset( $body['post_type'] ) ? sanitize_text_field( $body['post_type'] ) : 'post';
        $post_ids  = isset( $body['post_ids'] ) ? array_map( 'absint', (array) $body['post_ids'] ) : null;

        if ( empty( $find ) ) {
            return new WP_Error( 'missing_find', 'The "find" parameter is required.', array( 'status' => 400 ) );
        }

        // Validate regex if used
        if ( $is_regex ) {
            if ( @preg_match( $find, '' ) === false ) {
                return new WP_Error( 'invalid_regex', 'Invalid regex pattern. Include delimiters (e.g., /pattern/i).', array( 'status' => 400 ) );
            }
        }

        // Query posts
        $query_args = array(
            'post_type'      => $post_type,
            'post_status'    => array( 'publish', 'draft', 'pending', 'future' ),
            'posts_per_page' => -1,
        );

        if ( $post_ids ) {
            $query_args['post__in'] = $post_ids;
        }

        $posts = get_posts( $query_args );

        $matches   = array();
        $updated   = 0;
        $unchanged = 0;

        foreach ( $posts as $post ) {
            $content = $post->post_content;

            // Count occurrences
            if ( $is_regex ) {
                $count = preg_match_all( $find, $content );
                $new_content = preg_replace( $find, $replace, $content );
            } else {
                $count = substr_count( $content, $find );
                $new_content = str_replace( $find, $replace, $content );
            }

            if ( $count > 0 ) {
                // Build context snippets (show surrounding text)
                $snippets = $this->extract_snippets( $content, $find, $is_regex, 3 );

                $match_entry = array(
                    'post_id'      => $post->ID,
                    'title'        => $post->post_title,
                    'slug'         => $post->post_name,
                    'url'          => get_permalink( $post->ID ),
                    'status'       => $post->post_status,
                    'occurrences'  => $count,
                    'snippets'     => $snippets,
                );

                if ( ! $dry_run ) {
                    $result = wp_update_post( array(
                        'ID'           => $post->ID,
                        'post_content' => $new_content,
                    ), true );

                    if ( is_wp_error( $result ) ) {
                        $match_entry['error'] = $result->get_error_message();
                    } else {
                        $match_entry['replaced'] = true;
                        $updated++;
                    }
                }

                $matches[] = $match_entry;
            } else {
                $unchanged++;
            }
        }

        return rest_ensure_response( array(
            'dry_run'       => $dry_run,
            'find'          => $find,
            'replace'       => $replace,
            'regex'         => $is_regex,
            'total_scanned' => count( $posts ),
            'total_matched' => count( $matches ),
            'total_updated' => $updated,
            'unchanged'     => $unchanged,
            'matches'       => $matches,
        ) );
    }

    /**
     * Extract text snippets showing context around matches.
     */
    private function extract_snippets( $content, $find, $is_regex, $max_snippets = 3 ) {
        $snippets = array();
        $text     = wp_strip_all_tags( $content );

        if ( $is_regex ) {
            preg_match_all( $find, $text, $m, PREG_OFFSET_CAPTURE );
            $offsets = array();
            if ( ! empty( $m[0] ) ) {
                foreach ( $m[0] as $match ) {
                    $offsets[] = $match[1];
                }
            }
        } else {
            $offsets = array();
            $offset  = 0;
            while ( ( $pos = strpos( $text, $find, $offset ) ) !== false ) {
                $offsets[] = $pos;
                $offset    = $pos + strlen( $find );
            }
        }

        foreach ( array_slice( $offsets, 0, $max_snippets ) as $pos ) {
            $start = max( 0, $pos - 60 );
            $end   = min( strlen( $text ), $pos + strlen( $find ) + 60 );
            $snippet = substr( $text, $start, $end - $start );
            if ( $start > 0 ) $snippet = '...' . $snippet;
            if ( $end < strlen( $text ) ) $snippet .= '...';
            $snippets[] = $snippet;
        }

        return $snippets;
    }
}
