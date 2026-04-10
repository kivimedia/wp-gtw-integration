<?php
/**
 * WPForms Handler - hooks into WPForms submission and triggers GoToWebinar registration.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class GTW_WPForms_Handler {

    /**
     * Register the WPForms hook.
     */
    public function register_hooks(): void {
        add_action( 'wpforms_process_complete', array( $this, 'handle_submission' ), 10, 4 );
    }

    /**
     * Handle a WPForms submission.
     * Fires only after validation passes (wpforms_process_complete).
     */
    public function handle_submission( $fields, $entry, $form_data, $entry_id ): void {
        $form_id = (int) ( $form_data['id'] ?? 0 );

        // Find matching webinar series for this form
        $series = $this->get_series_for_form( $form_id );
        if ( ! $series ) {
            return; // This form isn't mapped to a webinar
        }

        // Extract field values using the mapping
        $mapping   = json_decode( $series['field_mapping'] ?? '{}', true ) ?: array();
        $registrant = $this->extract_registrant( $fields, $mapping );

        if ( empty( $registrant['email'] ) ) {
            GTW_Logger::log_entry( array(
                'registrant_email' => 'unknown',
                'registrant_name'  => '',
                'webinar_key'      => $series['webinar_key'],
                'status'           => 'failed',
                'error_message'    => 'Required field (email) missing from form submission',
            ) );
            return;
        }

        // Resolve the upcoming session
        $api      = new GTW_API();
        $resolver = new GTW_Session_Resolver( $api );
        $session  = $resolver->get_upcoming_session( $series['webinar_key'] );

        $full_name = trim( ( $registrant['firstName'] ?? '' ) . ' ' . ( $registrant['lastName'] ?? '' ) );

        if ( ! $session ) {
            // No upcoming session - queue for retry and alert admin
            GTW_Logger::log_entry( array(
                'registrant_email' => $registrant['email'],
                'registrant_name'  => $full_name,
                'webinar_key'      => $series['webinar_key'],
                'status'           => 'retrying',
                'error_message'    => 'No upcoming session found - queued for retry',
            ) );
            GTW_Logger::send_alert( $full_name, $registrant['email'], 'No upcoming GoToWebinar session found. Registration queued for retry.' );
            return;
        }

        // Register the attendee
        $result = $api->register_attendee( $session['webinarKey'], $session['sessionKey'], $registrant );

        if ( $result['success'] ) {
            GTW_Logger::log_entry( array(
                'registrant_email' => $registrant['email'],
                'registrant_name'  => $full_name,
                'session_id'       => $session['sessionKey'],
                'webinar_key'      => $series['webinar_key'],
                'status'           => 'success',
                'api_response'     => wp_json_encode( $result['data'] ?? array() ),
            ) );
        } else {
            // Registration failed - queue for retry
            GTW_Logger::log_entry( array(
                'registrant_email' => $registrant['email'],
                'registrant_name'  => $full_name,
                'session_id'       => $session['sessionKey'] ?? '',
                'webinar_key'      => $series['webinar_key'],
                'status'           => 'retrying',
                'error_message'    => $result['error'] ?? 'Unknown API error',
                'api_response'     => wp_json_encode( $result ),
            ) );
            GTW_Logger::send_alert( $full_name, $registrant['email'], $result['error'] ?? 'GoToWebinar API error' );
        }
    }

    /**
     * Get the active webinar series mapped to a form ID.
     */
    private function get_series_for_form( int $form_id ): ?array {
        global $wpdb;
        $table = $wpdb->prefix . 'gtw_webinar_series';

        return $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$table} WHERE wpforms_form_id = %d AND is_active = 1 LIMIT 1",
            $form_id
        ), ARRAY_A );
    }

    /**
     * Extract registrant data from WPForms fields using the field mapping.
     * Mapping format: { "firstName": "3", "lastName": "4", "email": "1", "phone": "5", "organization": "6" }
     * Where the value is the WPForms field ID.
     */
    private function extract_registrant( array $fields, array $mapping ): array {
        $registrant = array();
        $gtw_fields = array( 'firstName', 'lastName', 'email', 'phone', 'organization' );

        foreach ( $gtw_fields as $gtw_field ) {
            $wpforms_field_id = $mapping[ $gtw_field ] ?? '';
            if ( $wpforms_field_id === '' ) {
                continue;
            }

            // WPForms fields array is keyed by field ID
            foreach ( $fields as $field ) {
                if ( (string) ( $field['id'] ?? '' ) === (string) $wpforms_field_id ) {
                    $value = $field['value'] ?? '';

                    // Handle name fields (WPForms may combine first+last)
                    if ( $gtw_field === 'firstName' && ! empty( $field['first'] ) ) {
                        $value = $field['first'];
                    }
                    if ( $gtw_field === 'lastName' && ! empty( $field['last'] ) ) {
                        $value = $field['last'];
                    }

                    $registrant[ $gtw_field ] = sanitize_text_field( $value );
                    break;
                }
            }
        }

        return $registrant;
    }
}
