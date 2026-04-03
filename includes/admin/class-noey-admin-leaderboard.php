<?php
/**
 * Noey_Admin_Leaderboard — Leaderboard admin panel.
 *
 * v2.1 changes:
 *  - Testing tab: added Simulate Submit Upsert (mirrors real exam submit flow)
 *  - Testing tab: added Read My Boards by Child ID (mirrors React /leaderboard/me)
 *  - Testing tab: all tests show raw Railway response inline
 *  - Testing tab: architecture notice confirms WP → Railway → Supabase routing
 *  - No test writes directly to Supabase — all go through Railway service layer
 *
 * Tabs:
 *   1. Today's Boards    — browse top 10 per subject board
 *   2. Nickname Mgmt     — search, view, regenerate child nicknames
 *   3. Testing           — full test suite mirroring React frontend use cases
 *
 * @package NoeyAPI
 */

defined( 'ABSPATH' ) || exit;

class Noey_Admin_Leaderboard {

    // ── Boot ──────────────────────────────────────────────────────────────────

    public static function register(): void {
        add_action( 'admin_menu', [ __CLASS__, 'add_menu' ] );
        add_action( 'admin_post_noey_regenerate_nickname',   [ __CLASS__, 'handle_regenerate_nickname' ] );
        add_action( 'admin_post_noey_lb_inject_entry',       [ __CLASS__, 'handle_inject_entry' ] );
        add_action( 'admin_post_noey_lb_simulate_upsert',    [ __CLASS__, 'handle_simulate_upsert' ] );
        add_action( 'admin_post_noey_lb_reset_board',        [ __CLASS__, 'handle_reset_board' ] );
        add_action( 'admin_post_noey_lb_daily_reset',        [ __CLASS__, 'handle_daily_reset' ] );
        add_action( 'admin_post_noey_lb_generate_nickname',  [ __CLASS__, 'handle_generate_nickname' ] );
    }

    public static function add_menu(): void {
        add_submenu_page(
            'noey-api',
            'Leaderboards',
            'Leaderboards',
            'manage_options',
            'noey-leaderboard',
            [ __CLASS__, 'render' ]
        );
    }

    // ── Render ────────────────────────────────────────────────────────────────

    public static function render(): void {
        $active_tab = sanitize_text_field( $_GET['tab'] ?? 'boards' );
        ?>
        <div class="wrap">
            <h1>NoeyAI — Leaderboards</h1>

            <nav class="nav-tab-wrapper">
                <?php foreach ( [
                    'boards'    => "Today's Boards",
                    'nicknames' => 'Nickname Management',
                    'testing'   => '🧪 Testing',
                ] as $tab => $label ) : ?>
                    <a href="<?php echo esc_url( self::tab_url( $tab ) ); ?>"
                       class="nav-tab <?php echo $active_tab === $tab ? 'nav-tab-active' : ''; ?>">
                        <?php echo esc_html( $label ); ?>
                    </a>
                <?php endforeach; ?>
            </nav>

            <div class="tab-content" style="margin-top: 20px;">
                <?php
                match ( $active_tab ) {
                    'nicknames' => self::render_nicknames_tab(),
                    'testing'   => self::render_testing_tab(),
                    default     => self::render_boards_tab(),
                };
                ?>
            </div>
        </div>
        <?php
    }

    // ── Tab 1: Today's Boards ─────────────────────────────────────────────────

