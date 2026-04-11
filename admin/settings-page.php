<?php
/**
 * Admin Settings Page - WP Admin > Settings > GTW Integration
 */

if ( ! defined( 'ABSPATH' ) ) exit;

// Register menu
add_action( 'admin_menu', function() {
    add_options_page(
        'GTW Integration',
        'GTW Integration',
        'manage_options',
        'wp-gtw-settings',
        'wp_gtw_settings_page'
    );
} );

// Register settings
add_action( 'admin_init', function() {
    register_setting( 'wp_gtw_settings', 'wp_gtw_client_id' );
    register_setting( 'wp_gtw_settings', 'wp_gtw_client_secret' );
    register_setting( 'wp_gtw_settings', 'wp_gtw_cache_ttl', array( 'type' => 'integer', 'default' => 15 ) );
    register_setting( 'wp_gtw_settings', 'wp_gtw_alert_email' );
} );

// Handle AJAX: test connection
add_action( 'wp_ajax_wp_gtw_test_connection', function() {
    check_ajax_referer( 'wp_gtw_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Unauthorized' );

    $api    = new GTW_API();
    $result = $api->test_connection();
    wp_send_json( $result );
} );

// Handle AJAX: refresh sessions
add_action( 'wp_ajax_wp_gtw_refresh_sessions', function() {
    check_ajax_referer( 'wp_gtw_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Unauthorized' );

    $webinar_key = sanitize_text_field( $_POST['webinar_key'] ?? '' );
    if ( empty( $webinar_key ) ) {
        wp_send_json( array( 'success' => false, 'error' => 'No webinar key provided' ) );
    }

    $api      = new GTW_API();
    $resolver = new GTW_Session_Resolver( $api );
    $session  = $resolver->refresh_session( $webinar_key );

    if ( $session ) {
        wp_send_json( array(
            'success' => true,
            'session' => array(
                'sessionKey' => $session['sessionKey'],
                'startTime'  => $session['startTimeFormatted'],
                'endTime'    => $session['endTimeFormatted'],
            ),
        ) );
    } else {
        wp_send_json( array( 'success' => false, 'error' => 'No upcoming sessions found' ) );
    }
} );

// Handle AJAX: save series
add_action( 'wp_ajax_wp_gtw_save_series', function() {
    check_ajax_referer( 'wp_gtw_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Unauthorized' );

    global $wpdb;
    $table = $wpdb->prefix . 'gtw_webinar_series';

    $data = array(
        'label'                => sanitize_text_field( $_POST['label'] ?? 'Default Webinar' ),
        'webinar_key'          => sanitize_text_field( $_POST['webinar_key'] ?? '' ),
        'name_pattern'         => sanitize_text_field( $_POST['name_pattern'] ?? '' ),
        'wpforms_form_id'      => absint( $_POST['wpforms_form_id'] ?? 0 ),
        'field_mapping'        => wp_json_encode( array(
            'firstName'    => sanitize_text_field( $_POST['map_first_name'] ?? '' ),
            'lastName'     => sanitize_text_field( $_POST['map_last_name'] ?? '' ),
            'email'        => sanitize_text_field( $_POST['map_email'] ?? '' ),
            'phone'        => sanitize_text_field( $_POST['map_phone'] ?? '' ),
            'organization' => sanitize_text_field( $_POST['map_organization'] ?? '' ),
        ) ),
        'is_active'            => 1,
        'auto_create_enabled'  => ! empty( $_POST['auto_create_enabled'] ) ? 1 : 0,
        'auto_create_day'      => sanitize_text_field( $_POST['auto_create_day'] ?? 'monday' ),
        'auto_create_time'     => sanitize_text_field( $_POST['auto_create_time'] ?? '15:00' ),
        'auto_create_duration' => absint( $_POST['auto_create_duration'] ?? 30 ),
        'auto_create_timezone' => sanitize_text_field( $_POST['auto_create_timezone'] ?? 'America/New_York' ),
    );

    $series_id = absint( $_POST['series_id'] ?? 0 );

    if ( $series_id ) {
        $wpdb->update( $table, $data, array( 'id' => $series_id ) );
    } else {
        $wpdb->insert( $table, $data );
        $series_id = $wpdb->insert_id;
    }

    wp_send_json( array( 'success' => true, 'id' => $series_id ) );
} );

