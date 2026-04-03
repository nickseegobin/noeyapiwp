<?php
/**
 * Noey_Leaderboard_Service — Leaderboard business logic.
 *
 * v2.1 fix:
 *  - handle_submit_upsert() now normalises subject slug before sending
 *    to Railway. Prevents "Mathematics" being stored instead of "math"
 *    when the session subject comes from the React frontend display name.
 *
 * v2.0:
 *  - Board key: standard + term + subject (difficulty removed)
 *  - Points ACCUMULATE across the day — every exam adds to running total
 *  - Daily reset via cron at 04:00 UTC (Trinidad midnight)
 *  - Nickname generation deferred — no longer fires on create_child()
 *
 * @package NoeyAPI
 */

defined( 'ABSPATH' ) || exit;

class Noey_Leaderboard_Service {

    // ── Board Fetch ───────────────────────────────────────────────────────────

    /**
     * Fetch today's top 10 for a subject board.
     *
     * @param string   $standard  e.g. 'std_4'
     * @param string   $term      e.g. 'term_1' — pass 'none' for std_5
     * @param string   $subject   e.g. 'math'
     * @param int|null $child_id  If provided, Railway flags is_current_user + my_position.
     * @return array|WP_Error
     */
    public static function get_board(
        string $standard,
        string $term,
        string $subject,
        ?int   $child_id = null
    ): array|WP_Error {
        Noey_Debug::log( 'leaderboard.fetch', 'Fetching board from Railway', [
            'standard' => $standard,
            'term'     => $term,
            'subject'  => $subject,
            'child_id' => $child_id,
        ], $child_id, 'info' );

        $term_segment = ( ! $term || $term === 'none' ) ? 'none' : $term;
        $path         = "/leaderboard/{$standard}/{$term_segment}/{$subject}";
        $response     = self::railway_get( $path, [], $child_id );

        if ( is_wp_error( $response ) ) {
            Noey_Debug::log( 'leaderboard.fetch', 'Board fetch failed', [
                'path'  => $path,
                'error' => $response->get_error_message(),
            ], $child_id, 'error' );
            return $response;
        }

        Noey_Debug::log( 'leaderboard.fetch', 'Board fetched successfully', [
            'board_key'          => $response['board_key'] ?? 'unknown',
            'total_participants' => $response['total_participants'] ?? 0,
        ], $child_id, 'debug' );

        return $response;
    }

    // ── Personal Boards ───────────────────────────────────────────────────────

    /**
     * Fetch all subject boards the child appears on today.
     *
     * @param  int $child_id
     * @return array|WP_Error
     */
    public static function get_my_boards( int $child_id ): array|WP_Error {
        Noey_Debug::log( 'leaderboard.fetch', 'Fetching personal boards', [
            'child_id' => $child_id,
        ], $child_id, 'info' );

        $response = self::railway_get( "/leaderboard/me/{$child_id}" );

        if ( is_wp_error( $response ) ) {
            Noey_Debug::log( 'leaderboard.fetch', 'Personal boards fetch failed', [
                'child_id' => $child_id,
                'error'    => $response->get_error_message(),
            ], $child_id, 'error' );
            return $response;
        }

        Noey_Debug::log( 'leaderboard.fetch', 'Personal boards fetched', [
            'child_id'    => $child_id,
            'board_count' => count( $response['boards'] ?? [] ),
        ], $child_id, 'debug' );

        return $response;
    }

    // ── Nickname Generation ───────────────────────────────────────────────────

