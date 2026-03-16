<?php
/**
 * User audit REST API endpoint.
 *
 * List all WordPress users with roles, capabilities, last login,
 * and application password status.
 *
 * GET /spilt-mcp/v1/users                      — list all users
 * GET /spilt-mcp/v1/users/(?P<id>\d+)          — single user detail
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Spilt_MCP_User_Audit_API {

    public function register_routes() {
        register_rest_route( 'spilt-mcp/v1', '/users', array(
            'methods'             => 'GET',
            'callback'            => array( $this, 'list_users' ),
            'permission_callback' => 'spilt_mcp_admin_check',
        ) );

        register_rest_route( 'spilt-mcp/v1', '/users/(?P<id>\\d+)', array(
            'methods'             => 'GET',
            'callback'            => array( $this, 'get_user' ),
            'permission_callback' => 'spilt_mcp_admin_check',
        ) );
    }

    /**
     * GET: List all users with audit info.
     */
    public function list_users( $request ) {
        $users = get_users( array( 'orderby' => 'registered', 'order' => 'DESC' ) );

        $result = array();
        foreach ( $users as $user ) {
            $result[] = $this->format_user( $user );
        }

        // Role summary
        $role_counts = array();
        foreach ( $result as $u ) {
            foreach ( $u['roles'] as $role ) {
                if ( ! isset( $role_counts[ $role ] ) ) {
                    $role_counts[ $role ] = 0;
                }
                $role_counts[ $role ]++;
            }
        }

        return rest_ensure_response( array(
            'total'       => count( $result ),
            'role_counts' => $role_counts,
            'users'       => $result,
        ) );
    }

    /**
     * GET: Single user detail.
     */
    public function get_user( $request ) {
        $user_id = (int) $request['id'];
        $user    = get_user_by( 'ID', $user_id );

        if ( ! $user ) {
            return new WP_Error( 'not_found', "User {$user_id} not found.", array( 'status' => 404 ) );
        }

        $data = $this->format_user( $user );

        // Add extra detail for single user view
        $data['capabilities'] = array_keys( array_filter( $user->allcaps ) );
        $data['post_count']   = count_user_posts( $user_id, 'post', true );
        $data['page_count']   = count_user_posts( $user_id, 'page', true );

        return rest_ensure_response( $data );
    }

    /**
     * Format a user object for output.
     */
    private function format_user( $user ) {
        // Last login — check common meta keys used by various plugins
        $last_login = get_user_meta( $user->ID, 'last_login', true );
        if ( ! $last_login ) {
            $last_login = get_user_meta( $user->ID, 'wfls-last-login', true ); // Wordfence
        }
        if ( ! $last_login ) {
            $last_login = get_user_meta( $user->ID, 'session_tokens', true );
            if ( is_array( $last_login ) && ! empty( $last_login ) ) {
                $sessions   = array_values( $last_login );
                $latest     = end( $sessions );
                $last_login = isset( $latest['login'] ) ? gmdate( 'Y-m-d H:i:s', $latest['login'] ) : null;
            } else {
                $last_login = null;
            }
        }

        // Application passwords
        $app_passwords = WP_Application_Passwords::get_user_application_passwords( $user->ID );
        $app_pass_info = array();
        foreach ( $app_passwords as $ap ) {
            $app_pass_info[] = array(
                'name'      => $ap['name'],
                'created'   => gmdate( 'Y-m-d H:i:s', $ap['created'] ),
                'last_used' => $ap['last_used'] ? gmdate( 'Y-m-d H:i:s', $ap['last_used'] ) : null,
                'last_ip'   => $ap['last_ip'] ?? null,
            );
        }

        return array(
            'id'                  => $user->ID,
            'login'               => $user->user_login,
            'email'               => $user->user_email,
            'display_name'        => $user->display_name,
            'roles'               => $user->roles,
            'registered'          => $user->user_registered,
            'last_login'          => $last_login,
            'app_passwords'       => $app_pass_info,
            'app_password_count'  => count( $app_passwords ),
        );
    }
}
