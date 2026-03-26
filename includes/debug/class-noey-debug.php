<?php
/**
 * Noey_Debug — Global debug logging system.
 *
 * Toggle via Admin › NoeyAPI › Settings › Enable Debug Mode.
 * Logs are written to wp_noey_debug_log and visible in Admin › NoeyAPI › Debug Log.
 *
 * Usage anywhere in the codebase:
 *   Noey_Debug::log( 'auth.login', 'Login attempt', [ 'username' => $u ], null, 'info' );
 *   Noey_Debug::log( 'token.deduct', 'Insufficient balance', [ 'balance' => 0 ], $uid, 'warning' );
 *   Noey_Debug::log( 'exam.serve', 'Railway call failed', [ 'error' => $msg ], $uid, 'error' );
 *
 * @package NoeyAPI
 */

defined( 'ABSPATH' ) || exit;

class Noey_Debug {

    const LEVELS = [ 'debug', 'info', 'warning', 'error' ];

    // ── Core API ──────────────────────────────────────────────────────────────

    /**
     * Write a debug log entry.
     *
     * @param string   $context  Dot-notation context, e.g. 'auth.login', 'exam.serve'.
     * @param string   $message  Human-readable description.
     * @param array    $data     Structured data payload (JSON-encoded in DB).
     * @param int|null $user_id  Associated WP user ID (null = system/unauthenticated).
     * @param string   $level    One of: debug | info | warning | error.
     */
    public static function log(
        string $context,
        string $message,
        array  $data    = [],
        ?int   $user_id = null,
        string $level   = 'info'
    ): void {
        if ( ! self::is_enabled() ) {
            return;
        }

        if ( ! in_array( $level, self::LEVELS, true ) ) {
            $level = 'info';
        }

        global $wpdb;

        $wpdb->insert(
            $wpdb->prefix . 'noey_debug_log',
            [
                'level'      => $level,
                'context'    => substr( $context, 0, 100 ),
                'message'    => $message,
                'data'       => ! empty( $data ) ? wp_json_encode( $data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT ) : null,
                'user_id'    => $user_id,
                'request_id' => self::request_id(),
                'created_at' => current_time( 'mysql', true ), // UTC
            ],
            [ '%s', '%s', '%s', '%s', '%d', '%s', '%s' ]
        );

        // Lazy prune — once per request
        static $pruned = false;
        if ( ! $pruned ) {
            $pruned = true;
            self::maybe_prune();
        }
    }

    /**
     * Whether debug mode is currently active.
     */
    public static function is_enabled(): bool {
        return (bool) get_option( 'noey_debug_enabled', false );
    }

    // ── Query API (used by Admin Debug page) ──────────────────────────────────

    /**
     * Retrieve log entries with optional filters.
     *
     * @param array $filters {
     *   @type string $level    Filter by level.
     *   @type string $context  Partial match on context.
     *   @type int    $user_id  Filter by user.
     *   @type string $search   Partial match on message.
     *   @type int    $limit    Max rows (default 100).
     *   @type int    $offset   Pagination offset (default 0).
     * }
     */
    public static function get_logs( array $filters = [] ): array {
        global $wpdb;

        $table  = $wpdb->prefix . 'noey_debug_log';
        $where  = [ '1=1' ];
        $values = [];

        if ( ! empty( $filters['level'] ) ) {
            $where[]  = 'level = %s';
            $values[] = $filters['level'];
        }
        if ( ! empty( $filters['context'] ) ) {
            $where[]  = 'context LIKE %s';
            $values[] = '%' . $wpdb->esc_like( $filters['context'] ) . '%';
        }
        if ( ! empty( $filters['user_id'] ) ) {
            $where[]  = 'user_id = %d';
            $values[] = (int) $filters['user_id'];
        }
        if ( ! empty( $filters['search'] ) ) {
            $where[]  = 'message LIKE %s';
            $values[] = '%' . $wpdb->esc_like( $filters['search'] ) . '%';
        }

        $limit  = max( 1, min( 500, (int) ( $filters['limit']  ?? 100 ) ) );
        $offset = max( 0, (int) ( $filters['offset'] ?? 0 ) );

        $sql = "SELECT * FROM {$table} WHERE " . implode( ' AND ', $where )
             . ' ORDER BY log_id DESC LIMIT %d OFFSET %d';

        $values[] = $limit;
        $values[] = $offset;

        return $wpdb->get_results(
            $wpdb->prepare( $sql, ...$values ),
            ARRAY_A
        ) ?: [];
    }

    /**
     * Count log entries matching filters (for pagination).
     */
    public static function get_count( array $filters = [] ): int {
        global $wpdb;

        $table  = $wpdb->prefix . 'noey_debug_log';
        $where  = [ '1=1' ];
        $values = [];

        if ( ! empty( $filters['level'] ) ) {
            $where[]  = 'level = %s';
            $values[] = $filters['level'];
        }
        if ( ! empty( $filters['context'] ) ) {
            $where[]  = 'context LIKE %s';
            $values[] = '%' . $wpdb->esc_like( $filters['context'] ) . '%';
        }

        $sql = "SELECT COUNT(*) FROM {$table} WHERE " . implode( ' AND ', $where );

        return (int) $wpdb->get_var(
            empty( $values ) ? $sql : $wpdb->prepare( $sql, ...$values )
        );
    }

    /**
     * Delete all log entries.
     */
    public static function clear_logs(): void {
        global $wpdb;
        $wpdb->query( 'TRUNCATE TABLE ' . $wpdb->prefix . 'noey_debug_log' );
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /**
     * A unique ID for the current HTTP request (stable within one PHP process).
     */
    public static function request_id(): string {
        static $id = null;
        if ( $id === null ) {
            $id = substr( bin2hex( random_bytes( 8 ) ), 0, 12 );
        }
        return $id;
    }

    /**
     * Level badge colour for admin display.
     */
    public static function level_colour( string $level ): string {
        return match ( $level ) {
            'error'   => '#dc2626',
            'warning' => '#d97706',
            'debug'   => '#6b7280',
            default   => '#2563eb', // info
        };
    }

    // ── Private ───────────────────────────────────────────────────────────────

    private static function maybe_prune(): void {
        global $wpdb;
        $table = $wpdb->prefix . 'noey_debug_log';
        $count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );

        if ( $count > NOEY_DEBUG_MAX_LOGS ) {
            $cutoff = $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT log_id FROM {$table} ORDER BY log_id DESC LIMIT 1 OFFSET %d",
                    NOEY_DEBUG_MAX_LOGS - 1
                )
            );
            if ( $cutoff ) {
                $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE log_id < %d", $cutoff ) );
            }
        }
    }
}
