<?php
/**
 * Database maintenance/cleanup REST API endpoint.
 *
 * Safely clean up WordPress database bloat: revisions, transients,
 * trashed posts, spam comments, auto-drafts.
 *
 * GET  /spilt-mcp/v1/maintenance              — preview what can be cleaned
 * POST /spilt-mcp/v1/maintenance/clean         — run cleanup
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Spilt_MCP_Maintenance_API {

    public function register_routes() {
        register_rest_route( 'spilt-mcp/v1', '/maintenance', array(
            'methods'             => 'GET',
            'callback'            => array( $this, 'preview' ),
            'permission_callback' => 'spilt_mcp_admin_check',
        ) );

        register_rest_route( 'spilt-mcp/v1', '/maintenance/clean', array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'clean' ),
            'permission_callback' => 'spilt_mcp_admin_check',
        ) );
    }

    /**
     * GET: Preview cleanup — counts of items that can be removed.
     */
    public function preview( $request ) {
        global $wpdb;

        $counts = array(
            'revisions'        => (int) $wpdb->get_var(
                "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'revision'"
            ),
            'auto_drafts'      => (int) $wpdb->get_var(
                "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_status = 'auto-draft'"
            ),
            'trashed_posts'    => (int) $wpdb->get_var(
                "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_status = 'trash'"
            ),
            'spam_comments'    => (int) $wpdb->get_var(
                "SELECT COUNT(*) FROM {$wpdb->comments} WHERE comment_approved = 'spam'"
            ),
            'trashed_comments' => (int) $wpdb->get_var(
                "SELECT COUNT(*) FROM {$wpdb->comments} WHERE comment_approved = 'trash'"
            ),
            'expired_transients' => (int) $wpdb->get_var(
                "SELECT COUNT(*) FROM {$wpdb->options}
                 WHERE option_name LIKE '_transient_timeout_%'
                   AND option_value < UNIX_TIMESTAMP()"
            ),
            'orphaned_postmeta' => (int) $wpdb->get_var(
                "SELECT COUNT(*) FROM {$wpdb->postmeta} pm
                 LEFT JOIN {$wpdb->posts} p ON pm.post_id = p.ID
                 WHERE p.ID IS NULL"
            ),
            'orphaned_commentmeta' => (int) $wpdb->get_var(
                "SELECT COUNT(*) FROM {$wpdb->commentmeta} cm
                 LEFT JOIN {$wpdb->comments} c ON cm.comment_id = c.comment_ID
                 WHERE c.comment_ID IS NULL"
            ),
        );

        $counts['total_cleanable'] = array_sum( $counts );

        // Database size
        $db_name = DB_NAME;
        $size = $wpdb->get_var( $wpdb->prepare(
            "SELECT ROUND(SUM(data_length + index_length) / 1024 / 1024, 2)
             FROM information_schema.tables WHERE table_schema = %s",
            $db_name
        ) );

        $counts['database_size_mb'] = (float) $size;

        return rest_ensure_response( $counts );
    }

    /**
     * POST: Run cleanup.
     *
     * Body: { "targets": ["revisions", "auto_drafts", "trashed_posts", "spam_comments", "trashed_comments", "expired_transients", "orphaned_postmeta", "orphaned_commentmeta"] }
     * Omit "targets" to clean everything.
     */
    public function clean( $request ) {
        global $wpdb;

        $body    = $request->get_json_params();
        $targets = isset( $body['targets'] ) ? (array) $body['targets'] : array(
            'revisions', 'auto_drafts', 'trashed_posts', 'spam_comments',
            'trashed_comments', 'expired_transients', 'orphaned_postmeta', 'orphaned_commentmeta',
        );

        $results = array();

        if ( in_array( 'revisions', $targets, true ) ) {
            $ids = $wpdb->get_col( "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'revision'" );
            foreach ( $ids as $id ) {
                wp_delete_post_revision( $id );
            }
            $results['revisions'] = count( $ids );
        }

        if ( in_array( 'auto_drafts', $targets, true ) ) {
            $ids = $wpdb->get_col( "SELECT ID FROM {$wpdb->posts} WHERE post_status = 'auto-draft'" );
            foreach ( $ids as $id ) {
                wp_delete_post( $id, true );
            }
            $results['auto_drafts'] = count( $ids );
        }

        if ( in_array( 'trashed_posts', $targets, true ) ) {
            $ids = $wpdb->get_col( "SELECT ID FROM {$wpdb->posts} WHERE post_status = 'trash'" );
            foreach ( $ids as $id ) {
                wp_delete_post( $id, true );
            }
            $results['trashed_posts'] = count( $ids );
        }

        if ( in_array( 'spam_comments', $targets, true ) ) {
            $count = $wpdb->query( "DELETE FROM {$wpdb->comments} WHERE comment_approved = 'spam'" );
            $results['spam_comments'] = (int) $count;
        }

        if ( in_array( 'trashed_comments', $targets, true ) ) {
            $count = $wpdb->query( "DELETE FROM {$wpdb->comments} WHERE comment_approved = 'trash'" );
            $results['trashed_comments'] = (int) $count;
        }

        if ( in_array( 'expired_transients', $targets, true ) ) {
            $transients = $wpdb->get_col(
                "SELECT option_name FROM {$wpdb->options}
                 WHERE option_name LIKE '_transient_timeout_%'
                   AND option_value < UNIX_TIMESTAMP()"
            );
            $count = 0;
            foreach ( $transients as $transient ) {
                $name = str_replace( '_transient_timeout_', '', $transient );
                delete_transient( $name );
                $count++;
            }
            $results['expired_transients'] = $count;
        }

        if ( in_array( 'orphaned_postmeta', $targets, true ) ) {
            $count = $wpdb->query(
                "DELETE pm FROM {$wpdb->postmeta} pm
                 LEFT JOIN {$wpdb->posts} p ON pm.post_id = p.ID
                 WHERE p.ID IS NULL"
            );
            $results['orphaned_postmeta'] = (int) $count;
        }

        if ( in_array( 'orphaned_commentmeta', $targets, true ) ) {
            $count = $wpdb->query(
                "DELETE cm FROM {$wpdb->commentmeta} cm
                 LEFT JOIN {$wpdb->comments} c ON cm.comment_id = c.comment_ID
                 WHERE c.comment_ID IS NULL"
            );
            $results['orphaned_commentmeta'] = (int) $count;
        }

        $results['total_cleaned'] = array_sum( $results );

        return rest_ensure_response( array(
            'success' => true,
            'cleaned' => $results,
        ) );
    }
}
