<?php
/**
 * Session Resolver - auto-detects the next upcoming GoToWebinar session.
 * Caches the result and auto-switches when the current session ends.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class GTW_Session_Resolver {

    private GTW_API $api;

    public function __construct( GTW_API $api ) {
        $this->api = $api;
    }

    /**
     * Get the upcoming session for a webinar series.
     * Uses cache if valid, otherwise queries the API.
     *
     * @return array|null Session data with 'webinarKey', 'sessionKey', 'startTime', 'endTime', or null if none found.
     */
    public function get_upcoming_session( string $webinar_key ): ?array {
        // Check cache first
        $cached = $this->get_cached_session( $webinar_key );
        if ( $cached ) {
            return $cached;
        }

        // Query API for sessions
        $result = $this->api->get_webinar_sessions( $webinar_key );
        if ( ! $result['success'] ) {
            return null;
        }

        $now = time();
        $sessions = $result['sessions'] ?? array();
        $upcoming = array();

        foreach ( $sessions as $session ) {
            $start_time = $this->parse_gtw_time( $session['startTime'] ?? $session['startDate'] ?? '' );
            $end_time   = $this->parse_gtw_time( $session['endTime'] ?? $session['endDate'] ?? '' );

            // Only consider sessions that haven't ended yet
            if ( $end_time && $end_time > $now ) {
                $upcoming[] = array(
                    'webinarKey' => $webinar_key,
                    'sessionKey' => $session['sessionKey'] ?? $session['webinarKey'] ?? $webinar_key,
                    'startTime'  => $start_time,
                    'endTime'    => $end_time,
                    'startTimeFormatted' => gmdate( 'Y-m-d H:i:s', $start_time ),
                    'endTimeFormatted'   => gmdate( 'Y-m-d H:i:s', $end_time ),
                );
            }
        }

        if ( empty( $upcoming ) ) {
            return null;
        }

        // Sort by start time ascending - pick the soonest
        usort( $upcoming, function( $a, $b ) {
            return $a['startTime'] <=> $b['startTime'];
        } );

        $next_session = $upcoming[0];

        // Cache the result
        $this->cache_session( $webinar_key, $next_session );

        return $next_session;
    }

    /**
     * Force clear cache and re-query.
     */
    public function refresh_session( string $webinar_key ): ?array {
        $this->clear_cache( $webinar_key );
        return $this->get_upcoming_session( $webinar_key );
    }

    /**
     * Get cached session if still valid.
     */
    private function get_cached_session( string $webinar_key ): ?array {
        global $wpdb;
        $table = $wpdb->prefix . 'gtw_webinar_series';

        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$table} WHERE webinar_key = %s AND is_active = 1 LIMIT 1",
            $webinar_key
        ), ARRAY_A );

        if ( ! $row || empty( $row['cached_session_id'] ) ) {
            return null;
        }

        $now = time();

        // Check if cache has expired (TTL)
        $cache_expires = strtotime( $row['cache_expires_at'] ?? '2000-01-01' );
        if ( $cache_expires < $now ) {
            return null; // Cache TTL expired
        }

        // Check if session has ended (auto-switch)
        $session_end = strtotime( $row['cached_session_end'] ?? '2000-01-01' );
        if ( $session_end < $now ) {
            $this->clear_cache( $webinar_key );
            return null; // Session ended, need next one
        }

        return array(
            'webinarKey' => $webinar_key,
            'sessionKey' => $row['cached_session_id'],
            'startTime'  => strtotime( $row['cached_session_start'] ),
            'endTime'    => $session_end,
            'startTimeFormatted' => $row['cached_session_start'],
            'endTimeFormatted'   => $row['cached_session_end'],
        );
    }

    /**
     * Cache a resolved session.
     */
    private function cache_session( string $webinar_key, array $session ): void {
        global $wpdb;
        $table = $wpdb->prefix . 'gtw_webinar_series';
        $ttl   = (int) get_option( 'wp_gtw_cache_ttl', 15 );

        $wpdb->update(
            $table,
            array(
                'cached_session_id'    => $session['sessionKey'],
                'cached_session_start' => $session['startTimeFormatted'],
                'cached_session_end'   => $session['endTimeFormatted'],
                'cache_expires_at'     => gmdate( 'Y-m-d H:i:s', time() + ( $ttl * 60 ) ),
            ),
            array( 'webinar_key' => $webinar_key, 'is_active' => 1 ),
            array( '%s', '%s', '%s', '%s' ),
            array( '%s', '%d' )
        );
    }

    /**
     * Clear cached session for a webinar key.
     */
    private function clear_cache( string $webinar_key ): void {
        global $wpdb;
        $table = $wpdb->prefix . 'gtw_webinar_series';

        $wpdb->update(
            $table,
            array(
                'cached_session_id'    => null,
                'cached_session_start' => null,
                'cached_session_end'   => null,
                'cache_expires_at'     => null,
            ),
            array( 'webinar_key' => $webinar_key )
        );
    }

    /**
     * Parse GoToWebinar timestamp (ISO 8601) to Unix timestamp.
     */
    private function parse_gtw_time( string $time_str ): int {
        if ( empty( $time_str ) ) {
            return 0;
        }
        $ts = strtotime( $time_str );
        return $ts ?: 0;
    }
}