// Handle AJAX: get WPForms form fields for auto-mapping
add_action( 'wp_ajax_wp_gtw_get_form_fields', function() {
    check_ajax_referer( 'wp_gtw_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Unauthorized' );

    $form_id = absint( $_POST['form_id'] ?? 0 );
    if ( ! $form_id || ! function_exists( 'wpforms' ) ) {
        wp_send_json( array( 'success' => false, 'error' => 'Invalid form ID or WPForms not active' ) );
    }

    $form = wpforms()->form->get( $form_id );
    if ( ! $form ) {
        wp_send_json( array( 'success' => false, 'error' => 'Form not found' ) );
    }

    $form_data = wpforms_decode( $form->post_content );
    $fields = $form_data['fields'] ?? array();

    $result = array( 'success' => true, 'fields' => array(), 'auto_map' => array() );

    foreach ( $fields as $fid => $field ) {
        $label = strtolower( $field['label'] ?? '' );
        $type  = $field['type'] ?? '';
        $result['fields'][] = array( 'id' => $fid, 'label' => $field['label'] ?? '', 'type' => $type );

        // Auto-detect mapping by label and type
        if ( $type === 'name' || strpos( $label, 'name' ) !== false ) {
            if ( $type === 'name' ) {
                // WPForms Name field has sub-fields (first/last)
                if ( ! isset( $result['auto_map']['firstName'] ) ) {
                    $result['auto_map']['firstName'] = (string) $fid;
                    $result['auto_map']['lastName']  = (string) $fid;
                }
            } elseif ( strpos( $label, 'first' ) !== false ) {
                $result['auto_map']['firstName'] = (string) $fid;
            } elseif ( strpos( $label, 'last' ) !== false ) {
                $result['auto_map']['lastName'] = (string) $fid;
            }
        }
        if ( $type === 'email' || strpos( $label, 'email' ) !== false || strpos( $label, 'e-mail' ) !== false ) {
            $result['auto_map']['email'] = (string) $fid;
        }
        if ( $type === 'phone' || strpos( $label, 'phone' ) !== false || strpos( $label, 'tel' ) !== false ) {
            $result['auto_map']['phone'] = (string) $fid;
        }
        if ( strpos( $label, 'company' ) !== false || strpos( $label, 'organization' ) !== false || strpos( $label, 'business' ) !== false || strpos( $label, 'shop' ) !== false ) {
            $result['auto_map']['organization'] = (string) $fid;
        }
    }

    wp_send_json( $result );
} );

// Handle AJAX: list webinars from GTW account
add_action( 'wp_ajax_wp_gtw_list_webinars', function() {
    check_ajax_referer( 'wp_gtw_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Unauthorized' );

    $api    = new GTW_API();
    $result = $api->list_webinars();
    wp_send_json( $result );
} );

// Handle AJAX: disconnect
add_action( 'wp_ajax_wp_gtw_disconnect', function() {
    check_ajax_referer( 'wp_gtw_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Unauthorized' );

    $api = new GTW_API();
    $api->disconnect();
    wp_send_json( array( 'success' => true ) );
} );

/**
 * Render the settings page.
 */
