<?php
/**
 * Webhook/event notification REST API endpoint.
 *
 * Register webhook URLs to receive notifications when WordPress events
 * fire (post published, post updated, comment posted, plugin updated, etc.).
 *
 * GET    /spilt-mcp/v1/webhooks                — list registered webhooks
 * POST   /spilt-mcp/v1/webhooks                — register a webhook
 * DELETE /spilt-mcp/v1/webhooks/(?P<id>\d+)    — delete a webhook
 * GET    /spilt-mcp/v1/webhooks/log            — recent webhook delivery log
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Spilt_MCP_Webhooks_API {

    const OPTION_KEY = 'spilt_mcp_webhooks';
    const LOG_KEY    = 'spilt_mcp_webhook_log';

    public function register_routes() {
        register_rest_route( 'spilt-mcp/v1', '/webhooks', array(
            array(
                'methods'             => 'GET',
                'callback'            => array( $this, 'list_webhooks' ),
                'permission_callback' => 'spilt_mcp_admin_check',
            ),
            array(
                'methods'             => 'POST',
                'callback'            => array( $this, 'create_webhook' ),
                'permission_callback' => 'spilt_mcp_admin_check',
            ),
        ) );

        register_rest_route( 'spilt-mcp/v1', '/webhooks/(?P<id>\\d+)', array(
            'methods'             => 'DELETE',
            'callback'            => array( $this, 'delete_webhook' ),
            'permission_callback' => 'spilt_mcp_admin_check',
        ) );

        register_rest_route( 'spilt-mcp/v1', '/webhooks/log', array(
            'methods'             => 'GET',
            'callback'            => array( $this, 'get_log' ),
            'permission_callback' => 'spilt_mcp_admin_check',
        ) );
    }

    /**
     * Register WordPress hooks for active webhooks.
     * Called during plugin init.
     */
    public function init_hooks() {
        $webhooks = get_option( self::OPTION_KEY, array() );

        foreach ( $webhooks as $webhook ) {
            if ( $webhook['active'] ) {
                $event = $webhook['event'];
                $url   = $webhook['url'];
                $id    = $webhook['id'];

                add_action( $this->get_wp_hook( $event ), function() use ( $event, $url, $id ) {
                    $args = func_get_args();
                    $this->fire_webhook( $id, $event, $url, $args );
                }, 99, 5 );
            }
        }
    }

    /**
     * GET: List all webhooks.
     */
    public function list_webhooks( $request ) {
        $webhooks = get_option( self::OPTION_KEY, array() );

        return rest_ensure_response( array(
            'total'           => count( $webhooks ),
            'webhooks'        => $webhooks,
            'available_events' => $this->get_available_events(),
        ) );
    }

    /**
     * POST: Register a webhook.
     *
     * Body: {
     *   "url": "https://example.com/webhook",
     *   "event": "post_published",
     *   "secret": "optional-signing-secret"
     * }
     */
    public function create_webhook( $request ) {
        $body  = $request->get_json_params();
        $url   = isset( $body['url'] ) ? esc_url_raw( $body['url'] ) : '';
        $event = isset( $body['event'] ) ? sanitize_text_field( $body['event'] ) : '';
        $secret = isset( $body['secret'] ) ? sanitize_text_field( $body['secret'] ) : '';

        if ( empty( $url ) ) {
            return new WP_Error( 'missing_url', 'Provide a "url".', array( 'status' => 400 ) );
        }

        $available = array_keys( $this->get_available_events() );
        if ( ! in_array( $event, $available, true ) ) {
            return new WP_Error( 'invalid_event', 'Invalid event. Available: ' . implode( ', ', $available ), array( 'status' => 400 ) );
        }

        $webhooks = get_option( self::OPTION_KEY, array() );

        // Generate ID
        $max_id = 0;
        foreach ( $webhooks as $wh ) {
            if ( $wh['id'] > $max_id ) $max_id = $wh['id'];
        }

        $new = array(
            'id'      => $max_id + 1,
            'url'     => $url,
            'event'   => $event,
            'secret'  => $secret,
            'active'  => true,
            'created' => current_time( 'mysql' ),
        );

        $webhooks[] = $new;
        update_option( self::OPTION_KEY, $webhooks );

        return rest_ensure_response( array(
            'success' => true,
            'webhook' => $new,
            'note'    => 'Hook will activate on next page load.',
        ) );
    }

    /**
     * DELETE: Remove a webhook.
     */
    public function delete_webhook( $request ) {
        $id       = (int) $request['id'];
        $webhooks = get_option( self::OPTION_KEY, array() );
        $found    = false;

        $webhooks = array_filter( $webhooks, function( $wh ) use ( $id, &$found ) {
            if ( $wh['id'] === $id ) {
                $found = true;
                return false;
            }
            return true;
        } );

        if ( ! $found ) {
            return new WP_Error( 'not_found', "Webhook {$id} not found.", array( 'status' => 404 ) );
        }

        update_option( self::OPTION_KEY, array_values( $webhooks ) );

        return rest_ensure_response( array( 'success' => true, 'deleted_id' => $id ) );
    }

    /**
     * GET: Recent webhook delivery log.
     */
    public function get_log( $request ) {
        $log = get_option( self::LOG_KEY, array() );

        return rest_ensure_response( array(
            'total'   => count( $log ),
            'entries' => $log,
        ) );
    }

    /**
     * Fire a webhook — send POST to the registered URL.
     */
    private function fire_webhook( $id, $event, $url, $args ) {
        $payload = array(
            'event'     => $event,
            'timestamp' => current_time( 'mysql' ),
            'site_url'  => home_url(),
            'data'      => $this->format_event_data( $event, $args ),
        );

        $json = wp_json_encode( $payload );

        // Get webhook config for signing
        $webhooks = get_option( self::OPTION_KEY, array() );
        $secret   = '';
        foreach ( $webhooks as $wh ) {
            if ( $wh['id'] === $id ) {
                $secret = $wh['secret'];
                break;
            }
        }

        $headers = array( 'Content-Type' => 'application/json' );
        if ( $secret ) {
            $headers['X-Spilt-Signature'] = hash_hmac( 'sha256', $json, $secret );
        }

        $response = wp_remote_post( $url, array(
            'body'    => $json,
            'headers' => $headers,
            'timeout' => 5,
        ) );

        // Log delivery
        $log   = get_option( self::LOG_KEY, array() );
        $entry = array(
            'webhook_id'  => $id,
            'event'       => $event,
            'url'         => $url,
            'status_code' => is_wp_error( $response ) ? 0 : wp_remote_retrieve_response_code( $response ),
            'error'       => is_wp_error( $response ) ? $response->get_error_message() : null,
            'timestamp'   => current_time( 'mysql' ),
        );
        array_unshift( $log, $entry );
        $log = array_slice( $log, 0, 100 ); // Keep last 100
        update_option( self::LOG_KEY, $log, false );
    }

    /**
     * Map our event names to WordPress hooks.
     */
    private function get_wp_hook( $event ) {
        $map = array(
            'post_published'   => 'publish_post',
            'post_updated'     => 'post_updated',
            'post_trashed'     => 'trashed_post',
            'comment_posted'   => 'wp_insert_comment',
            'comment_approved' => 'comment_unapproved_to_approved',
            'user_registered'  => 'user_register',
            'plugin_updated'   => 'upgrader_process_complete',
        );
        return isset( $map[ $event ] ) ? $map[ $event ] : $event;
    }

    /**
     * Format event-specific data for the webhook payload.
     */
    private function format_event_data( $event, $args ) {
        switch ( $event ) {
            case 'post_published':
                $post_id = isset( $args[0] ) ? $args[0] : 0;
                $post    = get_post( $post_id );
                if ( ! $post ) return array( 'post_id' => $post_id );
                return array(
                    'post_id' => $post->ID,
                    'title'   => $post->post_title,
                    'url'     => get_permalink( $post->ID ),
                    'type'    => $post->post_type,
                    'author'  => get_the_author_meta( 'display_name', $post->post_author ),
                );

            case 'post_updated':
                $post_id = isset( $args[0] ) ? $args[0] : 0;
                $post    = get_post( $post_id );
                if ( ! $post ) return array( 'post_id' => $post_id );
                return array(
                    'post_id' => $post->ID,
                    'title'   => $post->post_title,
                    'url'     => get_permalink( $post->ID ),
                    'status'  => $post->post_status,
                );

            case 'comment_posted':
                $comment_id = isset( $args[0] ) ? $args[0] : 0;
                if ( is_object( $comment_id ) ) $comment_id = $comment_id->comment_ID;
                $comment = get_comment( $comment_id );
                if ( ! $comment ) return array( 'comment_id' => $comment_id );
                return array(
                    'comment_id' => (int) $comment->comment_ID,
                    'post_id'    => (int) $comment->comment_post_ID,
                    'author'     => $comment->comment_author,
                    'status'     => $comment->comment_approved,
                );

            default:
                return array( 'raw_args' => array_map( 'strval', array_slice( $args, 0, 3 ) ) );
        }
    }

    /**
     * Available events that can be subscribed to.
     */
    private function get_available_events() {
        return array(
            'post_published'   => 'Fires when a post is published',
            'post_updated'     => 'Fires when a post is updated',
            'post_trashed'     => 'Fires when a post is trashed',
            'comment_posted'   => 'Fires when a new comment is posted',
            'comment_approved' => 'Fires when a comment is approved',
            'user_registered'  => 'Fires when a new user registers',
            'plugin_updated'   => 'Fires when plugins are updated',
        );
    }
}
