<?php
/**
 * Noey_Admin_Debug — Debug log viewer.
 *
 * Features:
 *  - Filter by level, context, user_id, search term
 *  - Paginated log table with expandable data panels
 *  - AJAX "Clear all logs" button
 *  - Auto-refresh toggle (every 10s)
 *
 * @package NoeyAPI
 */

defined( 'ABSPATH' ) || exit;

class Noey_Admin_Debug {

    const LOGS_PER_PAGE = 50;

    public static function render(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Insufficient permissions.' );
        }

        $level   = sanitize_key( $_GET['level'] ?? '' );
        $context = sanitize_text_field( $_GET['context'] ?? '' );
        $search  = sanitize_text_field( $_GET['search'] ?? '' );
        $user_id = (int) ( $_GET['user_id'] ?? 0 );
        $paged   = max( 1, (int) ( $_GET['paged'] ?? 1 ) );
        $offset  = ( $paged - 1 ) * self::LOGS_PER_PAGE;

        $filters = array_filter( [
            'level'   => $level,
            'context' => $context,
            'search'  => $search,
            'user_id' => $user_id ?: null,
        ] );

        $logs  = Noey_Debug::get_logs( array_merge( $filters, [ 'limit' => self::LOGS_PER_PAGE, 'offset' => $offset ] ) );
        $total = Noey_Debug::get_count( $filters );
        $pages = (int) ceil( $total / self::LOGS_PER_PAGE );
        ?>
        <div class="wrap noey-wrap">
            <h1>NoeyAPI — Debug Log
                <span class="noey-log-count"><?= esc_html( $total ) ?> entries</span>
            </h1>

            <?php if ( ! Noey_Debug::is_enabled() ) : ?>
                <div class="notice notice-warning">
                    <p>Debug mode is <strong>OFF</strong>. Enable it in <a href="<?= esc_url( admin_url( 'admin.php?page=noey-settings' ) ) ?>">Settings</a> to start capturing logs.</p>
                </div>
            <?php endif; ?>

            <!-- Filters -->
            <form method="get" action="" class="noey-filter-form">
                <input type="hidden" name="page" value="noey-debug" />
                <select name="level">
                    <option value="">All Levels</option>
                    <?php foreach ( Noey_Debug::LEVELS as $l ) : ?>
                        <option value="<?= esc_attr( $l ) ?>" <?= selected( $level, $l, false ) ?>><?= esc_html( ucfirst( $l ) ) ?></option>
                    <?php endforeach; ?>
                </select>
                <input type="text" name="context" value="<?= esc_attr( $context ) ?>" placeholder="Context filter (e.g. auth.login)" class="regular-text" />
                <input type="text" name="search"  value="<?= esc_attr( $search ) ?>"  placeholder="Search message…"  class="regular-text" />
                <input type="number" name="user_id" value="<?= esc_attr( $user_id ?: '' ) ?>" placeholder="User ID" class="small-text" />
                <button type="submit" class="button">Filter</button>
                <a href="<?= esc_url( admin_url( 'admin.php?page=noey-debug' ) ) ?>" class="button">Reset</a>
            </form>

            <!-- Actions -->
            <div class="noey-debug-actions">
                <button id="noey-clear-logs" class="button button-link-delete" data-nonce="<?= esc_attr( wp_create_nonce( 'noey_admin_nonce' ) ) ?>">
                    Clear All Logs
                </button>
                <label class="noey-autorefresh">
                    <input type="checkbox" id="noey-autorefresh" /> Auto-refresh (10s)
                </label>
            </div>

            <!-- Log Table -->
            <?php if ( empty( $logs ) ) : ?>
                <p>No log entries found.</p>
            <?php else : ?>
                <table class="noey-table noey-log-table">
                    <thead>
                        <tr>
                            <th style="width:90px">Time (UTC)</th>
                            <th style="width:70px">Level</th>
                            <th style="width:160px">Context</th>
                            <th>Message</th>
                            <th style="width:70px">User</th>
                            <th style="width:90px">Req ID</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ( $logs as $log ) :
                            $colour = Noey_Debug::level_colour( $log['level'] );
                            $has_data = ! empty( $log['data'] );
                            $row_id   = 'log-' . (int) $log['log_id'];
                        ?>
                        <tr class="noey-log-row level-<?= esc_attr( $log['level'] ) ?>" <?= $has_data ? "data-target=\"{$row_id}\" style=\"cursor:pointer\"" : '' ?>>
                            <td><code><?= esc_html( substr( $log['created_at'], 11, 8 ) ) ?></code><br><small><?= esc_html( substr( $log['created_at'], 0, 10 ) ) ?></small></td>
                            <td><span class="noey-level-badge" style="background:<?= esc_attr( $colour ) ?>"><?= esc_html( strtoupper( $log['level'] ) ) ?></span></td>
                            <td><code><?= esc_html( $log['context'] ) ?></code></td>
                            <td><?= esc_html( $log['message'] ) ?><?= $has_data ? ' <span class="noey-expand-hint">▾</span>' : '' ?></td>
                            <td><?= $log['user_id'] ? esc_html( $log['user_id'] ) : '—' ?></td>
                            <td><code style="font-size:10px"><?= esc_html( $log['request_id'] ) ?></code></td>
                        </tr>
                        <?php if ( $has_data ) : ?>
                        <tr id="<?= esc_attr( $row_id ) ?>" class="noey-log-data" style="display:none">
                            <td colspan="6">
                                <pre class="noey-json"><?= esc_html( json_encode( json_decode( $log['data'] ), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ) ) ?></pre>
                            </td>
                        </tr>
                        <?php endif; ?>
                        <?php endforeach; ?>
                    </tbody>
                </table>

                <!-- Pagination -->
                <?php if ( $pages > 1 ) : ?>
                <div class="noey-pagination">
                    <?php for ( $i = 1; $i <= $pages; $i++ ) :
                        $url = add_query_arg( array_merge( $_GET, [ 'paged' => $i ] ) );
                    ?>
                        <a href="<?= esc_url( $url ) ?>" class="button <?= $i === $paged ? 'button-primary' : '' ?>"><?= $i ?></a>
                    <?php endfor; ?>
                    <span class="noey-page-info">Page <?= $paged ?> of <?= $pages ?></span>
                </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>
        <?php
    }
}