    private static function render_boards_tab(): void {
        $standard = sanitize_text_field( $_GET['standard'] ?? '' );
        $term     = sanitize_text_field( $_GET['term']     ?? 'none' );
        $subject  = sanitize_text_field( $_GET['subject']  ?? '' );

        $standards = self::standards();
        $terms     = self::terms();
        $subjects  = self::subjects();

        $board_data = null;
        $error      = null;

        if ( $standard && $subject ) {
            $result = Noey_Leaderboard_Service::get_board( $standard, $term, $subject );
            if ( is_wp_error( $result ) ) {
                $error = $result->get_error_message();
            } else {
                $board_data = $result;
            }
        }
        ?>

        <p style="color: #555; margin-bottom: 16px;">
            Points accumulate across the day. A student's total reflects all exams completed today in this subject.
        </p>

        <form method="GET" action="">
            <input type="hidden" name="page" value="noey-leaderboard">
            <input type="hidden" name="tab"  value="boards">

            <table class="form-table" style="max-width: 600px;">
                <tr>
                    <th><label for="standard">Standard</label></th>
                    <td>
                        <select name="standard" id="standard">
                            <option value="">— Select —</option>
                            <?php foreach ( $standards as $val => $label ) : ?>
                                <option value="<?php echo esc_attr( $val ); ?>" <?php selected( $standard, $val ); ?>>
                                    <?php echo esc_html( $label ); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th><label for="term">Term</label></th>
                    <td>
                        <select name="term" id="term">
                            <?php foreach ( $terms as $val => $label ) : ?>
                                <option value="<?php echo esc_attr( $val ); ?>" <?php selected( $term, $val ); ?>>
                                    <?php echo esc_html( $label ); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <p class="description">Select "—" for Standard 5 boards.</p>
                    </td>
                </tr>
                <tr>
                    <th><label for="subject">Subject</label></th>
                    <td>
                        <select name="subject" id="subject">
                            <option value="">— Select —</option>
                            <?php foreach ( $subjects as $val => $label ) : ?>
                                <option value="<?php echo esc_attr( $val ); ?>" <?php selected( $subject, $val ); ?>>
                                    <?php echo esc_html( $label ); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                </tr>
            </table>

            <?php submit_button( 'View Board', 'primary', 'submit', false ); ?>
        </form>

        <?php if ( $error ) : ?>
            <div class="notice notice-error" style="margin-top: 16px;"><p><?php echo esc_html( $error ); ?></p></div>
        <?php endif; ?>

        <?php if ( $board_data ) : ?>
            <hr style="margin: 24px 0;">
            <h2 style="margin-bottom: 4px;">
                <?php
                $label_standard = $standards[ $standard ] ?? strtoupper( $standard );
                $label_term     = $term !== 'none' ? ' / ' . ucwords( str_replace( '_', ' ', $term ) ) : '';
                $label_subject  = $subjects[ $subject ] ?? ucfirst( $subject );
                echo esc_html( "{$label_subject} — {$label_standard}{$label_term}" );
                ?>
            </h2>
            <p style="color: #666; margin-top: 0;">
                Date: <strong><?php echo esc_html( $board_data['date'] ?? '—' ); ?></strong>
                &nbsp;|&nbsp;
                Participants today: <strong><?php echo esc_html( $board_data['total_participants'] ?? 0 ); ?></strong>
            </p>

            <?php $entries = $board_data['entries'] ?? []; ?>

            <?php if ( empty( $entries ) ) : ?>
                <p>No entries on this board today.</p>
            <?php else : ?>
                <table class="wp-list-table widefat fixed striped" style="max-width: 650px;">
                    <thead>
                        <tr>
                            <th style="width:55px;">Rank</th>
                            <th>Nickname</th>
                            <th>Total Points</th>
                            <th>Last Score</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ( $entries as $entry ) : ?>
                            <tr>
                                <td>
                                    <?php
                                    $rank = (int) ( $entry['rank'] ?? 0 );
                                    echo esc_html( match ( $rank ) { 1 => '🥇', 2 => '🥈', 3 => '🥉', default => "#{$rank}" } );
                                    ?>
                                </td>
                                <td><?php echo esc_html( $entry['nickname'] ?? '—' ); ?></td>
                                <td><strong><?php echo esc_html( $entry['total_points'] ?? 0 ); ?></strong></td>
                                <td><?php echo esc_html( ( $entry['last_score_pct'] ?? 0 ) . '%' ); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        <?php endif; ?>
        <?php
    }

    // ── Tab 2: Nicknames ──────────────────────────────────────────────────────

