<?php
/**
 * WordPress cron management REST API endpoint.
 *
 * List, trigger, and delete WP-Cron scheduled events.
 *
 * GET    /spilt-mcp/v1/cron                    — list all cron events
 * POST   /spilt-mcp/v1/cron/trigger            — trigger a cron hook now
 * DELETE /spilt-mcp/v1/cron/(?P<hook>[a-zA-Z0-9_]+) — delete all events for a hook
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Spilt_MCP_Cron_API {

    public function register_routes() {
        register_rest_route( 'spilt-mcp/v1', '/cron', array(
            'methods'             => 'GET',
            'callback'            => array( $this, 'list_events' ),
            'permission_callback' => 'spilt_mcp_admin_check',
        ) );

        register_rest_route( 'spilt-mcp/v1', '/cron/trigger', array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'trigger_event' ),
            'permission_callback' => 'spilt_mcp_admin_check',
        ) );

        register_rest_route( 'spilt-mcp/v1', '/cron/(?P<hook>[a-zA-Z0-9_]+)', array(
            'methods'             => 'DELETE',
            'callback'            => array( $this, 'delete_event' ),
            'permission_callback' => 'spilt_mcp_admin_check',
        ) );
    }

    /**
     * GET: List all scheduled cron events.
     */
    public function list_events( $request ) {
        $crons     = _get_cron_array();
        $schedules = wp_get_schedules();
        $events    = array();

        if ( ! is_array( $crons ) ) {
            return rest_ensure_response( array( 'events' => array(), 'total' => 0 ) );
        }

        foreach ( $crons as $timestamp => $hooks ) {
            foreach ( $hooks as $hook => $entries ) {
                foreach ( $entries as $key => $entry ) {
                    $schedule_name = $entry['schedule'];
                    $interval      = 0;

                    if ( $schedule_name && isset( $schedules[ $schedule_name ] ) ) {
                        $interval = $schedules[ $schedule_name ]['interval'];
                    }

                    $events[] = array(
                        'hook'          => $hook,
                        'next_run'      => gmdate( 'Y-m-d H:i:s', $timestamp ),
                        'next_run_unix' => (int) $timestamp,
                        'schedule'      => $schedule_name ?: 'one-time',
                        'interval_sec'  => (int) $interval,
                        'args'          => $entry['args'],
                    );
                }
            }
        }

        // Sort by next run
        usort( $events, function( $a, $b ) {
            return $a['next_run_unix'] - $b['next_run_unix'];
        } );

        // Available schedules
        $available = array();
        foreach ( $schedules as $name => $info ) {
            $available[] = array(
                'name'     => $name,
                'interval' => $info['interval'],
                'display'  => $info['display'],
            );
        }

        return rest_ensure_response( array(
            'events'     => $events,
            'total'      => count( $events ),
            'schedules'  => $available,
            'cron_status' => defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ? 'disabled' : 'enabled',
        ) );
    }

    /**
     * POST: Trigger a cron hook immediately.
     *
     * Body: { "hook": "wp_scheduled_delete" }
     */
    public function trigger_event( $request ) {
        $body = $request->get_json_params();
        $hook = isset( $body['hook'] ) ? sanitize_text_field( $body['hook'] ) : '';

        if ( empty( $hook ) ) {
            return new WP_Error( 'missing_hook', 'Provide a "hook" name.', array( 'status' => 400 ) );
        }

        // Check hook exists in cron
        $crons = _get_cron_array();
        $found = false;

        if ( is_array( $crons ) ) {
            foreach ( $crons as $timestamp => $hooks ) {
                if ( isset( $hooks[ $hook ] ) ) {
                    $found = true;
                    break;
                }
            }
        }

        if ( ! $found ) {
            return new WP_Error( 'not_found', "Hook '{$hook}' not found in cron.", array( 'status' => 404 ) );
        }

        // Fire it
        do_action( $hook );

        return rest_ensure_response( array(
            'success'   => true,
            'hook'      => $hook,
            'triggered' => current_time( 'mysql' ),
        ) );
    }

    /**
     * DELETE: Remove all scheduled events for a hook.
     */
    public function delete_event( $request ) {
        $hook = sanitize_text_field( $request['hook'] );

        $crons   = _get_cron_array();
        $removed = 0;

        if ( is_array( $crons ) ) {
            foreach ( $crons as $timestamp => $hooks ) {
                if ( isset( $hooks[ $hook ] ) ) {
                    foreach ( $hooks[ $hook ] as $key => $entry ) {
                        wp_unschedule_event( $timestamp, $hook, $entry['args'] );
                        $removed++;
                    }
                }
            }
        }

        if ( $removed === 0 ) {
            return new WP_Error( 'not_found', "No cron events found for hook '{$hook}'.", array( 'status' => 404 ) );
        }

        return rest_ensure_response( array(
            'success' => true,
            'hook'    => $hook,
            'removed' => $removed,
        ) );
    }
}
