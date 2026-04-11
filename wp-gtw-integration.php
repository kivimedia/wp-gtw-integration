<?php
/**
 * Plugin Name: WP-GTW Integration
 * Plugin URI:  https://github.com/kivimedia/wp-gtw-integration
 * Description: WordPress to GoToWebinar integration - auto-detects upcoming sessions, registers via WPForms, replaces Zapier.
 * Version:     2.0.0
 * Author:      Kivi Media
 * Author URI:  https://kivimedia.co
 * License:     GPL-2.0-or-later
 * Text Domain: wp-gtw
 * Requires PHP: 8.0
 * Requires at least: 6.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'WP_GTW_VERSION', '2.0.0' );
define( 'WP_GTW_PATH', plugin_dir_path( __FILE__ ) );
define( 'WP_GTW_URL', plugin_dir_url( __FILE__ ) );

// Autoload classes
require_once WP_GTW_PATH . 'includes/class-gtw-api.php';
require_once WP_GTW_PATH . 'includes/class-gtw-session-resolver.php';
require_once WP_GTW_PATH . 'includes/class-gtw-wpforms-handler.php';
require_once WP_GTW_PATH . 'includes/class-gtw-logger.php';

// Admin pages
if ( is_admin() ) {
    require_once WP_GTW_PATH . 'admin/settings-page.php';
    require_once WP_GTW_PATH . 'admin/log-page.php';
}

/**
 * Activation: create DB tables for activity log and webinar series.
 */
function wp_gtw_activate() {
    global $wpdb;
    $charset = $wpdb->get_charset_collate();

    // Activity log table
    $log_table = $wpdb->prefix . 'gtw_activity_log';
    $sql_log = "CREATE TABLE IF NOT EXISTS {$log_table} (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        registrant_email VARCHAR(255) NOT NULL,
        registrant_name VARCHAR(255) DEFAULT '',
        session_id VARCHAR(100) DEFAULT '',
        webinar_key VARCHAR(100) DEFAULT '',
        status ENUM('success','failed','pending','retrying') NOT NULL DEFAULT 'pending',
        error_message TEXT DEFAULT NULL,
        api_response TEXT DEFAULT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_status (status),
        INDEX idx_created (created_at)
    ) {$charset};";

    // Webinar series table (multi-series ready from day one)
    $series_table = $wpdb->prefix . 'gtw_webinar_series';
    $sql_series = "CREATE TABLE IF NOT EXISTS {$series_table} (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        label VARCHAR(255) NOT NULL,
        webinar_key VARCHAR(100) DEFAULT '',
        name_pattern VARCHAR(255) DEFAULT '',
        wpforms_form_id BIGINT UNSIGNED DEFAULT NULL,
        field_mapping TEXT DEFAULT NULL,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        auto_create_enabled TINYINT(1) DEFAULT 0,
        auto_create_day VARCHAR(20) DEFAULT 'monday',
        auto_create_time VARCHAR(10) DEFAULT '15:00',
        auto_create_duration INT DEFAULT 30,
        auto_create_timezone VARCHAR(50) DEFAULT 'America/New_York',
        cached_session_id VARCHAR(100) DEFAULT NULL,
        cached_session_start DATETIME DEFAULT NULL,
        cached_session_end DATETIME DEFAULT NULL,
        cache_expires_at DATETIME DEFAULT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) {$charset};";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta( $sql_log );
    dbDelta( $sql_series );

    // Set default options
    if ( ! get_option( 'wp_gtw_cache_ttl' ) ) {
        update_option( 'wp_gtw_cache_ttl', 15 ); // minutes
    }
    if ( ! get_option( 'wp_gtw_alert_email' ) ) {
        update_option( 'wp_gtw_alert_email', get_option( 'admin_email' ) );
    }
}
register_activation_hook( __FILE__, 'wp_gtw_activate' );

/**
 * REST API endpoints for remote debugging and configuration.
 */