    private static function render_nicknames_tab(): void {
        $updated  = sanitize_text_field( $_GET['updated']     ?? '' );
        $error    = sanitize_text_field( $_GET['error']       ?? '' );
        $t_result = sanitize_text_field( $_GET['test_result'] ?? '' );
        $t_error  = sanitize_text_field( $_GET['test_error']  ?? '' );
        $search   = sanitize_text_field( $_GET['search']      ?? '' );
        $children = $search ? self::search_children( $search ) : [];
        ?>

        <?php if ( $updated === '1' || $t_result ) : ?>
            <div class="notice notice-success is-dismissible">
                <p><?php echo esc_html( $t_result ? urldecode( $t_result ) : 'Nickname updated successfully.' ); ?></p>
            </div>
        <?php endif; ?>
        <?php if ( $error || $t_error ) : ?>
            <div class="notice notice-error is-dismissible">
                <p>Error: <?php echo esc_html( urldecode( $error ?: $t_error ) ); ?></p>
            </div>
        <?php endif; ?>

        <form method="GET" action="" style="margin-bottom: 20px;">
            <input type="hidden" name="page" value="noey-leaderboard">
            <input type="hidden" name="tab"  value="nicknames">
            <input type="text" name="search" value="<?php echo esc_attr( $search ); ?>"
                   placeholder="Search by display name or child ID…"
                   style="width: 340px; margin-right: 8px;">
            <?php submit_button( 'Search', 'secondary', 'submit', false ); ?>
        </form>

        <?php if ( $search && empty( $children ) ) : ?>
            <p>No children found for "<strong><?php echo esc_html( $search ); ?></strong>".</p>
        <?php endif; ?>

        <?php if ( ! empty( $children ) ) : ?>
            <table class="wp-list-table widefat fixed striped" style="max-width: 860px;">
                <thead>
                    <tr>
                        <th style="width:55px;">ID</th>
                        <th>Display Name</th>
                        <th>Class</th>
                        <th>Nickname</th>
                        <th>Status</th>
                        <th style="width:200px;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ( $children as $child ) : ?>
                        <?php
                        $child_id = (int) $child['child_id'];
                        $nickname = get_user_meta( $child_id, 'noey_nickname', true );
                        $pending  = get_user_meta( $child_id, 'noey_nickname_pending', true );
                        ?>
                        <tr>
                            <td><?php echo esc_html( $child_id ); ?></td>
                            <td><?php echo esc_html( $child['display_name'] ); ?></td>
                            <td>
                                <?php
                                echo esc_html( strtoupper( $child['standard'] ?? '—' ) );
                                if ( ! empty( $child['term'] ) ) {
                                    echo ' / ' . esc_html( ucwords( str_replace( '_', ' ', $child['term'] ) ) );
                                }
                                ?>
                            </td>
                            <td>
                                <?php if ( $nickname ) : ?>
                                    <code><?php echo esc_html( $nickname ); ?></code>
                                <?php else : ?>
                                    <em style="color:#999;">None</em>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ( $pending ) : ?>
                                    <span style="color:#d97706;">⚠ Pending</span>
                                <?php elseif ( $nickname ) : ?>
                                    <span style="color:#16a34a;">✓ Active</span>
                                <?php else : ?>
                                    <span style="color:#dc2626;">✗ Missing</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ( ! $nickname ) : ?>
                                    <form method="POST" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline; margin-right:6px;">
                                        <?php wp_nonce_field( 'noey_lb_generate_nickname_' . $child_id, '_nonce' ); ?>
                                        <input type="hidden" name="action"   value="noey_lb_generate_nickname">
                                        <input type="hidden" name="child_id" value="<?php echo esc_attr( $child_id ); ?>">
                                        <input type="hidden" name="search"   value="<?php echo esc_attr( $search ); ?>">
                                        <button type="submit" class="button button-primary button-small">Generate</button>
                                    </form>
                                <?php else : ?>
                                    <form method="POST" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;"
                                          onsubmit="return confirm('Regenerate nickname for <?php echo esc_attr( $child['display_name'] ); ?>?');">
                                        <?php wp_nonce_field( 'noey_regenerate_nickname_' . $child_id, '_nonce' ); ?>
                                        <input type="hidden" name="action"   value="noey_regenerate_nickname">
                                        <input type="hidden" name="child_id" value="<?php echo esc_attr( $child_id ); ?>">
                                        <input type="hidden" name="search"   value="<?php echo esc_attr( $search ); ?>">
                                        <select name="reason" style="margin-right:4px;">
                                            <option value="request">Request</option>
                                            <option value="inappropriate">Inappropriate</option>
                                            <option value="system">System</option>
                                        </select>
                                        <button type="submit" class="button button-secondary button-small">Regenerate</button>
                                    </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
        <?php
    }

    // ── Tab 3: Testing ────────────────────────────────────────────────────────

