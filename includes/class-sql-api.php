<?php
/**
 * Direct SQL query REST API endpoint.
 *
 * Execute read-only (SELECT) queries and limited write queries against
 * the WordPress database. Intended for edge cases no other endpoint covers.
 *
 * POST /spilt-mcp/v1/sql                       — run a query
 * GET  /spilt-mcp/v1/sql/tables                — list all tables
 * GET  /spilt-mcp/v1/sql/describe              — describe a table
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Spilt_MCP_SQL_API {

    /**
     * Allowed write operations (beyond SELECT).
     * UPDATE and DELETE require a WHERE clause.
     * No DROP, TRUNCATE, ALTER, CREATE, or GRANT.
     */
    private $allowed_write = array( 'UPDATE', 'DELETE', 'INSERT' );
    private $blocked_keywords = array(
        'DROP', 'TRUNCATE', 'ALTER', 'CREATE', 'GRANT', 'REVOKE', 'RENAME',
        'OUTFILE', 'INFILE', 'DUMPFILE', 'LOAD', 'EXECUTE', 'CALL', 'SET',
    );

    public function register_routes() {
        register_rest_route( 'spilt-mcp/v1', '/sql', array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'run_query' ),
            'permission_callback' => 'spilt_mcp_admin_check',
        ) );

        register_rest_route( 'spilt-mcp/v1', '/sql/tables', array(
            'methods'             => 'GET',
            'callback'            => array( $this, 'list_tables' ),
            'permission_callback' => 'spilt_mcp_admin_check',
        ) );

        register_rest_route( 'spilt-mcp/v1', '/sql/describe', array(
            'methods'             => 'GET',
            'callback'            => array( $this, 'describe_table' ),
            'permission_callback' => 'spilt_mcp_admin_check',
            'args'                => array(
                'table' => array( 'required' => true, 'sanitize_callback' => 'sanitize_text_field' ),
            ),
        ) );
    }

    /**
     * POST: Execute a SQL query.
     *
     * Body: {
     *   "query": "SELECT * FROM wp_posts WHERE post_type = 'post' LIMIT 10",
     *   "allow_write": false
     * }
     */
    public function run_query( $request ) {
        global $wpdb;

        $body        = $request->get_json_params();
        $query       = isset( $body['query'] ) ? trim( $body['query'] ) : '';
        $allow_write = isset( $body['allow_write'] ) ? (bool) $body['allow_write'] : false;

        if ( empty( $query ) ) {
            return new WP_Error( 'no_query', 'Provide a "query" string.', array( 'status' => 400 ) );
        }

        // Block multi-statement queries (semicolons)
        // Strip semicolons inside quoted strings first, then check
        $stripped = preg_replace( "/'[^']*'/", '', $query );
        $stripped = preg_replace( '/"[^"]*"/', '', $stripped );
        if ( strpos( $stripped, ';' ) !== false ) {
            return new WP_Error(
                'multi_statement',
                'Multi-statement queries (semicolons) are not allowed.',
                array( 'status' => 403 )
            );
        }

        // Determine query type
        $first_word = strtoupper( strtok( $query, " \t\n\r" ) );

        // Block dangerous keywords (check as whole words to reduce false positives)
        foreach ( $this->blocked_keywords as $kw ) {
            if ( preg_match( '/\b' . $kw . '\b/i', $stripped ) ) {
                return new WP_Error(
                    'blocked_operation',
                    "{$kw} operations are not allowed.",
                    array( 'status' => 403 )
                );
            }
        }

        // Handle SELECT queries
        if ( $first_word === 'SELECT' || $first_word === 'SHOW' || $first_word === 'DESCRIBE' || $first_word === 'EXPLAIN' ) {
            $results = $wpdb->get_results( $query, ARRAY_A );

            if ( $wpdb->last_error ) {
                return new WP_Error( 'query_error', $wpdb->last_error, array( 'status' => 400 ) );
            }

            return rest_ensure_response( array(
                'success'   => true,
                'type'      => 'read',
                'rows'      => $results,
                'row_count' => count( $results ),
                'query'     => $query,
            ) );
        }

        // Handle write queries
        if ( ! $allow_write ) {
            return new WP_Error(
                'write_not_allowed',
                'This is a write query. Set "allow_write": true to execute.',
                array( 'status' => 403 )
            );
        }

        if ( ! in_array( $first_word, $this->allowed_write, true ) ) {
            return new WP_Error(
                'operation_not_allowed',
                "Only SELECT, INSERT, UPDATE, DELETE are allowed. Got: {$first_word}",
                array( 'status' => 403 )
            );
        }

        // UPDATE and DELETE must have WHERE clause
        if ( in_array( $first_word, array( 'UPDATE', 'DELETE' ), true ) ) {
            if ( stripos( $query, 'WHERE' ) === false ) {
                return new WP_Error(
                    'no_where_clause',
                    "{$first_word} without WHERE is not allowed. Add a WHERE clause.",
                    array( 'status' => 403 )
                );
            }
        }

        $result = $wpdb->query( $query );

        if ( $wpdb->last_error ) {
            return new WP_Error( 'query_error', $wpdb->last_error, array( 'status' => 400 ) );
        }

        return rest_ensure_response( array(
            'success'       => true,
            'type'          => 'write',
            'affected_rows' => (int) $result,
            'insert_id'     => $wpdb->insert_id ?: null,
            'query'         => $query,
        ) );
    }

    /**
     * GET: List all database tables.
     */
    public function list_tables( $request ) {
        global $wpdb;

        $tables = $wpdb->get_results( 'SHOW TABLE STATUS', ARRAY_A );

        $result = array();
        foreach ( $tables as $table ) {
            $result[] = array(
                'name'       => $table['Name'],
                'engine'     => $table['Engine'],
                'rows'       => (int) $table['Rows'],
                'size'       => size_format( $table['Data_length'] + $table['Index_length'] ),
                'size_bytes' => (int) $table['Data_length'] + (int) $table['Index_length'],
                'collation'  => $table['Collation'],
            );
        }

        // Sort by size descending
        usort( $result, function( $a, $b ) {
            return $b['size_bytes'] - $a['size_bytes'];
        } );

        $total_size = array_sum( array_column( $result, 'size_bytes' ) );

        return rest_ensure_response( array(
            'total_tables' => count( $result ),
            'total_size'   => size_format( $total_size ),
            'wp_prefix'    => $wpdb->prefix,
            'tables'       => $result,
        ) );
    }

    /**
     * GET: Describe a table's columns.
     */
    public function describe_table( $request ) {
        global $wpdb;

        $table = sanitize_text_field( $request->get_param( 'table' ) );

        // Verify table exists and belongs to this database
        $exists = $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = %s AND table_name = %s",
            DB_NAME,
            $table
        ) );

        if ( ! $exists ) {
            return new WP_Error( 'not_found', "Table '{$table}' not found.", array( 'status' => 404 ) );
        }

        $columns = $wpdb->get_results( "DESCRIBE `{$table}`", ARRAY_A );
        $indexes = $wpdb->get_results( "SHOW INDEX FROM `{$table}`", ARRAY_A );

        $row_count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$table}`" );

        return rest_ensure_response( array(
            'table'     => $table,
            'columns'   => $columns,
            'indexes'   => $indexes,
            'row_count' => $row_count,
        ) );
    }
}
