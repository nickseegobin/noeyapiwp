<?php
/**
 * Noey_Admin_Tokens — Token management admin page.
 *
 * Features:
 *  - Platform stats: total tokens in circulation, total spent, zero-balance accounts
 *  - Account table with balance, lifetime usage, last transaction, premium badge
 *  - Per-account: credit, deduct, set exact balance, view full ledger
 *  - Bulk credit — top up all (or all zero-balance) accounts at once
 *  - Trigger monthly refresh — manually run the cron
 *  - Premium toggle — exclude an account from monthly reset
 *  - Dev bypass toggle — disable token deduction globally for testing
 *  - Recent transactions feed — cross-account last 50 ledger entries
 *
 * @package NoeyAPI
 */

defined( 'ABSPATH' ) || exit;

class Noey_Admin_Tokens {

    // ── Boot ──────────────────────────────────────────────────────────────────

    public static function boot(): void {
        add_action( 'wp_ajax_noey_tokens_credit',          [ __CLASS__, 'ajax_credit' ] );
        add_action( 'wp_ajax_noey_tokens_deduct',          [ __CLASS__, 'ajax_deduct' ] );
        add_action( 'wp_ajax_noey_tokens_set',             [ __CLASS__, 'ajax_set' ] );
        add_action( 'wp_ajax_noey_tokens_ledger',          [ __CLASS__, 'ajax_ledger' ] );
        add_action( 'wp_ajax_noey_tokens_bulk_credit',     [ __CLASS__, 'ajax_bulk_credit' ] );
        add_action( 'wp_ajax_noey_tokens_monthly_refresh', [ __CLASS__, 'ajax_monthly_refresh' ] );
        add_action( 'wp_ajax_noey_tokens_toggle_premium',  [ __CLASS__, 'ajax_toggle_premium' ] );
        add_action( 'wp_ajax_noey_tokens_bypass_toggle',   [ __CLASS__, 'ajax_bypass_toggle' ] );
        add_action( 'wp_ajax_noey_tokens_recent',          [ __CLASS__, 'ajax_recent' ] );
    }

    // ── Render ────────────────────────────────────────────────────────────────

    public static function render(): void {
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Insufficient permissions.' );

        global $wpdb;
        $ledger_table = $wpdb->prefix . 'noey_token_ledger';

        $parents = get_users( [ 'role' => 'noey_parent', 'orderby' => 'registered', 'order' => 'DESC' ] );

        // Platform stats
        $total_in_circulation = 0;
        $total_lifetime_spent = 0;
        $zero_balance_count   = 0;
        $premium_count        = 0;

        foreach ( $parents as $p ) {
            $bal = (int) get_user_meta( $p->ID, 'noey_token_balance', true );
            $total_in_circulation += $bal;
            $total_lifetime_spent += (int) get_user_meta( $p->ID, 'noey_tokens_lifetime', true );
            if ( $bal === 0 )   $zero_balance_count++;
            if ( get_user_meta( $p->ID, 'noey_premium', true ) ) $premium_count++;
        }

        $total_tx = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$ledger_table}" );
        $dev_bypass = (bool) get_option( 'noey_dev_bypass_tokens', false );
        $next_refresh = wp_next_scheduled( 'noey_monthly_token_refresh' );
        ?>
        <div class="wrap noey-wrap">
            <h1>NoeyAPI — Tokens</h1>

            <!-- Dev Bypass Banner -->
            <?php if ( $dev_bypass ) : ?>
            <div class="notice notice-warning" style="display:flex;align-items:center;justify-content:space-between;padding:10px 16px;">
                <p style="margin:0;font-weight:600;">⚠ Dev Bypass is ON — tokens are not being deducted for any exam.</p>
                <button class="button noey-bypass-toggle" data-state="1">Turn Off Bypass</button>
            </div>
            <?php endif; ?>

            <!-- Stats -->
            <div class="noey-stat-grid" style="grid-template-columns:repeat(5,1fr);margin-bottom:24px;">
                <div class="noey-stat-card">
                    <div class="noey-stat-number" id="noey-stat-circulation"><?= esc_html( $total_in_circulation ) ?></div>
                    <div class="noey-stat-label">Tokens in Circulation</div>
                </div>
                <div class="noey-stat-card">
                    <div class="noey-stat-number"><?= esc_html( $total_lifetime_spent ) ?></div>
                    <div class="noey-stat-label">Total Ever Spent</div>
                </div>
                <div class="noey-stat-card">
                    <div class="noey-stat-number" style="color:<?= $zero_balance_count > 0 ? '#dc2626' : '#16a34a' ?>;"><?= esc_html( $zero_balance_count ) ?></div>
                    <div class="noey-stat-label">Zero Balance Accounts</div>
                </div>
                <div class="noey-stat-card">
                    <div class="noey-stat-number"><?= esc_html( $total_tx ) ?></div>
                    <div class="noey-stat-label">Total Transactions</div>
                </div>
                <div class="noey-stat-card">
                    <div class="noey-stat-number"><?= esc_html( $premium_count ) ?></div>
                    <div class="noey-stat-label">Premium Accounts</div>
                </div>
            </div>

