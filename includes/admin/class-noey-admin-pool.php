<?php
/**
 * Noey_Admin_Pool — Exam Pool Manager admin page.
 *
 * Features:
 *  - Pool overview stats (total packages, by standard / subject / difficulty)
 *  - Pool Inspector: expandable table of all combinations with pool counts,
 *    status badges, per-package detail (questions, answer sheet presence)
 *  - One-click Generate — calls Railway /generate-exam for a specific slot
 *  - Full Sync — imports all approved packages from Railway /pool endpoint
 *  - Manual JSON Upload — paste a raw package JSON to add it to the local pool
 *  - Live Railway Catalogue — shows Railway's live availability counts
 *
 * @package NoeyAPI
 */

defined( 'ABSPATH' ) || exit;

class Noey_Admin_Pool {

    // ── Boot ──────────────────────────────────────────────────────────────────

    public static function boot(): void {
        add_action( 'admin_post_noey_pool_sync',      [ __CLASS__, 'handle_sync' ] );
        add_action( 'admin_post_noey_pool_generate',  [ __CLASS__, 'handle_generate' ] );
        add_action( 'admin_post_noey_pool_upload',    [ __CLASS__, 'handle_upload' ] );
        add_action( 'admin_post_noey_pool_delete',    [ __CLASS__, 'handle_delete' ] );
    }

    // ── Render ────────────────────────────────────────────────────────────────