    private static function render_testing_tab(): void {
        $feedback = sanitize_text_field( $_GET['test_result'] ?? '' );
        $error    = sanitize_text_field( $_GET['test_error']  ?? '' );

        $standards  = self::standards();
        $terms      = self::terms();
        $subjects   = self::subjects();
        $difficulties = [ 'easy' => 'Easy', 'medium' => 'Medium', 'hard' => 'Hard' ];
        ?>

        <!-- Architecture notice -->
        <div class="notice notice-info" style="margin-bottom: 20px;">
            <p>
                <strong>All test actions route through:</strong>
                WordPress Plugin → Railway API → Supabase.
                Nothing writes directly to the database.
                All actions are logged under context <code>leaderboard.test</code> in the Debug Log.
            </p>
        </div>

        <?php if ( $feedback ) : ?>
            <div class="notice notice-success is-dismissible"><p><?php echo esc_html( urldecode( $feedback ) ); ?></p></div>
        <?php endif; ?>
        <?php if ( $error ) : ?>
            <div class="notice notice-error is-dismissible"><p>Error: <?php echo esc_html( urldecode( $error ) ); ?></p></div>
        <?php endif; ?>

        <!-- ════════════════════════════════════════════════════════════════════
             TEST 1 — Read Board
             Mirrors: React useBoard() hook — GET /leaderboard/:std/:term/:subject
             ════════════════════════════════════════════════════════════════════ -->
        <h3 style="border-top:1px solid #ddd; padding-top:16px;">
            📖 Test 1 — Read Board
            <span style="font-weight:400; font-size:13px; color:#666; margin-left:8px;">
                Mirrors: <code>GET /leaderboard/:standard/:term/:subject</code>
            </span>
        </h3>
        <p class="description">Fetches the top 10 for any board. Same call React makes when a student views the leaderboard page.</p>

        <form method="GET" action="" style="margin-bottom: 8px;">
            <input type="hidden" name="page" value="noey-leaderboard">
            <input type="hidden" name="tab"  value="testing">
            <?php echo self::board_selectors( 'read', $standards, $terms, $subjects ); ?>
            <?php submit_button( 'Read Board', 'secondary', 'read_board', false ); ?>
        </form>

        <?php
        if ( isset( $_GET['read_board'] ) && ( $_GET['read_std'] ?? '' ) ) {
            $read_result = Noey_Leaderboard_Service::get_board(
                sanitize_text_field( $_GET['read_std'] ),
                sanitize_text_field( $_GET['read_term'] ?? 'none' ),
                sanitize_text_field( $_GET['read_subject'] ?? '' )
            );
            self::render_raw_response( $read_result );
        }
        ?>

        <!-- ════════════════════════════════════════════════════════════════════
             TEST 2 — Read My Boards (by Child ID)
             Mirrors: React useMyBoards() hook — GET /leaderboard/me
             ════════════════════════════════════════════════════════════════════ -->
        <h3 style="border-top:1px solid #ddd; padding-top:16px;">
            👤 Test 2 — Read My Boards
            <span style="font-weight:400; font-size:13px; color:#666; margin-left:8px;">
                Mirrors: <code>GET /leaderboard/me</code>
            </span>
        </h3>
        <p class="description">
            Returns all boards a specific child appears on today. Same call React makes for the dashboard widget and leaderboard summary strip.
        </p>

        <form method="GET" action="" style="margin-bottom: 8px;">
            <input type="hidden" name="page" value="noey-leaderboard">
            <input type="hidden" name="tab"  value="testing">
            <label>Child ID:
                <input type="number" name="my_child_id"
                       value="<?php echo esc_attr( $_GET['my_child_id'] ?? '' ); ?>"
                       min="1" style="width:90px; margin: 0 8px;">
            </label>
            <?php submit_button( 'Read My Boards', 'secondary', 'read_my_boards', false ); ?>
        </form>

        <?php
        if ( isset( $_GET['read_my_boards'] ) && ( $_GET['my_child_id'] ?? '' ) ) {
            $my_result = Noey_Leaderboard_Service::get_my_boards( (int) $_GET['my_child_id'] );
            self::render_raw_response( $my_result );
        }
        ?>

        <!-- ════════════════════════════════════════════════════════════════════
             TEST 3 — Simulate Submit Upsert
             Mirrors: handle_submit_upsert() called after POST /exams/:id/submit
             This is the CRITICAL path — verifies /leaderboard/upsert endpoint works
             ════════════════════════════════════════════════════════════════════ -->
        <h3 style="border-top:1px solid #ddd; padding-top:16px;">
            ⚡ Test 3 — Simulate Submit Upsert
            <span style="font-weight:400; font-size:13px; color:#666; margin-left:8px;">
                Mirrors: <code>POST /exams/:id/submit</code> leaderboard side-effect
            </span>
        </h3>
        <p class="description">
            Sends the exact payload that WordPress sends to <code>POST /leaderboard/upsert</code> after a real exam submission.
            Use this to verify the upsert endpoint is live and returning the correct <code>leaderboard_update</code> block.
            The response shown here is exactly what gets appended to the submit-exam response that React reads.
        </p>

        <form method="POST" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="max-width: 560px;">
            <?php wp_nonce_field( 'noey_lb_simulate_upsert', '_nonce' ); ?>
            <input type="hidden" name="action" value="noey_lb_simulate_upsert">

            <table class="form-table">
                <tr>
                    <th><label>Child ID</label></th>
                    <td>
                        <input type="number" name="child_id" min="1" class="small-text" required>
                        <p class="description">Must have a nickname in user_profiles</p>
                    </td>
                </tr>
                <?php echo self::board_selectors_tr( 'sim', $standards, $terms, $subjects ); ?>
                <tr>
                    <th><label>Difficulty</label></th>
                    <td>
                        <select name="difficulty">
                            <?php foreach ( $difficulties as $v => $l ) : ?>
                                <option value="<?php echo esc_attr( $v ); ?>"><?php echo esc_html( $l ); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th><label>Correct Answers</label></th>
                    <td>
                        <input type="number" name="correct" value="15" min="0" max="60" style="width:80px;">
                        <span class="description">&nbsp;Points = correct + bonus (medium +1, hard +2)</span>
                    </td>
                </tr>
                <tr>
                    <th><label>Total Questions</label></th>
                    <td><input type="number" name="total" value="20" min="1" max="60" style="width:80px;"></td>
                </tr>
                <tr>
                    <th><label>Score %</label></th>
                    <td><input type="number" name="score_pct" value="75" min="0" max="100" style="width:80px;"></td>
                </tr>
                <tr>
                    <th><label>Session ID</label></th>
                    <td>
                        <input type="text" name="session_id" value="ses_test_admin_<?php echo esc_attr( time() ); ?>" class="regular-text">
                        <p class="description">Fake session reference — not validated</p>
                    </td>
                </tr>
            </table>

            <?php submit_button( 'Simulate Upsert', 'primary', 'submit', false ); ?>
        </form>

        <?php if ( isset( $_GET['sim_response'] ) ) : ?>
            <h4>Railway Response (leaderboard_update block):</h4>
            <?php
            $decoded = json_decode( urldecode( $_GET['sim_response'] ), true );
            self::render_raw_response( $decoded ?? urldecode( $_GET['sim_response'] ) );
            ?>
        <?php endif; ?>

        <!-- ════════════════════════════════════════════════════════════════════
             TEST 4 — Add Fake Player Entry
             Mirrors: inject a test player directly onto a board
             ════════════════════════════════════════════════════════════════════ -->
        <h3 style="border-top:1px solid #ddd; padding-top:16px;">
            ➕ Test 4 — Add Fake Player Entry
            <span style="font-weight:400; font-size:13px; color:#666; margin-left:8px;">
                Calls: <code>POST /leaderboard/test/inject</code> via Railway
            </span>
        </h3>
        <p class="description">
            Injects a fake named player with custom points onto any board.
            Use to populate empty boards for UI testing (rank display, top-3 treatment, tie-breaking).
        </p>

        <form method="POST" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="max-width: 520px;">
            <?php wp_nonce_field( 'noey_lb_inject_entry', '_nonce' ); ?>
            <input type="hidden" name="action" value="noey_lb_inject_entry">

            <table class="form-table">
                <tr>
                    <th><label>Nickname</label></th>
                    <td>
                        <input type="text" name="nickname" value="TestShark" class="regular-text" required
                               placeholder="e.g. TestShark">
                        <p class="description">Must be unique on the board for today</p>
                    </td>
                </tr>
                <?php echo self::board_selectors_tr( 'inj', $standards, $terms, $subjects ); ?>
                <tr>
                    <th><label>Total Points</label></th>
                    <td>
                        <input type="number" name="points" value="15" min="1" max="200" style="width:80px;">
                        <span class="description">&nbsp;Easy max 20 · Medium max 41 · Hard max 62</span>
                    </td>
                </tr>
                <tr>
                    <th><label>Score %</label></th>
                    <td><input type="number" name="score_pct" value="75" min="0" max="100" style="width:80px;"></td>
                </tr>
            </table>

            <?php submit_button( 'Add Fake Entry', 'primary', 'submit', false ); ?>
        </form>

        <?php if ( isset( $_GET['inj_response'] ) ) : ?>
            <h4>Railway Response:</h4>
            <?php
            $decoded = json_decode( urldecode( $_GET['inj_response'] ), true );
            self::render_raw_response( $decoded ?? urldecode( $_GET['inj_response'] ) );
            ?>
        <?php endif; ?>

        <!-- ════════════════════════════════════════════════════════════════════
             TEST 5 — Reset Specific Board
             ════════════════════════════════════════════════════════════════════ -->
        <h3 style="border-top:1px solid #ddd; padding-top:16px;">
            🗑 Test 5 — Reset Specific Board
            <span style="font-weight:400; font-size:13px; color:#666; margin-left:8px;">
                Calls: <code>POST /leaderboard/test/reset-board</code> via Railway
            </span>
        </h3>
        <p class="description">
            Clears today's entries for a single board. Use after testing to clean up fake entries.
            Does not affect other boards or the archive.
        </p>

        <form method="POST" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
              onsubmit="return confirm('Reset this board? Today\'s entries will be cleared.');">
            <?php wp_nonce_field( 'noey_lb_reset_board', '_nonce' ); ?>
            <input type="hidden" name="action" value="noey_lb_reset_board">
            <?php echo self::board_selectors( 'rst', $standards, $terms, $subjects ); ?>
            &nbsp;
            <?php submit_button( 'Reset This Board', 'delete', 'submit', false ); ?>
        </form>

        <!-- ════════════════════════════════════════════════════════════════════
             TEST 6 — Full Daily Reset
             ════════════════════════════════════════════════════════════════════ -->
        <h3 style="border-top:1px solid #ddd; padding-top:16px;">
            🔄 Test 6 — Trigger Full Daily Reset
            <span style="font-weight:400; font-size:13px; color:#666; margin-left:8px;">
                Calls: <code>POST /leaderboard/reset</code> via Railway
            </span>
        </h3>
        <p class="description">
            Manually triggers the reset that normally runs at Trinidad midnight (04:00 UTC).
            Archives all today's entries then clears the live table. <strong>Use with caution.</strong>
        </p>

        <form method="POST" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
              onsubmit="return confirm('This clears ALL leaderboard entries for today. Continue?');">
            <?php wp_nonce_field( 'noey_lb_daily_reset', '_nonce' ); ?>
            <input type="hidden" name="action" value="noey_lb_daily_reset">
            <?php submit_button( 'Run Full Daily Reset', 'delete', 'submit', false ); ?>
        </form>

        <!-- ════════════════════════════════════════════════════════════════════
             TEST 7 — Generate Nickname
             ════════════════════════════════════════════════════════════════════ -->
        <h3 style="border-top:1px solid #ddd; padding-top:16px;">
            🎲 Test 7 — Generate Nickname
            <span style="font-weight:400; font-size:13px; color:#666; margin-left:8px;">
                Calls: <code>POST /leaderboard/generate-nickname</code> via Railway
            </span>
        </h3>
        <p class="description">
            Manually trigger nickname generation for a child by ID.
            Safe to call on children who already have a nickname — returns existing without regenerating.
        </p>

        <form method="POST" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
            <?php wp_nonce_field( 'noey_lb_generate_nickname_test', '_nonce' ); ?>
            <input type="hidden" name="action"  value="noey_lb_generate_nickname">
            <input type="hidden" name="is_test" value="1">

            <table class="form-table" style="max-width:420px;">
                <tr>
                    <th><label>Child ID</label></th>
                    <td><input type="number" name="child_id" min="1" class="small-text" required></td>
                </tr>
                <tr>
                    <th><label>Standard</label></th>
                    <td>
                        <select name="standard">
                            <?php foreach ( $standards as $v => $l ) : ?>
                                <option value="<?php echo esc_attr( $v ); ?>"><?php echo esc_html( $l ); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th><label>Term</label></th>
                    <td>
                        <select name="term">
                            <?php foreach ( $terms as $v => $l ) : ?>
                                <option value="<?php echo esc_attr( $v ); ?>"><?php echo esc_html( $l ); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                </tr>
            </table>

            <?php submit_button( 'Generate Nickname', 'secondary', 'submit', false ); ?>
        </form>
        <?php
    }

