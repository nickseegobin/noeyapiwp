<?php
/**
 * Noey_Exam_Service — Exam pool management and Railway AI integration.
 *
 * Flow:
 *  1. start() — check tokens → query pool (excluding seen packages) → Railway fallback → deduct token
 *  2. checkpoint() — persist mid-exam state to user meta
 *  3. submit() — pass results to Noey_Results_Service
 *
 * @package NoeyAPI
 */

defined( 'ABSPATH' ) || exit;

class Noey_Exam_Service {

    // ── Start Exam ────────────────────────────────────────────────────────────

    /**
     * Serve an exam package and deduct a token atomically.
     *
     * @param int    $parent_id   Billing account.
     * @param int    $child_id    Learner.
     * @param string $standard    e.g. 'std_4'
     * @param string $term        e.g. 'term_1'
     * @param string $subject     e.g. 'Mathematics'
     * @param string $difficulty  easy | medium | hard
     * @return array|WP_Error  { session_id, package, balance_after }
     */
    public static function start(
        int    $parent_id,
        int    $child_id,
        string $standard,
        string $term,
        string $subject,
        string $difficulty = 'medium'
    ): array|WP_Error {
        Noey_Debug::log( 'exam.start', 'Exam start requested', [
            'parent_id' => $parent_id,
            'child_id'  => $child_id,
            'standard'  => $standard,
            'term'      => $term,
            'subject'   => $subject,
            'difficulty' => $difficulty,
        ], $parent_id, 'info' );

        // ── 1. Pre-check token balance ────────────────────────────────────────
        if ( ! Noey_Token_Service::has_enough( $parent_id ) ) {
            Noey_Debug::log( 'exam.start', 'Token pre-check failed', [
                'parent_id' => $parent_id,
                'balance'   => Noey_Token_Service::get_balance( $parent_id ),
            ], $parent_id, 'warning' );
            return new WP_Error( 'noey_insufficient_tokens', 'No tokens available. Please purchase more to continue.', [
                'status'  => 402,
                'balance' => Noey_Token_Service::get_balance( $parent_id ),
            ] );
        }

        // ── 2. Get seen package IDs for this child ────────────────────────────
        $seen_ids = self::get_seen_package_ids( $child_id );
        Noey_Debug::log( 'exam.start', 'Seen package IDs fetched', [
            'child_id' => $child_id,
            'count'    => count( $seen_ids ),
        ], $child_id, 'debug' );

        // ── 3. Query pool ─────────────────────────────────────────────────────
        $package = self::serve_from_pool( $standard, $term, $subject, $difficulty, $seen_ids );

        if ( ! $package ) {
            Noey_Debug::log( 'exam.start', 'Pool miss — falling back to Railway', [
                'standard'  => $standard,
                'term'      => $term,
                'subject'   => $subject,
                'difficulty' => $difficulty,
            ], $parent_id, 'info' );

            $source = get_option( 'noey_content_source', 'pool_only' );
            if ( $source === 'pool_only' ) {
                return new WP_Error( 'noey_no_exam_available', 'No exam available for this selection right now. Please try a different subject or difficulty.', [ 'status' => 404 ] );
            }

            $package = self::fetch_from_railway( $standard, $term, $subject, $difficulty, $seen_ids );
            if ( is_wp_error( $package ) ) {
                return $package;
            }

            // Store in pool for future use
            self::store_in_pool( $package, $standard, $term, $subject, $difficulty );
        }

        // ── 4. Generate session ID ────────────────────────────────────────────
        $external_session_id = self::generate_session_id();

        // ── 5. Deduct token ───────────────────────────────────────────────────
        $deduction = Noey_Token_Service::deduct( $parent_id, 1, $external_session_id, "Exam started: {$subject}" );
        if ( is_wp_error( $deduction ) ) {
            return $deduction;
        }

        // ── 6. Create active session record ───────────────────────────────────
        global $wpdb;
        $wpdb->insert(
            $wpdb->prefix . 'noey_exam_sessions',
            [
                'external_session_id' => $external_session_id,
                'child_id'            => $child_id,
                'parent_id'           => $parent_id,
                'package_id'          => $package['package_id'],
                'subject'             => $subject,
                'standard'            => $standard,
                'term'                => $term,
                'difficulty'          => $difficulty,
                'state'               => 'active',
                'started_at'          => current_time( 'mysql', true ),
            ],
            [ '%s', '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s' ]
        );

        $session_id = $wpdb->insert_id;

        // Mark pool package as served
        self::mark_served( $package['package_id'] );

        Noey_Debug::log( 'exam.start', 'Exam session created', [
            'session_id'          => $session_id,
            'external_session_id' => $external_session_id,
            'package_id'          => $package['package_id'],
            'balance_after'       => $deduction['balance_after'],
        ], $parent_id, 'info' );

        // Strip answer sheet from response (security — answers stay server-side or come from Railway with key)
        $safe_package = $package;
        unset( $safe_package['answer_sheet'], $safe_package['answers'] );

        return [
            'session_id'          => $session_id,
            'external_session_id' => $external_session_id,
            'package'             => $safe_package,
            'balance_after'       => $deduction['balance_after'],
        ];
    }