add_action( 'rest_api_init', function() {
    register_rest_route( 'wp-gtw/v1', '/debug', array(
        'methods'             => 'GET',
        'callback'            => function() {
            $api = new GTW_API();
            $token = $api->get_token();
            $org_key = $api->get_organizer_key();

            $debug = array(
                'has_token'     => ! empty( $token ),
                'token_prefix'  => $token ? substr( $token, 0, 10 ) . '...' : null,
                'organizer_key' => $org_key ?: null,
                'is_connected'  => $api->is_connected(),
            );

            // Try known GoTo API endpoints to find the organizer
            if ( $token ) {
                $org = get_option( 'wp_gtw_organizer_key', '' );
                $from = gmdate( 'Y-m-d\TH:i:s\Z' );
                $to   = gmdate( 'Y-m-d\TH:i:s\Z', time() + 365 * 86400 );
                $endpoints = array(
                    'admin_me'            => 'https://api.getgo.com/admin/rest/v1/me',
                    'g2w_org_webinars'    => "https://api.getgo.com/G2W/rest/v2/organizers/{$org}/webinars?fromTime={$from}&toTime={$to}",
                );

                // After all endpoints tested, extract webinar details
                // (moved after the endpoint loop below)

                foreach ( $endpoints as $name => $url ) {
                    $resp = wp_remote_get( $url, array(
                        'timeout' => 8,
                        'headers' => array( 'Authorization' => 'Bearer ' . $token, 'Accept' => 'application/json' ),
                    ) );
                    $code = wp_remote_retrieve_response_code( $resp );
                    $body = wp_remote_retrieve_body( $resp );
                    $debug['endpoints'][ $name ] = array(
                        'status' => $code,
                        'body'   => json_decode( $body, true ) ?? substr( $body, 0, 500 ),
                    );
                }
            }

            // Extract webinar detail from the endpoint response
            $webinars_resp = $debug['endpoints']['g2w_org_webinars']['body'] ?? array();
            $embedded = is_array( $webinars_resp ) ? ( $webinars_resp['_embedded']['webinars'] ?? $webinars_resp ) : array();
            $all_webinars = array();
            if ( is_array( $embedded ) ) {
                foreach ( $embedded as $w ) {
                    if ( ! is_array( $w ) ) continue;
                    $times = $w['times'] ?? array();
                    $all_webinars[] = array(
                        'subject'        => $w['subject'] ?? '?',
                        'webinarKey'     => $w['webinarKey'] ?? '?',
                        'startTime'      => $times[0]['startTime'] ?? 'unknown',
                        'endTime'        => $times[0]['endTime'] ?? 'unknown',
                        'status'         => $w['status'] ?? '?',
                        'recurrenceType' => $w['recurrenceType'] ?? 'single',
                        'recurrenceKey'  => $w['recurrenceKey'] ?? null,
                    );
                }
            }
            $debug['webinars_detail'] = $all_webinars;

            // Test CREATE capability
            $create_test = wp_remote_post( "https://api.getgo.com/G2W/rest/v2/organizers/{$org}/webinars", array(
                'timeout' => 8,
                'headers' => array(
                    'Authorization' => 'Bearer ' . $token,
                    'Content-Type'  => 'application/json',
                    'Accept'        => 'application/json',
                ),
                'body' => wp_json_encode( array(
                    'subject'     => 'API_CREATE_TEST_DELETE_ME',
                    'description' => 'Testing if API can create webinars - delete this',
                    'times'       => array( array(
                        'startTime' => gmdate( 'Y-m-d\TH:i:s\Z', time() + 30 * 86400 ),
                        'endTime'   => gmdate( 'Y-m-d\TH:i:s\Z', time() + 30 * 86400 + 1800 ),
                    ) ),
                    'timeZone'    => 'America/New_York',
                ) ),
            ) );
            $create_code = wp_remote_retrieve_response_code( $create_test );
            $create_body = json_decode( wp_remote_retrieve_body( $create_test ), true );
            $debug['create_test'] = array(
                'status' => $create_code,
                'body'   => $create_body,
                'can_create' => $create_code >= 200 && $create_code < 300,
            );

            // If test webinar was created, delete it immediately
            if ( $debug['create_test']['can_create'] && ! empty( $create_body['webinarKey'] ) ) {
                $del_key = $create_body['webinarKey'];
                wp_remote_request( "https://api.getgo.com/G2W/rest/v2/organizers/{$org}/webinars/{$del_key}", array(
                    'method'  => 'DELETE',
                    'timeout' => 8,
                    'headers' => array( 'Authorization' => 'Bearer ' . $token ),
                ) );
                $debug['create_test']['deleted_test_webinar'] = $del_key;
            }

            return $debug;
        },
        'permission_callback' => function() {
            return current_user_can( 'manage_options' );
        },
    ) );

    register_rest_route( 'wp-gtw/v1', '/set-organizer', array(
        'methods'             => 'POST',
        'callback'            => function( $request ) {
            $key = sanitize_text_field( $request->get_param( 'organizer_key' ) );
            if ( empty( $key ) ) {
                return new WP_Error( 'missing_key', 'organizer_key is required' );
            }
            update_option( 'wp_gtw_organizer_key', $key );
            return array( 'success' => true, 'organizer_key' => $key );
        },
        'permission_callback' => function() {
            return current_user_can( 'manage_options' );
        },
    ) );
} );