    // ── Form Handlers ─────────────────────────────────────────────────────────

    public static function handle_regenerate_nickname(): void {
        $child_id = (int) ( $_POST['child_id'] ?? 0 );
        $reason   = sanitize_text_field( $_POST['reason'] ?? 'request' );
        $search   = sanitize_text_field( $_POST['search'] ?? '' );

        if ( ! wp_verify_nonce( $_POST['_nonce'] ?? '', 'noey_regenerate_nickname_' . $child_id ) ) wp_die( 'Security check failed.' );
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Insufficient permissions.' );

        $result = Noey_Leaderboard_Service::regenerate_nickname( $child_id, get_current_user_id(), $reason );
        self::redirect_testing_result( 'nicknames', $result, 'Nickname regenerated successfully.', [ 'search' => $search ] );
    }

    public static function handle_generate_nickname(): void {
        $child_id = (int) ( $_POST['child_id'] ?? 0 );
        $standard = sanitize_text_field( $_POST['standard'] ?? '' );
        $term     = sanitize_text_field( $_POST['term']     ?? '' );
        $is_test  = (bool) ( $_POST['is_test'] ?? false );
        $search   = sanitize_text_field( $_POST['search']   ?? '' );

        $nonce_action = $is_test ? 'noey_lb_generate_nickname_test' : 'noey_lb_generate_nickname_' . $child_id;
        if ( ! wp_verify_nonce( $_POST['_nonce'] ?? '', $nonce_action ) ) wp_die( 'Security check failed.' );
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Insufficient permissions.' );

        $result = Noey_Leaderboard_Service::generate_nickname( $child_id, $standard, $term );
        $tab    = $is_test ? 'testing' : 'nicknames';

        self::redirect_testing_result(
            $tab,
            $result,
            is_string( $result ) ? "Nickname generated: {$result}" : 'Nickname generated.',
            $is_test ? [] : [ 'search' => $search ]
        );
    }

