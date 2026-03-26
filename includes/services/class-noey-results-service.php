<?php
/**
 * Noey_Results_Service — Exam results persistence and analytics.
 *
 * Handles:
 *  - Saving session + answers + topic breakdown
 *  - Updating child summary meta
 *  - Querying history, single session, and aggregate stats
 *
 * @package NoeyAPI
 */

defined( 'ABSPATH' ) || exit;

class Noey_Results_Service {

    // ── Save ──────────────────────────────────────────────────────────────────

    /**
     * Persist a completed exam submission.
     *
     * @param  array $session  Raw session row from wp_noey_exam_sessions.
     * @param  array $answers  Array of answer objects from client.
     * @return array|WP_Error  Session summary.
     */
    public static function save_submission( array $session, array $answers ): array|WP_Error {
        global $wpdb;

        Noey_Debug::log( 'results.save', 'Saving exam submission', [
            'session_id' => $session['session_id'],
            'child_id'   => $session['child_id'],
            'answers'    => count( $answers ),
        ], (int) $session['child_id'], 'info' );

        // ── Score calculation ─────────────────────────────────────────────────
        $total      = count( $answers );
        $correct    = 0;
        $time_taken = 0;

        foreach ( $answers as $ans ) {
            if ( ! empty( $ans['is_correct'] ) ) {
                $correct++;
            }
            $time_taken += (int) ( $ans['time_taken_seconds'] ?? 0 );
        }

        $percentage = $total > 0 ? round( ( $correct / $total ) * 100, 2 ) : 0;

        // ── Update session state ──────────────────────────────────────────────
        $wpdb->update(
            $wpdb->prefix . 'noey_exam_sessions',
            [
                'state'              => 'completed',
                'score'              => $correct,
                'total'              => $total,
                'percentage'         => $percentage,
                'time_taken_seconds' => $time_taken,
                'completed_at'       => current_time( 'mysql', true ),
            ],
            [ 'session_id' => $session['session_id'] ],
            [ '%s', '%d', '%d', '%f', '%d', '%s' ],
            [ '%d' ]
        );

        // ── Insert answers ────────────────────────────────────────────────────
        foreach ( $answers as $ans ) {
            $cognitive = self::normalise_cognitive( $ans['cognitive_level'] ?? '' );
            $wpdb->insert(
                $wpdb->prefix . 'noey_exam_answers',
                [
                    'session_id'         => $session['session_id'],
                    'child_id'           => $session['child_id'],
                    'question_id'        => sanitize_text_field( $ans['question_id'] ?? '' ),
                    'topic'              => sanitize_text_field( $ans['topic'] ?? '' ),
                    'subtopic'           => sanitize_text_field( $ans['subtopic'] ?? '' ) ?: null,
                    'cognitive_level'    => $cognitive,
                    'selected_answer'    => sanitize_text_field( $ans['selected_answer'] ?? '' ) ?: null,
                    'correct_answer'     => sanitize_text_field( $ans['correct_answer'] ?? '' ),
                    'is_correct'         => ! empty( $ans['is_correct'] ) ? 1 : 0,
                    'time_taken_seconds' => (int) ( $ans['time_taken_seconds'] ?? 0 ) ?: null,
                ],
                [ '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d' ]
            );
        }

        // ── Topic breakdown ───────────────────────────────────────────────────
        $breakdown = self::calculate_topic_breakdown( (int) $session['session_id'], (int) $session['child_id'], $answers );
        self::save_topic_breakdown( (int) $session['session_id'], (int) $session['child_id'], $breakdown );

        // ── Update child summary meta ─────────────────────────────────────────
        self::update_child_summary( (int) $session['child_id'] );

        Noey_Debug::log( 'results.save', 'Results saved successfully', [
            'session_id' => $session['session_id'],
            'score'      => "{$correct}/{$total}",
            'percentage' => $percentage,
            'topics'     => count( $breakdown ),
        ], (int) $session['child_id'], 'info' );

        return [
            'session_id'         => (int) $session['session_id'],
            'external_session_id' => $session['external_session_id'],
            'score'              => $correct,
            'total'              => $total,
            'percentage'         => $percentage,
            'time_taken_seconds' => $time_taken,
            'topic_breakdown'    => $breakdown,
            'completed_at'       => current_time( 'mysql', true ),
        ];
    }