            <div style="display:grid;grid-template-columns:1fr 300px;gap:20px;align-items:start;">

                <!-- ── MAIN ACCOUNT TABLE ───────────────────────────────────── -->
                <div>
                    <!-- Toolbar -->
                    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:12px;gap:10px;flex-wrap:wrap;">
                        <div style="display:flex;gap:8px;align-items:center;">
                            <input type="text" id="noey-token-search" placeholder="Search by name or email…"
                                class="regular-text" style="height:32px;max-width:260px;" />
                            <select id="noey-token-filter" style="height:32px;">
                                <option value="all">All accounts</option>
                                <option value="zero">Zero balance</option>
                                <option value="premium">Premium</option>
                                <option value="low">Low (≤ 2)</option>
                            </select>
                        </div>
                        <div style="display:flex;gap:6px;">
                            <button id="noey-bulk-credit-btn" class="button">Bulk Credit…</button>
                            <button id="noey-monthly-refresh-btn" class="button">
                                Run Monthly Refresh
                                <?php if ( $next_refresh ) : ?>
                                    <span style="font-size:10px;color:#888;font-weight:400;">(next: <?= esc_html( date_i18n( 'M j', $next_refresh ) ) ?>)</span>
                                <?php endif; ?>
                            </button>
                            <?php if ( ! $dev_bypass ) : ?>
                            <button class="button noey-bypass-toggle" data-state="0">Enable Dev Bypass</button>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="noey-settings-section" style="padding:0;overflow:hidden;">
                        <table class="noey-table noey-token-table" style="border:none;border-radius:0;">
                            <thead>
                                <tr>
                                    <th>Account</th>
                                    <th style="text-align:center;">Balance</th>
                                    <th style="text-align:center;">Lifetime Spent</th>
                                    <th>Last Transaction</th>
                                    <th style="text-align:center;">Premium</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php foreach ( $parents as $parent ) :
                                $balance    = (int) get_user_meta( $parent->ID, 'noey_token_balance', true );
                                $lifetime   = (int) get_user_meta( $parent->ID, 'noey_tokens_lifetime', true );
                                $is_premium = (bool) get_user_meta( $parent->ID, 'noey_premium', true );
                                $last_tx    = $wpdb->get_row( $wpdb->prepare(
                                    "SELECT amount, type, created_at FROM {$ledger_table} WHERE user_id=%d ORDER BY ledger_id DESC LIMIT 1",
                                    $parent->ID
                                ) );
                                $bal_color  = $balance === 0 ? '#dc2626' : ( $balance <= 2 ? '#d97706' : '#16a34a' );
                            ?>
                            <tr class="noey-token-row"
                                data-parent-id="<?= esc_attr( $parent->ID ) ?>"
                                data-balance="<?= esc_attr( $balance ) ?>"
                                data-name="<?= esc_attr( strtolower( $parent->display_name ) ) ?>"
                                data-email="<?= esc_attr( strtolower( $parent->user_email ) ) ?>"
                                data-premium="<?= esc_attr( $is_premium ? '1' : '0' ) ?>"
                                data-low="<?= esc_attr( $balance <= 2 ? '1' : '0' ) ?>">
                                <td>
                                    <strong><?= esc_html( $parent->display_name ) ?></strong>
                                    <div style="font-size:11px;color:#888;">@<?= esc_html( $parent->user_login ) ?> · <?= esc_html( $parent->user_email ) ?></div>
                                </td>
                                <td style="text-align:center;">
                                    <span class="noey-balance-cell" style="font-size:20px;font-weight:700;color:<?= $bal_color ?>;"><?= esc_html( $balance ) ?></span>
                                </td>
                                <td style="text-align:center;color:#888;font-weight:600;"><?= esc_html( $lifetime ) ?></td>
                                <td style="font-size:11px;">
                                    <?php if ( $last_tx ) :
                                        $amt_color = $last_tx->amount > 0 ? '#16a34a' : '#dc2626';
                                        $amt_sign  = $last_tx->amount > 0 ? '+' : '';
                                    ?>
                                        <span style="color:<?= $amt_color ?>;font-weight:600;"><?= $amt_sign . esc_html( $last_tx->amount ) ?></span>
                                        <span style="color:#888;"> · <?= esc_html( self::type_label( $last_tx->type ) ) ?></span>
                                        <div style="color:#aaa;"><?= esc_html( date_i18n( 'M j, Y g:i a', strtotime( $last_tx->created_at ) ) ) ?></div>
                                    <?php else : ?>
                                        <span style="color:#ccc;">—</span>
                                    <?php endif; ?>
                                </td>
                                <td style="text-align:center;">
                                    <button class="button button-small noey-premium-toggle"
                                        data-parent-id="<?= esc_attr( $parent->ID ) ?>"
                                        data-state="<?= esc_attr( $is_premium ? '1' : '0' ) ?>"
                                        style="<?= $is_premium ? 'color:#2563eb;border-color:#2563eb;font-weight:600;' : '' ?>">
                                        <?= $is_premium ? '★ Premium' : '☆ Free' ?>
                                    </button>
                                </td>
                                <td>
                                    <div style="display:flex;gap:4px;flex-wrap:wrap;">
                                        <button class="button button-small noey-token-action"
                                            data-action="credit"
                                            data-parent-id="<?= esc_attr( $parent->ID ) ?>"
                                            data-name="<?= esc_attr( $parent->display_name ) ?>"
                                            data-balance="<?= esc_attr( $balance ) ?>"
                                            style="color:#16a34a;border-color:#16a34a;">
                                            + Credit
                                        </button>
                                        <button class="button button-small noey-token-action"
                                            data-action="deduct"
                                            data-parent-id="<?= esc_attr( $parent->ID ) ?>"
                                            data-name="<?= esc_attr( $parent->display_name ) ?>"
                                            data-balance="<?= esc_attr( $balance ) ?>"
                                            style="color:#dc2626;border-color:#dc2626;">
                                            − Deduct
                                        </button>
                                        <button class="button button-small noey-token-action"
                                            data-action="set"
                                            data-parent-id="<?= esc_attr( $parent->ID ) ?>"
                                            data-name="<?= esc_attr( $parent->display_name ) ?>"
                                            data-balance="<?= esc_attr( $balance ) ?>">
                                            = Set
                                        </button>
                                        <button class="button button-small noey-view-ledger"
                                            data-parent-id="<?= esc_attr( $parent->ID ) ?>"
                                            data-name="<?= esc_attr( $parent->display_name ) ?>">
                                            Ledger
                                        </button>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                        <?php if ( empty( $parents ) ) : ?>
                            <p style="padding:20px;color:#888;text-align:center;">No parent accounts found.</p>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- ── SIDEBAR ──────────────────────────────────────────────── -->
                <div style="display:flex;flex-direction:column;gap:16px;">

