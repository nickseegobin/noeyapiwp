<?php
/**
 * Noey_Token_Service — Token wallet business logic.
 *
 * Balance is always anchored to the parent account.
 * All children of a parent share the same token pool.
 * Ledger is append-only (never updated or deleted).
 *
 * @package NoeyAPI
 */

defined( 'ABSPATH' ) || exit;

class Noey_Token_Service {

    // ── Balance ───────────────────────────────────────────────────────────────

    /**
     * Get current token balance for a user (resolves child → parent automatically).
     */
    public static function get_balance( int $user_id ): int {
        $parent_id = self::resolve_to_parent( $user_id );
        return (int) get_user_meta( $parent_id, 'noey_token_balance', true );
    }

    /**
     * Check whether the user has enough tokens.
     */
    public static function has_enough( int $user_id, int $required = 1 ): bool {
        return self::get_balance( $user_id ) >= $required;
    }

    // ── Credit ────────────────────────────────────────────────────────────────

    /**
     * Credit tokens to the parent wallet.
     *
     * @param  int    $user_id      Parent or child (auto-resolved).
     * @param  int    $amount       Tokens to add (must be positive).
     * @param  string $type         Ledger type constant.
     * @param  string $reference_id External reference (order ID, etc.).
     * @param  string $note         Human-readable note.
     * @return array{balance_before:int,balance_after:int}|WP_Error
     */
    public static function credit(
        int    $user_id,
        int    $amount,
        string $type         = 'admin_credit',
        string $reference_id = '',
        string $note         = ''
    ): array|WP_Error {
        if ( $amount <= 0 ) {
            return new WP_Error( 'noey_invalid_amount', 'Credit amount must be greater than zero.', [ 'status' => 422 ] );
        }

        $parent_id     = self::resolve_to_parent( $user_id );
        $balance_before = (int) get_user_meta( $parent_id, 'noey_token_balance', true );
        $balance_after  = $balance_before + $amount;

        update_user_meta( $parent_id, 'noey_token_balance', $balance_after );

        self::write_ledger( $parent_id, $amount, $balance_after, $type, $reference_id, $note );

        Noey_Debug::log( 'token.credit', 'Tokens credited', [
            'parent_id'      => $parent_id,
            'amount'         => $amount,
            'balance_before' => $balance_before,
            'balance_after'  => $balance_after,
            'type'           => $type,
            'reference_id'   => $reference_id,
        ], $parent_id, 'info' );

        return [
            'balance_before' => $balance_before,
            'balance_after'  => $balance_after,
        ];
    }

    // ── Deduct ────────────────────────────────────────────────────────────────

    /**
     * Deduct tokens from the parent wallet (atomic check-and-deduct).
     *
     * Returns WP_Error if balance is insufficient or dev bypass is active.
     *
     * @param  int    $user_id      Parent or child (auto-resolved).
     * @param  int    $amount       Tokens to deduct (positive).
     * @param  string $reference_id Exam session ID or other reference.
     * @param  string $note
     * @return array{balance_before:int,balance_after:int}|WP_Error
     */
    public static function deduct(
        int    $user_id,
        int    $amount       = 1,
        string $reference_id = '',
        string $note         = 'Exam started'
    ): array|WP_Error {
        // Dev bypass
        if ( (bool) get_option( 'noey_dev_bypass_tokens', false ) ) {
            Noey_Debug::log( 'token.deduct', 'Dev bypass active — skipping deduction', [
                'user_id' => $user_id,
                'amount'  => $amount,
            ], $user_id, 'debug' );
            $balance = self::get_balance( $user_id );
            return [ 'balance_before' => $balance, 'balance_after' => $balance ];
        }

        $parent_id     = self::resolve_to_parent( $user_id );
        $balance_before = (int) get_user_meta( $parent_id, 'noey_token_balance', true );

        if ( $balance_before < $amount ) {
            Noey_Debug::log( 'token.deduct', 'Insufficient token balance', [
                'parent_id' => $parent_id,
                'required'  => $amount,
                'balance'   => $balance_before,
            ], $parent_id, 'warning' );
            return new WP_Error( 'noey_insufficient_tokens', 'Insufficient tokens. Please purchase more to continue.', [
                'status'  => 402,
                'balance' => $balance_before,
                'required' => $amount,
            ] );
        }

        $balance_after = $balance_before - $amount;
        update_user_meta( $parent_id, 'noey_token_balance', $balance_after );

        // Track lifetime usage
        $lifetime = (int) get_user_meta( $parent_id, 'noey_tokens_lifetime', true );
        update_user_meta( $parent_id, 'noey_tokens_lifetime', $lifetime + $amount );

        self::write_ledger( $parent_id, -$amount, $balance_after, 'exam_deduct', $reference_id, $note );

        Noey_Debug::log( 'token.deduct', 'Tokens deducted', [
            'parent_id'      => $parent_id,
            'amount'         => $amount,
            'balance_before' => $balance_before,
            'balance_after'  => $balance_after,
            'reference_id'   => $reference_id,
        ], $parent_id, 'info' );

        return [
            'balance_before' => $balance_before,
            'balance_after'  => $balance_after,
        ];
    }

