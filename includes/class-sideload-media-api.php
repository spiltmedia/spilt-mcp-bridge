<?php
/**
 * Sideload media REST API endpoint.
 *
 * Upload an image from a URL or base64 string, add it to the media library,
 * and optionally set it as a post's featured image — all in one call.
 *
 * Replaces the 3-step pipeline: download → upload to media → assign to post.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Spilt_MCP_Sideload_Media_API {

    public function register_routes() {
        register_rest_route( 'spilt-mcp/v1', '/sideload-media/(?P<post_id>\d+)', array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'sideload' ),
            'permission_callback' => 'spilt_mcp_admin_check',
            'args'                => array(
                'post_id' => array(
                    'validate_callback' => function ( $param ) {
                        return is_numeric( $param );
                    },
                ),
            ),
        ) );

        // Standalone upload (no post assignment)
        register_rest_route( 'spilt-mcp/v1', '/sideload-media', array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'sideload_standalone' ),
            'permission_callback' => 'spilt_mcp_admin_check',
        ) );
    }

    /**
     * POST: Sideload image and assign as featured image to a post.
     *
     * Body params:
     *   - source:       (required) URL or base64-encoded image data
     *   - source_type:  (optional) "url" or "base64" — auto-detected if omitted
     *   - filename:     (required) e.g. "blog-featured-image.png"
     *   - alt_text:     (optional) alt text for the image
     *   - title:        (optional) media title (defaults to filename without extension)
     *   - caption:      (optional) media caption
     *   - set_featured: (optional) bool, default true — set as post featured image
     */
    public function sideload( $request ) {
        $post_id = (int) $request['post_id'];
        $post    = get_post( $post_id );

        if ( ! $post ) {
            return new WP_Error(
                'post_not_found',
                "Post {$post_id} not found.",
                array( 'status' => 404 )
            );
        }

        $result = $this->process_sideload( $request );

        if ( is_wp_error( $result ) ) {
            return $result;
        }

        $attachment_id = $result['attachment_id'];
        $body          = $request->get_json_params();
        $set_featured  = isset( $body['set_featured'] ) ? (bool) $body['set_featured'] : true;

        if ( $set_featured ) {
            set_post_thumbnail( $post_id, $attachment_id );
        }

        $result['post_id']     = $post_id;
        $result['set_featured'] = $set_featured;

        return rest_ensure_response( $result );
    }

    /**
     * POST: Sideload image to media library without assigning to a post.
     */
    public function sideload_standalone( $request ) {
        $result = $this->process_sideload( $request );

        if ( is_wp_error( $result ) ) {
            return $result;
        }

        return rest_ensure_response( $result );
    }

    /**
     * Core sideload logic shared by both endpoints.
     */
    private function process_sideload( $request ) {
        $body = $request->get_json_params();

        $source   = isset( $body['source'] ) ? $body['source'] : '';
        $filename = isset( $body['filename'] ) ? sanitize_file_name( $body['filename'] ) : '';
        $alt_text = isset( $body['alt_text'] ) ? sanitize_text_field( $body['alt_text'] ) : '';
        $title    = isset( $body['title'] ) ? sanitize_text_field( $body['title'] ) : '';
        $caption  = isset( $body['caption'] ) ? sanitize_text_field( $body['caption'] ) : '';

        if ( empty( $source ) ) {
            return new WP_Error( 'missing_source', 'The "source" parameter is required (URL or base64).', array( 'status' => 400 ) );
        }

        if ( empty( $filename ) ) {
            return new WP_Error( 'missing_filename', 'The "filename" parameter is required.', array( 'status' => 400 ) );
        }

        // Auto-detect source type
        $source_type = isset( $body['source_type'] ) ? $body['source_type'] : $this->detect_source_type( $source );

        // Get image data
        if ( $source_type === 'url' ) {
            $image_data = $this->download_from_url( $source );
        } else {
            $image_data = $this->decode_base64( $source );
        }

        if ( is_wp_error( $image_data ) ) {
            return $image_data;
        }

        // Validate file type
        $allowed_types = array( 'image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/svg+xml' );
        $finfo         = new finfo( FILEINFO_MIME_TYPE );
        $mime_type     = $finfo->buffer( $image_data );

        if ( ! in_array( $mime_type, $allowed_types, true ) ) {
            return new WP_Error(
                'invalid_mime',
                "File type {$mime_type} is not allowed. Allowed: " . implode( ', ', $allowed_types ),
                array( 'status' => 400 )
            );
        }

        // Write to temp file
        $tmp_file = wp_tempnam( $filename );
        file_put_contents( $tmp_file, $image_data );

        // Prepare file array for wp_handle_sideload
        $file_array = array(
            'name'     => $filename,
            'tmp_name' => $tmp_file,
            'size'     => strlen( $image_data ),
        );

        // Need media functions
        if ( ! function_exists( 'media_handle_sideload' ) ) {
            require_once ABSPATH . 'wp-admin/includes/image.php';
            require_once ABSPATH . 'wp-admin/includes/file.php';
            require_once ABSPATH . 'wp-admin/includes/media.php';
        }

        // Upload to media library
        $attachment_id = media_handle_sideload( $file_array, 0 );

        if ( is_wp_error( $attachment_id ) ) {
            @unlink( $tmp_file );
            return new WP_Error(
                'sideload_failed',
                'Media sideload failed: ' . $attachment_id->get_error_message(),
                array( 'status' => 500 )
            );
        }

        // Set alt text
        if ( $alt_text ) {
            update_post_meta( $attachment_id, '_wp_attachment_image_alt', $alt_text );
        }

        // Set title and caption
        $update_args = array( 'ID' => $attachment_id );
        if ( $title ) {
            $update_args['post_title'] = $title;
        }
        if ( $caption ) {
            $update_args['post_excerpt'] = $caption;
        }
        if ( count( $update_args ) > 1 ) {
            wp_update_post( $update_args );
        }

        return array(
            'success'       => true,
            'attachment_id' => $attachment_id,
            'url'           => wp_get_attachment_url( $attachment_id ),
            'mime_type'     => $mime_type,
            'filename'      => $filename,
            'alt_text'      => $alt_text,
            'filesize'      => size_format( strlen( $image_data ) ),
        );
    }

    /**
     * Detect if source is a URL or base64.
     */
    private function detect_source_type( $source ) {
        if ( filter_var( $source, FILTER_VALIDATE_URL ) ) {
            return 'url';
        }
        return 'base64';
    }

    /**
     * Download image from URL.
     */
    private function download_from_url( $url ) {
        $response = wp_remote_get( $url, array(
            'timeout' => 30,
            'sslverify' => false,
        ) );

        if ( is_wp_error( $response ) ) {
            return new WP_Error(
                'download_failed',
                'Failed to download image: ' . $response->get_error_message(),
                array( 'status' => 502 )
            );
        }

        $code = wp_remote_retrieve_response_code( $response );
        if ( $code !== 200 ) {
            return new WP_Error(
                'download_failed',
                "Image URL returned HTTP {$code}.",
                array( 'status' => 502 )
            );
        }

        $body = wp_remote_retrieve_body( $response );
        if ( empty( $body ) ) {
            return new WP_Error(
                'empty_response',
                'Image URL returned empty body.',
                array( 'status' => 502 )
            );
        }

        return $body;
    }

    /**
     * Decode base64 image data.
     * Handles both raw base64 and data URI format (data:image/png;base64,...).
     */
    private function decode_base64( $source ) {
        // Strip data URI prefix if present
        if ( preg_match( '/^data:image\/[a-z+]+;base64,/i', $source ) ) {
            $source = preg_replace( '/^data:image\/[a-z+]+;base64,/i', '', $source );
        }

        $decoded = base64_decode( $source, true );

        if ( $decoded === false || strlen( $decoded ) < 100 ) {
            return new WP_Error(
                'invalid_base64',
                'The source does not appear to be valid base64-encoded image data.',
                array( 'status' => 400 )
            );
        }

        return $decoded;
    }
}