                    <!-- Dev bypass -->
                    <div class="noey-settings-section">
                        <h2>Dev Token Bypass</h2>
                        <p style="font-size:12px;color:#666;margin-bottom:10px;">
                            When ON, <strong>no tokens are deducted</strong> for any exam — useful for testing.
                            Does not affect credits or ledger writes.
                        </p>
                        <div style="display:flex;align-items:center;gap:10px;">
                            <span id="noey-bypass-status-label" style="font-size:13px;font-weight:600;color:<?= $dev_bypass ? '#dc2626' : '#16a34a' ?>;">
                                <?= $dev_bypass ? 'ON' : 'OFF' ?>
                            </span>
                            <button class="button noey-bypass-toggle" data-state="<?= $dev_bypass ? '1' : '0' ?>">
                                <?= $dev_bypass ? 'Disable Bypass' : 'Enable Bypass' ?>
                            </button>
                        </div>
                    </div>

                    <!-- Monthly refresh info -->
                    <div class="noey-settings-section">
                        <h2>Monthly Refresh</h2>
                        <p style="font-size:12px;color:#666;margin-bottom:6px;">
                            Resets all <em>free-tier</em> accounts to <strong><?= esc_html( NOEY_FREE_TOKEN_MONTHLY ) ?> tokens</strong>.
                            Premium accounts are excluded.
                        </p>
                        <?php if ( $next_refresh ) : ?>
                        <p style="font-size:12px;color:#888;margin-bottom:10px;">
                            Next scheduled: <strong><?= esc_html( date_i18n( 'M j, Y', $next_refresh ) ) ?></strong>
                        </p>
                        <?php endif; ?>
                        <button id="noey-monthly-refresh-sidebar" class="button" style="width:100%;">Run Now</button>
                        <div id="noey-refresh-result" style="font-size:12px;margin-top:8px;"></div>
                    </div>

                    <!-- Recent transactions -->
                    <div class="noey-settings-section">
                        <h2>Recent Transactions</h2>
                        <button id="noey-load-recent" class="button" style="width:100%;margin-bottom:10px;">Load Recent</button>
                        <div id="noey-recent-feed" style="font-size:11px;"></div>
                    </div>

                </div>
            </div>
        </div>

        <!-- ── Adjust Tokens Modal ─────────────────────────────────────────── -->
        <div id="noey-adjust-modal" class="noey-modal-overlay" style="display:none;">
            <div class="noey-modal-box" style="max-width:400px;">
                <div class="noey-modal-header">
                    <h2 id="adj-title">Adjust Tokens</h2>
                    <button class="button noey-modal-close">✕</button>
                </div>
                <div class="noey-modal-body">
                    <input type="hidden" id="adj-parent-id" />
                    <input type="hidden" id="adj-action-type" />
                    <p id="adj-current-balance" style="font-size:13px;margin:0 0 12px;"></p>
                    <label id="adj-amount-label" style="font-size:13px;font-weight:600;display:block;margin-bottom:6px;"></label>
                    <input type="number" id="adj-amount" class="regular-text" min="0" />
                    <div style="margin-top:10px;">
                        <label style="font-size:12px;font-weight:600;display:block;margin-bottom:4px;">Note (optional)</label>
                        <input type="text" id="adj-note" class="regular-text" placeholder="Reason for adjustment…" />
                    </div>
                    <div id="adj-error" style="color:#dc2626;font-size:12px;margin-top:8px;display:none;"></div>
                </div>
                <div class="noey-modal-footer">
                    <button id="adj-submit" class="button button-primary">Apply</button>
                    <button class="button noey-modal-close">Cancel</button>
                </div>
            </div>
        </div>