    /**
     * Handle simulate upsert — mirrors handle_submit_upsert() exactly.
     * Builds a fake $session and $result array from admin form inputs,
     * then calls handle_submit_upsert() so the test goes through the
     * identical code path as a real exam submission.
     */
    public static function handle_simulate_upsert(): void {
        if ( ! wp_verify_nonce( $_POST['_nonce'] ?? '', 'noey_lb_simulate_upsert' ) ) wp_die( 'Security check failed.' );
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Insufficient permissions.' );

        $child_id   = (int) ( $_POST['child_id']   ?? 0 );
        $standard   = sanitize_text_field( $_POST['sim_std']       ?? '' );
        $term       = sanitize_text_field( $_POST['sim_term']      ?? 'none' );
        $subject    = sanitize_text_field( $_POST['sim_subject']   ?? '' );
        $difficulty = sanitize_text_field( $_POST['difficulty']    ?? 'easy' );
        $correct    = (int) ( $_POST['correct']   ?? 15 );
        $total      = (int) ( $_POST['total']     ?? 20 );
        $score_pct  = (int) ( $_POST['score_pct'] ?? 75 );
        $session_id = sanitize_text_field( $_POST['session_id']    ?? 'ses_test_admin' );

        // Build fake session row matching the shape of noey_exam_sessions
        $fake_session = [
            'child_id'            => $child_id,
            'standard'            => $standard,
            'term'                => $term === 'none' ? '' : $term,
            'subject'             => $subject,   // normalised in handle_submit_upsert
            'difficulty'          => $difficulty,
            'external_session_id' => $session_id,
        ];

        // Build fake result matching the shape returned by Noey_Results_Service
        $fake_result = [
            'score'      => $correct,
            'total'      => $total,
            'percentage' => $score_pct,
        ];

        Noey_Debug::log( 'leaderboard.test', 'Admin: simulating submit upsert', [
            'child_id'   => $child_id,
            'session'    => $fake_session,
            'result'     => $fake_result,
        ], get_current_user_id(), 'info' );

        // Call the real upsert handler — identical to the exam submit hook
        $leaderboard_update = Noey_Leaderboard_Service::handle_submit_upsert( $fake_session, $fake_result );

        $base = admin_url( 'admin.php?page=noey-leaderboard&tab=testing' );

        if ( $leaderboard_update === null ) {
            wp_redirect( add_query_arg( [
                'test_error'   => urlencode( 'Upsert returned null — check leaderboard.upsert_failed in Debug Log' ),
            ], $base ) );
        } else {
            wp_redirect( add_query_arg( [
                'sim_response' => urlencode( wp_json_encode( $leaderboard_update ) ),
            ], $base ) );
        }
        exit;
    }

