<?php
/**
 * Logger - activity log entries and admin email alerts.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class GTW_Logger {

    /**
     * Log a registration attempt.
     */
    public static function log_entry( array $data ): void {
        global $wpdb;
        $table = $wpdb->prefix . 'gtw_activity_log';

        $wpdb->insert( $table, array(
            'registrant_email' => $data['registrant_email'] ?? '',
            'registrant_name'  => $data['registrant_name'] ?? '',
            'session_id'       => $data['session_id'] ?? '',
            'webinar_key'      => $data['webinar_key'] ?? '',
            'status'           => $data['status'] ?? 'pending',
            'error_message'    => $data['error_message'] ?? null,
            'api_response'     => $data['api_response'] ?? null,
            'created_at'       => current_time( 'mysql', true ),
        ) );
    }

    /**
     * Send an admin alert email.
     */
    public static function send_alert( string $registrant_name, string $registrant_email, string $reason ): void {
        $to = get_option( 'wp_gtw_alert_email', get_option( 'admin_email' ) );
        if ( empty( $to ) ) {
            return;
        }

        $site_name = get_bloginfo( 'name' );
        $subject   = "[{$site_name}] GoToWebinar Registration Failed";
        $message   = "A GoToWebinar registration has failed.\n\n";
        $message  .= "Registrant: {$registrant_name}\n";
        $message  .= "Email: {$registrant_email}\n";
        $message  .= "Reason: {$reason}\n";
        $message  .= "Time: " . current_time( 'Y-m-d H:i:s T' ) . "\n\n";
        $message  .= "Log in to WP Admin > Settings > GTW Integration > Activity Log to review.\n";
        $message  .= admin_url( 'admin.php?page=wp-gtw-log' );

        wp_mail( $to, $subject, $message );
    }

    /**
     * Log an API-level error (for debugging, separate from registration log).
     */
    public static function log_api_error( string $context, string $error ): void {
        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            error_log( "[WP-GTW] API Error ({$context}): {$error}" );
        }
    }

    /**
     * Get recent log entries.
     */
    public static function get_entries( int $limit = 100, int $offset = 0 ): array {
        global $wpdb;
        $table = $wpdb->prefix . 'gtw_activity_log';

        return $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$table} ORDER BY created_at DESC LIMIT %d OFFSET %d",
            $limit,
            $offset
        ), ARRAY_A );
    }

    /**
     * Get count of entries by status.
     */
    public static function get_counts(): array {
        global $wpdb;
        $table = $wpdb->prefix . 'gtw_activity_log';

        $rows = $wpdb->get_results(
            "SELECT status, COUNT(*) as cnt FROM {$table} GROUP BY status",
            ARRAY_A
        );

        $counts = array( 'success' => 0, 'failed' => 0, 'pending' => 0, 'retrying' => 0 );
        foreach ( $rows as $row ) {
            $counts[ $row['status'] ] = (int) $row['cnt'];
        }
        return $counts;
    }

    /**
     * Purge entries older than N days.
     */
    public static function purge_old( int $days = 90 ): int {
        global $wpdb;
        $table = $wpdb->prefix . 'gtw_activity_log';

        return (int) $wpdb->query( $wpdb->prepare(
            "DELETE FROM {$table} WHERE created_at < %s",
            gmdate( 'Y-m-d H:i:s', time() - ( $days * 86400 ) )
        ) );
    }
}