        <!-- ── Ledger Modal ────────────────────────────────────────────────── -->
        <div id="noey-ledger-modal" class="noey-modal-overlay" style="display:none;">
            <div class="noey-modal-box" style="max-width:760px;">
                <div class="noey-modal-header">
                    <h2>Token Ledger — <span id="ledger-name"></span></h2>
                    <button class="button noey-modal-close">✕</button>
                </div>
                <div id="ledger-body" class="noey-modal-body" style="max-height:65vh;overflow:auto;padding:20px;"></div>
            </div>
        </div>

        <!-- ── Bulk Credit Modal ───────────────────────────────────────────── -->
        <div id="noey-bulk-modal" class="noey-modal-overlay" style="display:none;">
            <div class="noey-modal-box" style="max-width:420px;">
                <div class="noey-modal-header">
                    <h2>Bulk Credit Tokens</h2>
                    <button class="button noey-modal-close">✕</button>
                </div>
                <div class="noey-modal-body">
                    <p style="font-size:13px;margin:0 0 12px;color:#444;">
                        Credits tokens to <strong>all parent accounts</strong>, or just those with a zero balance.
                    </p>
                    <table class="form-table" style="margin:0;">
                        <tr>
                            <th>Target</th>
                            <td>
                                <select id="bulk-target" class="regular-text">
                                    <option value="all">All accounts (<?= esc_html( count( $parents ) ) ?>)</option>
                                    <option value="zero">Zero balance only (<?= esc_html( $zero_balance_count ) ?>)</option>
                                </select>
                            </td>
                        </tr>
                        <tr>
                            <th>Tokens to add</th>
                            <td><input type="number" id="bulk-amount" class="small-text" value="3" min="1" /></td>
                        </tr>
                        <tr>
                            <th>Note</th>
                            <td><input type="text" id="bulk-note" class="regular-text" placeholder="e.g. Monthly promotion" /></td>
                        </tr>
                    </table>
                    <div id="bulk-error" style="color:#dc2626;font-size:12px;margin-top:8px;display:none;"></div>
                </div>
                <div class="noey-modal-footer">
                    <button id="bulk-submit" class="button button-primary">Credit All</button>
                    <button class="button noey-modal-close">Cancel</button>
                </div>
            </div>
        </div>