    public static function handle_inject_entry(): void {
        if ( ! wp_verify_nonce( $_POST['_nonce'] ?? '', 'noey_lb_inject_entry' ) ) wp_die( 'Security check failed.' );
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Insufficient permissions.' );

        $result = Noey_Leaderboard_Service::inject_test_entry( [
            'nickname'  => sanitize_text_field( $_POST['nickname']    ?? 'TestPlayer' ),
            'standard'  => sanitize_text_field( $_POST['inj_std']     ?? '' ),
            'term'      => sanitize_text_field( $_POST['inj_term']    ?? 'none' ),
            'subject'   => sanitize_text_field( $_POST['inj_subject'] ?? '' ),
            'points'    => (int) ( $_POST['points']    ?? 10 ),
            'score_pct' => (int) ( $_POST['score_pct'] ?? 75 ),
        ] );

        $base = admin_url( 'admin.php?page=noey-leaderboard&tab=testing' );

        if ( is_wp_error( $result ) ) {
            wp_redirect( add_query_arg( [ 'test_error' => urlencode( $result->get_error_message() ) ], $base ) );
        } else {
            wp_redirect( add_query_arg( [ 'inj_response' => urlencode( wp_json_encode( $result ) ) ], $base ) );
        }
        exit;
    }

    public static function handle_reset_board(): void {
        if ( ! wp_verify_nonce( $_POST['_nonce'] ?? '', 'noey_lb_reset_board' ) ) wp_die( 'Security check failed.' );
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Insufficient permissions.' );

        $result = Noey_Leaderboard_Service::reset_board(
            sanitize_text_field( $_POST['rst_std']     ?? '' ),
            sanitize_text_field( $_POST['rst_term']    ?? 'none' ),
            sanitize_text_field( $_POST['rst_subject'] ?? '' )
        );

        self::redirect_testing_result( 'testing', $result, 'Board reset successfully.' );
    }

