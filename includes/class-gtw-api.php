<?php
/**
 * GoToWebinar API wrapper.
 * Handles OAuth 2.0, token management, session queries, and registration.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class GTW_API {

    private const AUTH_URL  = 'https://authentication.logmeininc.com/oauth/token';
    private const API_BASE  = 'https://api.getgo.com/G2W/rest/v2';
    private const TOKEN_KEY = 'wp_gtw_access_token';
    private const EXPIRY_KEY = 'wp_gtw_token_expiry';
    private const ORG_KEY   = 'wp_gtw_organizer_key';

    /**
     * Get a valid access token, refreshing if needed.
     */
    public function get_token(): ?string {
        $token  = get_option( self::TOKEN_KEY );
        $expiry = (int) get_option( self::EXPIRY_KEY, 0 );

        // Refresh 5 minutes before expiry
        if ( $token && $expiry > ( time() + 300 ) ) {
            return $token;
        }

        return $this->authenticate();
    }

    /**
     * Authenticate with GoToWebinar OAuth (Client Credentials grant).
     */
    public function authenticate(): ?string {
        $client_id     = get_option( 'wp_gtw_client_id', '' );
        $client_secret = get_option( 'wp_gtw_client_secret', '' );

        if ( empty( $client_id ) || empty( $client_secret ) ) {
            return null;
        }

        $response = wp_remote_post( self::AUTH_URL, array(
            'timeout' => 10,
            'headers' => array(
                'Content-Type'  => 'application/x-www-form-urlencoded',
                'Authorization' => 'Basic ' . base64_encode( $client_id . ':' . $client_secret ),
            ),
            'body' => array(
                'grant_type' => 'client_credentials',
            ),
        ) );

        if ( is_wp_error( $response ) ) {
            GTW_Logger::log_api_error( 'auth', $response->get_error_message() );
            return null;
        }

        $code = wp_remote_retrieve_response_code( $response );
        $body = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( $code !== 200 || empty( $body['access_token'] ) ) {
            $error = $body['error_description'] ?? $body['error'] ?? "HTTP {$code}";
            GTW_Logger::log_api_error( 'auth', $error );
            return null;
        }

        $token     = $body['access_token'];
        $expires_in = (int) ( $body['expires_in'] ?? 3600 );
        $org_key   = $body['organizer_key'] ?? '';

        update_option( self::TOKEN_KEY, $token );
        update_option( self::EXPIRY_KEY, time() + $expires_in );
        if ( $org_key ) {
            update_option( self::ORG_KEY, $org_key );
        }

        return $token;
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
            return array( 'success' => false, 'error' => 'Authentication failed. Check your Client ID and Secret.' );
        }

        $org_key = $this->get_organizer_key();
        if ( empty( $org_key ) ) {
            return array( 'success' => false, 'error' => 'No organizer key found. Re-authenticate.' );
        }

        $response = $this->api_get( "/organizers/{$org_key}" );
        if ( $response['success'] ) {
            return array(
                'success' => true,
                'data'    => array(
                    'organizer_key' => $org_key,
                    'email'         => $response['data']['email'] ?? 'Unknown',
                    'first_name'    => $response['data']['firstName'] ?? '',
                    'last_name'     => $response['data']['lastName'] ?? '',
                ),
            );
        }

        return $response;
    }

    /**
     * Get upcoming sessions for a webinar key.
     */
    public function get_webinar_sessions( string $webinar_key ): array {
        $org_key = $this->get_organizer_key();
        if ( empty( $org_key ) ) {
            return array( 'success' => false, 'error' => 'No organizer key' );
        }

        $response = $this->api_get( "/organizers/{$org_key}/webinars/{$webinar_key}/sessions" );
        if ( ! $response['success'] ) {
            // Fallback: try getting webinar info which includes sessions
            $webinar_response = $this->api_get( "/organizers/{$org_key}/webinars/{$webinar_key}" );
            if ( $webinar_response['success'] && ! empty( $webinar_response['data']['times'] ) ) {
                return array(
                    'success'  => true,
                    'sessions' => $webinar_response['data']['times'],
                    'source'   => 'webinar_times',
                );
            }
            return $response;
        }

        return array(
            'success'  => true,
            'sessions' => $response['data'] ?? array(),
            'source'   => 'sessions_endpoint',
        );
    }

    /**
     * Register an attendee to a webinar session.
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

        $response = $this->api_post(
            "/organizers/{$org_key}/webinars/{$webinar_key}/registrants",
            $body
        );

        return $response;
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