        <style>
        .noey-modal-overlay {
            position: fixed; inset: 0;
            background: rgba(0,0,0,.5);
            z-index: 99999;
            display: flex;
            align-items: flex-start;
            justify-content: center;
            padding-top: 60px;
            overflow: auto;
        }
        .noey-modal-box {
            background: #fff;
            width: 100%;
            border-radius: 8px;
            overflow: hidden;
            box-shadow: 0 20px 60px rgba(0,0,0,.3);
        }
        .noey-modal-header {
            padding: 14px 20px;
            background: #f6f7f7;
            border-bottom: 1px solid #ddd;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .noey-modal-header h2 { margin: 0; font-size: 15px; }
        .noey-modal-body { padding: 20px; }
        .noey-modal-footer {
            padding: 12px 20px;
            background: #f6f7f7;
            border-top: 1px solid #ddd;
            display: flex;
            gap: 8px;
        }
        .noey-token-row td { vertical-align: middle; }
        </style>

        <script>
        (function($) {
            var nonce = '<?= wp_create_nonce( 'noey_admin_nonce' ) ?>';

            // ── Search & Filter ───────────────────────────────────────────────
            function applyFilters() {
                var q      = $('#noey-token-search').val().toLowerCase();
                var filter = $('#noey-token-filter').val();
                $('.noey-token-row').each(function() {
                    var $r = $(this);
                    var matchSearch = !q || $r.data('name').indexOf(q) >= 0 || $r.data('email').indexOf(q) >= 0;
                    var matchFilter = filter === 'all'
                        || (filter === 'zero'    && parseInt($r.data('balance')) === 0)
                        || (filter === 'premium' && $r.data('premium') === '1')
                        || (filter === 'low'     && $r.data('low') === '1');
                    $r.toggle(matchSearch && matchFilter);
                });
            }
            $('#noey-token-search').on('input', applyFilters);
            $('#noey-token-filter').on('change', applyFilters);

            // ── Modal helpers ─────────────────────────────────────────────────
            function openModal(id) { $('#' + id).fadeIn(150); }
            function closeAllModals() { $('.noey-modal-overlay').fadeOut(150); }
            $(document).on('click', '.noey-modal-close', closeAllModals);
            $(document).on('click', '.noey-modal-overlay', function(e) {
                if ($(e.target).hasClass('noey-modal-overlay')) closeAllModals();
            });

            // ── Helper: update a row's balance display ─────────────────────────
            function updateRowBalance(parentId, newBalance) {
                var $row = $('.noey-token-row[data-parent-id="' + parentId + '"]');
                $row.data('balance', newBalance);
                $row.data('low', newBalance <= 2 ? '1' : '0');
                var color = newBalance === 0 ? '#dc2626' : (newBalance <= 2 ? '#d97706' : '#16a34a');
                $row.find('.noey-balance-cell').css('color', color).text(newBalance);
                // Keep action buttons in sync
                $row.find('.noey-token-action').data('balance', newBalance);
                $row.find('.noey-token-action[data-action="credit"]').data('balance', newBalance);
            }

            // ── Adjust modal (credit / deduct / set) ──────────────────────────
            $(document).on('click', '.noey-token-action', function() {
                var action  = $(this).data('action');
                var pid     = $(this).data('parent-id');
                var name    = $(this).data('name');
                var balance = parseInt($(this).data('balance'));

                var titles  = { credit: 'Credit Tokens', deduct: 'Deduct Tokens', set: 'Set Exact Balance' };
                var labels  = { credit: 'Tokens to add', deduct: 'Tokens to remove', set: 'New balance' };
                var defaults = { credit: 5, deduct: 1, set: balance };

                $('#adj-title').text(titles[action] + ' — ' + name);
                $('#adj-current-balance').html('Current balance: <strong>' + balance + '</strong> tokens');
                $('#adj-amount-label').text(labels[action]);
                $('#adj-amount').val(defaults[action]);
                $('#adj-note').val('');
                $('#adj-error').hide();
                $('#adj-parent-id').val(pid);
                $('#adj-action-type').val(action);

                var submitLabels = { credit: 'Credit', deduct: 'Deduct', set: 'Set Balance' };
                $('#adj-submit').text(submitLabels[action]);

                openModal('noey-adjust-modal');
            });

            $('#adj-submit').on('click', function() {
                var $btn   = $(this).prop('disabled', true).text('Applying…');
                var action = $('#adj-action-type').val();
                var pid    = $('#adj-parent-id').val();
                var amount = parseInt($('#adj-amount').val());
                var note   = $('#adj-note').val();
                $('#adj-error').hide();

                var ajaxAction = 'noey_tokens_' + action; // credit | deduct | set

                $.post(ajaxurl, {
                    action:    ajaxAction,
                    nonce:     nonce,
                    parent_id: pid,
                    amount:    amount,
                    note:      note
                }, function(res) {
                    $btn.prop('disabled', false).text($('#adj-action-type').val() === 'set' ? 'Set Balance' : ($('#adj-action-type').val() === 'credit' ? 'Credit' : 'Deduct'));
                    if (res.success) {
                        updateRowBalance(pid, res.data.balance_after);
                        closeAllModals();
                        // Update circulation stat
                        var diff = res.data.balance_after - res.data.balance_before;
                        var circ = parseInt($('#noey-stat-circulation').text()) + diff;
                        $('#noey-stat-circulation').text(circ);
                    } else {
                        $('#adj-error').text(res.data.message).show();
                        $btn.prop('disabled', false);
                    }
                });
            });

            // ── View Ledger ───────────────────────────────────────────────────
            $(document).on('click', '.noey-view-ledger', function() {
                var pid  = $(this).data('parent-id');
                var name = $(this).data('name');
                $('#ledger-name').text(name);
                $('#ledger-body').html('<p style="color:#888;padding:10px 0;">Loading…</p>');
                openModal('noey-ledger-modal');

                $.post(ajaxurl, { action: 'noey_tokens_ledger', nonce: nonce, parent_id: pid },
                    function(res) {
                        if (res.success) {
                            $('#ledger-body').html(res.data.html);
                        } else {
                            $('#ledger-body').html('<p style="color:#dc2626;">' + (res.data.message || 'Failed.') + '</p>');
                        }
                    }
                );
            });

            // ── Bulk Credit ───────────────────────────────────────────────────
            $('#noey-bulk-credit-btn').on('click', function() {
                $('#bulk-amount').val(3);
                $('#bulk-note').val('');
                $('#bulk-error').hide();
                openModal('noey-bulk-modal');
            });

            $('#bulk-submit').on('click', function() {
                var $btn = $(this).prop('disabled', true).text('Crediting…');
                $('#bulk-error').hide();

                $.post(ajaxurl, {
                    action:  'noey_tokens_bulk_credit',
                    nonce:   nonce,
                    target:  $('#bulk-target').val(),
                    amount:  $('#bulk-amount').val(),
                    note:    $('#bulk-note').val()
                }, function(res) {
                    $btn.prop('disabled', false).text('Credit All');
                    if (res.success) {
                        closeAllModals();
                        alert('Done! ' + res.data.credited + ' account(s) credited. Reload to see updated balances.');
                        location.reload();
                    } else {
                        $('#bulk-error').text(res.data.message).show();
                    }
                });
            });

            // ── Monthly Refresh ───────────────────────────────────────────────
            function runMonthlyRefresh($btn, $resultEl) {
                $btn.prop('disabled', true).text('Running…');
                $.post(ajaxurl, { action: 'noey_tokens_monthly_refresh', nonce: nonce },
                    function(res) {
                        $btn.prop('disabled', false).text('Run Now');
                        if (res.success) {
                            $resultEl.html('<span style="color:#16a34a;">✓ ' + res.data.refreshed + ' account(s) refreshed.</span>');
                        } else {
                            $resultEl.html('<span style="color:#dc2626;">Error: ' + (res.data.message || 'Failed.') + '</span>');
                        }
                    }
                );
            }

            $('#noey-monthly-refresh-btn').on('click', function() {
                if (!confirm('Run the monthly token refresh now? This will reset all free-tier accounts to <?= NOEY_FREE_TOKEN_MONTHLY ?> tokens.')) return;
                runMonthlyRefresh($(this), $('<span>'));
                location.reload();
            });

            $('#noey-monthly-refresh-sidebar').on('click', function() {
                if (!confirm('Run the monthly token refresh now?')) return;
                runMonthlyRefresh($(this), $('#noey-refresh-result'));
            });

            // ── Premium Toggle ────────────────────────────────────────────────
            $(document).on('click', '.noey-premium-toggle', function() {
                var $btn  = $(this);
                var pid   = $btn.data('parent-id');
                var state = parseInt($btn.data('state'));

                $.post(ajaxurl, {
                    action:    'noey_tokens_toggle_premium',
                    nonce:     nonce,
                    parent_id: pid,
                    premium:   state ? 0 : 1
                }, function(res) {
                    if (res.success) {
                        var newState = res.data.premium;
                        $btn.data('state', newState ? '1' : '0');
                        $btn.closest('tr').data('premium', newState ? '1' : '0');
                        if (newState) {
                            $btn.text('★ Premium').css({color:'#2563eb','border-color':'#2563eb','font-weight':'600'});
                        } else {
                            $btn.text('☆ Free').css({color:'','border-color':'','font-weight':''});
                        }
                    }
                });
            });

            // ── Dev Bypass Toggle ─────────────────────────────────────────────
            $(document).on('click', '.noey-bypass-toggle', function() {
                var currentState = parseInt($(this).data('state'));
                var newState     = currentState ? 0 : 1;
                var label        = newState ? 'enabled' : 'disabled';

                if (!confirm('Turn token bypass ' + label + '?')) return;

                $.post(ajaxurl, {
                    action:  'noey_tokens_bypass_toggle',
                    nonce:   nonce,
                    enabled: newState
                }, function(res) {
                    if (res.success) {
                        location.reload();
                    }
                });
            });

            // ── Recent Transactions ───────────────────────────────────────────
            $('#noey-load-recent').on('click', function() {
                var $btn = $(this).prop('disabled', true).text('Loading…');
                $.post(ajaxurl, { action: 'noey_tokens_recent', nonce: nonce },
                    function(res) {
                        $btn.prop('disabled', false).text('Refresh');
                        if (res.success) {
                            $('#noey-recent-feed').html(res.data.html);
                        }
                    }
                );
            });

        })(jQuery);
        </script>
        <?php
    }

