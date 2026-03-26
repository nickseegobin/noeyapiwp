<?php
/**
 * Noey_Insights_API — AI insight endpoints.
 *
 * Routes:
 *   POST /noey/v1/insights/exam/{session_id}       JWT  Generate (or return cached) per-exam insight
 *   GET  /noey/v1/insights/exam/{session_id}       JWT  Retrieve stored per-exam insight
 *   GET  /noey/v1/insights/weekly/{iso_week}       JWT  Retrieve weekly digest (e.g. 2026-W12)
 *   POST /noey/v1/insights/weekly/{iso_week}       JWT  Manually trigger weekly digest for active child
 *
 * @package NoeyAPI
 */

defined( 'ABSPATH' ) || exit;

class Noey_Insights_API extends Noey_API_Base {

    public function register_routes(): void {
        $ns = $this->namespace;

        register_rest_route( $ns, '/insights/exam/(?P<session_id>\d+)', [
            [
                'methods'             => 'POST',
                'callback'            => [ $this, 'generate_exam_insight' ],
                'permission_callback' => '__return_true',
            ],
            [
                'methods'             => 'GET',
                'callback'            => [ $this, 'get_exam_insight' ],
                'permission_callback' => '__return_true',
            ],
        ] );

        register_rest_route( $ns, '/insights/weekly/(?P<iso_week>[0-9]{4}-W[0-9]{1,2})', [
            [
                'methods'             => 'GET',
                'callback'            => [ $this, 'get_weekly_digest' ],
                'permission_callback' => '__return_true',
            ],
            [
                'methods'             => 'POST',
                'callback'            => [ $this, 'trigger_weekly_digest' ],
                'permission_callback' => '__return_true',
            ],
        ] );
    }

    // ── Handlers ──────────────────────────────────────────────────────────────

    public function generate_exam_insight( WP_REST_Request $request ): WP_REST_Response|WP_Error {
        $ctx = $this->require_child_context( $request );
        if ( is_wp_error( $ctx ) ) return $ctx;

        $result = Noey_Insight_Service::get_or_generate_exam_insight(
            (int) $request['session_id'],
            $ctx['child_id']
        );

        return is_wp_error( $result ) ? $result : $this->success( $result );
    }

    public function get_exam_insight( WP_REST_Request $request ): WP_REST_Response|WP_Error {
        $ctx = $this->require_child_context( $request );
        if ( is_wp_error( $ctx ) ) return $ctx;

        global $wpdb;
        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}noey_exam_insights WHERE session_id = %d AND child_id = %d",
                (int) $request['session_id'],
                $ctx['child_id']
            ),
            ARRAY_A
        );

        if ( ! $row ) {
            return new WP_Error( 'noey_not_found', 'No insight found for this exam. Generate one first via POST.', [ 'status' => 404 ] );
        }

        return $this->success( [
            'session_id'   => (int) $row['session_id'],
            'insight_text' => $row['insight_text'],
            'model_used'   => $row['model_used'],
            'generated_at' => $row['generated_at'],
            'from_cache'   => true,
        ] );
    }

    public function get_weekly_digest( WP_REST_Request $request ): WP_REST_Response|WP_Error {
        $ctx = $this->require_child_context( $request );
        if ( is_wp_error( $ctx ) ) return $ctx;

        $result = Noey_Insight_Service::get_weekly_digest( $ctx['child_id'], $request['iso_week'] );
        return is_wp_error( $result ) ? $result : $this->success( $result );
    }

    public function trigger_weekly_digest( WP_REST_Request $request ): WP_REST_Response|WP_Error {
        $ctx = $this->require_child_context( $request );
        if ( is_wp_error( $ctx ) ) return $ctx;

        $result = Noey_Insight_Service::generate_weekly_digest( $ctx['child_id'], $request['iso_week'] );
        return is_wp_error( $result ) ? $result : $this->success( $result );
    }
}