    /**
     * Generate a child-safe Caribbean nickname.
     *
     * NOTE: No longer called automatically on create_child().
     * Called explicitly from the signup flow or admin panel.
     *
     * @param  int    $child_id
     * @param  string $standard
     * @param  string $term
     * @return string|WP_Error
     */
    public static function generate_nickname(
        int    $child_id,
        string $standard,
        string $term = ''
    ): string|WP_Error {
        Noey_Debug::log( 'leaderboard.nickname_generate', 'Requesting nickname generation', [
            'child_id' => $child_id,
            'standard' => $standard,
            'term'     => $term,
        ], $child_id, 'info' );

        $response = self::railway_post( '/leaderboard/generate-nickname', [
            'user_id'  => (string) $child_id,
            'standard' => $standard,
            'term'     => $term ?: null,
        ] );

        if ( is_wp_error( $response ) ) {
            Noey_Debug::log( 'leaderboard.nickname_generate', 'Nickname generation failed', [
                'child_id' => $child_id,
                'error'    => $response->get_error_message(),
            ], $child_id, 'warning' );
            return $response;
        }

        $nickname = $response['nickname'] ?? '';

        if ( ! $nickname ) {
            Noey_Debug::log( 'leaderboard.nickname_generate', 'Railway returned empty nickname', [
                'child_id' => $child_id,
            ], $child_id, 'warning' );
            return new WP_Error( 'noey_nickname_empty', 'Nickname generation returned empty.', [ 'status' => 500 ] );
        }

        update_user_meta( $child_id, 'noey_nickname', $nickname );
        delete_user_meta( $child_id, 'noey_nickname_pending' );

        Noey_Debug::log( 'leaderboard.nickname_generate', 'Nickname generated and saved', [
            'child_id' => $child_id,
            'nickname' => $nickname,
            'is_new'   => $response['is_new'] ?? true,
        ], $child_id, 'info' );

        return $nickname;
    }

    /**
     * Regenerate a child's nickname. Called from admin panel.
     *
     * @param  int    $child_id
     * @param  int    $admin_id
     * @param  string $reason  inappropriate | request | system
     * @return array|WP_Error
     */
    public static function regenerate_nickname(
        int    $child_id,
        int    $admin_id,
        string $reason = 'request'
    ): array|WP_Error {
        $old_nickname = get_user_meta( $child_id, 'noey_nickname', true ) ?: 'unknown';

        Noey_Debug::log( 'leaderboard.admin', 'Nickname regeneration requested', [
            'child_id'     => $child_id,
            'admin_id'     => $admin_id,
            'old_nickname' => $old_nickname,
            'reason'       => $reason,
        ], $admin_id, 'info' );

        $response = self::railway_post( '/leaderboard/regenerate-nickname', [
            'user_id'  => (string) $child_id,
            'admin_id' => (string) $admin_id,
            'reason'   => $reason,
        ] );

        if ( is_wp_error( $response ) ) {
            Noey_Debug::log( 'leaderboard.admin', 'Nickname regeneration failed', [
                'child_id' => $child_id,
                'error'    => $response->get_error_message(),
            ], $admin_id, 'error' );
            return $response;
        }

        $new_nickname = $response['new_nickname'] ?? '';
        if ( $new_nickname ) {
            update_user_meta( $child_id, 'noey_nickname', $new_nickname );
        }

        Noey_Debug::log( 'leaderboard.admin', 'Nickname regenerated successfully', [
            'child_id'     => $child_id,
            'admin_id'     => $admin_id,
            'old_nickname' => $old_nickname,
            'new_nickname' => $new_nickname,
            'reason'       => $reason,
        ], $admin_id, 'info' );

        return $response;
    }

    // ── Post-Submit Upsert ────────────────────────────────────────────────────

