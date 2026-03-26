<?php
/**
 * Noey_Activator — Plugin activation, database setup, role registration.
 *
 * Creates all NoeyAPI database tables, registers WP roles, sets default options,
 * and schedules cron jobs.
 *
 * @package NoeyAPI
 */

defined( 'ABSPATH' ) || exit;

class Noey_Activator {

    // ── Activation ────────────────────────────────────────────────────────────

    public static function activate(): void {
        self::create_tables();
        self::register_roles();
        self::set_defaults();
        self::schedule_crons();

        update_option( 'noey_db_version', NOEY_DB_VERSION );
        flush_rewrite_rules();
    }

    // ── Deactivation ─────────────────────────────────────────────────────────

    public static function deactivate(): void {
        wp_clear_scheduled_hook( 'noey_monthly_token_refresh' );
        wp_clear_scheduled_hook( 'noey_weekly_digest' );
        flush_rewrite_rules();
    }

    // ── Safety net (called on every boot if DB version mismatch) ─────────────

    public static function maybe_upgrade(): void {
        if ( get_option( 'noey_db_version' ) !== NOEY_DB_VERSION ) {
            self::create_tables();
            update_option( 'noey_db_version', NOEY_DB_VERSION );
        }
    }

    // ── DB Tables ─────────────────────────────────────────────────────────────

    private static function create_tables(): void {
        global $wpdb;
        $charset = $wpdb->get_charset_collate();

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        // ── 1. Children (parent ↔ child relationships) ───────────────────────
        dbDelta( "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}noey_children (
            child_row_id  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            parent_id     BIGINT UNSIGNED NOT NULL,
            child_id      BIGINT UNSIGNED NOT NULL,
            display_name  VARCHAR(100)    NOT NULL DEFAULT '',
            standard      VARCHAR(20)     NOT NULL DEFAULT '',
            term          VARCHAR(20)     NOT NULL DEFAULT '',
            age           TINYINT UNSIGNED         DEFAULT NULL,
            avatar_index  TINYINT UNSIGNED NOT NULL DEFAULT 1,
            created_at    DATETIME        NOT NULL,
            PRIMARY KEY   (child_row_id),
            UNIQUE KEY    uq_child (child_id),
            KEY           idx_parent (parent_id)
        ) {$charset};" );

        // ── 2. Token Ledger (append-only audit trail) ─────────────────────────
        dbDelta( "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}noey_token_ledger (
            ledger_id     BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id       BIGINT UNSIGNED NOT NULL,
            amount        INT             NOT NULL,
            balance_after INT             NOT NULL,
            type          ENUM('purchase','exam_deduct','registration','monthly_refresh','admin_credit','admin_deduct','refund') NOT NULL,
            reference_id  VARCHAR(100)             DEFAULT NULL,
            note          TEXT                     DEFAULT NULL,
            created_at    DATETIME        NOT NULL,
            PRIMARY KEY   (ledger_id),
            KEY           idx_user (user_id),
            KEY           idx_created (created_at)
        ) {$charset};" );

        // ── 3. Exam Pool ──────────────────────────────────────────────────────
        dbDelta( "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}noey_exam_pool (
            pool_id        BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            package_id     VARCHAR(100)    NOT NULL,
            standard       VARCHAR(20)     NOT NULL DEFAULT '',
            term           VARCHAR(20)     NOT NULL DEFAULT '',
            subject        VARCHAR(100)    NOT NULL DEFAULT '',
            difficulty     ENUM('easy','medium','hard') NOT NULL DEFAULT 'medium',
            package_json   LONGTEXT        NOT NULL,
            times_served   INT UNSIGNED    NOT NULL DEFAULT 0,
            last_served_at DATETIME                 DEFAULT NULL,
            created_at     DATETIME        NOT NULL,
            PRIMARY KEY    (pool_id),
            UNIQUE KEY     uq_package (package_id),
            KEY            idx_filter (standard, term, subject, difficulty)
        ) {$charset};" );

        // ── 4. Exam Sessions ──────────────────────────────────────────────────
        dbDelta( "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}noey_exam_sessions (
            session_id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            external_session_id VARCHAR(100)    NOT NULL,
            child_id            BIGINT UNSIGNED NOT NULL,
            parent_id           BIGINT UNSIGNED NOT NULL,
            package_id          VARCHAR(100)    NOT NULL DEFAULT '',
            subject             VARCHAR(100)    NOT NULL DEFAULT '',
            standard            VARCHAR(20)     NOT NULL DEFAULT '',
            term                VARCHAR(20)     NOT NULL DEFAULT '',
            difficulty          ENUM('easy','medium','hard') NOT NULL DEFAULT 'medium',
            state               ENUM('active','completed','cancelled') NOT NULL DEFAULT 'active',
            score               INT UNSIGNED             DEFAULT NULL,
            total               INT UNSIGNED             DEFAULT NULL,
            percentage          DECIMAL(5,2)             DEFAULT NULL,
            time_taken_seconds  INT UNSIGNED             DEFAULT NULL,
            started_at          DATETIME        NOT NULL,
            completed_at        DATETIME                 DEFAULT NULL,
            PRIMARY KEY         (session_id),
            UNIQUE KEY          uq_external (external_session_id),
            KEY                 idx_child (child_id),
            KEY                 idx_parent (parent_id),
            KEY                 idx_state (state),
            KEY                 idx_started (started_at)
        ) {$charset};" );

        // ── 5. Exam Answers ───────────────────────────────────────────────────
        dbDelta( "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}noey_exam_answers (
            answer_id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            session_id         BIGINT UNSIGNED NOT NULL,
            child_id           BIGINT UNSIGNED NOT NULL,
            question_id        VARCHAR(100)    NOT NULL DEFAULT '',
            topic              VARCHAR(200)    NOT NULL DEFAULT '',
            subtopic           VARCHAR(200)             DEFAULT NULL,
            cognitive_level    ENUM('recall','application','analysis') NOT NULL DEFAULT 'recall',
            selected_answer    VARCHAR(10)              DEFAULT NULL,
            correct_answer     VARCHAR(10)     NOT NULL DEFAULT '',
            is_correct         TINYINT(1)      NOT NULL DEFAULT 0,
            time_taken_seconds INT UNSIGNED             DEFAULT NULL,
            PRIMARY KEY        (answer_id),
            KEY                idx_session (session_id),
            KEY                idx_child (child_id)
        ) {$charset};" );

        // ── 6. Topic Breakdown (per-session aggregate) ────────────────────────
        dbDelta( "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}noey_topic_breakdown (
            breakdown_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            session_id   BIGINT UNSIGNED NOT NULL,
            child_id     BIGINT UNSIGNED NOT NULL,
            topic        VARCHAR(200)    NOT NULL DEFAULT '',
            correct      INT UNSIGNED    NOT NULL DEFAULT 0,
            total        INT UNSIGNED    NOT NULL DEFAULT 0,
            pct          DECIMAL(5,2)   NOT NULL DEFAULT 0.00,
            PRIMARY KEY  (breakdown_id),
            KEY          idx_session (session_id),
            KEY          idx_child (child_id)
        ) {$charset};" );

        // ── 7. Exam Insights (per-exam AI insight, cached) ────────────────────
        dbDelta( "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}noey_exam_insights (
            insight_id   BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            session_id   BIGINT UNSIGNED NOT NULL,
            child_id     BIGINT UNSIGNED NOT NULL,
            insight_text LONGTEXT        NOT NULL,
            model_used   VARCHAR(100)             DEFAULT NULL,
            generated_at DATETIME        NOT NULL,
            PRIMARY KEY  (insight_id),
            UNIQUE KEY   uq_session (session_id),
            KEY          idx_child (child_id)
        ) {$charset};" );

        // ── 8. Weekly Digest Insights ─────────────────────────────────────────
        dbDelta( "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}noey_weekly_insights (
            digest_id    BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            child_id     BIGINT UNSIGNED NOT NULL,
            iso_week     VARCHAR(10)     NOT NULL,
            payload_json LONGTEXT                 DEFAULT NULL,
            insight_text LONGTEXT                 DEFAULT NULL,
            generated_at DATETIME        NOT NULL,
            PRIMARY KEY  (digest_id),
            UNIQUE KEY   uq_child_week (child_id, iso_week),
            KEY          idx_child (child_id)
        ) {$charset};" );

        // ── 9. Debug Log ──────────────────────────────────────────────────────
        dbDelta( "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}noey_debug_log (
            log_id     BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            level      ENUM('debug','info','warning','error') NOT NULL DEFAULT 'info',
            context    VARCHAR(100)    NOT NULL DEFAULT '',
            message    TEXT            NOT NULL,
            data       LONGTEXT                 DEFAULT NULL,
            user_id    BIGINT UNSIGNED          DEFAULT NULL,
            request_id VARCHAR(20)              DEFAULT NULL,
            created_at DATETIME        NOT NULL,
            PRIMARY KEY (log_id),
            KEY         idx_level (level),
            KEY         idx_context (context),
            KEY         idx_created (created_at)
        ) {$charset};" );
    }

