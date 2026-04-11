<?php
/**
 * Session Resolver - finds the next upcoming GoToWebinar session by name pattern.
 * Auto-creates new sessions when none are available.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class GTW_Session_Resolver {

    private GTW_API $api;

    public function __construct( GTW_API $api ) {
        $this->api = $api;
    }

    /**
     * Get the upcoming session matching a name pattern.
     * If no pattern given, falls back to webinar_key lookup.
     *
     * @param string $webinar_key  Legacy: specific webinar key (optional if pattern is set)
     * @param string $name_pattern Substring to match in webinar subject (e.g., "30 in 30")
     * @param array  $auto_create  Auto-create config: ['enabled' => bool, 'day' => 'monday', 'time' => '15:00', 'duration' => 30, 'timezone' => 'America/New_York']
     * @return array|null Session data or null
     */
    public function get_upcoming_session( string $webinar_key = '', string $name_pattern = '', array $auto_create = array() ): ?array {
        // Check cache first
        $cache_key = $name_pattern ?: $webinar_key;
        $cached = $this->get_cached_session( $cache_key );
        if ( $cached ) {
            return $cached;
        }

        // Find session by name pattern (new approach)
        if ( ! empty( $name_pattern ) ) {
            $session = $this->find_by_pattern( $name_pattern );
            if ( $session ) {
                $this->cache_session( $cache_key, $session );
                return $session;
            }

            // No session found - try auto-create if enabled
            if ( ! empty( $auto_create['enabled'] ) ) {
                $session = $this->auto_create_session( $name_pattern, $auto_create );
                if ( $session ) {
                    $this->cache_session( $cache_key, $session );
                    return $session;
                }
            }

            return null;
        }

        // Legacy: find by specific webinar key
        if ( ! empty( $webinar_key ) ) {
            return $this->find_by_key( $webinar_key );
        }

        return null;
    }

    /**
     * Find the soonest future session whose subject contains the pattern.
     */
    private function find_by_pattern( string $pattern ): ?array {
        $org_key = $this->api->get_organizer_key();
        if ( empty( $org_key ) ) return null;

        $from = gmdate( 'Y-m-d\TH:i:s\Z' );
        $to   = gmdate( 'Y-m-d\TH:i:s\Z', time() + 180 * 86400 ); // 6 months ahead

        $response = $this->api->api_get_public( "/organizers/{$org_key}/webinars?fromTime={$from}&toTime={$to}" );
        if ( ! $response['success'] ) return null;

        $all_webinars = $response['data']['_embedded']['webinars'] ?? $response['data'] ?? array();
        if ( ! is_array( $all_webinars ) ) return null;

        $pattern_lower = strtolower( $pattern );
        $now = time();
        $candidates = array();

        foreach ( $all_webinars as $w ) {
            $subject = strtolower( $w['subject'] ?? '' );
            if ( strpos( $subject, $pattern_lower ) === false ) continue;

            $times = $w['times'] ?? array();
            if ( empty( $times ) ) continue;

            $start = strtotime( $times[0]['startTime'] ?? '' );
            $end   = strtotime( $times[0]['endTime'] ?? '' );

            // Only future sessions (end time hasn't passed)
            if ( $end && $end > $now ) {
                $candidates[] = array(
                    'webinarKey'         => $w['webinarKey'] ?? '',
                    'sessionKey'         => $w['webinarKey'] ?? '',
                    'subject'            => $w['subject'] ?? '',
                    'startTime'          => $start,
                    'endTime'            => $end,
                    'startTimeFormatted' => gmdate( 'Y-m-d H:i:s', $start ),
                    'endTimeFormatted'   => gmdate( 'Y-m-d H:i:s', $end ),
                    'recurrenceKey'      => $w['recurrenceKey'] ?? null,
                    'recurrenceType'     => $w['recurrenceType'] ?? 'single',
                );
            }
        }

        if ( empty( $candidates ) ) return null;

        // Sort by start time - pick the soonest
        usort( $candidates, fn( $a, $b ) => $a['startTime'] <=> $b['startTime'] );
        return $candidates[0];
    }

    /**
     * Auto-create a new webinar session when no future session exists.
     * Copies subject/description from the most recent matching webinar.
     */
    private function auto_create_session( string $pattern, array $config ): ?array {
        // Find the most recent matching webinar to clone settings from
        $template = $this->find_template_webinar( $pattern );
        if ( ! $template ) {
            GTW_Logger::log_api_error( 'auto_create', "No template webinar found matching '{$pattern}'" );
            return null;
        }

        // Calculate next session time
        $day      = $config['day'] ?? 'monday';
        $time     = $config['time'] ?? '15:00';
        $duration = (int) ( $config['duration'] ?? 30 );
        $tz       = $config['timezone'] ?? 'America/New_York';

        $next_start = $this->calculate_next_date( $day, $time, $tz );
        if ( ! $next_start ) return null;

        $next_end = $next_start + ( $duration * 60 );

        // Create the webinar
        $result = $this->api->create_webinar( array(
            'subject'     => $template['subject'],
            'description' => $template['description'] ?? '',
            'times'       => array( array(
                'startTime' => gmdate( 'Y-m-d\TH:i:s\Z', $next_start ),
                'endTime'   => gmdate( 'Y-m-d\TH:i:s\Z', $next_end ),
            ) ),
            'timeZone'    => $tz,
        ) );

        if ( ! $result['success'] ) {
            GTW_Logger::log_api_error( 'auto_create', 'Failed to create webinar: ' . ( $result['error'] ?? 'unknown' ) );
            return null;
        }

        $new_key = $result['data']['webinarKey'] ?? '';
        if ( empty( $new_key ) ) return null;

        GTW_Logger::log_api_error( 'auto_create', "Created new webinar {$new_key} for " . gmdate( 'Y-m-d H:i', $next_start ) . ' UTC' );

        return array(
            'webinarKey'         => $new_key,
            'sessionKey'         => $new_key,
            'subject'            => $template['subject'],
            'startTime'          => $next_start,
            'endTime'            => $next_end,
            'startTimeFormatted' => gmdate( 'Y-m-d H:i:s', $next_start ),
            'endTimeFormatted'   => gmdate( 'Y-m-d H:i:s', $next_end ),
        );
    }

    /**
     * Find the most recent webinar matching pattern (for cloning settings).
     */
    private function find_template_webinar( string $pattern ): ?array {
        $org_key = $this->api->get_organizer_key();
        if ( empty( $org_key ) ) return null;

        // Look back 90 days for a template
        $from = gmdate( 'Y-m-d\TH:i:s\Z', time() - 90 * 86400 );
        $to   = gmdate( 'Y-m-d\TH:i:s\Z', time() + 30 * 86400 );

        $response = $this->api->api_get_public( "/organizers/{$org_key}/webinars?fromTime={$from}&toTime={$to}" );
        if ( ! $response['success'] ) return null;

        $webinars = $response['data']['_embedded']['webinars'] ?? $response['data'] ?? array();
        $pattern_lower = strtolower( $pattern );

        foreach ( $webinars as $w ) {
            if ( strpos( strtolower( $w['subject'] ?? '' ), $pattern_lower ) !== false ) {
                return $w;
            }
        }

        return null;
    }

    /**
     * Calculate the next occurrence of a given weekday and time.
     */
    private function calculate_next_date( string $day, string $time, string $timezone ): ?int {
        try {
            $tz = new DateTimeZone( $timezone );
            $now = new DateTime( 'now', $tz );
            $target = new DateTime( "next {$day} {$time}", $tz );

            // If "next monday" is today and the time hasn't passed, use today
            if ( strtolower( $now->format( 'l' ) ) === strtolower( $day ) ) {
                $today_target = new DateTime( "today {$time}", $tz );
                if ( $today_target > $now ) {
                    $target = $today_target;
                }
            }

            return $target->getTimestamp();
        } catch ( \Exception $e ) {
            return null;
        }
    }

    /**
     * Legacy: find by specific webinar key.
     */
    private function find_by_key( string $webinar_key ): ?array {
        $result = $this->api->get_webinar_sessions( $webinar_key );
        if ( ! $result['success'] ) return null;

        $sessions = $result['sessions'] ?? array();
        $now = time();
        $upcoming = array();

        foreach ( $sessions as $session ) {
            $start = strtotime( $session['startTime'] ?? $session['startDate'] ?? '' );
            $end   = strtotime( $session['endTime'] ?? $session['endDate'] ?? '' );
            if ( $end && $end > $now ) {
                $upcoming[] = array(
                    'webinarKey' => $webinar_key,
                    'sessionKey' => $session['sessionKey'] ?? $webinar_key,
                    'startTime'  => $start,
                    'endTime'    => $end,
                    'startTimeFormatted' => gmdate( 'Y-m-d H:i:s', $start ),
                    'endTimeFormatted'   => gmdate( 'Y-m-d H:i:s', $end ),
                );
            }
        }

        if ( empty( $upcoming ) ) return null;
        usort( $upcoming, fn( $a, $b ) => $a['startTime'] <=> $b['startTime'] );

        $next = $upcoming[0];
        $this->cache_session( $webinar_key, $next );
        return $next;
    }

    /**
     * Force refresh.
     */
    public function refresh_session( string $webinar_key = '', string $name_pattern = '' ): ?array {
        $cache_key = $name_pattern ?: $webinar_key;
        $this->clear_cache( $cache_key );
        return $this->get_upcoming_session( $webinar_key, $name_pattern );
    }

    // ---- Cache methods (same as before) ----

    private function get_cached_session( string $cache_key ): ?array {
        global $wpdb;
        $table = $wpdb->prefix . 'gtw_webinar_series';
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$table} WHERE (webinar_key = %s OR label = %s) AND is_active = 1 LIMIT 1",
            $cache_key, $cache_key
        ), ARRAY_A );

        if ( ! $row || empty( $row['cached_session_id'] ) ) return null;

        $now = time();
        $cache_expires = strtotime( $row['cache_expires_at'] ?? '2000-01-01' );
        if ( $cache_expires < $now ) return null;

        $session_end = strtotime( $row['cached_session_end'] ?? '2000-01-01' );
        if ( $session_end < $now ) {
            $this->clear_cache( $cache_key );
            return null;
        }

        return array(
            'webinarKey' => $row['cached_session_id'],
            'sessionKey' => $row['cached_session_id'],
            'startTime'  => strtotime( $row['cached_session_start'] ),
            'endTime'    => $session_end,
            'startTimeFormatted' => $row['cached_session_start'],
            'endTimeFormatted'   => $row['cached_session_end'],
        );
    }

    private function cache_session( string $cache_key, array $session ): void {
        global $wpdb;
        $table = $wpdb->prefix . 'gtw_webinar_series';
        $ttl = (int) get_option( 'wp_gtw_cache_ttl', 15 );

        $updated = $wpdb->update( $table, array(
            'cached_session_id'    => $session['sessionKey'] ?? $session['webinarKey'],
            'cached_session_start' => $session['startTimeFormatted'],
            'cached_session_end'   => $session['endTimeFormatted'],
            'cache_expires_at'     => gmdate( 'Y-m-d H:i:s', time() + $ttl * 60 ),
        ), array( 'is_active' => 1 ) );
    }

    private function clear_cache( string $cache_key ): void {
        global $wpdb;
        $table = $wpdb->prefix . 'gtw_webinar_series';
        $wpdb->update( $table, array(
            'cached_session_id' => null, 'cached_session_start' => null,
            'cached_session_end' => null, 'cache_expires_at' => null,
        ), array( 'is_active' => 1 ) );
    }
}
