<?php
/**
 * Comment moderation REST API endpoint.
 *
 * Bulk approve, spam, trash, and delete comments.
 * WordPress core REST API only handles one comment at a time.
 *
 * GET  /spilt-mcp/v1/comments                   — list comments with filters
 * POST /spilt-mcp/v1/comments/moderate           — bulk moderate comments
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Spilt_MCP_Comments_API {

    public function register_routes() {
        register_rest_route( 'spilt-mcp/v1', '/comments', array(
            'methods'             => 'GET',
            'callback'            => array( $this, 'list_comments' ),
            'permission_callback' => 'spilt_mcp_admin_check',
            'args'                => array(
                'status'   => array( 'default' => 'all', 'sanitize_callback' => 'sanitize_text_field' ),
                'per_page' => array( 'default' => 50, 'sanitize_callback' => 'absint' ),
                'page'     => array( 'default' => 1, 'sanitize_callback' => 'absint' ),
            ),
        ) );

        register_rest_route( 'spilt-mcp/v1', '/comments/moderate', array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'moderate' ),
            'permission_callback' => 'spilt_mcp_admin_check',
        ) );
    }

    /**
     * GET: List comments with status filter and counts.
     */
    public function list_comments( $request ) {
        $status   = $request->get_param( 'status' );
        $per_page = min( (int) $request->get_param( 'per_page' ), 200 );
        $page     = (int) $request->get_param( 'page' );

        $args = array(
            'number' => $per_page,
            'offset' => ( $page - 1 ) * $per_page,
            'orderby' => 'comment_date',
            'order'   => 'DESC',
        );

        if ( $status !== 'all' ) {
            $status_map = array(
                'pending'  => 'hold',
                'approved' => 'approve',
                'spam'     => 'spam',
                'trash'    => 'trash',
            );
            $args['status'] = isset( $status_map[ $status ] ) ? $status_map[ $status ] : $status;
        }

        $comments = get_comments( $args );

        $result = array();
        foreach ( $comments as $comment ) {
            $result[] = array(
                'id'          => (int) $comment->comment_ID,
                'post_id'     => (int) $comment->comment_post_ID,
                'post_title'  => get_the_title( $comment->comment_post_ID ),
                'author'      => $comment->comment_author,
                'author_email'=> $comment->comment_author_email,
                'author_url'  => $comment->comment_author_url,
                'author_ip'   => $comment->comment_author_IP,
                'date'        => $comment->comment_date,
                'content'     => wp_strip_all_tags( $comment->comment_content ),
                'status'      => $this->readable_status( $comment->comment_approved ),
                'parent'      => (int) $comment->comment_parent,
            );
        }

        // Get counts
        $counts = wp_count_comments();

        return rest_ensure_response( array(
            'comments' => $result,
            'page'     => $page,
            'per_page' => $per_page,
            'counts'   => array(
                'total'    => (int) $counts->total_comments,
                'approved' => (int) $counts->approved,
                'pending'  => (int) $counts->moderated,
                'spam'     => (int) $counts->spam,
                'trash'    => (int) $counts->trash,
            ),
        ) );
    }

    /**
     * POST: Bulk moderate comments.
     *
     * Body: {
     *   "ids": [1, 2, 3],
     *   "action": "approve"    // approve, spam, trash, delete, unapprove
     * }
     * Or:
     * Body: {
     *   "status": "spam",       // moderate all comments with this status
     *   "action": "delete"
     * }
     */
    public function moderate( $request ) {
        $body   = $request->get_json_params();
        $ids    = isset( $body['ids'] ) ? array_map( 'absint', (array) $body['ids'] ) : array();
        $action = isset( $body['action'] ) ? sanitize_text_field( $body['action'] ) : '';
        $status = isset( $body['status'] ) ? sanitize_text_field( $body['status'] ) : '';

        $valid_actions = array( 'approve', 'unapprove', 'spam', 'trash', 'delete' );
        if ( ! in_array( $action, $valid_actions, true ) ) {
            return new WP_Error( 'invalid_action', 'Action must be: ' . implode( ', ', $valid_actions ), array( 'status' => 400 ) );
        }

        // If status is provided instead of IDs, get all comments with that status
        if ( empty( $ids ) && ! empty( $status ) ) {
            $status_map = array(
                'pending'  => 'hold',
                'approved' => 'approve',
                'spam'     => 'spam',
                'trash'    => 'trash',
            );
            $fetch_status = isset( $status_map[ $status ] ) ? $status_map[ $status ] : $status;

            $comments = get_comments( array( 'status' => $fetch_status, 'fields' => 'ids', 'number' => 1000 ) );
            $ids = array_map( 'intval', $comments );
        }

        if ( empty( $ids ) ) {
            return new WP_Error( 'no_ids', 'Provide "ids" array or "status" to select comments.', array( 'status' => 400 ) );
        }

        $success = 0;
        $errors  = array();

        foreach ( $ids as $id ) {
            $result = false;

            switch ( $action ) {
                case 'approve':
                    $result = wp_set_comment_status( $id, 'approve' );
                    break;
                case 'unapprove':
                    $result = wp_set_comment_status( $id, 'hold' );
                    break;
                case 'spam':
                    $result = wp_spam_comment( $id );
                    break;
                case 'trash':
                    $result = wp_trash_comment( $id );
                    break;
                case 'delete':
                    $result = wp_delete_comment( $id, true );
                    break;
            }

            if ( $result ) {
                $success++;
            } else {
                $errors[] = $id;
            }
        }

        return rest_ensure_response( array(
            'success'  => true,
            'action'   => $action,
            'affected' => $success,
            'failed'   => count( $errors ),
            'errors'   => $errors,
        ) );
    }

    /**
     * Convert WP comment_approved value to readable string.
     */
    private function readable_status( $approved ) {
        switch ( $approved ) {
            case '1': return 'approved';
            case '0': return 'pending';
            case 'spam': return 'spam';
            case 'trash': return 'trash';
            default: return $approved;
        }
    }
}
