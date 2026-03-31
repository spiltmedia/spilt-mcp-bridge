<?php
/**
 * Recurring WordPress QC snapshot REST API endpoint.
 *
 * Returns a normalized, detection-only snapshot for agency WordPress quality
 * checks such as favicon status, plugin/config presence, admin users, and
 * featured image coverage.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Spilt_MCP_WordPress_QC_API {

    private $accessibility_allowlist = array(
        'pojo-accessibility'          => 'Pojo Accessibility',
        'one-click-accessibility'     => 'One Click Accessibility',
        'wp-accessibility'            => 'WP Accessibility',
        'userway'                     => 'UserWay',
        'userway-accessibility-widget' => 'UserWay Accessibility Widget',
    );

    public function register_routes() {
        register_rest_route(
            'spilt-mcp/v1',
            '/wordpress-qc',
            array(
                'methods'             => 'GET',
                'callback'            => array( $this, 'get_snapshot' ),
                'permission_callback' => 'spilt_mcp_admin_check',
            )
        );
    }

    public function get_snapshot( $request ) {
        if ( ! function_exists( 'get_plugins' ) ) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        $plugin_inventory = $this->build_plugin_inventory();
        $homepage         = $this->fetch_homepage_markup();
        $favicon          = $this->evaluate_favicon( $homepage );
        $mailgun_options  = $this->scan_options( array( 'mailgun' ) );
        $turnstile_options = $this->scan_options( array( 'turnstile', 'cfturnstile', 'simple_cloudflare_turnstile' ) );
        $mailgun          = $this->evaluate_mailgun( $plugin_inventory, $mailgun_options );
        $turnstile        = $this->evaluate_turnstile( $plugin_inventory, $turnstile_options );
        $admin_users      = $this->administrator_users();
        $featured_images  = $this->evaluate_featured_images();
        $accessibility    = $this->evaluate_accessibility( $plugin_inventory );

        return rest_ensure_response(
            array(
                'generated_at'    => gmdate( 'c' ),
                'site'            => array(
                    'home_url'      => home_url( '/' ),
                    'site_icon_id'  => (int) get_option( 'site_icon' ),
                    'site_icon_url' => $this->site_icon_url(),
                    'admin_email'   => sanitize_email( get_option( 'admin_email', '' ) ),
                ),
                'plugins'         => array(
                    'active'        => $plugin_inventory['active'],
                    'installed'     => $plugin_inventory['installed'],
                    'active_slugs'  => array_values( array_map( array( $this, 'plugin_export' ), $plugin_inventory['active'] ) ),
                    'installed_slugs' => array_values( array_map( array( $this, 'plugin_export' ), $plugin_inventory['installed'] ) ),
                ),
                'favicon'         => $favicon,
                'mailgun'         => $mailgun,
                'turnstile'       => $turnstile,
                'admin'           => array(
                    'admin_email'           => sanitize_email( get_option( 'admin_email', '' ) ),
                    'administrator_emails'  => array_values( array_unique( array_map( 'sanitize_email', wp_list_pluck( $admin_users, 'email' ) ) ) ),
                    'administrator_users'   => $admin_users,
                ),
                'featured_images' => $featured_images,
                'accessibility'   => $accessibility,
            )
        );
    }

    private function plugin_export( $plugin ) {
        return array(
            'slug' => $plugin['slug'],
            'name' => $plugin['name'],
            'file' => $plugin['file'],
        );
    }

    private function build_plugin_inventory() {
        $all_plugins    = get_plugins();
        $active_plugins = get_option( 'active_plugins', array() );
        $installed      = array();
        $active         = array();

        foreach ( $all_plugins as $file => $data ) {
            $plugin = array(
                'slug'   => $this->plugin_slug_from_file( $file ),
                'file'   => $file,
                'name'   => isset( $data['Name'] ) ? wp_strip_all_tags( $data['Name'] ) : $file,
                'active' => in_array( $file, $active_plugins, true ),
            );
            $installed[] = $plugin;
            if ( $plugin['active'] ) {
                $active[] = $plugin;
            }
        }

        return array(
            'installed' => $installed,
            'active'    => $active,
        );
    }

    private function plugin_slug_from_file( $file ) {
        $parts = explode( '/', (string) $file );
        if ( count( $parts ) > 1 && ! empty( $parts[0] ) ) {
            return sanitize_title( $parts[0] );
        }
        return sanitize_title( basename( (string) $file, '.php' ) );
    }

    private function matching_plugins( $plugins, $needles ) {
        $matches = array();
        foreach ( $plugins as $plugin ) {
            $haystack = strtolower( implode( ' ', array( $plugin['slug'], $plugin['file'], $plugin['name'] ) ) );
            foreach ( $needles as $needle ) {
                if ( strpos( $haystack, strtolower( $needle ) ) !== false ) {
                    $matches[] = $plugin;
                    break;
                }
            }
        }
        return array_values( $matches );
    }

    private function site_icon_url() {
        $site_icon_id = (int) get_option( 'site_icon' );
        return $site_icon_id ? wp_get_attachment_url( $site_icon_id ) : '';
    }

    private function fetch_homepage_markup() {
        $response = wp_remote_get(
            home_url( '/' ),
            array(
                'timeout'     => 15,
                'redirection' => 5,
            )
        );
        if ( is_wp_error( $response ) ) {
            return array(
                'html'   => '',
                'error'  => $response->get_error_message(),
                'status' => 0,
            );
        }

        return array(
            'html'   => wp_remote_retrieve_body( $response ),
            'error'  => '',
            'status' => (int) wp_remote_retrieve_response_code( $response ),
        );
    }

    private function evaluate_favicon( $homepage ) {
        $site_icon_id  = (int) get_option( 'site_icon' );
        $site_icon_url = $this->site_icon_url();
        $public_icons  = $this->extract_icon_links( isset( $homepage['html'] ) ? (string) $homepage['html'] : '' );
        $root_icon     = $this->root_favicon_url();
        $public_icon_candidates = $public_icons;
        if ( empty( $public_icon_candidates ) && $root_icon ) {
            $public_icon_candidates[] = $root_icon;
        }

        $custom_site_icon   = $site_icon_id > 0 && $this->is_custom_icon_url( $site_icon_url );
        $custom_public_icon = false;
        foreach ( $public_icon_candidates as $candidate ) {
            if ( $this->is_custom_icon_url( $candidate ) ) {
                $custom_public_icon = true;
                break;
            }
        }

        $status = ( $custom_site_icon || $custom_public_icon ) ? 'pass' : 'fail';
        if ( 'pass' === $status ) {
            $reason = 'Custom favicon detected via WordPress site identity or public icon tags.';
        } elseif ( ! empty( $homepage['error'] ) ) {
            $reason = 'Could not verify public favicon links and no custom WordPress site icon is set.';
        } else {
            $reason = 'No custom favicon was detected in WordPress site identity or public icon tags.';
        }

        return array(
            'status'            => $status,
            'pass'              => 'pass' === $status,
            'site_icon_id'      => $site_icon_id,
            'site_icon_url'     => $site_icon_url,
            'public_icon_urls'  => array_values( array_unique( $public_icon_candidates ) ),
            'public_fetch_error' => (string) ( $homepage['error'] ?? '' ),
            'reason'            => $reason,
        );
    }

    private function root_favicon_url() {
        $candidate = home_url( '/favicon.ico' );
        $response  = wp_remote_head(
            $candidate,
            array(
                'timeout'     => 10,
                'redirection' => 3,
            )
        );
        if ( is_wp_error( $response ) ) {
            return '';
        }
        $status = (int) wp_remote_retrieve_response_code( $response );
        if ( $status >= 200 && $status < 400 ) {
            return $candidate;
        }
        return '';
    }

    private function extract_icon_links( $html ) {
        if ( empty( $html ) ) {
            return array();
        }
        preg_match_all( '/<link\b[^>]*>/i', $html, $matches );
        $icons = array();
        foreach ( $matches[0] as $tag ) {
            $rel = strtolower( $this->extract_attribute( $tag, 'rel' ) );
            if ( false === strpos( $rel, 'icon' ) ) {
                continue;
            }
            $href = $this->extract_attribute( $tag, 'href' );
            if ( ! $href ) {
                continue;
            }
            $icons[] = $this->normalize_public_url( $href );
        }
        return array_values( array_filter( array_unique( $icons ) ) );
    }

    private function extract_attribute( $tag, $attribute ) {
        $pattern = '/\b' . preg_quote( $attribute, '/' ) . '\s*=\s*(["\'])(.*?)\1/i';
        if ( preg_match( $pattern, $tag, $match ) ) {
            return html_entity_decode( trim( $match[2] ) );
        }
        $pattern = '/\b' . preg_quote( $attribute, '/' ) . '\s*=\s*([^\s>]+)/i';
        if ( preg_match( $pattern, $tag, $match ) ) {
            return html_entity_decode( trim( $match[1], "\"' " ) );
        }
        return '';
    }

    private function normalize_public_url( $href ) {
        $href = trim( (string) $href );
        if ( '' === $href ) {
            return '';
        }
        if ( 0 === strpos( $href, '//' ) ) {
            $scheme = wp_parse_url( home_url( '/' ), PHP_URL_SCHEME ) ?: 'https';
            return $scheme . ':' . $href;
        }
        if ( preg_match( '#^https?://#i', $href ) ) {
            return $href;
        }
        if ( 0 === strpos( $href, '/' ) ) {
            return home_url( $href );
        }
        return home_url( '/' . ltrim( $href, '/' ) );
    }

    private function is_custom_icon_url( $url ) {
        $url = strtolower( trim( (string) $url ) );
        if ( '' === $url || 0 === strpos( $url, 'data:' ) ) {
            return false;
        }
        $blocked = array(
            'w-logo-blue-white-bg',
            'wordpress-logo',
            'wordpress.org/favicon',
            's.w.org/images/core',
            '/wp-admin/images/w-logo',
        );
        foreach ( $blocked as $token ) {
            if ( false !== strpos( $url, $token ) ) {
                return false;
            }
        }
        return true;
    }

    private function scan_options( $terms ) {
        global $wpdb;
        $terms   = array_values( array_unique( array_filter( array_map( 'strval', $terms ) ) ) );
        $clauses = array();
        $args    = array();
        foreach ( $terms as $term ) {
            $clauses[] = 'option_name LIKE %s';
            $args[]    = '%' . $wpdb->esc_like( $term ) . '%';
        }
        if ( empty( $clauses ) ) {
            return array();
        }

        $sql = "SELECT option_name, option_value FROM {$wpdb->options} WHERE " . implode( ' OR ', $clauses );
        $prepared = $wpdb->prepare( $sql, $args );
        $rows = $wpdb->get_results( $prepared, ARRAY_A );

        $results = array();
        foreach ( $rows as $row ) {
            $value = maybe_unserialize( $row['option_value'] );
            $results[] = array(
                'option_name' => (string) $row['option_name'],
                'flattened'   => $this->flatten_option_value( $value, (string) $row['option_name'] ),
            );
        }
        return $results;
    }

    private function flatten_option_value( $value, $prefix ) {
        $flat = array();
        if ( is_array( $value ) ) {
            foreach ( $value as $key => $child ) {
                $child_prefix = $prefix . '.' . sanitize_key( (string) $key );
                $flat = array_merge( $flat, $this->flatten_option_value( $child, $child_prefix ) );
            }
            return $flat;
        }
        if ( is_object( $value ) ) {
            return $this->flatten_option_value( get_object_vars( $value ), $prefix );
        }
        $flat[] = array(
            'path'  => $prefix,
            'value' => $value,
        );
        return $flat;
    }

    private function evaluate_mailgun( $plugin_inventory, $option_rows ) {
        $installed = $this->matching_plugins( $plugin_inventory['installed'], array( 'mailgun' ) );
        $active    = $this->matching_plugins( $plugin_inventory['active'], array( 'mailgun' ) );
        $signals   = $this->flattened_signal_rows( $option_rows );
        $domain_paths = $this->matching_signal_paths(
            $signals,
            function( $entry ) {
                return $this->path_has_any( $entry['path'], array( 'domain' ) ) && $this->looks_like_domain( $entry['value'] );
            }
        );
        $api_key_paths = $this->matching_signal_paths(
            $signals,
            function( $entry ) {
                return $this->path_has_any( $entry['path'], array( 'api', 'key' ) ) && $this->has_non_placeholder_secret( $entry['value'] );
            }
        );
        $from_address_paths = $this->matching_signal_paths(
            $signals,
            function( $entry ) {
                return $this->path_has_any( $entry['path'], array( 'from', 'sender' ) ) && $this->looks_like_email( $entry['value'] );
            }
        );
        $test_success_paths = $this->matching_signal_paths(
            $signals,
            function( $entry ) {
                return $this->path_has_any( $entry['path'], array( 'test', 'verify', 'valid', 'status', 'success' ) )
                    && $this->is_success_value( $entry['value'] );
            }
        );

        $configured = ! empty( $active ) && ! empty( $domain_paths ) && ( ! empty( $api_key_paths ) || ! empty( $from_address_paths ) );
        $test_proven = ! empty( $test_success_paths );

        if ( empty( $installed ) ) {
            $status = 'fail';
            $reason = 'Mailgun plugin is not installed.';
        } elseif ( empty( $active ) ) {
            $status = 'fail';
            $reason = 'Mailgun plugin is installed but not active.';
        } elseif ( ! $configured ) {
            $status = 'fail';
            $reason = 'Mailgun is active but the expected configuration signals were not found.';
        } elseif ( $test_proven ) {
            $status = 'pass';
            $reason = 'Mailgun appears configured and a success-style verification signal was found.';
        } else {
            $status = 'manual_verify';
            $reason = 'Mailgun appears configured, but the plugin test-success state could not be proven automatically.';
        }

        return array(
            'status'               => $status,
            'installed'            => ! empty( $installed ),
            'active'               => ! empty( $active ),
            'matched_plugins'      => array_values( array_map( array( $this, 'plugin_export' ), ! empty( $active ) ? $active : $installed ) ),
            'matched_option_names' => array_values( array_unique( array_map( function( $row ) {
                return $row['option_name'];
            }, $option_rows ) ) ),
            'has_domain'           => ! empty( $domain_paths ),
            'has_api_key'          => ! empty( $api_key_paths ),
            'has_from_address'     => ! empty( $from_address_paths ),
            'test_success_proven'  => $test_proven,
            'configuration_signals' => array_values( array_unique( array_merge( $domain_paths, $api_key_paths, $from_address_paths ) ) ),
            'reason'               => $reason,
        );
    }

    private function evaluate_turnstile( $plugin_inventory, $option_rows ) {
        $installed = $this->matching_plugins( $plugin_inventory['installed'], array( 'turnstile', 'simple-cloudflare-turnstile' ) );
        $active    = $this->matching_plugins( $plugin_inventory['active'], array( 'turnstile', 'simple-cloudflare-turnstile' ) );
        $signals   = $this->flattened_signal_rows( $option_rows );
        $site_key_paths = $this->matching_signal_paths(
            $signals,
            function( $entry ) {
                return $this->path_has_any( $entry['path'], array( 'sitekey', 'site_key', 'site-key' ) ) && $this->has_non_placeholder_secret( $entry['value'] );
            }
        );
        $secret_key_paths = $this->matching_signal_paths(
            $signals,
            function( $entry ) {
                return $this->path_has_any( $entry['path'], array( 'secret', 'secret_key', 'secret-key' ) ) && $this->has_non_placeholder_secret( $entry['value'] );
            }
        );
        $enabled_form_paths = $this->matching_signal_paths(
            $signals,
            function( $entry ) {
                return $this->looks_like_turnstile_form_toggle( $entry['path'] ) && $this->is_truthy_value( $entry['value'] );
            }
        );
        $test_success_paths = $this->matching_signal_paths(
            $signals,
            function( $entry ) {
                return $this->path_has_any( $entry['path'], array( 'test', 'verify', 'verified', 'success', 'status' ) )
                    && $this->is_success_value( $entry['value'] );
            }
        );

        $configured = ! empty( $active ) && ! empty( $site_key_paths ) && ! empty( $secret_key_paths ) && ! empty( $enabled_form_paths );
        $test_proven = ! empty( $test_success_paths );

        if ( empty( $installed ) ) {
            $status = 'fail';
            $reason = 'Cloudflare Turnstile plugin is not installed.';
        } elseif ( empty( $active ) ) {
            $status = 'fail';
            $reason = 'Cloudflare Turnstile plugin is installed but not active.';
        } elseif ( empty( $site_key_paths ) || empty( $secret_key_paths ) ) {
            $status = 'fail';
            $reason = 'Cloudflare Turnstile is active but the site key or secret key is missing.';
        } elseif ( empty( $enabled_form_paths ) ) {
            $status = 'fail';
            $reason = 'Cloudflare Turnstile is active but no enabled form integrations were detected.';
        } elseif ( $test_proven ) {
            $status = 'pass';
            $reason = 'Cloudflare Turnstile appears configured and a success-style verification signal was found.';
        } else {
            $status = 'manual_verify';
            $reason = 'Cloudflare Turnstile appears configured, but the plugin test-response success state could not be proven automatically.';
        }

        return array(
            'status'               => $status,
            'installed'            => ! empty( $installed ),
            'active'               => ! empty( $active ),
            'matched_plugins'      => array_values( array_map( array( $this, 'plugin_export' ), ! empty( $active ) ? $active : $installed ) ),
            'matched_option_names' => array_values( array_unique( array_map( function( $row ) {
                return $row['option_name'];
            }, $option_rows ) ) ),
            'has_site_key'         => ! empty( $site_key_paths ),
            'has_secret_key'       => ! empty( $secret_key_paths ),
            'enabled_form_signals' => $enabled_form_paths,
            'test_success_proven'  => $test_proven,
            'reason'               => $reason,
        );
    }

    private function flattened_signal_rows( $option_rows ) {
        $signals = array();
        foreach ( $option_rows as $row ) {
            foreach ( $row['flattened'] as $entry ) {
                $signals[] = array(
                    'path'  => strtolower( (string) $entry['path'] ),
                    'value' => $entry['value'],
                );
            }
        }
        return $signals;
    }

    private function matching_signal_paths( $signals, $callback ) {
        $paths = array();
        foreach ( $signals as $entry ) {
            if ( call_user_func( $callback, $entry ) ) {
                $paths[] = $entry['path'];
            }
        }
        return array_values( array_unique( $paths ) );
    }

    private function path_has_any( $path, $tokens ) {
        foreach ( $tokens as $token ) {
            if ( false !== strpos( (string) $path, strtolower( (string) $token ) ) ) {
                return true;
            }
        }
        return false;
    }

    private function looks_like_domain( $value ) {
        $value = strtolower( trim( (string) $value ) );
        if ( '' === $value || false !== strpos( $value, '@' ) ) {
            return false;
        }
        return (bool) preg_match( '/^[a-z0-9.-]+\.[a-z]{2,}$/', $value );
    }

    private function looks_like_email( $value ) {
        return (bool) is_email( trim( (string) $value ) );
    }

    private function has_non_placeholder_secret( $value ) {
        $value = trim( (string) $value );
        if ( strlen( $value ) < 8 ) {
            return false;
        }
        $lower = strtolower( $value );
        $blocked = array( 'your-', 'placeholder', 'example', 'xxxx', '****', 'changeme', 'replace-me' );
        foreach ( $blocked as $token ) {
            if ( false !== strpos( $lower, $token ) ) {
                return false;
            }
        }
        return true;
    }

    private function is_success_value( $value ) {
        if ( is_bool( $value ) ) {
            return $value;
        }
        if ( is_numeric( $value ) ) {
            return (int) $value > 0;
        }
        $value = strtolower( trim( (string) $value ) );
        return in_array( $value, array( '1', 'true', 'yes', 'ok', 'success', 'passed', 'valid', 'verified', 'working' ), true );
    }

    private function is_truthy_value( $value ) {
        if ( is_bool( $value ) ) {
            return $value;
        }
        if ( is_numeric( $value ) ) {
            return (int) $value > 0;
        }
        $value = strtolower( trim( (string) $value ) );
        return in_array( $value, array( '1', 'true', 'yes', 'on', 'enabled', 'active' ), true );
    }

    private function looks_like_turnstile_form_toggle( $path ) {
        $path = strtolower( (string) $path );
        if ( ! $this->path_has_any( $path, array( 'form', 'comment', 'login', 'register', 'password', 'woocommerce', 'elementor', 'wpforms', 'gravity', 'contact_form_7', 'cf7' ) ) ) {
            return false;
        }
        if ( $this->path_has_any( $path, array( 'sitekey', 'site_key', 'secret', 'appearance', 'theme', 'language', 'retry', 'error', 'message' ) ) ) {
            return false;
        }
        return true;
    }

    private function administrator_users() {
        $users = get_users(
            array(
                'role__in' => array( 'administrator' ),
                'orderby'  => 'registered',
                'order'    => 'ASC',
            )
        );
        $rows = array();
        foreach ( $users as $user ) {
            $rows[] = array(
                'id'           => (int) $user->ID,
                'login'        => $user->user_login,
                'email'        => $user->user_email,
                'display_name' => $user->display_name,
            );
        }
        return $rows;
    }

    private function evaluate_featured_images() {
        $post_types = get_post_types( array( 'public' => true ), 'objects' );
        $unsupported = array();
        $missing     = array();
        $scanned     = array();
        $skipped     = array();

        foreach ( $post_types as $post_type => $object ) {
            if ( 'attachment' === $post_type || ! is_post_type_viewable( $object ) ) {
                continue;
            }
            if ( ! post_type_supports( $post_type, 'thumbnail' ) ) {
                $entry = array(
                    'post_type' => $post_type,
                    'label'     => $object->labels->singular_name ?: $post_type,
                );
                if ( in_array( $post_type, array( 'post', 'page' ), true ) ) {
                    $unsupported[] = $entry;
                } else {
                    $skipped[] = $entry;
                }
                continue;
            }

            $scanned[] = array(
                'post_type' => $post_type,
                'label'     => $object->labels->singular_name ?: $post_type,
            );

            $posts = get_posts(
                array(
                    'post_type'      => $post_type,
                    'post_status'    => 'publish',
                    'posts_per_page' => -1,
                    'fields'         => 'ids',
                    'meta_query'     => array(
                        'relation' => 'OR',
                        array(
                            'key'     => '_thumbnail_id',
                            'compare' => 'NOT EXISTS',
                        ),
                        array(
                            'key'     => '_thumbnail_id',
                            'value'   => '',
                            'compare' => '=',
                        ),
                        array(
                            'key'     => '_thumbnail_id',
                            'value'   => '0',
                            'compare' => '=',
                        ),
                    ),
                )
            );

            foreach ( $posts as $post_id ) {
                $missing[] = array(
                    'post_id'         => (int) $post_id,
                    'post_type'       => $post_type,
                    'post_type_label' => $object->labels->singular_name ?: $post_type,
                    'title'           => get_the_title( $post_id ),
                    'url'             => get_permalink( $post_id ),
                );
            }
        }

        $total_missing = count( $missing );
        $status = ( 0 === $total_missing && empty( $unsupported ) ) ? 'pass' : 'fail';
        $items  = array_slice( $missing, 0, 250 );

        if ( ! empty( $unsupported ) ) {
            $reason = 'One or more public post types do not support featured images.';
        } elseif ( $total_missing > 0 ) {
            $reason = 'One or more published items are missing featured images.';
        } else {
            $reason = 'All scanned public content types support thumbnails and have featured images.';
        }

        return array(
            'status'                => $status,
            'pass'                  => 'pass' === $status,
            'total_missing'         => $total_missing,
            'unsupported_post_types' => $unsupported,
            'scanned_post_types'    => $scanned,
            'skipped_post_types'    => $skipped,
            'items'                 => $items,
            'truncated'             => $total_missing > count( $items ),
            'reason'                => $reason,
        );
    }

    private function evaluate_accessibility( $plugin_inventory ) {
        $active_matches = array();
        foreach ( $plugin_inventory['active'] as $plugin ) {
            foreach ( array_keys( $this->accessibility_allowlist ) as $slug ) {
                $haystack = strtolower( implode( ' ', array( $plugin['slug'], $plugin['file'], $plugin['name'] ) ) );
                if ( false !== strpos( $haystack, strtolower( $slug ) ) ) {
                    $active_matches[] = $plugin;
                    break;
                }
            }
        }

        $status = empty( $active_matches ) ? 'fail' : 'pass';
        return array(
            'status'          => $status,
            'pass'            => 'pass' === $status,
            'allowlist'       => $this->accessibility_allowlist,
            'matched_plugins' => array_values( array_map( array( $this, 'plugin_export' ), $active_matches ) ),
            'reason'          => empty( $active_matches )
                ? 'No approved accessibility plugin is active.'
                : 'An approved accessibility plugin is active.',
        );
    }
}