    // ── Roles ─────────────────────────────────────────────────────────────────

    private static function register_roles(): void {
        // Parent — account holder, billing user
        if ( ! get_role( 'noey_parent' ) ) {
            add_role( 'noey_parent', 'Noey Parent', [
                'read'      => true,
                'edit_posts' => false,
            ] );
        }

        // Child — learner profile, no admin access
        if ( ! get_role( 'noey_child' ) ) {
            add_role( 'noey_child', 'Noey Student', [
                'read' => true,
            ] );
        }
    }

    // ── Default Options ───────────────────────────────────────────────────────

    private static function set_defaults(): void {
        $defaults = [
            'noey_debug_enabled'       => false,
            'noey_railway_endpoint'    => '',
            'noey_railway_api_key'     => '',
            'noey_railway_server_key'  => '',
            'noey_allowed_origins'     => '',
            'noey_content_source'      => 'pool_only', // pool_only | railway | both
            'noey_dev_bypass_tokens'   => false,
            'noey_pool_default_target' => 10,
        ];

        foreach ( $defaults as $key => $value ) {
            if ( get_option( $key ) === false ) {
                add_option( $key, $value );
            }
        }
    }

    // ── Cron Scheduling ───────────────────────────────────────────────────────

    private static function schedule_crons(): void {
        // Monthly token refresh — 1st of month at 00:05 UTC
        if ( ! wp_next_scheduled( 'noey_monthly_token_refresh' ) ) {
            $first_of_month = mktime( 0, 5, 0, (int) date( 'n' ) + 1, 1, (int) date( 'Y' ) );
            wp_schedule_event( $first_of_month, 'monthly', 'noey_monthly_token_refresh' );
        }

        // Weekly digest — every Monday at 06:00 UTC
        if ( ! wp_next_scheduled( 'noey_weekly_digest' ) ) {
            // Find next Monday
            $now        = time();
            $days_until = ( 1 - (int) date( 'N', $now ) + 7 ) % 7;
            $next_mon   = strtotime( "+{$days_until} days", mktime( 6, 0, 0, (int) date( 'n', $now ), (int) date( 'j', $now ), (int) date( 'Y', $now ) ) );
            wp_schedule_event( $next_mon, 'weekly', 'noey_weekly_digest' );
        }
    }
}