    // ── AJAX: Credit ──────────────────────────────────────────────────────────

    public static function ajax_credit(): void {
        check_ajax_referer( 'noey_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Forbidden', 403 );

        $parent_id = (int) ( $_POST['parent_id'] ?? 0 );
        $amount    = (int) ( $_POST['amount']    ?? 0 );
        $note      = sanitize_text_field( $_POST['note'] ?? 'Admin credit' );

        if ( $amount <= 0 ) wp_send_json_error( [ 'message' => 'Amount must be greater than zero.' ] );

        $result = Noey_Token_Service::credit( $parent_id, $amount, 'admin_credit', '', $note ?: 'Admin credit' );
        if ( is_wp_error( $result ) ) wp_send_json_error( [ 'message' => $result->get_error_message() ] );

        Noey_Debug::log( 'admin.tokens', 'Tokens credited', [ 'parent_id' => $parent_id, 'amount' => $amount ], null, 'info' );
        wp_send_json_success( $result );
    }

    // ── AJAX: Deduct ──────────────────────────────────────────────────────────

    public static function ajax_deduct(): void {
        check_ajax_referer( 'noey_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Forbidden', 403 );

        $parent_id = (int) ( $_POST['parent_id'] ?? 0 );
        $amount    = (int) ( $_POST['amount']    ?? 0 );
        $note      = sanitize_text_field( $_POST['note'] ?? 'Admin deduct' );

        if ( $amount <= 0 ) wp_send_json_error( [ 'message' => 'Amount must be greater than zero.' ] );

        $balance_before = Noey_Token_Service::get_balance( $parent_id );
        if ( $amount > $balance_before ) {
            wp_send_json_error( [ 'message' => "Cannot deduct {$amount} — current balance is only {$balance_before}." ] );
        }

        $balance_after = $balance_before - $amount;
        update_user_meta( $parent_id, 'noey_token_balance', $balance_after );

        global $wpdb;
        $wpdb->insert(
            $wpdb->prefix . 'noey_token_ledger',
            [
                'user_id'       => $parent_id,
                'amount'        => -$amount,
                'balance_after' => $balance_after,
                'type'          => 'admin_deduct',
                'reference_id'  => null,
                'note'          => $note ?: 'Admin deduct',
                'created_at'    => current_time( 'mysql', true ),
            ],
            [ '%d', '%d', '%d', '%s', '%s', '%s', '%s' ]
        );

        Noey_Debug::log( 'admin.tokens', 'Tokens deducted', [ 'parent_id' => $parent_id, 'amount' => $amount ], null, 'info' );
        wp_send_json_success( [ 'balance_before' => $balance_before, 'balance_after' => $balance_after ] );
    }