    /**
     * Accumulate leaderboard points after an exam submission.
     *
     * v2.1 fix: subject is normalised to Railway slug format before sending.
     * The session stores whatever React sent (may be display name e.g. "Mathematics").
     * Railway expects slugs (e.g. "math"). Noey_Exam_Service::normalise_subject()
     * handles all known mappings.
     *
     * @param  array $session  Row from noey_exam_sessions.
     * @param  array $result   Scored result { score, total, percentage }.
     * @return array|null      leaderboard_update block or null on failure.
     */
    public static function handle_submit_upsert( array $session, array $result ): ?array {
        $child_id = (int) ( $session['child_id'] ?? 0 );

        try {
            $difficulty = $session['difficulty'] ?? '';
            $correct    = (int) ( $result['score'] ?? 0 );
            $bonus      = self::difficulty_bonus( $difficulty );
            $points     = $correct + $bonus;

            // ── FIX v2.1: normalise subject to Railway slug format ──────────
            // The session stores the subject as received from React (may be
            // a display name like "Mathematics"). Railway expects the slug
            // ("math"). Normalise here so the board key is always correct.
            $subject_raw        = $session['subject'] ?? '';
            $subject_normalised = Noey_Exam_Service::normalise_subject( $subject_raw );
            // ── END FIX ─────────────────────────────────────────────────────

            Noey_Debug::log( 'leaderboard.upsert', 'Accumulating leaderboard points', [
                'child_id'          => $child_id,
                'standard'          => $session['standard'] ?? '',
                'term'              => $session['term'] ?? '',
                'subject_raw'       => $subject_raw,
                'subject_normalised' => $subject_normalised,
                'difficulty'        => $difficulty,
                'correct'           => $correct,
                'bonus'             => $bonus,
                'points'            => $points,
            ], $child_id, 'info' );

            $response = self::railway_post( '/leaderboard/upsert', [
                'user_id'         => (string) $child_id,
                'nickname'        => get_user_meta( $child_id, 'noey_nickname', true ) ?: 'Player' . $child_id,  // ← ADD THIS
                'standard'        => $session['standard'] ?? '',
                'term'            => $session['term'] ?: 'none',
                'subject'         => $subject_normalised,  // always a slug
                'difficulty'      => $difficulty,
                'score_pct'       => (int) ( $result['percentage'] ?? 0 ),
                'points'          => $points,              // pre-calculated by WP
                'correct_count'   => $correct,
                'total_questions' => (int) ( $result['total'] ?? 0 ),
                'session_id'      => $session['external_session_id'] ?? '',
                'accumulate'      => true,
            ] );

            if ( is_wp_error( $response ) ) {
                Noey_Debug::log( 'leaderboard.upsert_failed', 'Leaderboard upsert failed', [
                    'child_id' => $child_id,
                    'error'    => $response->get_error_message(),
                ], $child_id, 'error' );
                return null;
            }

            Noey_Debug::log( 'leaderboard.upsert', 'Points accumulated successfully', [
                'child_id'     => $child_id,
                'points_added' => $points,
                'total_today'  => $response['total_points_today'] ?? null,
                'new_rank'     => $response['new_rank'] ?? null,
            ], $child_id, 'debug' );

            return [
                'points_earned'      => $points,
                'total_points_today' => $response['total_points_today'] ?? null,
                'board_key'          => $response['board_key']          ?? null,
                'new_rank'           => $response['new_rank']           ?? null,
                'previous_rank'      => $response['previous_rank']      ?? null,
            ];

        } catch ( \Throwable $e ) {
            Noey_Debug::log( 'leaderboard.upsert_failed', 'Leaderboard upsert threw exception', [
                'child_id' => $child_id,
                'message'  => $e->getMessage(),
                'line'     => $e->getLine(),
            ], $child_id, 'error' );
            return null;
        }
    }

    // ── Daily Reset ───────────────────────────────────────────────────────────

    /**
     * Trigger a full daily leaderboard reset.
     * Called by Noey_Cron at 04:00 UTC (Trinidad midnight).
     *
     * @param  int $admin_id  0 = cron/system trigger.
     * @return array|WP_Error
     */
    public static function trigger_daily_reset( int $admin_id = 0 ): array|WP_Error {
        Noey_Debug::log( 'leaderboard.reset', 'Daily leaderboard reset triggered', [
            'admin_id' => $admin_id,
            'trigger'  => $admin_id > 0 ? 'manual' : 'cron',
            'utc_time' => current_time( 'mysql', true ),
        ], $admin_id ?: null, 'info' );

        $response = self::railway_post( '/leaderboard/reset', [
            'admin_id' => (string) $admin_id,
            'trigger'  => $admin_id > 0 ? 'manual' : 'cron',
        ] );

        if ( is_wp_error( $response ) ) {
            Noey_Debug::log( 'leaderboard.reset', 'Daily reset failed', [
                'error' => $response->get_error_message(),
            ], null, 'error' );
            return $response;
        }

        Noey_Debug::log( 'leaderboard.reset', 'Daily reset completed', [
            'entries_cleared' => $response['entries_cleared'] ?? 0,
            'boards_cleared'  => $response['boards_cleared']  ?? 0,
        ], null, 'info' );

        return $response;
    }

    // ── Testing Helpers ───────────────────────────────────────────────────────