    public static function render(): void {
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Insufficient permissions.' );

        global $wpdb;

        $railway_ok   = ! empty( get_option( 'noey_railway_endpoint' ) );
        $server_key   = get_option( 'noey_railway_server_key', '' );
        $pool_table   = $wpdb->prefix . 'noey_exam_pool';

        // Stats
        $total_packages = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$pool_table}" );
        $total_served   = (int) $wpdb->get_var( "SELECT SUM(times_served) FROM {$pool_table}" );
        $has_answers    = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$pool_table} WHERE package_json LIKE '%answer_sheet%'" );

        // All combinations from pool
        $pool_summary = $wpdb->get_results(
            "SELECT standard, term, subject, difficulty, COUNT(*) as pool_count,
                    SUM(times_served) as total_served,
                    MAX(created_at) as latest_at
             FROM {$pool_table}
             GROUP BY standard, term, subject, difficulty
             ORDER BY standard, subject, difficulty",
            ARRAY_A
        ) ?: [];

        // Known combinations from Railway taxonomy
        $all_combinations = self::get_all_combinations();
        ?>
        <div class="wrap noey-wrap">
            <h1>NoeyAPI — Pool Manager</h1>

            <?php self::render_notices(); ?>

            <?php if ( ! $railway_ok ) : ?>
                <div class="notice notice-warning">
                    <p>Railway endpoint not configured. <a href="<?= esc_url( admin_url( 'admin.php?page=noey-settings' ) ) ?>">Configure in Settings →</a></p>
                </div>
            <?php endif; ?>

            <?php if ( $railway_ok && ! $server_key ) : ?>
                <div class="notice notice-warning">
                    <p>No <strong>Server Key</strong> configured. Packages will be imported <em>without</em> answer sheets. Set it in <a href="<?= esc_url( admin_url( 'admin.php?page=noey-settings' ) ) ?>">Settings</a> to receive answer sheets for server-side scoring.</p>
                </div>
            <?php endif; ?>

            <!-- Stats Row -->
            <div class="noey-stat-grid" style="grid-template-columns:repeat(4,1fr);margin-bottom:24px;">
                <div class="noey-stat-card">
                    <div class="noey-stat-number"><?= esc_html( $total_packages ) ?></div>
                    <div class="noey-stat-label">Packages in Pool</div>
                </div>
                <div class="noey-stat-card">
                    <div class="noey-stat-number"><?= esc_html( count( $pool_summary ) ) ?> <small style="font-size:16px;color:#888;">/ <?= count( $all_combinations ) ?></small></div>
                    <div class="noey-stat-label">Slots Filled / Total</div>
                </div>
                <div class="noey-stat-card">
                    <div class="noey-stat-number"><?= esc_html( $has_answers ) ?></div>
                    <div class="noey-stat-label">With Answer Sheet</div>
                </div>
                <div class="noey-stat-card">
                    <div class="noey-stat-number"><?= esc_html( number_format( $total_served ) ) ?></div>
                    <div class="noey-stat-label">Total Serves</div>
                </div>
            </div>

            <div style="display:grid;grid-template-columns:1fr 320px;gap:20px;align-items:start;">

                <!-- ── POOL INSPECTOR ───────────────────────────────────────── -->
                <div class="noey-settings-section" style="padding:0;overflow:hidden;">
                    <div style="padding:16px 20px;border-bottom:1px solid #eee;display:flex;align-items:center;justify-content:space-between;">
                        <h2 style="margin:0;font-size:15px;">Pool Inspector</h2>
                        <div style="display:flex;gap:8px;align-items:center;">
                            <input type="text" id="noey-pool-filter" placeholder="Filter by subject…" class="regular-text" style="height:30px;" />
                            <?php if ( $railway_ok ) : ?>
                            <form method="post" action="<?= esc_url( admin_url( 'admin-post.php' ) ) ?>" style="margin:0;">
                                <?php wp_nonce_field( 'noey_pool_sync', 'noey_sync_nonce' ); ?>
                                <input type="hidden" name="action" value="noey_pool_sync" />
                                <button type="submit" class="button button-primary">↓ Sync from Railway</button>
                            </form>
                            <?php endif; ?>
                        </div>
                    </div>

                    <table class="noey-table noey-pool-table" style="border:none;border-radius:0;">
                        <thead>
                            <tr>
                                <th>Standard</th>
                                <th>Term</th>
                                <th>Subject</th>
                                <th>Difficulty</th>
                                <th style="text-align:center;">Pool</th>
                                <th style="text-align:center;">Answers</th>
                                <th style="text-align:center;">Serves</th>
                                <th>Status</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php
                        // Index pool data by key
                        $pool_index = [];
                        foreach ( $pool_summary as $row ) {
                            $key = "{$row['standard']}|{$row['term']}|{$row['subject']}|{$row['difficulty']}";
                            $pool_index[ $key ] = $row;
                        }

                        foreach ( $all_combinations as $combo ) :
                            $key       = "{$combo['standard']}|{$combo['term']}|{$combo['subject_display']}|{$combo['difficulty']}";
                            $pool_row  = $pool_index[ $key ] ?? null;
                            $count     = $pool_row ? (int) $pool_row['pool_count'] : 0;
                            $serves    = $pool_row ? (int) $pool_row['total_served'] : 0;

                            // Count packages with answer_sheet for this slot
                            $ans_count = $count > 0 ? (int) $wpdb->get_var( $wpdb->prepare(
                                "SELECT COUNT(*) FROM {$pool_table}
                                 WHERE standard=%s AND term=%s AND subject=%s AND difficulty=%s
                                 AND package_json LIKE %s",
                                $combo['standard'], $combo['term'], $combo['subject_display'], $combo['difficulty'],
                                '%answer_sheet%'
                            ) ) : 0;

                            $status = $count === 0 ? 'empty' : ( $count < 3 ? 'low' : 'ready' );
                            $row_class = $count === 0 ? 'pool-empty' : '';
                        ?>
                        <tr class="noey-pool-row <?= esc_attr( $row_class ) ?>"
                            data-subject="<?= esc_attr( strtolower( $combo['subject'] ) ) ?>">
                            <td><?= esc_html( strtoupper( $combo['standard'] ) ) ?></td>
                            <td><?= esc_html( $combo['term'] ? strtoupper( $combo['term'] ) : 'SEA' ) ?></td>
                            <td><strong><?= esc_html( $combo['subject_display'] ) ?></strong></td>
                            <td><?= esc_html( ucfirst( $combo['difficulty'] ) ) ?></td>
                            <td style="text-align:center;font-weight:600;"><?= esc_html( $count ) ?></td>
                            <td style="text-align:center;">
                                <?php if ( $count > 0 ) : ?>
                                    <span style="color:<?= $ans_count === $count ? '#16a34a' : ( $ans_count > 0 ? '#d97706' : '#dc2626' ) ?>;">
                                        <?= esc_html( $ans_count ) ?>/<?= esc_html( $count ) ?>
                                    </span>
                                <?php else : ?>—<?php endif; ?>
                            </td>
                            <td style="text-align:center;color:#888;"><?= esc_html( $serves ) ?></td>
                            <td><?= self::status_badge( $status ) ?></td>
                            <td>
                                <div style="display:flex;gap:4px;flex-wrap:wrap;">
                                    <?php if ( $railway_ok ) : ?>
                                    <form method="post" action="<?= esc_url( admin_url( 'admin-post.php' ) ) ?>" style="margin:0;">
                                        <?php wp_nonce_field( 'noey_pool_generate', 'noey_gen_nonce' ); ?>
                                        <input type="hidden" name="action"     value="noey_pool_generate" />
                                        <input type="hidden" name="standard"   value="<?= esc_attr( $combo['standard'] ) ?>" />
                                        <input type="hidden" name="term"       value="<?= esc_attr( $combo['term'] ) ?>" />
                                        <input type="hidden" name="subject"    value="<?= esc_attr( $combo['subject_display'] ) ?>" />
                                        <input type="hidden" name="difficulty" value="<?= esc_attr( $combo['difficulty'] ) ?>" />
                                        <button type="submit" class="button button-small">Generate</button>
                                    </form>
                                    <?php endif; ?>
                                    <?php if ( $count > 0 ) : ?>
                                        <button class="button button-small noey-view-packages"
                                            data-key="<?= esc_attr( $key ) ?>">View</button>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>

                        <?php endforeach; ?>
                        </tbody>
                    </table>

                    <!-- Package Detail Drawer (hidden, populated via JS) -->
                    <div id="noey-package-drawer" style="display:none;padding:16px 20px;background:#f9fafb;border-top:1px solid #e5e7eb;">
                        <div id="noey-package-drawer-content"></div>
                    </div>
                </div>

                <!-- ── SIDEBAR ──────────────────────────────────────────────── -->
                <div style="display:flex;flex-direction:column;gap:16px;">

                    <!-- Manual Upload -->
                    <div class="noey-settings-section">
                        <h2>Manual Upload</h2>
                        <p style="font-size:12px;color:#666;margin-bottom:8px;">
                            Paste a raw Railway package JSON (with or without <code>answer_sheet</code>).
                        </p>
                        <form method="post" action="<?= esc_url( admin_url( 'admin-post.php' ) ) ?>">
                            <?php wp_nonce_field( 'noey_pool_upload', 'noey_upload_nonce' ); ?>
                            <input type="hidden" name="action" value="noey_pool_upload" />
                            <textarea name="noey_package_json" rows="8" class="large-text"
                                style="font-family:monospace;font-size:11px;"
                                placeholder='{"package_id":"pkg-...","meta":{...},"questions":[...]}'></textarea>
                            <button type="submit" class="button button-primary" style="margin-top:8px;width:100%;">
                                Add to Pool
                            </button>
                        </form>
                    </div>

                    <!-- Railway Live Catalogue -->
                    <?php if ( $railway_ok ) : ?>
                    <div class="noey-settings-section">
                        <h2>Railway Catalogue</h2>
                        <p style="font-size:12px;color:#666;margin-bottom:10px;">
                            Live availability from Railway server.
                        </p>
                        <button id="noey-load-railway-catalogue" class="button" style="width:100%;">
                            Load Live Catalogue
                        </button>
                        <div id="noey-railway-catalogue-result" style="margin-top:10px;font-size:12px;"></div>
                    </div>
                    <?php endif; ?>

                    <!-- Pool Health -->
                    <div class="noey-settings-section">
                        <h2>Quick Stats</h2>
                        <?php
                        $by_difficulty = $wpdb->get_results(
                            "SELECT difficulty, COUNT(*) as cnt FROM {$pool_table} GROUP BY difficulty",
                            ARRAY_A
                        ) ?: [];
                        $by_standard = $wpdb->get_results(
                            "SELECT standard, COUNT(*) as cnt FROM {$pool_table} GROUP BY standard ORDER BY standard",
                            ARRAY_A
                        ) ?: [];
                        ?>
                        <table style="width:100%;font-size:12px;border-collapse:collapse;">
                            <tr style="background:#f6f7f7;"><th style="padding:4px 6px;text-align:left;">Difficulty</th><th style="text-align:right;padding:4px 6px;">Packages</th></tr>
                            <?php foreach ( $by_difficulty as $d ) : ?>
                            <tr style="border-bottom:1px solid #f0f0f0;">
                                <td style="padding:4px 6px;"><?= esc_html( ucfirst( $d['difficulty'] ) ) ?></td>
                                <td style="text-align:right;padding:4px 6px;font-weight:600;"><?= esc_html( $d['cnt'] ) ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </table>
                        <table style="width:100%;font-size:12px;border-collapse:collapse;margin-top:12px;">
                            <tr style="background:#f6f7f7;"><th style="padding:4px 6px;text-align:left;">Standard</th><th style="text-align:right;padding:4px 6px;">Packages</th></tr>
                            <?php foreach ( $by_standard as $s ) : ?>
                            <tr style="border-bottom:1px solid #f0f0f0;">
                                <td style="padding:4px 6px;"><?= esc_html( strtoupper( $s['standard'] ) ) ?></td>
                                <td style="text-align:right;padding:4px 6px;font-weight:600;"><?= esc_html( $s['cnt'] ) ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </table>

                        <hr style="margin:12px 0;" />
                        <p style="font-size:12px;color:#666;margin:0 0 8px;">
                            Last pool sync: <strong><?= esc_html( get_option( 'noey_last_pool_sync', 'Never' ) ) ?></strong>
                        </p>
                        <a href="<?= esc_url( rest_url( NOEY_REST_NAMESPACE . '/exams' ) . '?_wpnonce=' . wp_create_nonce( 'wp_rest' ) ) ?>"
                           target="_blank" class="button button-small" style="width:100%;text-align:center;box-sizing:border-box;">
                            Test GET /exams →
                        </a>
                    </div>

                </div>
            </div>

            <!-- Package Detail Modal (populated by AJAX) -->
            <div id="noey-package-modal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:9999;overflow:auto;">
                <div style="background:#fff;max-width:900px;margin:40px auto;border-radius:8px;overflow:hidden;box-shadow:0 20px 60px rgba(0,0,0,.3);">
                    <div style="padding:16px 20px;background:#f6f7f7;border-bottom:1px solid #ddd;display:flex;justify-content:space-between;align-items:center;">
                        <h2 id="noey-modal-title" style="margin:0;font-size:15px;">Package Detail</h2>
                        <button id="noey-modal-close" class="button">✕ Close</button>
                    </div>
                    <div id="noey-modal-body" style="padding:20px;max-height:70vh;overflow:auto;"></div>
                </div>
            </div>
        </div>

        <script>
        ( function($) {
            // Filter table
            $('#noey-pool-filter').on('input', function() {
                var q = $(this).val().toLowerCase();
                $('.noey-pool-row').each(function() {
                    $(this).toggle( !q || $(this).data('subject').indexOf(q) >= 0 );
                });
            });

            // View packages — fetch and show modal
            $(document).on('click', '.noey-view-packages', function() {
                var key = $(this).data('key');
                var parts = key.split('|');
                var $btn = $(this).prop('disabled', true).text('Loading…');

                $.post(ajaxurl, {
                    action: 'noey_pool_packages',
                    nonce: '<?= wp_create_nonce( 'noey_admin_nonce' ) ?>',
                    standard: parts[0],
                    term: parts[1],
                    subject: parts[2],
                    difficulty: parts[3]
                }, function(res) {
                    $btn.prop('disabled', false).text('View');
                    if (res.success) {
                        renderModal(parts[2] + ' — ' + parts[3] + ' (' + parts[0].toUpperCase() + ' ' + parts[1].toUpperCase() + ')', res.data.html);
                    } else {
                        alert('Failed to load packages.');
                    }
                });
            });

            // Close modal
            $('#noey-modal-close').on('click', function() { $('#noey-package-modal').hide(); });
            $('#noey-package-modal').on('click', function(e) { if ($(e.target).is(this)) $(this).hide(); });

            function renderModal(title, html) {
                $('#noey-modal-title').text(title);
                $('#noey-modal-body').html(html);
                $('#noey-package-modal').show();
            }

            // Load Railway catalogue
            $('#noey-load-railway-catalogue').on('click', function() {
                var $btn = $(this).prop('disabled', true).text('Loading…');
                var $result = $('#noey-railway-catalogue-result');

                $.post(ajaxurl, {
                    action: 'noey_railway_catalogue',
                    nonce: '<?= wp_create_nonce( 'noey_admin_nonce' ) ?>'
                }, function(res) {
                    $btn.prop('disabled', false).text('Load Live Catalogue');
                    if (res.success) {
                        var html = '<table style="width:100%;border-collapse:collapse;">';
                        html += '<tr style="background:#f6f7f7;font-weight:600;"><td style="padding:3px 6px;">Slot</td><td style="padding:3px 6px;text-align:right;">Count</td></tr>';
                        $.each(res.data.catalogue, function(i, row) {
                            var color = row.available_count > 0 ? '#16a34a' : '#dc2626';
                            html += '<tr style="border-bottom:1px solid #f0f0f0;">';
                            html += '<td style="padding:3px 6px;font-size:11px;">' + row.subject + ' ' + row.difficulty + ' ' + (row.standard || '') + '</td>';
                            html += '<td style="padding:3px 6px;text-align:right;font-weight:600;color:' + color + ';">' + row.available_count + '</td>';
                            html += '</tr>';
                        });
                        html += '</table>';
                        $result.html(html);
                    } else {
                        $result.html('<p style="color:#dc2626;">Failed: ' + (res.data.message || 'Unknown error') + '</p>');
                    }
                });
            });
        })(jQuery);
        </script>
        <?php
    }

    // ── Action Handlers ───────────────────────────────────────────────────────

    public static function handle_sync(): void {
        check_admin_referer( 'noey_pool_sync', 'noey_sync_nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Forbidden' );

        Noey_Debug::log( 'admin.pool', 'Full sync triggered from admin', [], null, 'info' );

        $result = self::sync_from_railway();

        if ( is_wp_error( $result ) ) {
            wp_safe_redirect( admin_url( 'admin.php?page=noey-pool&sync_error=' . urlencode( $result->get_error_message() ) ) );
        } else {
            wp_safe_redirect( admin_url( 'admin.php?page=noey-pool&synced=' . urlencode( wp_json_encode( $result ) ) ) );
        }
        exit;
    }

    public static function handle_generate(): void {
        check_admin_referer( 'noey_pool_generate', 'noey_gen_nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Forbidden' );

        $standard   = sanitize_text_field( $_POST['standard']   ?? '' );
        $term       = sanitize_text_field( $_POST['term']       ?? '' );
        $subject    = sanitize_text_field( $_POST['subject']    ?? '' );
        $difficulty = sanitize_text_field( $_POST['difficulty'] ?? '' );

        Noey_Debug::log( 'admin.pool', 'Generate triggered from admin', [
            'standard'   => $standard,
            'term'       => $term,
            'subject'    => $subject,
            'difficulty' => $difficulty,
        ], null, 'info' );

        // Get existing package IDs to exclude from Railway
        global $wpdb;
        $seen = $wpdb->get_col( $wpdb->prepare(
            "SELECT package_id FROM {$wpdb->prefix}noey_exam_pool
             WHERE standard=%s AND term=%s AND subject=%s AND difficulty=%s",
            $standard, $term, $subject, $difficulty
        ) ) ?: [];

        $package = Noey_Exam_Service::fetch_from_railway( $standard, $term, $subject, $difficulty, $seen );

        if ( is_wp_error( $package ) ) {
            wp_safe_redirect( admin_url( 'admin.php?page=noey-pool&gen_error=' . urlencode( $package->get_error_message() ) ) );
        } else {
            // Store in pool
            self::store_package( $package, $standard, $term, $subject, $difficulty );
            wp_safe_redirect( admin_url( 'admin.php?page=noey-pool&generated=' . urlencode( $package['package_id'] ?? 'ok' ) ) );
        }
        exit;
    }

    public static function handle_upload(): void {
        check_admin_referer( 'noey_pool_upload', 'noey_upload_nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Forbidden' );

        $json = wp_unslash( $_POST['noey_package_json'] ?? '' );
        if ( ! trim( $json ) ) {
            wp_safe_redirect( admin_url( 'admin.php?page=noey-pool&upload_error=' . urlencode( 'No JSON provided.' ) ) );
            exit;
        }

        $pkg = json_decode( $json, true );
        if ( ! $pkg || empty( $pkg['package_id'] ) ) {
            wp_safe_redirect( admin_url( 'admin.php?page=noey-pool&upload_error=' . urlencode( 'Invalid JSON or missing package_id.' ) ) );
            exit;
        }

        $meta     = $pkg['meta'] ?? [];
        $standard = sanitize_text_field( $meta['standard'] ?? '' );
        $term     = sanitize_text_field( $meta['term']     ?? '' );
        $subject  = sanitize_text_field( $meta['subject']  ?? '' );
        $diff     = sanitize_text_field( $meta['difficulty'] ?? 'medium' );

        // Map Railway subject slug back to display name
        $subject_display = self::subject_to_display( $subject );

        self::store_package( $pkg, $standard, $term, $subject_display, $diff );

        Noey_Debug::log( 'admin.pool', 'Package uploaded via admin', [
            'package_id' => $pkg['package_id'],
            'standard'   => $standard,
            'subject'    => $subject_display,
        ], null, 'info' );

        wp_safe_redirect( admin_url( 'admin.php?page=noey-pool&uploaded=' . urlencode( $pkg['package_id'] ) ) );
        exit;
    }

    public static function handle_delete(): void {
        check_admin_referer( 'noey_pool_delete', 'noey_del_nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Forbidden' );

        $pool_id = (int) ( $_POST['pool_id'] ?? 0 );
        if ( ! $pool_id ) wp_die( 'Invalid pool_id' );

        global $wpdb;
        $wpdb->delete( $wpdb->prefix . 'noey_exam_pool', [ 'pool_id' => $pool_id ], [ '%d' ] );

        Noey_Debug::log( 'admin.pool', 'Package deleted from pool', [ 'pool_id' => $pool_id ], null, 'info' );
        wp_safe_redirect( admin_url( 'admin.php?page=noey-pool&deleted=1' ) );
        exit;
    }

    // ── AJAX handlers (registered in Noey_Admin) ──────────────────────────────

    public static function handle_ajax_packages(): void {
        check_ajax_referer( 'noey_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Forbidden', 403 );

        global $wpdb;
        $standard   = sanitize_text_field( $_POST['standard']   ?? '' );
        $term       = sanitize_text_field( $_POST['term']       ?? '' );
        $subject    = sanitize_text_field( $_POST['subject']    ?? '' );
        $difficulty = sanitize_text_field( $_POST['difficulty'] ?? '' );

        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}noey_exam_pool
             WHERE standard=%s AND term=%s AND subject=%s AND difficulty=%s
             ORDER BY created_at DESC",
            $standard, $term, $subject, $difficulty
        ), ARRAY_A ) ?: [];

        ob_start();
        self::render_package_list( $rows );
        $html = ob_get_clean();

        wp_send_json_success( [ 'html' => $html, 'count' => count( $rows ) ] );
    }

    public static function handle_ajax_railway_catalogue(): void {
        check_ajax_referer( 'noey_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Forbidden', 403 );

        $endpoint = rtrim( get_option( 'noey_railway_endpoint', '' ), '/' );
        if ( ! $endpoint ) {
            wp_send_json_error( [ 'message' => 'Railway endpoint not configured.' ] );
        }

        // GET /catalogue is public — no auth needed
        $response = wp_remote_get( "{$endpoint}/catalogue", [ 'timeout' => 15 ] );

        if ( is_wp_error( $response ) ) {
            wp_send_json_error( [ 'message' => $response->get_error_message() ] );
        }

        $code = wp_remote_retrieve_response_code( $response );
        $body = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( $code !== 200 || ! is_array( $body ) ) {
            wp_send_json_error( [ 'message' => "Railway returned HTTP {$code}." ] );
        }

        wp_send_json_success( [ 'catalogue' => $body ] );
    }

    // ── Sync Logic ────────────────────────────────────────────────────────────

    private static function sync_from_railway(): array|WP_Error {
        $endpoint   = rtrim( get_option( 'noey_railway_endpoint', '' ), '/' );
        $api_key    = get_option( 'noey_railway_api_key', '' );
        $server_key = get_option( 'noey_railway_server_key', '' );

        if ( ! $endpoint ) {
            return new WP_Error( 'noey_not_configured', 'Railway endpoint not configured.' );
        }

        $added   = 0;
        $skipped = 0;
        $errors  = [];
        $offset  = 0;
        $limit   = 50;

        do {
            Noey_Debug::log( 'admin.pool.sync', 'Fetching pool page', [
                'offset' => $offset,
                'limit'  => $limit,
            ], null, 'info' );

            $headers = [
                'Authorization' => "Bearer {$api_key}",
            ];
            if ( $server_key ) {
                $headers['X-AEP-Server-Key'] = $server_key;
            }

            $response = wp_remote_get(
                add_query_arg( [ 'status' => 'approved', 'limit' => $limit, 'offset' => $offset ], "{$endpoint}/pool" ),
                [ 'timeout' => 30, 'headers' => $headers ]
            );

            if ( is_wp_error( $response ) ) {
                return $response;
            }

            $code = wp_remote_retrieve_response_code( $response );
            $body = json_decode( wp_remote_retrieve_body( $response ), true );

            if ( $code !== 200 || empty( $body['packages'] ) ) {
                break;
            }

            foreach ( $body['packages'] as $pkg ) {
                if ( empty( $pkg['package_id'] ) ) continue;

                $meta     = $pkg['meta'] ?? [];
                $standard = sanitize_text_field( $meta['standard'] ?? '' );
                $term     = sanitize_text_field( $meta['term']     ?? '' );
                $subject  = self::subject_to_display( $meta['subject'] ?? '' );
                $diff     = sanitize_text_field( $meta['difficulty'] ?? 'medium' );

                $inserted = self::store_package( $pkg, $standard, $term, $subject, $diff );
                if ( $inserted ) {
                    $added++;
                } else {
                    $skipped++;
                }
            }

            $total_available = (int) ( $body['total'] ?? 0 );
            $offset         += $limit;

        } while ( $offset < $total_available );

        update_option( 'noey_last_pool_sync', current_time( 'mysql', true ) );

        Noey_Debug::log( 'admin.pool.sync', 'Railway sync complete', [
            'added'   => $added,
            'skipped' => $skipped,
        ], null, 'info' );

        return [
            'added'   => $added,
            'skipped' => $skipped,
        ];
    }

    // ── Package Storage ───────────────────────────────────────────────────────

    private static function store_package( array $pkg, string $standard, string $term, string $subject, string $difficulty ): bool {
        global $wpdb;

        $package_id = sanitize_text_field( $pkg['package_id'] ?? '' );
        if ( ! $package_id ) return false;

        // Skip if already exists
        $exists = $wpdb->get_var( $wpdb->prepare(
            "SELECT pool_id FROM {$wpdb->prefix}noey_exam_pool WHERE package_id = %s",
            $package_id
        ) );
        if ( $exists ) return false;

        $wpdb->insert(
            $wpdb->prefix . 'noey_exam_pool',
            [
                'package_id'   => $package_id,
                'standard'     => $standard,
                'term'         => $term,
                'subject'      => $subject,
                'difficulty'   => in_array( $difficulty, [ 'easy', 'medium', 'hard' ], true ) ? $difficulty : 'medium',
                'package_json' => wp_json_encode( $pkg ),
                'created_at'   => current_time( 'mysql', true ),
            ],
            [ '%s', '%s', '%s', '%s', '%s', '%s', '%s' ]
        );

        return (bool) $wpdb->insert_id;
    }

    // ── Package List HTML (for modal) ─────────────────────────────────────────

    private static function render_package_list( array $rows ): void {
        if ( empty( $rows ) ) {
            echo '<p style="color:#888;">No packages found.</p>';
            return;
        }
        foreach ( $rows as $row ) :
            $pkg          = json_decode( $row['package_json'], true ) ?? [];
            $questions    = $pkg['questions'] ?? [];
            $has_answers  = ! empty( $pkg['answer_sheet'] );
            $topics       = $pkg['meta']['topics_covered'] ?? [];
        ?>
        <div style="background:#fff;border:1px solid #e5e7eb;border-radius:6px;padding:14px;margin-bottom:12px;">
            <div style="display:flex;gap:6px;flex-wrap:wrap;align-items:center;margin-bottom:10px;">
                <code style="font-size:11px;background:#f0f0f0;padding:2px 6px;border-radius:3px;"><?= esc_html( $row['package_id'] ) ?></code>
                <span style="font-size:11px;color:#666;">Created: <?= esc_html( $row['created_at'] ) ?></span>
                <span style="font-size:11px;color:#666;">Served: <?= esc_html( $row['times_served'] ) ?>×</span>
                <span style="font-size:11px;font-weight:600;color:<?= $has_answers ? '#16a34a' : '#dc2626' ?>;">
                    <?= $has_answers ? '✓ Answer sheet present' : '✗ No answer sheet' ?>
                </span>
                <?php if ( $topics ) : ?>
                <span style="font-size:11px;color:#666;">Topics: <?= esc_html( implode( ', ', (array) $topics ) ) ?></span>
                <?php endif; ?>
            </div>

            <?php if ( ! empty( $questions ) ) : ?>
            <details>
                <summary style="cursor:pointer;font-size:12px;color:#2563eb;user-select:none;">
                    ▶ <?= count( $questions ) ?> questions
                </summary>
                <table class="noey-table" style="margin-top:8px;font-size:11px;">
                    <thead>
                        <tr><th>ID</th><th>Topic</th><th>Subtopic</th><th>Level</th><th>Answer</th></tr>
                    </thead>
                    <tbody>
                    <?php foreach ( $questions as $q ) : ?>
                        <tr>
                            <td><code><?= esc_html( $q['question_id'] ?? '?' ) ?></code></td>
                            <td><?= esc_html( $q['meta']['topic'] ?? '' ) ?></td>
                            <td style="color:#888;"><?= esc_html( $q['meta']['subtopic'] ?? '' ) ?></td>
                            <td><?= esc_html( $q['meta']['cognitive_level'] ?? '' ) ?></td>
                            <td style="font-weight:700;color:#16a34a;"><?= esc_html( $q['correct_answer'] ?? '' ) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </details>
            <?php endif; ?>

            <!-- Delete button -->
            <form method="post" action="<?= esc_url( admin_url( 'admin-post.php' ) ) ?>" style="margin-top:10px;">
                <?php wp_nonce_field( 'noey_pool_delete', 'noey_del_nonce' ); ?>
                <input type="hidden" name="action"  value="noey_pool_delete" />
                <input type="hidden" name="pool_id" value="<?= esc_attr( $row['pool_id'] ) ?>" />
                <button type="submit" class="button button-small"
                    onclick="return confirm('Delete this package from the pool?')"
                    style="color:#dc2626;border-color:#dc2626;">
                    Delete Package
                </button>
            </form>
        </div>
        <?php
        endforeach;
    }

    // ── Notices ───────────────────────────────────────────────────────────────

    private static function render_notices(): void {
        if ( isset( $_GET['synced'] ) ) :
            $r = json_decode( urldecode( $_GET['synced'] ), true );
        ?>
            <div class="notice notice-success is-dismissible">
                <p>Sync complete — <strong><?= (int) ( $r['added'] ?? 0 ) ?> added</strong>, <?= (int) ( $r['skipped'] ?? 0 ) ?> already in pool.</p>
            </div>
        <?php elseif ( isset( $_GET['sync_error'] ) ) : ?>
            <div class="notice notice-error is-dismissible"><p>Sync failed: <?= esc_html( urldecode( $_GET['sync_error'] ) ) ?></p></div>
        <?php elseif ( isset( $_GET['generated'] ) ) : ?>
            <div class="notice notice-success is-dismissible"><p>Package generated: <code><?= esc_html( urldecode( $_GET['generated'] ) ) ?></code></p></div>
        <?php elseif ( isset( $_GET['gen_error'] ) ) : ?>
            <div class="notice notice-error is-dismissible"><p>Generation failed: <?= esc_html( urldecode( $_GET['gen_error'] ) ) ?></p></div>
        <?php elseif ( isset( $_GET['uploaded'] ) ) : ?>
            <div class="notice notice-success is-dismissible"><p>Package uploaded: <code><?= esc_html( urldecode( $_GET['uploaded'] ) ) ?></code></p></div>
        <?php elseif ( isset( $_GET['upload_error'] ) ) : ?>
            <div class="notice notice-error is-dismissible"><p>Upload failed: <?= esc_html( urldecode( $_GET['upload_error'] ) ) ?></p></div>
        <?php elseif ( isset( $_GET['deleted'] ) ) : ?>
            <div class="notice notice-success is-dismissible"><p>Package removed from pool.</p></div>
        <?php endif;
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /**
     * All 48 valid curriculum combinations.
     * Std4: 4 subjects × 3 terms × 3 difficulties = 36
     * Std5: 4 subjects × 3 difficulties            = 12
     */
    public static function get_all_combinations(): array {
        $combinations = [];
        $subjects     = [
            'math'          => 'Mathematics',
            'english'       => 'English Language Arts',
            'science'       => 'Science',
            'social_studies' => 'Social Studies',
        ];
        $difficulties = [ 'easy', 'medium', 'hard' ];

        // Std 4 — term-scoped
        foreach ( [ 'term_1', 'term_2', 'term_3' ] as $term ) {
            foreach ( $subjects as $slug => $display ) {
                foreach ( $difficulties as $diff ) {
                    $combinations[] = [
                        'standard'        => 'std_4',
                        'term'            => $term,
                        'subject'         => $slug,
                        'subject_display' => $display,
                        'difficulty'      => $diff,
                    ];
                }
            }
        }

        // Std 5 — SEA prep, no term
        foreach ( $subjects as $slug => $display ) {
            foreach ( $difficulties as $diff ) {
                $combinations[] = [
                    'standard'        => 'std_5',
                    'term'            => '',
                    'subject'         => $slug,
                    'subject_display' => $display,
                    'difficulty'      => $diff,
                ];
            }
        }

        return $combinations;
    }

    private static function subject_to_display( string $slug ): string {
        return match ( strtolower( $slug ) ) {
            'math'           => 'Mathematics',
            'english'        => 'English Language Arts',
            'science'        => 'Science',
            'social_studies' => 'Social Studies',
            default          => ucwords( str_replace( '_', ' ', $slug ) ),
        };
    }

    private static function status_badge( string $status ): string {
        [ $colour, $label ] = match ( $status ) {
            'ready'   => [ '#16a34a', '● Ready' ],
            'low'     => [ '#d97706', '● Low' ],
            default   => [ '#dc2626', '● Empty' ],
        };
        return "<span style='color:{$colour};font-weight:600;font-size:11px;'>{$label}</span>";
    }
}
