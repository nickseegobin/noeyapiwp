<?php
/**
 * Noey_Cron — Scheduled background jobs.
 *
 * Jobs:
 *  noey_monthly_token_refresh  — 1st of each month at 00:05 UTC
 *                                 Resets free-tier token balances to NOEY_FREE_TOKEN_MONTHLY.
 *
 *  noey_weekly_digest          — Every Monday at 06:00 UTC
 *                                 Generates AI weekly digest insights for all children
 *                                 who completed ≥1 exam in the past 7 days.
 *                                 Small random delay per child to spread Railway API load.
 *
 * @package NoeyAPI
 */

defined( 'ABSPATH' ) || exit;

class Noey_Cron {

    /**
     * Register WP cron action hooks.
     * Called from Noey_Core::boot().
     */
    public static function register_hooks(): void {
        add_action( 'noey_monthly_token_refresh', [ __CLASS__, 'run_monthly_token_refresh' ] );
        add_action( 'noey_weekly_digest',         [ __CLASS__, 'run_weekly_digest' ] );

        // Add 'monthly' to WP cron schedules if not present
        add_filter( 'cron_schedules', [ __CLASS__, 'add_cron_schedules' ] );
    }

    // ── Monthly Token Refresh ─────────────────────────────────────────────────

    public static function run_monthly_token_refresh(): void {
        Noey_Debug::log( 'cron.token_refresh', 'Monthly token refresh cron started', [], null, 'info' );

        $count = Noey_Token_Service::run_monthly_refresh();

        Noey_Debug::log( 'cron.token_refresh', 'Monthly token refresh cron completed', [
            'accounts_refreshed' => $count,
        ], null, 'info' );
    }

    // ── Weekly Digest ─────────────────────────────────────────────────────────

    public static function run_weekly_digest(): void {
        $iso_week = date( 'o-\WW' ); // e.g. 2026-W12

        Noey_Debug::log( 'cron.weekly_digest', 'Weekly digest cron started', [
            'iso_week' => $iso_week,
        ], null, 'info' );

        // Find children with ≥1 completed exam in the past 7 days
        global $wpdb;
        $since = date( 'Y-m-d H:i:s', strtotime( '-7 days' ) );

        $active_children = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT DISTINCT child_id
                 FROM {$wpdb->prefix}noey_exam_sessions
                 WHERE state = 'completed' AND completed_at >= %s",
                $since
            )
        );

        Noey_Debug::log( 'cron.weekly_digest', 'Active children found', [
            'count'    => count( $active_children ),
            'iso_week' => $iso_week,
        ], null, 'info' );

        $processed  = 0;
        $skipped    = 0;
        $errors     = 0;

        foreach ( $active_children as $child_id ) {
            // Small random delay to stagger Railway API calls (0–5 seconds)
            usleep( random_int( 0, 5000000 ) );

            $result = Noey_Insight_Service::generate_weekly_digest( (int) $child_id, $iso_week );

            if ( is_wp_error( $result ) ) {
                Noey_Debug::log( 'cron.weekly_digest', 'Digest generation failed', [
                    'child_id' => $child_id,
                    'error'    => $result->get_error_message(),
                ], (int) $child_id, 'error' );
                $errors++;
            } elseif ( ! empty( $result['skipped'] ) ) {
                $skipped++;
            } else {
                $processed++;
            }
        }

        Noey_Debug::log( 'cron.weekly_digest', 'Weekly digest cron completed', [
            'iso_week'  => $iso_week,
            'processed' => $processed,
            'skipped'   => $skipped,
            'errors'    => $errors,
        ], null, 'info' );
    }

    // ── Custom Schedules ──────────────────────────────────────────────────────

    public static function add_cron_schedules( array $schedules ): array {
        if ( ! isset( $schedules['monthly'] ) ) {
            $schedules['monthly'] = [
                'interval' => 30 * DAY_IN_SECONDS,
                'display'  => 'Once a month',
            ];
        }
        return $schedules;
    }
}
