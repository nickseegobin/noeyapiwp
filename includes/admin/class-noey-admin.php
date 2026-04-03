<?php
/**
 * Noey_Admin — Admin panel bootstrap.
 *
 * Registers the top-level "NoeyAPI" admin menu with four sub-pages:
 *   Dashboard  — quick status overview
 *   Settings   — all plugin configuration
 *   Debug Log  — searchable log viewer (visible when debug mode is on)
 *   Test Suite — integrated API testing panel
 *
 * @package NoeyAPI
 */

defined( 'ABSPATH' ) || exit;

class Noey_Admin {

    public static function boot(): void {
        add_action( 'admin_menu',            [ __CLASS__, 'register_menus' ] );
        add_action( 'admin_enqueue_scripts', [ __CLASS__, 'enqueue_assets' ] );
        add_action( 'wp_ajax_noey_test',     [ __CLASS__, 'handle_test_ajax' ] );
        add_action( 'wp_ajax_noey_clear_logs', [ __CLASS__, 'handle_clear_logs' ] );
        add_action( 'wp_ajax_noey_pool_packages',      [ 'Noey_Admin_Pool', 'handle_ajax_packages' ] );
        add_action( 'wp_ajax_noey_railway_catalogue',  [ 'Noey_Admin_Pool', 'handle_ajax_railway_catalogue' ] );
        Noey_Admin_Pool::boot();
        Noey_Admin_Members::boot();
        Noey_Admin_Tokens::boot();
        Noey_Admin_Leaderboard::register();
    }

    // ── Menu Registration ─────────────────────────────────────────────────────

    public static function register_menus(): void {
        add_menu_page(
            'NoeyAPI',
            'NoeyAPI',
            'manage_options',
            'noey-api',
            [ __CLASS__, 'render_dashboard' ],
            'dashicons-rest-api',
            30
        );

        add_submenu_page( 'noey-api', 'Dashboard',    'Dashboard',    'manage_options', 'noey-api',          [ __CLASS__, 'render_dashboard' ] );
        add_submenu_page( 'noey-api', 'Members',      'Members',      'manage_options', 'noey-members',      [ 'Noey_Admin_Members', 'render' ] );
        add_submenu_page( 'noey-api', 'Tokens',       'Tokens',       'manage_options', 'noey-tokens',       [ 'Noey_Admin_Tokens', 'render' ] );
        add_submenu_page( 'noey-api', 'Pool Manager', 'Pool Manager', 'manage_options', 'noey-pool',         [ 'Noey_Admin_Pool', 'render' ] );
        add_submenu_page( 'noey-api', 'Settings',     'Settings',     'manage_options', 'noey-settings',     [ 'Noey_Admin_Settings', 'render' ] );
        add_submenu_page( 'noey-api', 'Debug Log',    'Debug Log',    'manage_options', 'noey-debug',        [ 'Noey_Admin_Debug', 'render' ] );
        add_submenu_page( 'noey-api', 'Test Suite',   'Test Suite',   'manage_options', 'noey-test-suite',   [ 'Noey_Admin_Testing', 'render' ] );
    }

    // ── Assets ────────────────────────────────────────────────────────────────

    public static function enqueue_assets( string $hook ): void {
        $noey_pages = [ 'toplevel_page_noey-api', 'noeyapi_page_noey-members', 'noeyapi_page_noey-tokens', 'noeyapi_page_noey-pool', 'noeyapi_page_noey-settings', 'noeyapi_page_noey-debug', 'noeyapi_page_noey-test-suite' ];

        if ( ! in_array( $hook, $noey_pages, true ) ) {
            return;
        }

        wp_enqueue_style(
            'noey-admin',
            NOEY_PLUGIN_URL . 'assets/css/noey-admin.css',
            [],
            NOEY_VERSION
        );

        wp_enqueue_script(
            'noey-admin',
            NOEY_PLUGIN_URL . 'assets/js/noey-admin.js',
            [ 'jquery' ],
            NOEY_VERSION,
            true
        );

        wp_localize_script( 'noey-admin', 'NoeyAdmin', [
            'ajaxUrl'   => admin_url( 'admin-ajax.php' ),
            'nonce'     => wp_create_nonce( 'noey_admin_nonce' ),
            'siteUrl'   => get_site_url(),
            'restBase'  => rest_url( NOEY_REST_NAMESPACE ),
            'version'   => NOEY_VERSION,
            'debugMode' => Noey_Debug::is_enabled() ? '1' : '0',
        ] );
    }