/**
 * Initialize the WPForms handler on every request.
 */
function wp_gtw_init() {
    $handler = new GTW_WPForms_Handler();
    $handler->register_hooks();
}
add_action( 'init', 'wp_gtw_init' );

/**
 * Register WP-Cron event for retry queue.
 */
function wp_gtw_schedule_retry() {
    if ( ! wp_next_scheduled( 'wp_gtw_retry_failed' ) ) {
        wp_schedule_event( time(), 'wp_gtw_one_minute', 'wp_gtw_retry_failed' );
    }
}
add_action( 'wp', 'wp_gtw_schedule_retry' );

/**
 * Custom cron interval: 1 minute.
 */
function wp_gtw_cron_schedules( $schedules ) {
    $schedules['wp_gtw_one_minute'] = array(
        'interval' => 60,
        'display'  => __( 'Every Minute (WP-GTW)', 'wp-gtw' ),
    );
    return $schedules;
}
add_filter( 'cron_schedules', 'wp_gtw_cron_schedules' );

/**
 * Retry failed registrations (WP-Cron callback).
 */
function wp_gtw_process_retry() {
    global $wpdb;
    $table = $wpdb->prefix . 'gtw_activity_log';

    // Find entries marked for retry (status = 'retrying', created in last hour)
    $entries = $wpdb->get_results( $wpdb->prepare(
        "SELECT * FROM {$table} WHERE status = 'retrying' AND created_at > %s ORDER BY created_at ASC LIMIT 5",
        gmdate( 'Y-m-d H:i:s', time() - 3600 )
    ) );

    if ( empty( $entries ) ) {
        return;
    }

    $api = new GTW_API();
    $resolver = new GTW_Session_Resolver( $api );

    foreach ( $entries as $entry ) {
        $session = $resolver->get_upcoming_session( $entry->webinar_key );
        if ( ! $session ) {
            // Still no session - mark as failed
            $wpdb->update( $table, array( 'status' => 'failed', 'error_message' => 'No upcoming session found on retry' ), array( 'id' => $entry->id ) );
            GTW_Logger::send_alert( $entry->registrant_name, $entry->registrant_email, 'No upcoming session found on retry' );
            continue;
        }

        $result = $api->register_attendee( $session['webinarKey'], $session['sessionKey'], array(
            'firstName' => explode( ' ', $entry->registrant_name )[0] ?? '',
            'lastName'  => explode( ' ', $entry->registrant_name, 2 )[1] ?? '',
            'email'     => $entry->registrant_email,
        ) );

        if ( $result['success'] ) {
            $wpdb->update( $table, array(
                'status'     => 'success',
                'session_id' => $session['sessionKey'],
            ), array( 'id' => $entry->id ) );
        } else {
            $wpdb->update( $table, array(
                'status'        => 'failed',
                'error_message' => $result['error'],
            ), array( 'id' => $entry->id ) );
            GTW_Logger::send_alert( $entry->registrant_name, $entry->registrant_email, $result['error'] );
        }
    }
}
add_action( 'wp_gtw_retry_failed', 'wp_gtw_process_retry' );

/**
 * Deactivation: clear scheduled events.
 */
function wp_gtw_deactivate() {
    wp_clear_scheduled_hook( 'wp_gtw_retry_failed' );
}
register_deactivation_hook( __FILE__, 'wp_gtw_deactivate' );