    // ── AJAX: Set exact balance ───────────────────────────────────────────────

    public static function ajax_set(): void {
        check_ajax_referer( 'noey_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Forbidden', 403 );

        $parent_id = (int) ( $_POST['parent_id'] ?? 0 );
        $amount    = (int) ( $_POST['amount']    ?? 0 );
        $note      = sanitize_text_field( $_POST['note'] ?? 'Balance set by admin' );

        if ( $amount < 0 ) wp_send_json_error( [ 'message' => 'Balance cannot be negative.' ] );

        $balance_before = Noey_Token_Service::get_balance( $parent_id );
        $diff           = $amount - $balance_before;

        update_user_meta( $parent_id, 'noey_token_balance', $amount );

        global $wpdb;
        $wpdb->insert(
            $wpdb->prefix . 'noey_token_ledger',
            [
                'user_id'       => $parent_id,
                'amount'        => $diff,
                'balance_after' => $amount,
                'type'          => $diff >= 0 ? 'admin_credit' : 'admin_deduct',
                'reference_id'  => null,
                'note'          => $note ?: 'Balance set by admin',
                'created_at'    => current_time( 'mysql', true ),
            ],
            [ '%d', '%d', '%d', '%s', '%s', '%s', '%s' ]
        );

        Noey_Debug::log( 'admin.tokens', 'Balance set directly', [
            'parent_id' => $parent_id,
            'from'      => $balance_before,
            'to'        => $amount,
        ], null, 'info' );

        wp_send_json_success( [ 'balance_before' => $balance_before, 'balance_after' => $amount ] );
    }

    // ── AJAX: Ledger ──────────────────────────────────────────────────────────

    public static function ajax_ledger(): void {
        check_ajax_referer( 'noey_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Forbidden', 403 );

        $parent_id = (int) ( $_POST['parent_id'] ?? 0 );
        $rows      = Noey_Token_Service::get_ledger( $parent_id, 100 );

        ob_start();
        if ( empty( $rows ) ) {
            echo '<p style="color:#888;text-align:center;padding:10px 0;">No transactions yet.</p>';
        } else {
            $balance = Noey_Token_Service::get_balance( $parent_id );
            echo '<p style="font-size:13px;margin:0 0 12px;">Current balance: <strong>' . esc_html( $balance ) . ' tokens</strong></p>';
            ?>
            <table class="noey-table" style="font-size:12px;">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Type</th>
                        <th style="text-align:center;">Amount</th>
                        <th style="text-align:center;">Balance After</th>
                        <th>Note / Reference</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ( $rows as $row ) :
                    $amt_color = (int) $row['amount'] > 0 ? '#16a34a' : '#dc2626';
                    $amt_sign  = (int) $row['amount'] > 0 ? '+' : '';
                ?>
                <tr>
                    <td style="color:#888;white-space:nowrap;"><?= esc_html( date_i18n( 'M j, Y g:i a', strtotime( $row['created_at'] ) ) ) ?></td>
                    <td><span style="font-size:11px;background:#f0f0f0;padding:2px 6px;border-radius:3px;"><?= esc_html( self::type_label( $row['type'] ) ) ?></span></td>
                    <td style="text-align:center;font-weight:700;color:<?= $amt_color ?>;"><?= $amt_sign . esc_html( $row['amount'] ) ?></td>
                    <td style="text-align:center;font-weight:600;"><?= esc_html( $row['balance_after'] ) ?></td>
                    <td style="color:#666;font-size:11px;">
                        <?= $row['note'] ? esc_html( $row['note'] ) : '' ?>
                        <?= $row['reference_id'] ? '<code style="font-size:10px;background:#f5f5f5;padding:1px 4px;">' . esc_html( $row['reference_id'] ) . '</code>' : '' ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php
        }
        wp_send_json_success( [ 'html' => ob_get_clean() ] );
    }

    // ── AJAX: Bulk credit ─────────────────────────────────────────────────────

