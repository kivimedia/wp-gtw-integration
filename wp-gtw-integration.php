<?php
/**
 * Plugin Name: WP-GTW Integration
 * Plugin URI:  https://github.com/kivimedia/wp-gtw-integration
 * Description: WordPress to GoToWebinar integration - auto-detects upcoming sessions, registers via WPForms, replaces Zapier.
 * Version:     1.0.0
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

define( 'WP_GTW_VERSION', '1.0.0' );
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
        webinar_key VARCHAR(100) NOT NULL,
        wpforms_form_id BIGINT UNSIGNED DEFAULT NULL,
        field_mapping TEXT DEFAULT NULL,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
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
