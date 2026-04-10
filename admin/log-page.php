<?php
/**
 * Admin Log Page - Activity log viewer at WP Admin > Settings > GTW Activity Log
 */

if ( ! defined( 'ABSPATH' ) ) exit;

add_action( 'admin_menu', function() {
    add_submenu_page(
        'options-general.php',
        'GTW Activity Log',
        'GTW Activity Log',
        'manage_options',
        'wp-gtw-log',
        'wp_gtw_log_page'
    );
} );

function wp_gtw_log_page() {
    // Handle purge action
    if ( isset( $_POST['gtw_purge'] ) && check_admin_referer( 'gtw_purge_action' ) ) {
        $deleted = GTW_Logger::purge_old( 90 );
        echo '<div class="notice notice-success"><p>Purged ' . intval( $deleted ) . ' entries older than 90 days.</p></div>';
    }

    $entries = GTW_Logger::get_entries( 100 );
    $counts  = GTW_Logger::get_counts();
    ?>
    <div class="wrap">
        <h1>GoToWebinar Activity Log</h1>

        <style>
            .gtw-stats { display: flex; gap: 15px; margin-bottom: 20px; }
            .gtw-stat { background: #fff; border: 1px solid #ccd0d4; border-radius: 4px; padding: 15px 20px; text-align: center; min-width: 100px; }
            .gtw-stat-num { font-size: 24px; font-weight: 700; }
            .gtw-stat-label { font-size: 12px; color: #666; margin-top: 4px; }
            .gtw-stat-success .gtw-stat-num { color: #28a745; }
            .gtw-stat-failed .gtw-stat-num { color: #dc3545; }
            .gtw-stat-retrying .gtw-stat-num { color: #ffc107; }
            .gtw-log-table { border-collapse: collapse; width: 100%; }
            .gtw-log-table th { text-align: left; padding: 8px 12px; background: #f1f1f1; border-bottom: 2px solid #ccc; }
            .gtw-log-table td { padding: 8px 12px; border-bottom: 1px solid #eee; vertical-align: top; }
            .gtw-badge { display: inline-block; padding: 2px 8px; border-radius: 3px; font-size: 11px; font-weight: 600; }
            .gtw-badge-success { background: #d4edda; color: #155724; }
            .gtw-badge-failed { background: #f8d7da; color: #721c24; }
            .gtw-badge-retrying { background: #fff3cd; color: #856404; }
            .gtw-badge-pending { background: #e2e3e5; color: #383d41; }
        </style>

        <!-- Stats -->
        <div class="gtw-stats">
            <div class="gtw-stat gtw-stat-success">
                <div class="gtw-stat-num"><?php echo intval( $counts['success'] ); ?></div>
                <div class="gtw-stat-label">Successful</div>
            </div>
            <div class="gtw-stat gtw-stat-failed">
                <div class="gtw-stat-num"><?php echo intval( $counts['failed'] ); ?></div>
                <div class="gtw-stat-label">Failed</div>
            </div>
            <div class="gtw-stat gtw-stat-retrying">
                <div class="gtw-stat-num"><?php echo intval( $counts['retrying'] ); ?></div>
                <div class="gtw-stat-label">Retrying</div>
            </div>
            <div class="gtw-stat">
                <div class="gtw-stat-num"><?php echo intval( $counts['success'] + $counts['failed'] + $counts['retrying'] + $counts['pending'] ); ?></div>
                <div class="gtw-stat-label">Total</div>
            </div>
        </div>

        <!-- Purge -->
        <form method="post" style="margin-bottom: 15px;">
            <?php wp_nonce_field( 'gtw_purge_action' ); ?>
            <button type="submit" name="gtw_purge" class="button">Purge entries older than 90 days</button>
        </form>

        <!-- Log Table -->
        <?php if ( empty( $entries ) ) : ?>
            <p>No registration attempts logged yet.</p>
        <?php else : ?>
            <table class="gtw-log-table">
                <thead>
                    <tr>
                        <th>Time (UTC)</th>
                        <th>Email</th>
                        <th>Name</th>
                        <th>Session</th>
                        <th>Status</th>
                        <th>Error</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ( $entries as $entry ) : ?>
                        <tr>
                            <td style="white-space:nowrap; font-size:12px;"><?php echo esc_html( $entry['created_at'] ); ?></td>
                            <td><?php echo esc_html( $entry['registrant_email'] ); ?></td>
                            <td><?php echo esc_html( $entry['registrant_name'] ); ?></td>
                            <td style="font-size:12px; font-family:monospace;"><?php echo esc_html( $entry['session_id'] ?: '-' ); ?></td>
                            <td><span class="gtw-badge gtw-badge-<?php echo esc_attr( $entry['status'] ); ?>"><?php echo esc_html( ucfirst( $entry['status'] ) ); ?></span></td>
                            <td style="font-size:12px; max-width:300px; word-break:break-word;"><?php echo esc_html( $entry['error_message'] ?: '-' ); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
    <?php
}
