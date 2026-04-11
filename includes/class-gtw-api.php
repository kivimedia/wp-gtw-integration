<?php
/**
 * GoToWebinar API wrapper.
 * Handles OAuth 2.0 Authorization Code flow, token management, session queries, and registration.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class GTW_API {

    private const AUTH_URL    = 'https://authentication.logmeininc.com/oauth/authorize';
    private const TOKEN_URL   = 'https://authentication.logmeininc.com/oauth/token';
    private const API_BASE    = 'https://api.getgo.com/G2W/rest/v2';
    private const TOKEN_KEY   = 'wp_gtw_access_token';
    private const REFRESH_KEY = 'wp_gtw_refresh_token';
    private const EXPIRY_KEY  = 'wp_gtw_token_expiry';
    private const ORG_KEY     = 'wp_gtw_organizer_key';

    /**
     * Get the OAuth authorization URL (Step 1: redirect admin to GoTo login).
     */
    public function get_auth_url(): string {
        $client_id = get_option( 'wp_gtw_client_id', '' );
        $redirect  = admin_url( 'options-general.php?page=wp-gtw-settings' );

        return self::AUTH_URL . '?' . http_build_query( array(
            'response_type' => 'code',
            'client_id'     => $client_id,
            'redirect_uri'  => $redirect,
        ) );
    }

    /**
     * Exchange authorization code for tokens (Step 2: after GoTo redirects back).
     */
    public function exchange_code( string $code ): bool {
        $client_id     = get_option( 'wp_gtw_client_id', '' );
        $client_secret = get_option( 'wp_gtw_client_secret', '' );
        $redirect      = admin_url( 'options-general.php?page=wp-gtw-settings' );

        $response = wp_remote_post( self::TOKEN_URL, array(
            'timeout' => 15,
            'headers' => array(
                'Content-Type'  => 'application/x-www-form-urlencoded',
                'Authorization' => 'Basic ' . base64_encode( $client_id . ':' . $client_secret ),
            ),
            'body' => array(
                'grant_type'   => 'authorization_code',
                'code'         => $code,
                'redirect_uri' => $redirect,
            ),
        ) );

        return $this->handle_token_response( $response, 'exchange_code' );
    }

    /**
     * Get a valid access token, refreshing if needed.
     */
    public function get_token(): ?string {
        $token  = get_option( self::TOKEN_KEY );
        $expiry = (int) get_option( self::EXPIRY_KEY, 0 );

        // Token still valid (with 5 min buffer)
        if ( $token && $expiry > ( time() + 300 ) ) {
            return $token;
        }

        // Try refresh
        $refreshed = $this->refresh_token();
        if ( $refreshed ) {
            return get_option( self::TOKEN_KEY );
        }

        return null;
    }

    /**
     * Refresh the access token using the stored refresh token.
     */
    private function refresh_token(): bool {
        $refresh_token = get_option( self::REFRESH_KEY, '' );
        $client_id     = get_option( 'wp_gtw_client_id', '' );
        $client_secret = get_option( 'wp_gtw_client_secret', '' );

        if ( empty( $refresh_token ) || empty( $client_id ) || empty( $client_secret ) ) {
            return false;
        }

        $response = wp_remote_post( self::TOKEN_URL, array(
            'timeout' => 15,
            'headers' => array(
                'Content-Type'  => 'application/x-www-form-urlencoded',
                'Authorization' => 'Basic ' . base64_encode( $client_id . ':' . $client_secret ),
            ),
            'body' => array(
                'grant_type'    => 'refresh_token',
                'refresh_token' => $refresh_token,
            ),
        ) );

        return $this->handle_token_response( $response, 'refresh_token' );
    }

    /**
     * Handle token response from either exchange or refresh.
     */
    private function handle_token_response( $response, string $context ): bool {
        if ( is_wp_error( $response ) ) {
            GTW_Logger::log_api_error( $context, $response->get_error_message() );
            return false;
        }

        $code = wp_remote_retrieve_response_code( $response );
        $body = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( $code !== 200 || empty( $body['access_token'] ) ) {
            $error = $body['error_description'] ?? $body['error'] ?? "HTTP {$code}";
            GTW_Logger::log_api_error( $context, $error );
            return false;
        }

        $token       = $body['access_token'];
        $refresh     = $body['refresh_token'] ?? '';
        $expires_in  = (int) ( $body['expires_in'] ?? 3600 );
        $org_key     = $body['organizer_key'] ?? '';

        update_option( self::TOKEN_KEY, $token );
        update_option( self::EXPIRY_KEY, time() + $expires_in );
        if ( $refresh ) {
            update_option( self::REFRESH_KEY, $refresh );
        }
        if ( $org_key ) {
            update_option( self::ORG_KEY, $org_key );
        }

        // If no org key in token response, fetch it from admin API
        if ( empty( get_option( self::ORG_KEY, '' ) ) ) {
            $me_response = wp_remote_get( 'https://api.getgo.com/admin/rest/v1/me', array(
                'timeout' => 10,
                'headers' => array(
                    'Authorization' => 'Bearer ' . $token,
                    'Accept'        => 'application/json',
                ),
            ) );
            if ( ! is_wp_error( $me_response ) ) {
                $me_body = json_decode( wp_remote_retrieve_body( $me_response ), true );
                $fetched_key = $me_body['key'] ?? $me_body['organizerKey'] ?? '';
                if ( $fetched_key ) {
                    update_option( self::ORG_KEY, $fetched_key );
                }
            }

            // Fallback: try listing webinars to extract organizer key from the response
            if ( empty( get_option( self::ORG_KEY, '' ) ) ) {
                $acct_response = wp_remote_get( 'https://api.getgo.com/admin/rest/v1/accounts', array(
                    'timeout' => 10,
                    'headers' => array(
                        'Authorization' => 'Bearer ' . $token,
                        'Accept'        => 'application/json',
                    ),
                ) );
                if ( ! is_wp_error( $acct_response ) ) {
                    $acct_body = json_decode( wp_remote_retrieve_body( $acct_response ), true );
                    // Extract first account's organizer key
                    if ( is_array( $acct_body ) ) {
                        foreach ( $acct_body as $acct ) {
                            if ( ! empty( $acct['key'] ) ) {
                                update_option( self::ORG_KEY, $acct['key'] );
                                break;
                            }
                        }
                    }
                }
            }
        }

        return true;
    }

    /**
     * Check if we have a valid connection (has refresh token).
     */
    public function is_connected(): bool {
        return ! empty( get_option( self::REFRESH_KEY, '' ) );
    }

    /**
     * Disconnect - clear all tokens.
     */
    public function disconnect(): void {
        delete_option( self::TOKEN_KEY );
        delete_option( self::REFRESH_KEY );
        delete_option( self::EXPIRY_KEY );
        delete_option( self::ORG_KEY );
    }

    /**
     * Get the organizer key.
     */
    public function get_organizer_key(): string {
        return get_option( self::ORG_KEY, '' );
    }

    /**
     * Test the connection - returns organizer info or error.
     */
    public function test_connection(): array {
        $token = $this->get_token();
        if ( ! $token ) {
            return array( 'success' => false, 'error' => 'No access token. Connect to GoToWebinar first.' );
        }

        // Use the admin /me endpoint which is confirmed to work
        $response = wp_remote_get( 'https://api.getgo.com/admin/rest/v1/me', array(
            'timeout' => 10,
            'headers' => array( 'Authorization' => 'Bearer ' . $token, 'Accept' => 'application/json' ),
        ) );

        if ( is_wp_error( $response ) ) {
            return array( 'success' => false, 'error' => $response->get_error_message() );
        }

        $code = wp_remote_retrieve_response_code( $response );
        $body = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( $code === 200 && ! empty( $body['email'] ) ) {
            // Save organizer key if we got one
            if ( ! empty( $body['key'] ) ) {
                update_option( self::ORG_KEY, $body['key'] );
            }
            return array(
                'success' => true,
                'data'    => array(
                    'organizer_key' => $body['key'] ?? $this->get_organizer_key(),
                    'email'         => $body['email'] ?? 'Unknown',
                    'first_name'    => $body['firstName'] ?? '',
                    'last_name'     => $body['lastName'] ?? '',
                ),
            );
        }

        return array( 'success' => false, 'error' => $body['message'] ?? $body['msg'] ?? "HTTP {$code}" );
    }

    /**
     * Create a new webinar.
     */
    public function create_webinar( array $webinar_data ): array {
        $org_key = $this->get_organizer_key();
        if ( empty( $org_key ) ) {
            return array( 'success' => false, 'error' => 'No organizer key' );
        }
        return $this->api_post( "/organizers/{$org_key}/webinars", $webinar_data );
    }

    /**
     * Public wrapper for api_get (used by session resolver).
     */
    public function api_get_public( string $endpoint ): array {
        return $this->api_get( $endpoint );
    }

    /**
     * List all webinars for the organizer (for picking the series key).
     */
    public function list_webinars(): array {
        $org_key = $this->get_organizer_key();

        // Try with organizer key first (requires fromTime/toTime params)
        if ( ! empty( $org_key ) ) {
            $from = gmdate( 'Y-m-d\TH:i:s\Z' );
            $to   = gmdate( 'Y-m-d\TH:i:s\Z', time() + 365 * 86400 );
            $response = $this->api_get( "/organizers/{$org_key}/webinars?fromTime={$from}&toTime={$to}" );
            if ( $response['success'] ) {
                return $this->parse_webinars_response( $response );
            }
        }

        // Fallback: try the account-level webinars endpoint
        $token = $this->get_token();
        if ( ! $token ) {
            return array( 'success' => false, 'error' => 'No access token' );
        }

        // Try /admin/rest/v1/me to get the organizer key
        $me_response = wp_remote_get( 'https://api.getgo.com/admin/rest/v1/me', array(
            'timeout' => 10,
            'headers' => array( 'Authorization' => 'Bearer ' . $token, 'Accept' => 'application/json' ),
        ) );

        if ( ! is_wp_error( $me_response ) ) {
            $me_body = json_decode( wp_remote_retrieve_body( $me_response ), true );
            $fetched_key = $me_body['key'] ?? $me_body['organizerKey'] ?? '';
            if ( $fetched_key ) {
                update_option( self::ORG_KEY, $fetched_key );
                $from = gmdate( 'Y-m-d\TH:i:s\Z' );
                $to   = gmdate( 'Y-m-d\TH:i:s\Z', time() + 365 * 86400 );
                $response = $this->api_get( "/organizers/{$fetched_key}/webinars?fromTime={$from}&toTime={$to}" );
                if ( $response['success'] ) {
                    return $this->parse_webinars_response( $response );
                }
            }
        }

        return array( 'success' => false, 'error' => 'Could not retrieve organizer key. Click "Test Connection" first, or contact GoTo support to verify your account has API access.' );
    }

    private function parse_webinars_response( array $response ): array {
        $webinars = array();
        $items = $response['data']['_embedded']['webinars'] ?? $response['data'] ?? array();
        if ( ! is_array( $items ) ) {
            $items = array();
        }

        foreach ( $items as $w ) {
            $webinars[] = array(
                'webinarKey' => $w['webinarKey'] ?? '',
                'subject'    => $w['subject'] ?? 'Untitled',
                'times'      => $w['times'] ?? array(),
            );
        }

        return array( 'success' => true, 'webinars' => $webinars );
    }

    /**
     * Get upcoming sessions for a webinar key.
     */
    public function get_webinar_sessions( string $webinar_key ): array {
        $org_key = $this->get_organizer_key();
        if ( empty( $org_key ) ) {
            return array( 'success' => false, 'error' => 'No organizer key' );
        }

        // Try sessions endpoint first
        $response = $this->api_get( "/organizers/{$org_key}/webinars/{$webinar_key}/sessions" );
        if ( $response['success'] && ! empty( $response['data'] ) ) {
            return array(
                'success'  => true,
                'sessions' => $response['data'],
                'source'   => 'sessions_endpoint',
            );
        }

        // Fallback: get webinar info which includes scheduled times
        $webinar_response = $this->api_get( "/organizers/{$org_key}/webinars/{$webinar_key}" );
        if ( $webinar_response['success'] && ! empty( $webinar_response['data']['times'] ) ) {
            return array(
                'success'  => true,
                'sessions' => $webinar_response['data']['times'],
                'source'   => 'webinar_times',
            );
        }

        return array( 'success' => false, 'error' => 'No sessions found for this webinar' );
    }

    /**
     * Register an attendee to a webinar.
     */
    public function register_attendee( string $webinar_key, string $session_key, array $registrant ): array {
        $org_key = $this->get_organizer_key();
        if ( empty( $org_key ) ) {
            return array( 'success' => false, 'error' => 'No organizer key' );
        }

        $body = array(
            'firstName' => $registrant['firstName'] ?? '',
            'lastName'  => $registrant['lastName'] ?? '',
            'email'     => $registrant['email'] ?? '',
        );

        if ( ! empty( $registrant['phone'] ) ) {
            $body['phone'] = $registrant['phone'];
        }
        if ( ! empty( $registrant['organization'] ) ) {
            $body['organization'] = $registrant['organization'];
        }

        return $this->api_post(
            "/organizers/{$org_key}/webinars/{$webinar_key}/registrants",
            $body
        );
    }

    /**
     * Generic GET request to GTW API.
     */
    private function api_get( string $endpoint ): array {
        $token = $this->get_token();
        if ( ! $token ) {
            return array( 'success' => false, 'error' => 'No access token' );
        }

        $response = wp_remote_get( self::API_BASE . $endpoint, array(
            'timeout' => 10,
            'headers' => array(
                'Authorization' => 'Bearer ' . $token,
                'Accept'        => 'application/json',
            ),
        ) );

        return $this->parse_response( $response, "GET {$endpoint}" );
    }

    /**
     * Generic POST request to GTW API.
     */
    private function api_post( string $endpoint, array $body ): array {
        $token = $this->get_token();
        if ( ! $token ) {
            return array( 'success' => false, 'error' => 'No access token' );
        }

        $response = wp_remote_post( self::API_BASE . $endpoint, array(
            'timeout' => 10,
            'headers' => array(
                'Authorization' => 'Bearer ' . $token,
                'Content-Type'  => 'application/json',
                'Accept'        => 'application/json',
            ),
            'body' => wp_json_encode( $body ),
        ) );

        return $this->parse_response( $response, "POST {$endpoint}" );
    }

    /**
     * Parse WP HTTP response into standardized result.
     */
    private function parse_response( $response, string $context ): array {
        if ( is_wp_error( $response ) ) {
            $error = $response->get_error_message();
            GTW_Logger::log_api_error( $context, $error );
            return array( 'success' => false, 'error' => $error );
        }

        $code = wp_remote_retrieve_response_code( $response );
        $body = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( $code >= 200 && $code < 300 ) {
            return array( 'success' => true, 'data' => $body, 'code' => $code );
        }

        $error = $body['description'] ?? $body['message'] ?? $body['errorCode'] ?? "HTTP {$code}";
        GTW_Logger::log_api_error( $context, $error );
        return array( 'success' => false, 'error' => $error, 'code' => $code );
    }
}
