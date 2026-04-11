<?php
/**
 * Session Resolver - finds the next upcoming GoToWebinar session by name pattern.
 * Auto-extends series by creating new sessions when running low.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class GTW_Session_Resolver {

    private GTW_API $api;

    public function __construct( GTW_API $api ) {
        $this->api = $api;
    }

    /**
     * Get the next upcoming session matching a name pattern.
     * Each registrant is registered to a SPECIFIC webinar (specific date).
     * GoToWebinar handles confirmation + reminder emails for that date.
     *
     * @return array|null Session data or null if none found
     */
    public function get_upcoming_session( string $webinar_key = '', string $name_pattern = '', array $auto_create = array() ): ?array {
        $cache_key = $name_pattern ?: $webinar_key;
        $cached = $this->get_cached_session( $cache_key );
        if ( $cached ) return $cached;

        if ( ! empty( $name_pattern ) ) {
            $session = $this->find_by_pattern( $name_pattern );
            if ( $session ) {
                $this->cache_session( $cache_key, $session );
                return $session;
            }
            // No session found - if auto-create enabled, make one
            if ( ! empty( $auto_create['enabled'] ) ) {
                $session = $this->create_next_session( $name_pattern, $auto_create );
                if ( $session ) {
                    $this->cache_session( $cache_key, $session );
                    return $session;
                }
            }
            return null;
        }

        if ( ! empty( $webinar_key ) ) {
            return $this->find_by_key( $webinar_key );
        }

        return null;
    }

    /**
     * Count future sessions matching a name pattern.
     * Used by the cron to decide when to auto-extend.
     */
    public function count_upcoming_sessions( string $name_pattern ): int {
        $all = $this->find_all_by_pattern( $name_pattern );
        return count( $all );
    }

    /**
     * Get all future sessions matching a pattern (for display and counting).
     */
    public function find_all_by_pattern( string $pattern ): array {
        $org_key = $this->api->get_organizer_key();
        if ( empty( $org_key ) ) return array();

        $from = gmdate( 'Y-m-d\TH:i:s\Z' );
        $to   = gmdate( 'Y-m-d\TH:i:s\Z', time() + 365 * 86400 );

        $response = $this->api->api_get_public( "/organizers/{$org_key}/webinars?fromTime={$from}&toTime={$to}" );
        if ( ! $response['success'] ) return array();

        $all = $response['data']['_embedded']['webinars'] ?? $response['data'] ?? array();
        if ( ! is_array( $all ) ) return array();

        $pattern_lower = strtolower( $pattern );
        $now = time();
        $matches = array();

        foreach ( $all as $w ) {
            if ( strpos( strtolower( $w['subject'] ?? '' ), $pattern_lower ) === false ) continue;
            $times = $w['times'] ?? array();
            if ( empty( $times ) ) continue;

            $start = strtotime( $times[0]['startTime'] ?? '' );
            $end   = strtotime( $times[0]['endTime'] ?? '' );
            if ( $end && $end > $now ) {
                $matches[] = array(
                    'webinarKey'         => $w['webinarKey'],
                    'sessionKey'         => $w['webinarKey'],
                    'subject'            => $w['subject'],
                    'startTime'          => $start,
                    'endTime'            => $end,
                    'startTimeFormatted' => gmdate( 'Y-m-d H:i:s', $start ),
                    'endTimeFormatted'   => gmdate( 'Y-m-d H:i:s', $end ),
                    'recurrenceKey'      => $w['recurrenceKey'] ?? null,
                );
            }
        }

        usort( $matches, fn( $a, $b ) => $a['startTime'] <=> $b['startTime'] );
        return $matches;
    }

    /**
     * Find the soonest future session matching the pattern.
     */
    private function find_by_pattern( string $pattern ): ?array {
        $matches = $this->find_all_by_pattern( $pattern );
        return $matches[0] ?? null;
    }

    /**
     * Create the next session for a pattern.
     * Reads day, time, duration, timezone from the ACTUAL webinar template - not admin config.
     * The new session is an exact continuation of the existing series schedule.
     */
    public function create_next_session( string $pattern, array $config = array() ): ?array {
        $template = $this->find_template( $pattern );
        if ( ! $template ) {
            GTW_Logger::log_api_error( 'auto_create', "No template webinar found for pattern '{$pattern}'" );
            return null;
        }

        // Read schedule from the ACTUAL webinar, not from admin config
        $template_times = $template['times'] ?? array();
        $template_tz    = $template['timeZone'] ?? ( $config['timezone'] ?? 'America/New_York' );

        if ( empty( $template_times ) ) {
            GTW_Logger::log_api_error( 'auto_create', 'Template webinar has no scheduled times' );
            return null;
        }

        // Extract day of week, time, and duration from the template webinar
        $tpl_start_ts = strtotime( $template_times[0]['startTime'] );
        $tpl_end_ts   = strtotime( $template_times[0]['endTime'] );
        $duration     = $tpl_end_ts && $tpl_start_ts ? max( 15, (int) ( ( $tpl_end_ts - $tpl_start_ts ) / 60 ) ) : 30;

        $tz_obj  = new \DateTimeZone( $template_tz );
        $tpl_dt  = ( new \DateTime() )->setTimestamp( $tpl_start_ts )->setTimezone( $tz_obj );
        $day     = strtolower( $tpl_dt->format( 'l' ) ); // e.g. "monday"
        $time    = $tpl_dt->format( 'H:i' );              // e.g. "15:00"

        // Find the last scheduled session to avoid overlaps
        $existing = $this->find_all_by_pattern( $pattern );
        $last_start = 0;
        foreach ( $existing as $s ) {
            if ( $s['startTime'] > $last_start ) $last_start = $s['startTime'];
        }

        // Calculate next date AFTER the last existing session (same day/time, next week)
        $next_start = $this->calculate_next_date( $day, $time, $template_tz, $last_start );
        if ( ! $next_start ) return null;

        $next_end = $next_start + ( $duration * 60 );

        $result = $this->api->create_webinar( array(
            'subject'     => $template['subject'],
            'description' => $template['description'] ?? '',
            'times'       => array( array(
                'startTime' => gmdate( 'Y-m-d\TH:i:s\Z', $next_start ),
                'endTime'   => gmdate( 'Y-m-d\TH:i:s\Z', $next_end ),
            ) ),
            'timeZone'    => $template_tz,
            'type'        => 'single_session',
        ) );

        if ( ! $result['success'] ) {
            GTW_Logger::log_api_error( 'auto_create', 'Failed: ' . ( $result['error'] ?? 'unknown' ) );
            return null;
        }

        $new_key = $result['data']['webinarKey'] ?? '';
        if ( empty( $new_key ) ) return null;

        // Log success
        $date_str = gmdate( 'Y-m-d H:i', $next_start );
        GTW_Logger::log_entry( array(
            'registrant_email' => 'system',
            'registrant_name'  => 'Auto-Extend',
            'session_id'       => $new_key,
            'webinar_key'      => $new_key,
            'status'           => 'success',
            'error_message'    => "Auto-created session for {$date_str} UTC (pattern: {$pattern})",
        ) );

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
     * Find a template webinar to copy subject/description from.
     */
    private function find_template( string $pattern ): ?array {
        $org_key = $this->api->get_organizer_key();
        if ( empty( $org_key ) ) return null;

        $from = gmdate( 'Y-m-d\TH:i:s\Z', time() - 90 * 86400 );
        $to   = gmdate( 'Y-m-d\TH:i:s\Z', time() + 180 * 86400 );

        $response = $this->api->api_get_public( "/organizers/{$org_key}/webinars?fromTime={$from}&toTime={$to}" );
        if ( ! $response['success'] ) return null;

        $webinars = $response['data']['_embedded']['webinars'] ?? $response['data'] ?? array();
        $pattern_lower = strtolower( $pattern );

        foreach ( $webinars as $w ) {
            if ( is_array( $w ) && strpos( strtolower( $w['subject'] ?? '' ), $pattern_lower ) !== false ) {
                return $w;
            }
        }
        return null;
    }

    /**
     * Calculate next occurrence of a weekday/time AFTER a given timestamp.
     */
    private function calculate_next_date( string $day, string $time, string $timezone, int $after_ts = 0 ): ?int {
        try {
            $tz  = new DateTimeZone( $timezone );
            $now = new DateTime( 'now', $tz );
            $ref = $after_ts > 0 ? ( new DateTime() )->setTimestamp( $after_ts )->setTimezone( $tz ) : $now;

            // Start from the reference date and find the next matching weekday
            $target = clone $ref;
            $target->modify( "next {$day}" );
            $target->setTime( (int) explode( ':', $time )[0], (int) ( explode( ':', $time )[1] ?? 0 ) );

            // Make sure it's in the future
            if ( $target <= $now ) {
                $target->modify( '+7 days' );
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

        foreach ( $sessions as $s ) {
            $start = strtotime( $s['startTime'] ?? $s['startDate'] ?? '' );
            $end   = strtotime( $s['endTime'] ?? $s['endDate'] ?? '' );
            if ( $end && $end > $now ) {
                $upcoming[] = array(
                    'webinarKey' => $webinar_key,
                    'sessionKey' => $s['sessionKey'] ?? $webinar_key,
                    'startTime'  => $start, 'endTime' => $end,
                    'startTimeFormatted' => gmdate( 'Y-m-d H:i:s', $start ),
                    'endTimeFormatted'   => gmdate( 'Y-m-d H:i:s', $end ),
                );
            }
        }

        if ( empty( $upcoming ) ) return null;
        usort( $upcoming, fn( $a, $b ) => $a['startTime'] <=> $b['startTime'] );

        $this->cache_session( $webinar_key, $upcoming[0] );
        return $upcoming[0];
    }

    public function refresh_session( string $webinar_key = '', string $name_pattern = '' ): ?array {
        $this->clear_cache( $name_pattern ?: $webinar_key );
        return $this->get_upcoming_session( $webinar_key, $name_pattern );
    }

    // ---- Cache ----

    private function get_cached_session( string $key ): ?array {
        global $wpdb;
        $t = $wpdb->prefix . 'gtw_webinar_series';
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$t} WHERE (webinar_key = %s OR name_pattern = %s) AND is_active = 1 LIMIT 1",
            $key, $key
        ), ARRAY_A );

        if ( ! $row || empty( $row['cached_session_id'] ) ) return null;
        $now = time();
        if ( strtotime( $row['cache_expires_at'] ?? '2000-01-01' ) < $now ) return null;
        if ( strtotime( $row['cached_session_end'] ?? '2000-01-01' ) < $now ) {
            $this->clear_cache( $key );
            return null;
        }

        return array(
            'webinarKey' => $row['cached_session_id'], 'sessionKey' => $row['cached_session_id'],
            'startTime' => strtotime( $row['cached_session_start'] ), 'endTime' => strtotime( $row['cached_session_end'] ),
            'startTimeFormatted' => $row['cached_session_start'], 'endTimeFormatted' => $row['cached_session_end'],
        );
    }

    private function cache_session( string $key, array $s ): void {
        global $wpdb;
        $t = $wpdb->prefix . 'gtw_webinar_series';
        $ttl = (int) get_option( 'wp_gtw_cache_ttl', 15 );
        $wpdb->update( $t, array(
            'cached_session_id' => $s['sessionKey'] ?? $s['webinarKey'],
            'cached_session_start' => $s['startTimeFormatted'],
            'cached_session_end' => $s['endTimeFormatted'],
            'cache_expires_at' => gmdate( 'Y-m-d H:i:s', time() + $ttl * 60 ),
        ), array( 'is_active' => 1 ) );
    }

    private function clear_cache( string $key ): void {
        global $wpdb;
        $t = $wpdb->prefix . 'gtw_webinar_series';
        $wpdb->update( $t, array(
            'cached_session_id' => null, 'cached_session_start' => null,
            'cached_session_end' => null, 'cache_expires_at' => null,
        ), array( 'is_active' => 1 ) );
    }
}