    public static function ajax_bulk_credit(): void {
        check_ajax_referer( 'noey_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Forbidden', 403 );

        $target = sanitize_key( $_POST['target'] ?? 'all' );
        $amount = (int) ( $_POST['amount'] ?? 0 );
        $note   = sanitize_text_field( $_POST['note'] ?? 'Bulk admin credit' );

        if ( $amount <= 0 ) wp_send_json_error( [ 'message' => 'Amount must be greater than zero.' ] );

        $parents  = get_users( [ 'role' => 'noey_parent', 'fields' => 'ID' ] );
        $credited = 0;

        foreach ( $parents as $pid ) {
            if ( $target === 'zero' && Noey_Token_Service::get_balance( $pid ) > 0 ) {
                continue;
            }
            Noey_Token_Service::credit( $pid, $amount, 'admin_credit', '', $note ?: 'Bulk admin credit' );
            $credited++;
        }

        Noey_Debug::log( 'admin.tokens', 'Bulk credit applied', [
            'target'   => $target,
            'amount'   => $amount,
            'credited' => $credited,
        ], null, 'info' );

        wp_send_json_success( [ 'credited' => $credited ] );
    }

    // ── AJAX: Monthly refresh ─────────────────────────────────────────────────

    public static function ajax_monthly_refresh(): void {
        check_ajax_referer( 'noey_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Forbidden', 403 );

        $refreshed = Noey_Token_Service::run_monthly_refresh();

        Noey_Debug::log( 'admin.tokens', 'Monthly refresh triggered from admin', [ 'refreshed' => $refreshed ], null, 'info' );
        wp_send_json_success( [ 'refreshed' => $refreshed ] );
    }

    // ── AJAX: Toggle premium ──────────────────────────────────────────────────

    public static function ajax_toggle_premium(): void {
        check_ajax_referer( 'noey_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Forbidden', 403 );

        $parent_id = (int) ( $_POST['parent_id'] ?? 0 );
        $premium   = (int) ( $_POST['premium']   ?? 0 );

        if ( $premium ) {
            update_user_meta( $parent_id, 'noey_premium', 1 );
        } else {
            delete_user_meta( $parent_id, 'noey_premium' );
        }

        Noey_Debug::log( 'admin.tokens', 'Premium status toggled', [ 'parent_id' => $parent_id, 'premium' => $premium ], null, 'info' );
        wp_send_json_success( [ 'premium' => (bool) $premium ] );
    }

    // ── AJAX: Dev bypass toggle ───────────────────────────────────────────────

    public static function ajax_bypass_toggle(): void {
        check_ajax_referer( 'noey_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Forbidden', 403 );

        $enabled = (int) ( $_POST['enabled'] ?? 0 );
        update_option( 'noey_dev_bypass_tokens', (bool) $enabled );

        Noey_Debug::log( 'admin.tokens', 'Dev bypass toggled', [ 'enabled' => $enabled ], null, 'info' );
        wp_send_json_success( [ 'enabled' => (bool) $enabled ] );
    }

    // ── AJAX: Recent transactions ─────────────────────────────────────────────

    public static function ajax_recent(): void {
        check_ajax_referer( 'noey_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Forbidden', 403 );

        global $wpdb;
        $rows = $wpdb->get_results(
            "SELECT l.*, u.display_name
             FROM {$wpdb->prefix}noey_token_ledger l
             LEFT JOIN {$wpdb->users} u ON u.ID = l.user_id
             ORDER BY l.ledger_id DESC
             LIMIT 50",
            ARRAY_A
        ) ?: [];

        ob_start();
        if ( empty( $rows ) ) {
            echo '<p style="color:#888;font-size:12px;">No transactions yet.</p>';
        } else {
            foreach ( $rows as $row ) :
                $color = (int) $row['amount'] > 0 ? '#16a34a' : '#dc2626';
                $sign  = (int) $row['amount'] > 0 ? '+' : '';
            ?>
            <div style="padding:6px 0;border-bottom:1px solid #f0f0f0;line-height:1.4;">
                <div style="display:flex;justify-content:space-between;">
                    <span style="font-weight:600;font-size:11px;"><?= esc_html( $row['display_name'] ?? 'Unknown' ) ?></span>
                    <span style="font-weight:700;color:<?= $color ?>;font-size:12px;"><?= $sign . esc_html( $row['amount'] ) ?></span>
                </div>
                <div style="font-size:10px;color:#888;">
                    <?= esc_html( self::type_label( $row['type'] ) ) ?> · bal: <?= esc_html( $row['balance_after'] ) ?>
                    · <?= esc_html( date_i18n( 'M j g:ia', strtotime( $row['created_at'] ) ) ) ?>
                </div>
            </div>
            <?php endforeach;
        }
        wp_send_json_success( [ 'html' => ob_get_clean() ] );
    }

    // ── Helper ────────────────────────────────────────────────────────────────

    private static function type_label( string $type ): string {
        return match ( $type ) {
            'purchase'       => 'Purchase',
            'exam_deduct'    => 'Exam',
            'registration'   => 'Registration',
            'monthly_refresh' => 'Monthly Reset',
            'admin_credit'   => 'Admin Credit',
            'admin_deduct'   => 'Admin Deduct',
            'refund'         => 'Refund',
            default          => ucwords( str_replace( '_', ' ', $type ) ),
        };
    }
}