    // ── Query ─────────────────────────────────────────────────────────────────

    /**
     * List completed sessions for a child (paginated).
     */
    public static function get_sessions( int $child_id, int $page = 1, int $per_page = 20 ): array {
        global $wpdb;

        $offset = ( $page - 1 ) * $per_page;

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT session_id, external_session_id, subject, standard, term, difficulty,
                        score, total, percentage, time_taken_seconds, started_at, completed_at
                 FROM {$wpdb->prefix}noey_exam_sessions
                 WHERE child_id = %d AND state = 'completed'
                 ORDER BY completed_at DESC
                 LIMIT %d OFFSET %d",
                $child_id,
                $per_page,
                $offset
            ),
            ARRAY_A
        ) ?: [];

        $total = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->prefix}noey_exam_sessions WHERE child_id = %d AND state = 'completed'",
                $child_id
            )
        );

        Noey_Debug::log( 'results.sessions', 'Session history fetched', [
            'child_id' => $child_id,
            'page'     => $page,
            'total'    => $total,
        ], $child_id, 'debug' );

        return [
            'sessions'   => array_map( [ __CLASS__, 'format_session' ], $rows ),
            'total'      => $total,
            'page'       => $page,
            'per_page'   => $per_page,
            'total_pages' => (int) ceil( $total / $per_page ),
        ];
    }

    /**
     * Get a single session with full answer detail.
     */
    public static function get_session_detail( int $session_id, int $child_id ): array|WP_Error {
        global $wpdb;

        $session = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}noey_exam_sessions WHERE session_id = %d AND child_id = %d",
                $session_id,
                $child_id
            ),
            ARRAY_A
        );

        if ( ! $session ) {
            return new WP_Error( 'noey_not_found', 'Session not found.', [ 'status' => 404 ] );
        }

        $answers = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}noey_exam_answers WHERE session_id = %d ORDER BY answer_id ASC",
                $session_id
            ),
            ARRAY_A
        ) ?: [];

        $breakdown = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}noey_topic_breakdown WHERE session_id = %d ORDER BY pct DESC",
                $session_id
            ),
            ARRAY_A
        ) ?: [];

        Noey_Debug::log( 'results.detail', 'Session detail fetched', [
            'session_id' => $session_id,
            'child_id'   => $child_id,
        ], $child_id, 'debug' );

        return [
            'session'         => self::format_session( $session ),
            'answers'         => $answers,
            'topic_breakdown' => $breakdown,
        ];
    }

    /**
     * Aggregate stats for a child.
     */
    public static function get_stats( int $child_id ): array {
        global $wpdb;

        $totals = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT COUNT(*) as exams_completed,
                        AVG(percentage) as average_pct,
                        SUM(time_taken_seconds) as total_time_seconds
                 FROM {$wpdb->prefix}noey_exam_sessions
                 WHERE child_id = %d AND state = 'completed'",
                $child_id
            ),
            ARRAY_A
        );

        // Topic performance aggregated across all sessions
        $topics = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT topic,
                        SUM(correct) as total_correct,
                        SUM(total)   as total_questions,
                        ROUND((SUM(correct) / SUM(total)) * 100, 1) as overall_pct
                 FROM {$wpdb->prefix}noey_topic_breakdown
                 WHERE child_id = %d
                 GROUP BY topic
                 ORDER BY overall_pct DESC",
                $child_id
            ),
            ARRAY_A
        ) ?: [];

        $strongest = ! empty( $topics ) ? $topics[0]['topic'] : null;
        $weakest   = ! empty( $topics ) ? $topics[ count( $topics ) - 1 ]['topic'] : null;

        Noey_Debug::log( 'results.stats', 'Stats fetched', [
            'child_id'         => $child_id,
            'exams_completed'  => $totals['exams_completed'] ?? 0,
        ], $child_id, 'debug' );

        return [
            'exams_completed'    => (int) ( $totals['exams_completed'] ?? 0 ),
            'average_pct'        => round( (float) ( $totals['average_pct'] ?? 0 ), 1 ),
            'total_time_seconds' => (int) ( $totals['total_time_seconds'] ?? 0 ),
            'strongest_topic'    => $strongest,
            'weakest_topic'      => $weakest,
            'topics'             => $topics,
        ];
    }

    // ── Private Helpers ───────────────────────────────────────────────────────

    private static function calculate_topic_breakdown( int $session_id, int $child_id, array $answers ): array {
        $topics = [];

        foreach ( $answers as $ans ) {
            $topic = sanitize_text_field( $ans['topic'] ?? 'Unknown' );
            if ( ! isset( $topics[ $topic ] ) ) {
                $topics[ $topic ] = [ 'correct' => 0, 'total' => 0 ];
            }
            $topics[ $topic ]['total']++;
            if ( ! empty( $ans['is_correct'] ) ) {
                $topics[ $topic ]['correct']++;
            }
        }

        $breakdown = [];
        foreach ( $topics as $topic => $counts ) {
            $pct         = $counts['total'] > 0 ? round( ( $counts['correct'] / $counts['total'] ) * 100, 1 ) : 0;
            $breakdown[] = [
                'topic'   => $topic,
                'correct' => $counts['correct'],
                'total'   => $counts['total'],
                'pct'     => $pct,
            ];
        }

        return $breakdown;
    }

    private static function save_topic_breakdown( int $session_id, int $child_id, array $breakdown ): void {
        global $wpdb;

        foreach ( $breakdown as $item ) {
            $wpdb->insert(
                $wpdb->prefix . 'noey_topic_breakdown',
                [
                    'session_id' => $session_id,
                    'child_id'   => $child_id,
                    'topic'      => $item['topic'],
                    'correct'    => $item['correct'],
                    'total'      => $item['total'],
                    'pct'        => $item['pct'],
                ],
                [ '%d', '%d', '%s', '%d', '%d', '%f' ]
            );
        }
    }

    private static function update_child_summary( int $child_id ): void {
        global $wpdb;

        $stats = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT COUNT(*) as total,
                        AVG(percentage) as avg_pct,
                        MAX(completed_at) as last_at
                 FROM {$wpdb->prefix}noey_exam_sessions
                 WHERE child_id = %d AND state = 'completed'",
                $child_id
            ),
            ARRAY_A
        );

        update_user_meta( $child_id, 'noey_total_exams', (int) $stats['total'] );
        update_user_meta( $child_id, 'noey_average_score_pct', round( (float) $stats['avg_pct'], 1 ) );
        update_user_meta( $child_id, 'noey_last_exam_at', $stats['last_at'] );
    }

    private static function normalise_cognitive( string $level ): string {
        return match ( strtolower( $level ) ) {
            'knowledge', 'recall'                             => 'recall',
            'comprehension', 'application', 'apply'          => 'application',
            'analysis', 'analyse', 'analyze', 'synthesis',
            'evaluation', 'evaluate'                         => 'analysis',
            default                                          => 'recall',
        };
    }

    private static function format_session( array $row ): array {
        return [
            'session_id'          => (int) $row['session_id'],
            'external_session_id' => $row['external_session_id'],
            'subject'             => $row['subject'],
            'standard'            => $row['standard'],
            'term'                => $row['term'],
            'difficulty'          => $row['difficulty'],
            'score'               => (int) $row['score'],
            'total'               => (int) $row['total'],
            'percentage'          => (float) $row['percentage'],
            'time_taken_seconds'  => (int) $row['time_taken_seconds'],
            'started_at'          => $row['started_at'],
            'completed_at'        => $row['completed_at'],
        ];
    }
}