    // ── Checkpoint ────────────────────────────────────────────────────────────

    /**
     * Save mid-exam progress to child user meta.
     */
    public static function checkpoint( int $session_id, int $child_id, array $state ): true|WP_Error {
        // Verify session ownership
        global $wpdb;
        $session = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}noey_exam_sessions WHERE session_id = %d AND child_id = %d AND state = 'active'",
                $session_id,
                $child_id
            ),
            ARRAY_A
        );

        if ( ! $session ) {
            return new WP_Error( 'noey_session_not_found', 'Active session not found.', [ 'status' => 404 ] );
        }

        update_user_meta( $child_id, 'noey_checkpoint', wp_json_encode( [
            'session_id' => $session_id,
            'state'      => $state,
            'saved_at'   => current_time( 'mysql', true ),
        ] ) );

        Noey_Debug::log( 'exam.checkpoint', 'Checkpoint saved', [
            'session_id' => $session_id,
            'child_id'   => $child_id,
        ], $child_id, 'debug' );

        return true;
    }

    // ── Submit ────────────────────────────────────────────────────────────────

    /**
     * Submit a completed exam. Delegates storage to Noey_Results_Service.
     *
     * @param  int   $session_id
     * @param  int   $child_id
     * @param  array $answers     Raw answer payload from client.
     * @return array|WP_Error     Session summary.
     */
    public static function submit( int $session_id, int $child_id, array $answers ): array|WP_Error {
        Noey_Debug::log( 'exam.submit', 'Exam submission received', [
            'session_id' => $session_id,
            'child_id'   => $child_id,
            'answers'    => count( $answers ),
        ], $child_id, 'info' );

        // Verify session
        global $wpdb;
        $session = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}noey_exam_sessions WHERE session_id = %d AND child_id = %d AND state = 'active'",
                $session_id,
                $child_id
            ),
            ARRAY_A
        );

        if ( ! $session ) {
            Noey_Debug::log( 'exam.submit', 'Session not found or not active', [
                'session_id' => $session_id,
                'child_id'   => $child_id,
            ], $child_id, 'warning' );
            return new WP_Error( 'noey_session_not_found', 'Active exam session not found.', [ 'status' => 404 ] );
        }

        // Store results
        $result = Noey_Results_Service::save_submission( $session, $answers );
        if ( is_wp_error( $result ) ) {
            return $result;
        }

        // Clear checkpoint
        delete_user_meta( $child_id, 'noey_checkpoint' );

        Noey_Debug::log( 'exam.submit', 'Exam submitted and results saved', [
            'session_id' => $session_id,
            'score'      => $result['percentage'],
        ], $child_id, 'info' );

        return $result;
    }

    // ── Catalogue ─────────────────────────────────────────────────────────────

    /**
     * Return distinct exam types in the pool with availability counts.
     */
    public static function get_catalogue( array $filters = [] ): array {
        global $wpdb;

        $where  = [ '1=1' ];
        $values = [];

        if ( ! empty( $filters['standard'] ) ) {
            $where[]  = 'standard = %s';
            $values[] = $filters['standard'];
        }
        if ( ! empty( $filters['term'] ) ) {
            $where[]  = 'term = %s';
            $values[] = $filters['term'];
        }
        if ( ! empty( $filters['subject'] ) ) {
            $where[]  = 'subject LIKE %s';
            $values[] = '%' . $wpdb->esc_like( $filters['subject'] ) . '%';
        }
        if ( ! empty( $filters['difficulty'] ) ) {
            $where[]  = 'difficulty = %s';
            $values[] = $filters['difficulty'];
        }

        $sql = "SELECT standard, term, subject, difficulty, COUNT(*) as pool_count
                FROM {$wpdb->prefix}noey_exam_pool
                WHERE " . implode( ' AND ', $where ) . "
                GROUP BY standard, term, subject, difficulty
                ORDER BY subject, difficulty";

        $rows = empty( $values )
            ? $wpdb->get_results( $sql, ARRAY_A )
            : $wpdb->get_results( $wpdb->prepare( $sql, ...$values ), ARRAY_A );

        Noey_Debug::log( 'exam.catalogue', 'Catalogue fetched', [
            'filters' => $filters,
            'count'   => count( $rows ?: [] ),
        ], null, 'debug' );

        return $rows ?: [];
    }

    // ── Pool Management ───────────────────────────────────────────────────────

    private static function serve_from_pool( string $standard, string $term, string $subject, string $difficulty, array $exclude_ids ): ?array {
        global $wpdb;

        $exclude_clause = '';
        if ( ! empty( $exclude_ids ) ) {
            $placeholders   = implode( ',', array_fill( 0, count( $exclude_ids ), '%s' ) );
            $exclude_clause = "AND package_id NOT IN ({$placeholders})";
        }

        $sql  = "SELECT * FROM {$wpdb->prefix}noey_exam_pool
                 WHERE standard = %s AND term = %s AND subject = %s AND difficulty = %s
                 {$exclude_clause}
                 ORDER BY times_served ASC, RAND()
                 LIMIT 1";

        $args = [ $standard, $term, $subject, $difficulty ];
        if ( ! empty( $exclude_ids ) ) {
            $args = array_merge( $args, $exclude_ids );
        }

        $row = $wpdb->get_row( $wpdb->prepare( $sql, ...$args ), ARRAY_A );
        if ( ! $row ) {
            return null;
        }

        $decoded = json_decode( $row['package_json'], true );
        if ( ! $decoded ) {
            return null;
        }

        return array_merge( $decoded, [ 'package_id' => $row['package_id'] ] );
    }

    private static function store_in_pool( array $package, string $standard, string $term, string $subject, string $difficulty ): void {
        global $wpdb;
        $wpdb->replace(
            $wpdb->prefix . 'noey_exam_pool',
            [
                'package_id'   => $package['package_id'],
                'standard'     => $standard,
                'term'         => $term,
                'subject'      => $subject,
                'difficulty'   => $difficulty,
                'package_json' => wp_json_encode( $package ),
                'created_at'   => current_time( 'mysql', true ),
            ],
            [ '%s', '%s', '%s', '%s', '%s', '%s', '%s' ]
        );
    }

    private static function mark_served( string $package_id ): void {
        global $wpdb;
        $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$wpdb->prefix}noey_exam_pool
                 SET times_served = times_served + 1, last_served_at = %s
                 WHERE package_id = %s",
                current_time( 'mysql', true ),
                $package_id
            )
        );
    }

    // ── Railway API ───────────────────────────────────────────────────────────

    public static function fetch_from_railway( string $standard, string $term, string $subject, string $difficulty, array $seen_ids = [] ): array|WP_Error {
        $endpoint   = rtrim( get_option( 'noey_railway_endpoint', '' ), '/' );
        $api_key    = get_option( 'noey_railway_api_key', '' );
        $server_key = get_option( 'noey_railway_server_key', '' );

        if ( ! $endpoint ) {
            return new WP_Error( 'noey_railway_not_configured', 'Railway endpoint not configured.', [ 'status' => 503 ] );
        }

        // Railway uses lowercase subject slugs
        $railway_subject = self::normalise_subject( $subject );

        Noey_Debug::log( 'exam.railway', 'Calling Railway generate-exam', [
            'standard'        => $standard,
            'term'            => $term,
            'subject'         => $railway_subject,
            'difficulty'      => $difficulty,
            'seen_ids_count'  => count( $seen_ids ),
        ], null, 'info' );

        $headers = [
            'Authorization' => "Bearer {$api_key}",
            'Content-Type'  => 'application/json',
        ];

        // Include server key to receive answer_sheet for server-side scoring
        if ( $server_key ) {
            $headers['X-AEP-Server-Key'] = $server_key;
        }

        $body_payload = [
            'standard'               => $standard,
            'term'                   => $term,
            'subject'                => $railway_subject,
            'difficulty'             => $difficulty,
            'completed_package_ids'  => $seen_ids,
        ];

        $response = wp_remote_post( "{$endpoint}/generate-exam", [
            'timeout' => 30,
            'headers' => $headers,
            'body'    => wp_json_encode( $body_payload ),
        ] );

        if ( is_wp_error( $response ) ) {
            Noey_Debug::log( 'exam.railway', 'Railway HTTP error', [
                'error' => $response->get_error_message(),
            ], null, 'error' );
            return new WP_Error( 'noey_railway_error', 'Failed to connect to exam generation service.', [ 'status' => 503 ] );
        }

        $code = wp_remote_retrieve_response_code( $response );
        $body = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( $code !== 200 || empty( $body ) ) {
            Noey_Debug::log( 'exam.railway', 'Railway bad response', [
                'http_code' => $code,
                'body'      => $body,
            ], null, 'error' );
            return new WP_Error( 'noey_railway_error', 'Exam generation service returned an error.', [ 'status' => 503 ] );
        }

        Noey_Debug::log( 'exam.railway', 'Railway package received', [
            'package_id' => $body['package_id'] ?? 'unknown',
            'source'     => $body['source'] ?? 'unknown',
            'has_answers' => isset( $body['answer_sheet'] ),
        ], null, 'info' );

        return $body;
    }

    /**
     * Normalise a display subject name to Railway's lowercase slug.
     *
     * Railway expects: math | english | science | social_studies
     */
    public static function normalise_subject( string $subject ): string {
        return match ( strtolower( trim( $subject ) ) ) {
            'mathematics', 'math'            => 'math',
            'english', 'english language arts',
            'english language', 'ela'        => 'english',
            'science'                        => 'science',
            'social studies', 'social_studies' => 'social_studies',
            default                          => strtolower( str_replace( ' ', '_', $subject ) ),
        };
    }

    // ── Utilities ─────────────────────────────────────────────────────────────

    public static function get_seen_package_ids( int $child_id ): array {
        global $wpdb;
        $rows = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT DISTINCT package_id FROM {$wpdb->prefix}noey_exam_sessions
                 WHERE child_id = %d AND state = 'completed'",
                $child_id
            )
        );
        return $rows ?: [];
    }

    // ── Active Session ────────────────────────────────────────────────────────

    /**
     * Return the most recent active session for a child, with checkpoint attached.
     *
     * @param  int $child_id
     * @return array|null  Session row + checkpoint, or null if none active.
     */
    public static function get_active_session( int $child_id ): ?array {
        global $wpdb;

        $session = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT session_id, external_session_id, subject, standard, term, difficulty, started_at
                 FROM {$wpdb->prefix}noey_exam_sessions
                 WHERE child_id = %d AND state = 'active'
                 ORDER BY started_at DESC
                 LIMIT 1",
                $child_id
            ),
            ARRAY_A
        );

        if ( ! $session ) {
            return null;
        }

        // Attach matching checkpoint, if any
        $raw        = get_user_meta( $child_id, 'noey_checkpoint', true );
        $checkpoint = null;
        if ( $raw ) {
            $decoded = json_decode( $raw, true );
            if ( (int) ( $decoded['session_id'] ?? 0 ) === (int) $session['session_id'] ) {
                $checkpoint = $decoded;
            }
        }

        $session['checkpoint'] = $checkpoint;
        return $session;
    }

    // ── Cancel ────────────────────────────────────────────────────────────────

    /**
     * Cancel an active exam session.
     *
     * Sets the session state to 'cancelled' and clears any saved checkpoint.
     * The token consumed on start is NOT refunded.
     *
     * @param  int $session_id
     * @param  int $child_id
     * @return array|WP_Error  { cancelled: true, session_id: int }
     */
    public static function cancel( int $session_id, int $child_id ): array|WP_Error {
        global $wpdb;

        $session = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT session_id FROM {$wpdb->prefix}noey_exam_sessions
                 WHERE session_id = %d AND child_id = %d AND state = 'active'",
                $session_id,
                $child_id
            ),
            ARRAY_A
        );

        if ( ! $session ) {
            return new WP_Error(
                'noey_session_not_found',
                'Active exam session not found.',
                [ 'status' => 404 ]
            );
        }

        $wpdb->update(
            $wpdb->prefix . 'noey_exam_sessions',
            [ 'state' => 'cancelled' ],
            [ 'session_id' => $session_id ],
            [ '%s' ],
            [ '%d' ]
        );

        // Clear checkpoint so it doesn't linger
        delete_user_meta( $child_id, 'noey_checkpoint' );

        Noey_Debug::log( 'exam.cancel', 'Exam session cancelled', [
            'session_id' => $session_id,
            'child_id'   => $child_id,
        ], $child_id, 'info' );

        return [ 'cancelled' => true, 'session_id' => $session_id ];
    }

    private static function generate_session_id(): string {
        return 'ses_' . strtolower( bin2hex( random_bytes( 12 ) ) );
    }
}
