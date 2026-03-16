<?php
/**
 * Media audit REST API endpoint.
 *
 * Scans posts for media issues: missing featured images, empty alt text,
 * broken image URLs in content, and unused media library items.
 *
 * GET /spilt-mcp/v1/media-audit
 * GET /spilt-mcp/v1/media-audit?check=missing_featured
 * GET /spilt-mcp/v1/media-audit?check=missing_alt
 * GET /spilt-mcp/v1/media-audit?check=broken_images
 * GET /spilt-mcp/v1/media-audit?check=unused_media
 * GET /spilt-mcp/v1/media-audit?check=all
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Spilt_MCP_Media_Audit_API {

    public function register_routes() {
        register_rest_route( 'spilt-mcp/v1', '/media-audit', array(
            'methods'             => 'GET',
            'callback'            => array( $this, 'audit_media' ),
            'permission_callback' => 'spilt_mcp_admin_check',
            'args'                => array(
                'check' => array( 'default' => 'all', 'sanitize_callback' => 'sanitize_text_field' ),
            ),
        ) );

        // Bulk alt text update
        register_rest_route( 'spilt-mcp/v1', '/media-audit/update-alt', array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'update_alt_text' ),
            'permission_callback' => 'spilt_mcp_admin_check',
        ) );
    }

    public function audit_media( $request ) {
        $check = $request->get_param( 'check' );

        $response = array();

        // Missing featured images
        if ( $check === 'all' || $check === 'missing_featured' ) {
            $posts = get_posts( array(
                'post_type'      => 'post',
                'post_status'    => 'publish',
                'posts_per_page' => -1,
                'fields'         => 'ids',
                'meta_query'     => array(
                    'relation' => 'OR',
                    array( 'key' => '_thumbnail_id', 'compare' => 'NOT EXISTS' ),
                    array( 'key' => '_thumbnail_id', 'value' => '', 'compare' => '=' ),
                    array( 'key' => '_thumbnail_id', 'value' => '0', 'compare' => '=' ),
                ),
            ) );

            $missing = array();
            foreach ( $posts as $pid ) {
                $post = get_post( $pid );
                $missing[] = array(
                    'post_id' => $pid,
                    'title'   => $post->post_title,
                    'url'     => get_permalink( $pid ),
                );
            }

            $response['missing_featured_image'] = array(
                'count' => count( $missing ),
                'posts' => $missing,
            );
        }

        // Missing alt text on featured images
        if ( $check === 'all' || $check === 'missing_alt' ) {
            $posts = get_posts( array(
                'post_type'      => 'post',
                'post_status'    => 'publish',
                'posts_per_page' => -1,
                'fields'         => 'ids',
            ) );

            $missing_alt = array();
            foreach ( $posts as $pid ) {
                $thumb_id = get_post_thumbnail_id( $pid );
                if ( ! $thumb_id ) continue;

                $alt = get_post_meta( $thumb_id, '_wp_attachment_image_alt', true );
                if ( empty( trim( $alt ) ) ) {
                    $post = get_post( $pid );
                    $missing_alt[] = array(
                        'post_id'  => $pid,
                        'title'    => $post->post_title,
                        'media_id' => (int) $thumb_id,
                        'url'      => wp_get_attachment_url( $thumb_id ),
                    );
                }
            }

            $response['missing_alt_text'] = array(
                'count'  => count( $missing_alt ),
                'images' => $missing_alt,
            );
        }

        // Broken images in content
        if ( $check === 'all' || $check === 'broken_images' ) {
            $posts = get_posts( array(
                'post_type'      => 'post',
                'post_status'    => 'publish',
                'posts_per_page' => -1,
            ) );

            $broken = array();
            $site_url = home_url();

            foreach ( $posts as $post ) {
                $content = $post->post_content;
                if ( preg_match_all( '/<img[^>]+src=["\']([^"\']+)["\'][^>]*>/i', $content, $matches ) ) {
                    foreach ( $matches[1] as $img_url ) {
                        // Only check internal images
                        if ( strpos( $img_url, $site_url ) === false && strpos( $img_url, '/' ) !== 0 ) {
                            continue;
                        }

                        // Check if attachment exists in media library
                        $attachment_id = attachment_url_to_postid( $img_url );
                        if ( ! $attachment_id ) {
                            // Could be a resized version — check base URL
                            $base_url = preg_replace( '/-\d+x\d+\./', '.', $img_url );
                            $attachment_id = attachment_url_to_postid( $base_url );
                        }

                        if ( ! $attachment_id ) {
                            $broken[] = array(
                                'post_id'   => $post->ID,
                                'title'     => $post->post_title,
                                'image_url' => $img_url,
                            );
                        }
                    }
                }
            }

            $response['broken_images'] = array(
                'count'  => count( $broken ),
                'images' => array_slice( $broken, 0, 100 ), // Cap at 100
            );
        }

        // Unused media (attachments not referenced by any post)
        if ( $check === 'unused_media' ) {
            global $wpdb;

            // Get all attachment IDs
            $all_attachments = $wpdb->get_col(
                "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'attachment' ORDER BY ID DESC"
            );

            // Get all attachment IDs used as featured images
            $featured_ids = $wpdb->get_col(
                "SELECT DISTINCT meta_value FROM {$wpdb->postmeta} WHERE meta_key = '_thumbnail_id' AND meta_value > 0"
            );

            // Get all attachment URLs referenced in post content
            $upload_dir = wp_upload_dir();
            $upload_url = $upload_dir['baseurl'];

            $used_in_content = $wpdb->get_col( $wpdb->prepare(
                "SELECT ID FROM {$wpdb->posts}
                 WHERE post_type = 'attachment'
                   AND guid IN (
                     SELECT DISTINCT SUBSTRING_INDEX(SUBSTRING_INDEX(post_content, %s, -1), '\"', 1)
                     FROM {$wpdb->posts}
                     WHERE post_type IN ('post', 'page') AND post_status = 'publish'
                   )",
                $upload_url
            ) );

            $used_ids = array_unique( array_merge(
                array_map( 'intval', $featured_ids ),
                array_map( 'intval', $used_in_content )
            ) );

            $unused = array_diff( array_map( 'intval', $all_attachments ), $used_ids );

            $unused_items = array();
            foreach ( array_slice( $unused, 0, 50 ) as $aid ) {
                $unused_items[] = array(
                    'media_id' => $aid,
                    'url'      => wp_get_attachment_url( $aid ),
                    'title'    => get_the_title( $aid ),
                    'date'     => get_the_date( 'Y-m-d', $aid ),
                );
            }

            $response['unused_media'] = array(
                'count'      => count( $unused ),
                'showing'    => count( $unused_items ),
                'items'      => $unused_items,
            );
        }

        return rest_ensure_response( $response );
    }

    /**
     * POST: Bulk update alt text for attachments.
     *
     * Body: {
     *   "updates": {
     *     "1234": "Alt text for image 1234",
     *     "5678": "Alt text for image 5678"
     *   }
     * }
     */
    public function update_alt_text( $request ) {
        $body    = $request->get_json_params();
        $updates = isset( $body['updates'] ) ? (array) $body['updates'] : array();

        if ( empty( $updates ) ) {
            return new WP_Error( 'no_data', 'Provide "updates" object: { media_id: "alt text" }.', array( 'status' => 400 ) );
        }

        $success = 0;
        $errors  = array();

        foreach ( $updates as $media_id => $alt_text ) {
            $media_id = absint( $media_id );
            if ( ! get_post( $media_id ) ) {
                $errors[] = array( 'media_id' => $media_id, 'error' => 'Attachment not found' );
                continue;
            }
            update_post_meta( $media_id, '_wp_attachment_image_alt', sanitize_text_field( $alt_text ) );
            $success++;
        }

        return rest_ensure_response( array(
            'success' => true,
            'updated' => $success,
            'failed'  => count( $errors ),
            'errors'  => $errors,
        ) );
    }
}