    public static function handle_daily_reset(): void {
        if ( ! wp_verify_nonce( $_POST['_nonce'] ?? '', 'noey_lb_daily_reset' ) ) wp_die( 'Security check failed.' );
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Insufficient permissions.' );

        $result = Noey_Leaderboard_Service::trigger_daily_reset( get_current_user_id() );
        $msg    = is_array( $result )
            ? "Daily reset complete. Entries cleared: {$result['entries_cleared']}, Boards archived: {$result['boards_cleared']}"
            : 'Daily reset triggered.';

        self::redirect_testing_result( 'testing', $result, $msg );
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /**
     * Render a raw Railway API response in a styled pre block.
     * Handles both array responses and WP_Error.
     */
    private static function render_raw_response( mixed $response ): void {
        $is_error = is_wp_error( $response );
        $colour   = $is_error ? '#fef2f2' : '#f0fdf4';
        $border   = $is_error ? '#fca5a5' : '#86efac';
        $content  = $is_error
            ? 'ERROR: ' . $response->get_error_message()
            : wp_json_encode( $response, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE );
        echo '<pre style="background:' . esc_attr( $colour ) . '; border:1px solid ' . esc_attr( $border ) . '; padding:16px; max-width:700px; overflow:auto; font-size:12px; border-radius:4px; margin: 8px 0 16px;">';
        echo esc_html( $content );
        echo '</pre>';
    }

    private static function redirect_testing_result( string $tab, mixed $result, string $success_msg, array $extra = [] ): void {
        $base   = admin_url( 'admin.php?page=noey-leaderboard&tab=' . $tab );
        $params = is_wp_error( $result )
            ? array_merge( $extra, [ 'test_error'  => urlencode( $result->get_error_message() ) ] )
            : array_merge( $extra, [ 'test_result' => urlencode( $success_msg ) ] );
        wp_redirect( add_query_arg( $params, $base ) );
        exit;
    }

    private static function search_children( string $search ): array {
        global $wpdb;
        if ( is_numeric( $search ) ) {
            $rows = $wpdb->get_results( $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}noey_children WHERE child_id = %d LIMIT 20",
                (int) $search
            ), ARRAY_A );
        } else {
            $rows = $wpdb->get_results( $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}noey_children WHERE display_name LIKE %s ORDER BY display_name ASC LIMIT 20",
                '%' . $wpdb->esc_like( $search ) . '%'
            ), ARRAY_A );
        }
        return $rows ?: [];
    }

    // ── Reference Data ────────────────────────────────────────────────────────

    private static function standards(): array {
        return [ 'std_4' => 'Standard 4', 'std_5' => 'Standard 5' ];
    }

    private static function terms(): array {
        return [ 'none' => '—', 'term_1' => 'Term 1', 'term_2' => 'Term 2', 'term_3' => 'Term 3' ];
    }

    private static function subjects(): array {
        return [ 'math' => 'Mathematics', 'english' => 'English', 'science' => 'Science', 'social_studies' => 'Social Studies' ];
    }

    /** Inline board selector labels for GET forms */
    private static function board_selectors( string $prefix, array $standards, array $terms, array $subjects ): string {
        ob_start();
        foreach ( [
            "{$prefix}_std"     => [ 'Standard', $standards, '' ],
            "{$prefix}_term"    => [ 'Term', $terms, 'none' ],
            "{$prefix}_subject" => [ 'Subject', $subjects, '' ],
        ] as $name => [ $label, $options, $default ] ) :
            $current = sanitize_text_field( $_GET[ $name ] ?? $default );
            ?>
            <label style="margin-right:12px;"><?php echo esc_html( $label ); ?>:
                <select name="<?php echo esc_attr( $name ); ?>">
                    <?php if ( ! $default ) : ?><option value="">— Select —</option><?php endif; ?>
                    <?php foreach ( $options as $v => $l ) : ?>
                        <option value="<?php echo esc_attr( $v ); ?>" <?php selected( $current, $v ); ?>><?php echo esc_html( $l ); ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <?php
        endforeach;
        return ob_get_clean();
    }

    /** Board selector as <tr> rows for POST form-tables */
    private static function board_selectors_tr( string $prefix, array $standards, array $terms, array $subjects ): string {
        ob_start();
        foreach ( [
            "{$prefix}_std"     => [ 'Standard', $standards, '' ],
            "{$prefix}_term"    => [ 'Term', $terms, 'none' ],
            "{$prefix}_subject" => [ 'Subject', $subjects, '' ],
        ] as $name => [ $label, $options, $default ] ) :
            ?>
            <tr>
                <th><label><?php echo esc_html( $label ); ?></label></th>
                <td>
                    <select name="<?php echo esc_attr( $name ); ?>">
                        <?php if ( ! $default ) : ?><option value="">— Select —</option><?php endif; ?>
                        <?php foreach ( $options as $v => $l ) : ?>
                            <option value="<?php echo esc_attr( $v ); ?>"><?php echo esc_html( $l ); ?></option>
                        <?php endforeach; ?>
                    </select>
                </td>
            </tr>
            <?php
        endforeach;
        return ob_get_clean();
    }

    private static function tab_url( string $tab ): string {
        return admin_url( 'admin.php?page=noey-leaderboard&tab=' . $tab );
    }
}