    // ── Dashboard ─────────────────────────────────────────────────────────────

    public static function render_dashboard(): void {
        global $wpdb;

        $parent_count = count( get_users( [ 'role' => 'noey_parent', 'fields' => 'ID' ] ) );
        $child_count  = count( get_users( [ 'role' => 'noey_child',  'fields' => 'ID' ] ) );
        $pool_count   = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}noey_exam_pool" );
        $session_count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}noey_exam_sessions WHERE state = 'completed'" );
        $insight_count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}noey_exam_insights" );
        $log_count    = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}noey_debug_log" );

        $railway_ok = ! empty( get_option( 'noey_railway_endpoint' ) );
        ?>
        <div class="wrap noey-wrap">
            <h1>NoeyAPI <span class="noey-version">v<?= esc_html( NOEY_VERSION ) ?></span></h1>

            <div class="noey-status-bar <?= Noey_Debug::is_enabled() ? 'debug-on' : 'debug-off' ?>">
                <?php if ( Noey_Debug::is_enabled() ) : ?>
                    <span class="dashicons dashicons-visibility"></span> Debug Mode is <strong>ON</strong> — <?= esc_html( $log_count ) ?> log entries
                <?php else : ?>
                    <span class="dashicons dashicons-hidden"></span> Debug Mode is <strong>OFF</strong>
                <?php endif; ?>
                &nbsp;|&nbsp;
                Railway: <?= $railway_ok ? '<span class="noey-badge ok">Configured</span>' : '<span class="noey-badge warn">Not configured</span>' ?>
            </div>

            <div class="noey-stat-grid">
                <div class="noey-stat-card">
                    <div class="noey-stat-number"><?= esc_html( $parent_count ) ?></div>
                    <div class="noey-stat-label">Parent Accounts</div>
                </div>
                <div class="noey-stat-card">
                    <div class="noey-stat-number"><?= esc_html( $child_count ) ?></div>
                    <div class="noey-stat-label">Student Profiles</div>
                </div>
                <div class="noey-stat-card">
                    <div class="noey-stat-number"><?= esc_html( $pool_count ) ?></div>
                    <div class="noey-stat-label">Exam Packages in Pool</div>
                </div>
                <div class="noey-stat-card">
                    <div class="noey-stat-number"><?= esc_html( $session_count ) ?></div>
                    <div class="noey-stat-label">Completed Exams</div>
                </div>
                <div class="noey-stat-card">
                    <div class="noey-stat-number"><?= esc_html( $insight_count ) ?></div>
                    <div class="noey-stat-label">AI Insights Generated</div>
                </div>
            </div>

            <div class="noey-quick-links">
                <a href="<?= esc_url( admin_url( 'admin.php?page=noey-members' ) ) ?>" class="button button-primary">Members</a>
                <a href="<?= esc_url( admin_url( 'admin.php?page=noey-tokens' ) ) ?>" class="button button-primary">Tokens</a>
                <a href="<?= esc_url( admin_url( 'admin.php?page=noey-pool' ) ) ?>" class="button button-primary">Pool Manager</a>
                <a href="<?= esc_url( admin_url( 'admin.php?page=noey-settings' ) ) ?>" class="button">Settings</a>
                <a href="<?= esc_url( admin_url( 'admin.php?page=noey-debug' ) ) ?>" class="button">Debug Log</a>
                <a href="<?= esc_url( admin_url( 'admin.php?page=noey-test-suite' ) ) ?>" class="button">Test Suite</a>
            </div>

            <div class="noey-api-table-wrapper">
                <h2>API Endpoints Reference</h2>
                <table class="noey-table">
                    <thead><tr><th>Method</th><th>Route</th><th>Auth</th><th>Description</th></tr></thead>
                    <tbody>
                        <?php foreach ( self::endpoint_reference() as $ep ) : ?>
                        <tr>
                            <td><span class="noey-method <?= esc_attr( strtolower( $ep[0] ) ) ?>"><?= esc_html( $ep[0] ) ?></span></td>
                            <td><code><?= esc_html( '/noey/v1' . $ep[1] ) ?></code></td>
                            <td><?= esc_html( $ep[2] ) ?></td>
                            <td><?= esc_html( $ep[3] ) ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php
    }

    // ── AJAX Handlers ─────────────────────────────────────────────────────────

    public static function handle_test_ajax(): void {
        check_ajax_referer( 'noey_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Forbidden', 403 );

        $test = sanitize_key( $_POST['test'] ?? '' );
        $data = json_decode( stripslashes( $_POST['data'] ?? '{}' ), true ) ?: [];

        $result = Noey_Admin_Testing::run_test( $test, $data );
        wp_send_json( $result );
    }

    public static function handle_clear_logs(): void {
        check_ajax_referer( 'noey_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Forbidden', 403 );

        Noey_Debug::clear_logs();
        wp_send_json_success( [ 'message' => 'Debug logs cleared.' ] );
    }

    // ── Endpoint Reference ────────────────────────────────────────────────────

    private static function endpoint_reference(): array {
        return [
            [ 'GET',    '/ping',                          'Open',       'Health check' ],
            [ 'POST',   '/auth/login',                    'Open',       'Login → JWT' ],
            [ 'GET',    '/auth/me',                       'JWT',        'Current user profile + balance' ],
            [ 'POST',   '/auth/pin/set',                  'JWT Parent', 'Set / update parent PIN' ],
            [ 'POST',   '/auth/pin/verify',               'JWT Parent', 'Verify parent PIN' ],
            [ 'GET',    '/auth/pin/status',               'JWT Parent', 'PIN lock status' ],
            [ 'GET',    '/children',                      'JWT Parent', 'List children' ],
            [ 'POST',   '/children',                      'JWT Parent', 'Create child' ],
            [ 'GET',    '/children/{id}',                 'JWT Parent', 'Get child profile' ],
            [ 'PATCH',  '/children/{id}',                 'JWT Parent', 'Update child profile' ],
            [ 'DELETE', '/children/{id}',                 'JWT Parent', 'Remove child' ],
            [ 'POST',   '/children/{id}/switch',          'JWT Parent', 'Switch active child' ],
            [ 'POST',   '/children/deselect',             'JWT Parent', 'Return to parent view' ],
            [ 'GET',    '/tokens/balance',                'JWT',        'Token balance' ],
            [ 'GET',    '/tokens/ledger',                 'JWT',        'Transaction history' ],
            [ 'GET',    '/exams',                         'JWT',        'Exam catalogue' ],
            [ 'POST',   '/exams/start',                   'JWT',        'Start exam (deduct token)' ],
            [ 'GET',    '/exams/{id}/checkpoint',         'JWT',        'Get checkpoint' ],
            [ 'POST',   '/exams/{id}/checkpoint',         'JWT',        'Save checkpoint' ],
            [ 'POST',   '/exams/{id}/submit',             'JWT',        'Submit exam answers' ],
            [ 'GET',    '/results',                       'JWT',        'Exam history (paginated)' ],
            [ 'GET',    '/results/stats',                 'JWT',        'Aggregate stats' ],
            [ 'GET',    '/results/{id}',                  'JWT',        'Session detail + answers' ],
            [ 'POST',   '/insights/exam/{id}',            'JWT',        'Generate per-exam insight' ],
            [ 'GET',    '/insights/exam/{id}',            'JWT',        'Retrieve per-exam insight' ],
            [ 'GET',    '/insights/weekly/{week}',        'JWT',        'Weekly digest insight' ],
            [ 'POST',   '/insights/weekly/{week}',        'JWT',        'Trigger weekly digest' ],
        ];
    }
}