function wp_gtw_settings_page() {
    // Handle OAuth callback (GoTo redirects back with ?code=...)
    if ( isset( $_GET['code'] ) && ! empty( $_GET['code'] ) ) {
        $api = new GTW_API();
        $success = $api->exchange_code( sanitize_text_field( $_GET['code'] ) );
        if ( $success ) {
            echo '<div class="notice notice-success"><p><strong>Connected to GoToWebinar successfully!</strong></p></div>';
        } else {
            echo '<div class="notice notice-error"><p><strong>Failed to connect.</strong> Check your Client ID and Secret, then try again.</p></div>';
        }
    }

    global $wpdb;
    $series_table = $wpdb->prefix . 'gtw_webinar_series';
    $series = $wpdb->get_row( "SELECT * FROM {$series_table} WHERE is_active = 1 LIMIT 1", ARRAY_A );
    $api = new GTW_API();
    $is_connected = $api->is_connected();

    $mapping = $series ? ( json_decode( $series['field_mapping'] ?? '{}', true ) ?: array() ) : array();

    // Get WPForms forms for dropdown
    $wpforms_forms = array();
    if ( function_exists( 'wpforms' ) ) {
        $forms = wpforms()->form->get();
        if ( $forms ) {
            foreach ( $forms as $form ) {
                $wpforms_forms[] = array( 'id' => $form->ID, 'title' => $form->post_title );
            }
        }
    }

    $nonce = wp_create_nonce( 'wp_gtw_nonce' );
    ?>
    <div class="wrap">
        <h1>GoToWebinar Integration</h1>

        <style>
            .gtw-card { background: #fff; border: 1px solid #ccd0d4; border-radius: 4px; padding: 20px; margin-bottom: 20px; }
            .gtw-card h2 { margin-top: 0; padding: 0; font-size: 1.2em; border-bottom: 1px solid #eee; padding-bottom: 10px; }
            .gtw-status { display: inline-block; padding: 4px 12px; border-radius: 3px; font-weight: 600; font-size: 13px; }
            .gtw-status-ok { background: #d4edda; color: #155724; }
            .gtw-status-error { background: #f8d7da; color: #721c24; }
            .gtw-status-pending { background: #fff3cd; color: #856404; }
            .gtw-session-box { background: #f0f6ff; border: 1px solid #b8daff; border-radius: 4px; padding: 15px; margin-top: 10px; }
            #gtw-connection-result { margin-top: 10px; }
        </style>

        <!-- API Credentials & Connection -->
        <div class="gtw-card">
            <h2>GoToWebinar Connection</h2>

            <?php if ( $is_connected ) : ?>
                <p><span class="gtw-status gtw-status-ok">Connected</span></p>
                <button class="button" id="gtw-test-btn">Test Connection</button>
                <button class="button" id="gtw-disconnect-btn" style="color:#dc3545;">Disconnect</button>
                <div id="gtw-connection-result" style="margin-top:10px;"></div>
            <?php else : ?>
                <p>Enter your GoToWebinar OAuth credentials, save, then click Connect.</p>
                <form method="post" action="options.php">
                    <?php settings_fields( 'wp_gtw_settings' ); ?>
                    <table class="form-table">
                        <tr>
                            <th>Client ID</th>
                            <td><input type="text" name="wp_gtw_client_id" value="<?php echo esc_attr( get_option( 'wp_gtw_client_id', '' ) ); ?>" class="regular-text" /></td>
                        </tr>
                        <tr>
                            <th>Client Secret</th>
                            <td><input type="password" name="wp_gtw_client_secret" value="<?php echo esc_attr( get_option( 'wp_gtw_client_secret', '' ) ); ?>" class="regular-text" /></td>
                        </tr>
                    </table>
                    <?php submit_button( 'Save Credentials' ); ?>
                </form>
                <?php if ( get_option( 'wp_gtw_client_id' ) ) : ?>
                    <a href="<?php echo esc_url( $api->get_auth_url() ); ?>" class="button button-primary button-hero">Connect to GoToWebinar</a>
                    <p class="description" style="margin-top:10px;">You'll be redirected to GoTo to log in. After approval, you'll return here automatically.</p>
                <?php endif; ?>
                <div id="gtw-connection-result" style="margin-top:10px;"></div>
            <?php endif; ?>
        </div>

        <!-- Webinar Configuration -->
        <div class="gtw-card">
            <h2>Webinar Configuration</h2>
            <table class="form-table">
                <tr>
                    <th>Series Label</th>
                    <td><input type="text" id="gtw-label" value="<?php echo esc_attr( $series['label'] ?? 'Weekly Webinar' ); ?>" class="regular-text" /></td>
                </tr>
                <tr>
                    <th>Webinar Name Pattern</th>
                    <td>
                        <input type="text" id="gtw-name-pattern" value="<?php echo esc_attr( $series['name_pattern'] ?? '' ); ?>" class="regular-text" placeholder="e.g. 30 in 30" />
                        <button type="button" class="button" id="gtw-list-webinars-btn" style="vertical-align:baseline;margin-left:8px;">Browse Webinars</button>
                        <p class="description">The plugin finds the soonest upcoming webinar whose name contains this text. No manual ID updates needed - it floats to the next matching session automatically.</p>
                        <div id="gtw-webinar-list" style="margin-top:10px;"></div>
                    </td>
                </tr>
                <tr>
                    <th>Webinar Key (optional)</th>
                    <td>
                        <input type="text" id="gtw-webinar-key" value="<?php echo esc_attr( $series['webinar_key'] ?? '' ); ?>" class="regular-text" placeholder="Leave empty if using name pattern" />
                        <p class="description">Legacy: specific webinar key. Only needed if name pattern matching is not used.</p>
                    </td>
                </tr>
            </table>
            <button class="button" id="gtw-refresh-btn">Refresh Sessions</button>

            <h3 style="margin-top:20px;">Auto-Create Sessions</h3>
            <p class="description">When no upcoming session matches the name pattern, the plugin can automatically create a new webinar session.</p>
            <table class="form-table">
                <tr>
                    <th>Enable Auto-Create</th>
                    <td><label><input type="checkbox" id="gtw-auto-create" <?php checked( $series['auto_create_enabled'] ?? 0 ); ?> /> Automatically create a new webinar when none is available</label></td>
                </tr>
                <tr>
                    <th>Day of Week</th>
                    <td>
                        <select id="gtw-auto-day">
                            <?php foreach ( array( 'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday' ) as $d ) : ?>
                                <option value="<?php echo $d; ?>" <?php selected( $series['auto_create_day'] ?? 'monday', $d ); ?>><?php echo ucfirst( $d ); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th>Time (local)</th>
                    <td><input type="time" id="gtw-auto-time" value="<?php echo esc_attr( $series['auto_create_time'] ?? '15:00' ); ?>" /></td>
                </tr>
                <tr>
                    <th>Duration (minutes)</th>
                    <td><input type="number" id="gtw-auto-duration" value="<?php echo esc_attr( $series['auto_create_duration'] ?? 30 ); ?>" min="15" max="240" class="small-text" /></td>
                </tr>
                <tr>
                    <th>Timezone</th>
                    <td>
                        <select id="gtw-auto-tz">
                            <?php foreach ( array( 'America/New_York', 'America/Chicago', 'America/Denver', 'America/Los_Angeles', 'UTC' ) as $tz ) : ?>
                                <option value="<?php echo $tz; ?>" <?php selected( $series['auto_create_timezone'] ?? 'America/New_York', $tz ); ?>><?php echo $tz; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                </tr>
            </table>

            <?php if ( ! empty( $series['cached_session_id'] ) ) : ?>
                <div class="gtw-session-box">
                    <strong>Active Session:</strong><br />
                    Session ID: <?php echo esc_html( $series['cached_session_id'] ); ?><br />
                    Start: <?php echo esc_html( $series['cached_session_start'] ); ?> UTC<br />
                    End: <?php echo esc_html( $series['cached_session_end'] ); ?> UTC
                </div>
            <?php else : ?>
                <div class="gtw-session-box">
                    <span class="gtw-status gtw-status-pending">No session cached - will resolve on next registration</span>
                </div>
            <?php endif; ?>
            <div id="gtw-session-result" style="margin-top:10px;"></div>
        </div>

        <!-- Form Mapping -->
        <div class="gtw-card">
            <h2>WPForms Field Mapping</h2>
            <table class="form-table">
                <tr>
                    <th>WPForms Form</th>
                    <td>
                        <select id="gtw-form-id">
                            <option value="">Select a form</option>
                            <?php foreach ( $wpforms_forms as $form ) : ?>
                                <option value="<?php echo esc_attr( $form['id'] ); ?>" <?php selected( $series['wpforms_form_id'] ?? '', $form['id'] ); ?>>
                                    <?php echo esc_html( $form['title'] ); ?> (ID: <?php echo esc_html( $form['id'] ); ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <?php if ( empty( $wpforms_forms ) ) : ?>
                            <p class="description" style="color:#dc3545;">WPForms not detected. Install and activate WPForms to see forms here.</p>
                        <?php endif; ?>
                    </td>
                </tr>
                <tr><th>First Name Field ID</th><td><input type="text" id="gtw-map-first" value="<?php echo esc_attr( $mapping['firstName'] ?? '' ); ?>" class="small-text" /></td></tr>
                <tr><th>Last Name Field ID</th><td><input type="text" id="gtw-map-last" value="<?php echo esc_attr( $mapping['lastName'] ?? '' ); ?>" class="small-text" /></td></tr>
                <tr><th>Email Field ID</th><td><input type="text" id="gtw-map-email" value="<?php echo esc_attr( $mapping['email'] ?? '' ); ?>" class="small-text" /> <span style="color:red;">*required</span></td></tr>
                <tr><th>Phone Field ID</th><td><input type="text" id="gtw-map-phone" value="<?php echo esc_attr( $mapping['phone'] ?? '' ); ?>" class="small-text" /></td></tr>
                <tr><th>Organization Field ID</th><td><input type="text" id="gtw-map-org" value="<?php echo esc_attr( $mapping['organization'] ?? '' ); ?>" class="small-text" /></td></tr>
            </table>
            <p class="description">Enter the WPForms field IDs for each GoToWebinar field. Find field IDs in WPForms form editor (each field shows its ID).</p>
        </div>

        <!-- Notification Settings -->
        <div class="gtw-card">
            <h2>Notification Settings</h2>
            <form method="post" action="options.php">
                <?php settings_fields( 'wp_gtw_settings' ); ?>
                <table class="form-table">
                    <tr>
                        <th>Alert Email</th>
                        <td>
                            <input type="email" name="wp_gtw_alert_email" value="<?php echo esc_attr( get_option( 'wp_gtw_alert_email', get_option( 'admin_email' ) ) ); ?>" class="regular-text" />
                            <p class="description">Receives alerts when registrations fail.</p>
                        </td>
                    </tr>
                    <tr>
                        <th>Cache TTL (minutes)</th>
                        <td>
                            <input type="number" name="wp_gtw_cache_ttl" value="<?php echo esc_attr( get_option( 'wp_gtw_cache_ttl', 15 ) ); ?>" min="1" max="60" class="small-text" />
                            <p class="description">How long to cache the resolved session before re-querying the API.</p>
                        </td>
                    </tr>
                </table>
                <?php submit_button( 'Save Notification Settings' ); ?>
            </form>
        </div>

        <button class="button button-primary button-hero" id="gtw-save-series">Save Webinar Configuration</button>
        <input type="hidden" id="gtw-series-id" value="<?php echo esc_attr( $series['id'] ?? '' ); ?>" />
        <input type="hidden" id="gtw-nonce" value="<?php echo esc_attr( $nonce ); ?>" />

        <script>
        jQuery(function($) {
            var nonce = $('#gtw-nonce').val();

            // Test connection
            $('#gtw-test-btn').on('click', function() {
                var $btn = $(this).prop('disabled', true).text('Testing...');
                $.post(ajaxurl, { action: 'wp_gtw_test_connection', nonce: nonce }, function(r) {
                    var html = r.success
                        ? '<span class="gtw-status gtw-status-ok">Connected - ' + (r.data.email || '') + '</span>'
                        : '<span class="gtw-status gtw-status-error">Error: ' + (r.error || 'Unknown') + '</span>';
                    $('#gtw-connection-result').html(html);
                    $btn.prop('disabled', false).text('Test Connection');
                });
            });

            // Auto-detect form field mapping on form selection change
            $('#gtw-form-id').on('change', function() {
                var formId = $(this).val();
                if (!formId) return;
                $.post(ajaxurl, { action: 'wp_gtw_get_form_fields', nonce: nonce, form_id: formId }, function(r) {
                    if (!r.success) return;
                    var map = r.auto_map || {};
                    if (map.firstName) $('#gtw-map-first').val(map.firstName);
                    if (map.lastName) $('#gtw-map-last').val(map.lastName);
                    if (map.email) $('#gtw-map-email').val(map.email);
                    if (map.phone) $('#gtw-map-phone').val(map.phone);
                    if (map.organization) $('#gtw-map-org').val(map.organization);

                    // Show detected fields below the form selector
                    var html = '<div style="margin-top:8px;">';
                    html += '<span class="gtw-status gtw-status-ok" style="font-size:12px;">Auto-detected ' + Object.keys(map).length + ' field(s)</span>';
                    html += '<ul style="font-size:12px;margin:6px 0 0 16px;color:#555;">';
                    (r.fields || []).forEach(function(f) {
                        html += '<li>ID ' + f.id + ': ' + f.label + ' (' + f.type + ')</li>';
                    });
                    html += '</ul></div>';
                    $('#gtw-form-fields-info').remove();
                    $(html).attr('id', 'gtw-form-fields-info').insertAfter('#gtw-form-id');
                });
            });

            // Disconnect
            $('#gtw-disconnect-btn').on('click', function() {
                if (!confirm('Disconnect from GoToWebinar? You will need to reconnect to resume registrations.')) return;
                $.post(ajaxurl, { action: 'wp_gtw_disconnect', nonce: nonce }, function() {
                    location.reload();
                });
            });

            // List webinars
            $('#gtw-list-webinars-btn').on('click', function() {
                var $btn = $(this).prop('disabled', true).text('Loading...');
                $.post(ajaxurl, { action: 'wp_gtw_list_webinars', nonce: nonce }, function(r) {
                    $btn.prop('disabled', false).text('Browse Webinars');
                    if (!r.success) {
                        $('#gtw-webinar-list').html('<span class="gtw-status gtw-status-error">' + (r.error || 'Failed to load webinars') + '</span>');
                        return;
                    }
                    var webinars = r.webinars || [];
                    if (!webinars.length) {
                        $('#gtw-webinar-list').html('<span class="gtw-status gtw-status-pending">No webinars found on this account</span>');
                        return;
                    }
                    var html = '<table class="widefat striped" style="max-width:800px;"><thead><tr><th>Webinar</th><th>Date</th><th>Type</th><th>Key</th><th></th></tr></thead><tbody>';
                    webinars.forEach(function(w) {
                        var times = w.times || [];
                        var dateStr = times.length ? new Date(times[0].startTime).toLocaleDateString('en-US', {month:'short', day:'numeric', year:'numeric', hour:'numeric', minute:'2-digit'}) : 'N/A';
                        var type = (w.recurrenceType || 'single').replace('_', ' ');
                        var shortSubject = w.subject.length > 50 ? w.subject.substring(0, 50) + '...' : w.subject;
                        // Extract a name pattern from the subject (first phrase before |)
                        var namePattern = w.subject.split('|')[0].trim();
                        if (namePattern.length > 40) namePattern = namePattern.substring(0, 40);
                        html += '<tr>';
                        html += '<td title="' + w.subject.replace(/"/g, '&quot;') + '">' + shortSubject + '</td>';
                        html += '<td style="white-space:nowrap;font-size:12px;">' + dateStr + '</td>';
                        html += '<td><span style="font-size:11px;padding:2px 6px;border-radius:3px;background:' + (type === 'series' ? '#d4edda' : type === 'sequence' ? '#fff3cd' : '#e2e3e5') + ';">' + type + '</span></td>';
                        html += '<td style="font-family:monospace;font-size:11px;">' + w.webinarKey.substring(0, 12) + '...</td>';
                        html += '<td>';
                        html += '<button type="button" class="button button-small gtw-pick-pattern" data-pattern="' + namePattern.replace(/"/g, '&quot;') + '" style="margin-right:4px;">Use Name</button>';
                        html += '<button type="button" class="button button-small gtw-pick-webinar" data-key="' + w.webinarKey + '">Use Key</button>';
                        html += '</td></tr>';
                    });
                    html += '</tbody></table>';
                    html += '<p style="font-size:12px;color:#666;margin-top:8px;"><strong>Use Name</strong> = floating match (recommended for recurring). <strong>Use Key</strong> = exact webinar (legacy).</p>';
                    $('#gtw-webinar-list').html(html);
                    $('.gtw-pick-pattern').on('click', function() {
                        $('#gtw-name-pattern').val($(this).data('pattern'));
                        $('#gtw-webinar-key').val('');
                        $('#gtw-webinar-list').html('<span class="gtw-status gtw-status-ok">Pattern set: "' + $(this).data('pattern') + '" - will match the soonest upcoming webinar with this name</span>');
                    });
                    $('.gtw-pick-webinar').on('click', function() {
                        $('#gtw-webinar-key').val($(this).data('key'));
                        $('#gtw-webinar-list').html('<span class="gtw-status gtw-status-ok">Key set: ' + $(this).data('key') + '</span>');
                    });
                });
            });

            // Refresh sessions
            $('#gtw-refresh-btn').on('click', function() {
                var $btn = $(this).prop('disabled', true).text('Refreshing...');
                $.post(ajaxurl, { action: 'wp_gtw_refresh_sessions', nonce: nonce, webinar_key: $('#gtw-webinar-key').val() }, function(r) {
                    var html = r.success
                        ? '<span class="gtw-status gtw-status-ok">Next session: ' + r.session.startTime + ' UTC (ID: ' + r.session.sessionKey + ')</span>'
                        : '<span class="gtw-status gtw-status-error">' + (r.error || 'No sessions found') + '</span>';
                    $('#gtw-session-result').html(html);
                    $btn.prop('disabled', false).text('Refresh Sessions');
                });
            });

            // Save series
            $('#gtw-save-series').on('click', function() {
                var $btn = $(this).prop('disabled', true).text('Saving...');
                $.post(ajaxurl, {
                    action: 'wp_gtw_save_series',
                    nonce: nonce,
                    series_id: $('#gtw-series-id').val(),
                    label: $('#gtw-label').val(),
                    webinar_key: $('#gtw-webinar-key').val(),
                    name_pattern: $('#gtw-name-pattern').val(),
                    wpforms_form_id: $('#gtw-form-id').val(),
                    map_first_name: $('#gtw-map-first').val(),
                    map_last_name: $('#gtw-map-last').val(),
                    map_email: $('#gtw-map-email').val(),
                    map_phone: $('#gtw-map-phone').val(),
                    map_organization: $('#gtw-map-org').val(),
                    auto_create_enabled: $('#gtw-auto-create').is(':checked') ? 1 : 0,
                    auto_create_day: $('#gtw-auto-day').val(),
                    auto_create_time: $('#gtw-auto-time').val(),
                    auto_create_duration: $('#gtw-auto-duration').val(),
                    auto_create_timezone: $('#gtw-auto-tz').val(),
                }, function(r) {
                    if (r.success) {
                        $('#gtw-series-id').val(r.id);
                        alert('Saved successfully!');
                    } else {
                        alert('Save failed: ' + (r.error || 'Unknown error'));
                    }
                    $btn.prop('disabled', false).text('Save Webinar Configuration');
                });
            });
        });
        </script>
    </div>
    <?php
}