    // ── Registration Grant ────────────────────────────────────────────────────

    /**
     * Grant initial free tokens on parent account registration.
     */
    public static function grant_on_registration( int $parent_id ): void {
        if ( get_user_meta( $parent_id, 'noey_token_balance', true ) !== '' ) {
            return; // Already initialised
        }

        update_user_meta( $parent_id, 'noey_token_balance', NOEY_FREE_TOKEN_GRANT );
        update_user_meta( $parent_id, 'noey_tokens_lifetime', 0 );
        update_user_meta( $parent_id, 'noey_token_refresh_date', date( 'Y-m-01' ) );

        self::write_ledger( $parent_id, NOEY_FREE_TOKEN_GRANT, NOEY_FREE_TOKEN_GRANT, 'registration', '', 'Welcome gift' );

        Noey_Debug::log( 'token.registration', 'Registration tokens granted', [
            'parent_id' => $parent_id,
            'amount'    => NOEY_FREE_TOKEN_GRANT,
        ], $parent_id, 'info' );
    }

    // ── Monthly Refresh ───────────────────────────────────────────────────────

    /**
     * Run the monthly free-tier token refresh for all non-premium parents.
     *
     * Called by Noey_Cron on the 1st of each month.
     *
     * @return int  Number of accounts refreshed.
     */
    public static function run_monthly_refresh(): int {
        Noey_Debug::log( 'token.monthly_refresh', 'Monthly refresh started', [], null, 'info' );

        $parents = get_users( [
            'role'       => 'noey_parent',
            'fields'     => 'ID',
            'meta_query' => [
                [
                    'key'     => 'noey_premium',
                    'compare' => 'NOT EXISTS',
                ],
            ],
        ] );

        $this_month = date( 'Y-m-01' );
        $refreshed  = 0;

        foreach ( $parents as $parent_id ) {
            $last_refresh = get_user_meta( $parent_id, 'noey_token_refresh_date', true );
            if ( $last_refresh === $this_month ) {
                continue; // Already refreshed this month
            }

            update_user_meta( $parent_id, 'noey_token_balance', NOEY_FREE_TOKEN_MONTHLY );
            update_user_meta( $parent_id, 'noey_token_refresh_date', $this_month );

            self::write_ledger( $parent_id, NOEY_FREE_TOKEN_MONTHLY, NOEY_FREE_TOKEN_MONTHLY, 'monthly_refresh', $this_month, 'Monthly free token reset' );

            $refreshed++;
        }

        Noey_Debug::log( 'token.monthly_refresh', 'Monthly refresh complete', [
            'refreshed' => $refreshed,
            'month'     => $this_month,
        ], null, 'info' );

        return $refreshed;
    }

    // ── Ledger ────────────────────────────────────────────────────────────────

    /**
     * Return the transaction ledger for a user (paginated).
     */
    public static function get_ledger( int $user_id, int $limit = 50, int $offset = 0 ): array {
        $parent_id = self::resolve_to_parent( $user_id );

        global $wpdb;
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}noey_token_ledger
                 WHERE user_id = %d
                 ORDER BY ledger_id DESC
                 LIMIT %d OFFSET %d",
                $parent_id,
                $limit,
                $offset
            ),
            ARRAY_A
        ) ?: [];

        Noey_Debug::log( 'token.ledger', 'Ledger fetched', [
            'parent_id' => $parent_id,
            'rows'      => count( $rows ),
        ], $parent_id, 'debug' );

        return $rows;
    }

    // ── Private Helpers ───────────────────────────────────────────────────────

    /**
     * Resolve any user ID (parent or child) to the parent ID.
     */
    public static function resolve_to_parent( int $user_id ): int {
        $user = get_userdata( $user_id );
        if ( $user && in_array( 'noey_child', (array) $user->roles, true ) ) {
            $parent_id = (int) get_user_meta( $user_id, 'noey_parent_id', true );
            return $parent_id ?: $user_id;
        }
        return $user_id;
    }

    private static function write_ledger(
        int    $parent_id,
        int    $amount,
        int    $balance_after,
        string $type,
        string $reference_id,
        string $note
    ): void {
        global $wpdb;
        $wpdb->insert(
            $wpdb->prefix . 'noey_token_ledger',
            [
                'user_id'      => $parent_id,
                'amount'       => $amount,
                'balance_after' => $balance_after,
                'type'         => $type,
                'reference_id' => $reference_id ?: null,
                'note'         => $note ?: null,
                'created_at'   => current_time( 'mysql', true ),
            ],
            [ '%d', '%d', '%d', '%s', '%s', '%s', '%s' ]
        );
    }
}