    /**
     * Inject a fake leaderboard entry for admin testing.
     *
     * @param  array $data { nickname, standard, term, subject, points, score_pct }
     * @return array|WP_Error
     */
    public static function inject_test_entry( array $data ): array|WP_Error {
        Noey_Debug::log( 'leaderboard.test', 'Injecting test entry', $data, null, 'info' );

        $response = self::railway_post( '/leaderboard/test/inject', $data );

        if ( is_wp_error( $response ) ) {
            Noey_Debug::log( 'leaderboard.test', 'Test entry injection failed', [
                'error' => $response->get_error_message(),
            ], null, 'error' );
        }

        return $response;
    }

    /**
     * Reset a specific board for testing.
     *
     * @param  string $standard
     * @param  string $term
     * @param  string $subject
     * @return array|WP_Error
     */
    public static function reset_board( string $standard, string $term, string $subject ): array|WP_Error {
        Noey_Debug::log( 'leaderboard.test', 'Resetting specific board', [
            'standard' => $standard,
            'term'     => $term,
            'subject'  => $subject,
        ], null, 'info' );

        return self::railway_post( '/leaderboard/test/reset-board', [
            'standard' => $standard,
            'term'     => $term ?: null,
            'subject'  => $subject,
        ] );
    }

    // ── Points Helpers ────────────────────────────────────────────────────────

    public static function difficulty_bonus( string $difficulty ): int {
        return match ( strtolower( $difficulty ) ) {
            'medium' => 1,
            'hard'   => 2,
            default  => 0,
        };
    }

    public static function calculate_points( int $correct_count, string $difficulty ): int {
        return $correct_count + self::difficulty_bonus( $difficulty );
    }

    // ── Railway HTTP Helpers ──────────────────────────────────────────────────

    private static function railway_get( string $path, array $params = [], ?int $child_id = null ): array|WP_Error {
        $endpoint = rtrim( get_option( 'noey_railway_endpoint', '' ), '/' );
        $api_key  = get_option( 'noey_railway_api_key', '' );

        if ( ! $endpoint ) {
            return new WP_Error( 'noey_railway_not_configured', 'Railway endpoint not configured.', [ 'status' => 503 ] );
        }

        $url = $endpoint . $path;
        if ( ! empty( $params ) ) {
            $url .= '?' . http_build_query( $params );
        }

        $response = wp_remote_get( $url, [
            'timeout' => 10,
            'headers' => [
                'Authorization' => 'Bearer ' . $api_key,
                'Content-Type'  => 'application/json',
            ],
        ] );

        return self::parse_railway_response( $response, $path );
    }

    private static function railway_post( string $path, array $body ): array|WP_Error {
        $endpoint   = rtrim( get_option( 'noey_railway_endpoint', '' ), '/' );
        $api_key    = get_option( 'noey_railway_api_key', '' );
        $server_key = get_option( 'noey_railway_server_key', '' );

        if ( ! $endpoint ) {
            return new WP_Error( 'noey_railway_not_configured', 'Railway endpoint not configured.', [ 'status' => 503 ] );
        }

        $headers = [
            'Authorization' => 'Bearer ' . $api_key,
            'Content-Type'  => 'application/json',
        ];

        if ( $server_key ) {
            $headers['X-AEP-Server-Key'] = $server_key;
        }

        $response = wp_remote_post( $endpoint . $path, [
            'timeout' => 15,
            'headers' => $headers,
            'body'    => wp_json_encode( $body ),
        ] );

        return self::parse_railway_response( $response, $path );
    }

    private static function parse_railway_response( mixed $response, string $path ): array|WP_Error {
        if ( is_wp_error( $response ) ) {
            Noey_Debug::log( 'leaderboard.railway', 'Railway HTTP error', [
                'path'  => $path,
                'error' => $response->get_error_message(),
            ], null, 'error' );
            return new WP_Error( 'noey_railway_error', 'Failed to connect to leaderboard service.', [ 'status' => 503 ] );
        }

        $code = wp_remote_retrieve_response_code( $response );
        $body = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( $code < 200 || $code >= 300 ) {
            Noey_Debug::log( 'leaderboard.railway', 'Railway bad response', [
                'path'      => $path,
                'http_code' => $code,
                'body'      => $body,
            ], null, 'error' );
            $message = $body['error'] ?? 'Leaderboard service returned an error.';
            return new WP_Error( 'noey_railway_error', $message, [ 'status' => $code ] );
        }

        return $body ?? [];
    }
}