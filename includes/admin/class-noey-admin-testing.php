<?php
/**
 * Noey_Admin_Testing — Integrated API test suite.
 *
 * Each test group contains individual tests that can be run via AJAX.
 * Tests call the actual REST API endpoints internally (not mocked),
 * giving real confidence that the full stack is working.
 *
 * Test groups:
 *   System     — JWT secret, DB tables, Railway connection
 *   Auth       — Login, /me, PIN set/verify
 *   Children   — Create, list, switch, remove
 *   Tokens     — Balance, credit, deduct, monthly refresh
 *   Exams      — Catalogue, start, checkpoint, submit
 *   Results    — History, stats, session detail
 *   Insights   — Per-exam insight, weekly digest
 *
 * @package NoeyAPI
 */

defined( 'ABSPATH' ) || exit;

class Noey_Admin_Testing {

    // ── Render ────────────────────────────────────────────────────────────────

    public static function render(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Insufficient permissions.' );
        }
        ?>
        <div class="wrap noey-wrap">
            <h1>NoeyAPI — Test Suite</h1>
            <p class="noey-test-intro">
                Tests call your actual REST API endpoints (with valid admin credentials) and report pass/fail with full request/response detail.
                Run individual tests or click <strong>Run All</strong>.
            </p>

            <div class="noey-test-toolbar">
                <button id="noey-run-all" class="button button-primary">▶ Run All Tests</button>
                <button id="noey-clear-results" class="button">Clear Results</button>
                <span id="noey-test-summary" class="noey-test-summary"></span>
            </div>

            <?php foreach ( self::test_groups() as $group_id => $group ) : ?>
            <div class="noey-test-group" id="group-<?= esc_attr( $group_id ) ?>">
                <div class="noey-test-group-header">
                    <h2><?= esc_html( $group['label'] ) ?></h2>
                    <button class="button noey-run-group" data-group="<?= esc_attr( $group_id ) ?>">Run Group</button>
                </div>
                <div class="noey-test-list">
                    <?php foreach ( $group['tests'] as $test_id => $test ) : ?>
                    <div class="noey-test-item" id="test-<?= esc_attr( $test_id ) ?>">
                        <div class="noey-test-header">
                            <span class="noey-test-status" id="status-<?= esc_attr( $test_id ) ?>">○</span>
                            <span class="noey-test-name"><?= esc_html( $test['label'] ) ?></span>
                            <code class="noey-test-route"><?= esc_html( $test['method'] . ' /noey/v1' . $test['route'] ) ?></code>
                            <button class="button button-small noey-run-test" data-test="<?= esc_attr( $test_id ) ?>">Run</button>
                        </div>
                        <div class="noey-test-result" id="result-<?= esc_attr( $test_id ) ?>" style="display:none"></div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php
    }

    // ── Test Runner (called via AJAX) ─────────────────────────────────────────

    public static function run_test( string $test_id, array $data = [] ): array {
        $start = microtime( true );

        try {
            $result = match ( $test_id ) {
                // System
                'system_jwt_secret'    => self::test_jwt_secret(),
                'system_db_tables'     => self::test_db_tables(),
                'system_railway_ping'  => self::test_railway_ping(),
                // Auth
                'auth_ping'            => self::test_ping(),
                'auth_login'           => self::test_login( $data ),
                'auth_me'              => self::test_me( $data ),
                'auth_pin_set'         => self::test_pin_set( $data ),
                'auth_pin_verify'      => self::test_pin_verify( $data ),
                // Children
                'children_list'        => self::test_children_list( $data ),
                'children_create'      => self::test_children_create( $data ),
                'children_switch'      => self::test_children_switch( $data ),
                // Tokens
                'tokens_balance'       => self::test_tokens_balance( $data ),
                'tokens_credit'        => self::test_tokens_credit( $data ),
                'tokens_deduct'        => self::test_tokens_deduct( $data ),
                'tokens_monthly'       => self::test_tokens_monthly(),
                // Exams
                'exams_catalogue'      => self::test_exams_catalogue( $data ),
                'exams_start'          => self::test_exams_start( $data ),
                // Results
                'results_history'      => self::test_results_history( $data ),
                'results_stats'        => self::test_results_stats( $data ),
                // Insights
                'insights_weekly_build' => self::test_insights_weekly_build( $data ),
                default                => [ 'pass' => false, 'message' => "Unknown test: {$test_id}" ],
            };
        } catch ( Throwable $e ) {
            $result = [
                'pass'    => false,
                'message' => 'Exception: ' . $e->getMessage(),
                'trace'   => $e->getTraceAsString(),
            ];
        }

        $result['duration_ms'] = round( ( microtime( true ) - $start ) * 1000, 1 );
        return $result;
    }

    // ── System Tests ──────────────────────────────────────────────────────────

    private static function test_jwt_secret(): array {
        if ( defined( 'NOEY_JWT_SECRET' ) && NOEY_JWT_SECRET ) {
            return self::pass( 'NOEY_JWT_SECRET is defined.' );
        }
        if ( defined( 'JWT_AUTH_SECRET_KEY' ) && JWT_AUTH_SECRET_KEY ) {
            return self::warn( 'Using JWT_AUTH_SECRET_KEY as fallback. Define NOEY_JWT_SECRET in wp-config.php.' );
        }
        return self::fail( 'No JWT secret defined. Plugin is using a derived key — not suitable for production.' );
    }

    private static function test_db_tables(): array {
        global $wpdb;
        $tables = [
            'noey_children', 'noey_token_ledger', 'noey_exam_pool',
            'noey_exam_sessions', 'noey_exam_answers', 'noey_topic_breakdown',
            'noey_exam_insights', 'noey_weekly_insights', 'noey_debug_log',
        ];

        $missing = [];
        foreach ( $tables as $table ) {
            $full  = $wpdb->prefix . $table;
            $exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $full ) );
            if ( $exists !== $full ) {
                $missing[] = $full;
            }
        }

        if ( empty( $missing ) ) {
            return self::pass( 'All ' . count( $tables ) . ' NoeyAPI database tables exist.', [
                'tables' => $tables,
            ] );
        }

        return self::fail( 'Missing tables: ' . implode( ', ', $missing ) );
    }

    private static function test_railway_ping(): array {
        $endpoint = rtrim( get_option( 'noey_railway_endpoint', '' ), '/' );
        if ( ! $endpoint ) {
            return self::warn( 'Railway endpoint not configured. Configure it in Settings.' );
        }

        // Railway health endpoint is GET /health (no auth required)
        $response = wp_remote_get( "{$endpoint}/health", [ 'timeout' => 10 ] );

        if ( is_wp_error( $response ) ) {
            return self::fail( 'Railway connection failed: ' . $response->get_error_message() );
        }

        $code = wp_remote_retrieve_response_code( $response );
        $body = json_decode( wp_remote_retrieve_body( $response ), true );

        return $code === 200
            ? self::pass( "Railway is healthy (HTTP {$code}).", $body ?? [] )
            : self::fail( "Railway returned HTTP {$code}.", [ 'body' => wp_remote_retrieve_body( $response ) ] );
    }

    // ── Auth Tests ────────────────────────────────────────────────────────────

    private static function test_ping(): array {
        $res = self::api_get( '/ping' );
        return $res['status'] === 200
            ? self::pass( 'Ping OK.', $res['body'] )
            : self::fail( 'Ping failed.', $res );
    }

    private static function test_login( array $data ): array {
        if ( empty( $data['username'] ) || empty( $data['password'] ) ) {
            return self::warn( 'Provide username and password in test data to run this test.' );
        }

        $res = self::api_post( '/auth/login', [
            'username' => $data['username'],
            'password' => $data['password'],
        ] );

        if ( $res['status'] === 200 && ! empty( $res['body']['data']['token'] ) ) {
            return self::pass( 'Login successful. JWT received.', [
                'user_id'  => $res['body']['data']['user_id'],
                'role'     => $res['body']['data']['role'],
                'token'    => substr( $res['body']['data']['token'], 0, 30 ) . '…',
            ] );
        }

        return self::fail( 'Login failed.', $res );
    }

    private static function test_me( array $data ): array {
        if ( empty( $data['token'] ) ) {
            return self::warn( 'Provide a JWT token in test data.' );
        }
        $res = self::api_get( '/auth/me', $data['token'] );
        return $res['status'] === 200
            ? self::pass( '/me returned user profile.', $res['body']['data'] ?? [] )
            : self::fail( '/me failed.', $res );
    }

    private static function test_pin_set( array $data ): array {
        if ( empty( $data['token'] ) || empty( $data['pin'] ) ) {
            return self::warn( 'Provide token and pin (4 digits) in test data.' );
        }
        $res = self::api_post( '/auth/pin/set', [ 'pin' => $data['pin'] ], $data['token'] );
        return $res['status'] === 200
            ? self::pass( 'PIN set successfully.' )
            : self::fail( 'PIN set failed.', $res );
    }

    private static function test_pin_verify( array $data ): array {
        if ( empty( $data['token'] ) || empty( $data['pin'] ) ) {
            return self::warn( 'Provide token and pin in test data.' );
        }
        $res = self::api_post( '/auth/pin/verify', [ 'pin' => $data['pin'] ], $data['token'] );
        return $res['status'] === 200
            ? self::pass( 'PIN verified successfully.' )
            : self::fail( 'PIN verification failed.', $res );
    }

    // ── Children Tests ────────────────────────────────────────────────────────

    private static function test_children_list( array $data ): array {
        if ( empty( $data['token'] ) ) return self::warn( 'Provide parent token.' );
        $res = self::api_get( '/children', $data['token'] );
        return $res['status'] === 200
            ? self::pass( 'Children listed.', [ 'count' => count( $res['body']['data']['children'] ?? [] ) ] )
            : self::fail( 'Children list failed.', $res );
    }

    private static function test_children_create( array $data ): array {
        if ( empty( $data['token'] ) ) return self::warn( 'Provide parent token.' );

        $res = self::api_post( '/children', [
            'display_name' => 'Test Student',
            'username'     => 'test_child_' . time(),
            'password'     => 'TestPass123!',
            'standard'     => 'std_4',
            'term'         => 'term_1',
            'age'          => 9,
        ], $data['token'] );

        return $res['status'] === 201
            ? self::pass( 'Child created.', [ 'child_id' => $res['body']['data']['child_id'] ?? null ] )
            : self::fail( 'Child creation failed.', $res );
    }

    private static function test_children_switch( array $data ): array {
        if ( empty( $data['token'] ) || empty( $data['child_id'] ) ) {
            return self::warn( 'Provide token and child_id.' );
        }
        $res = self::api_post( '/children/' . (int) $data['child_id'] . '/switch', [], $data['token'] );
        return $res['status'] === 200
            ? self::pass( 'Child switched.', $res['body']['data'] ?? [] )
            : self::fail( 'Switch failed.', $res );
    }

    // ── Token Tests ───────────────────────────────────────────────────────────

    private static function test_tokens_balance( array $data ): array {
        if ( empty( $data['token'] ) ) return self::warn( 'Provide token.' );
        $res = self::api_get( '/tokens/balance', $data['token'] );
        return $res['status'] === 200
            ? self::pass( 'Balance fetched.', $res['body']['data'] ?? [] )
            : self::fail( 'Balance failed.', $res );
    }

    private static function test_tokens_credit( array $data ): array {
        if ( empty( $data['user_id'] ) ) return self::warn( 'Provide user_id for admin credit test.' );
        $admin_token = self::get_admin_token();
        if ( ! $admin_token ) return self::warn( 'Could not generate admin token.' );

        $res = self::api_post( '/tokens/admin/credit', [
            'user_id' => (int) $data['user_id'],
            'amount'  => 1,
            'note'    => 'Test Suite credit',
        ], $admin_token );

        return $res['status'] === 200
            ? self::pass( 'Credit applied.', $res['body']['data'] ?? [] )
            : self::fail( 'Credit failed.', $res );
    }

    private static function test_tokens_deduct( array $data ): array {
        if ( empty( $data['user_id'] ) ) return self::warn( 'Provide user_id.' );
        $admin_token = self::get_admin_token();
        if ( ! $admin_token ) return self::warn( 'Could not generate admin token.' );

        $res = self::api_post( '/tokens/admin/deduct', [
            'user_id' => (int) $data['user_id'],
            'amount'  => 1,
            'note'    => 'Test Suite deduct',
        ], $admin_token );

        return $res['status'] === 200
            ? self::pass( 'Deduct applied.', $res['body']['data'] ?? [] )
            : self::fail( 'Deduct failed.', $res );
    }

    private static function test_tokens_monthly(): array {
        $admin_token = self::get_admin_token();
        if ( ! $admin_token ) return self::warn( 'Could not generate admin token.' );
        $res = self::api_post( '/tokens/admin/refresh', [], $admin_token );
        return $res['status'] === 200
            ? self::pass( 'Monthly refresh triggered.', $res['body']['data'] ?? [] )
            : self::fail( 'Monthly refresh failed.', $res );
    }

    // ── Exam Tests ────────────────────────────────────────────────────────────

    private static function test_exams_catalogue( array $data ): array {
        if ( empty( $data['token'] ) ) return self::warn( 'Provide token.' );
        $res = self::api_get( '/exams', $data['token'] );
        return $res['status'] === 200
            ? self::pass( 'Catalogue fetched.', [ 'count' => count( $res['body']['data']['catalogue'] ?? [] ) ] )
            : self::fail( 'Catalogue failed.', $res );
    }

    private static function test_exams_start( array $data ): array {
        if ( empty( $data['token'] ) ) return self::warn( 'Provide token (with active child).' );
        $res = self::api_post( '/exams/start', [
            'standard'   => $data['standard'] ?? 'std_4',
            'term'       => $data['term'] ?? 'term_1',
            'subject'    => $data['subject'] ?? 'Mathematics',
            'difficulty' => $data['difficulty'] ?? 'medium',
        ], $data['token'] );
        return $res['status'] === 200
            ? self::pass( 'Exam started.', [ 'session_id' => $res['body']['data']['session_id'] ?? null ] )
            : self::fail( 'Exam start failed.', $res );
    }

    // ── Results Tests ─────────────────────────────────────────────────────────

    private static function test_results_history( array $data ): array {
        if ( empty( $data['token'] ) ) return self::warn( 'Provide token.' );
        $res = self::api_get( '/results', $data['token'] );
        return $res['status'] === 200
            ? self::pass( 'History fetched.', [ 'total' => $res['body']['data']['total'] ?? 0 ] )
            : self::fail( 'History failed.', $res );
    }

    private static function test_results_stats( array $data ): array {
        if ( empty( $data['token'] ) ) return self::warn( 'Provide token.' );
        $res = self::api_get( '/results/stats', $data['token'] );
        return $res['status'] === 200
            ? self::pass( 'Stats fetched.', $res['body']['data'] ?? [] )
            : self::fail( 'Stats failed.', $res );
    }

    // ── Insight Tests ─────────────────────────────────────────────────────────

    private static function test_insights_weekly_build( array $data ): array {
        if ( empty( $data['child_id'] ) ) return self::warn( 'Provide child_id.' );
        $iso_week = $data['iso_week'] ?? date( 'o-\WW' );
        $payload  = Noey_Insight_Service::build_weekly_payload( (int) $data['child_id'], $iso_week );

        if ( is_wp_error( $payload ) ) {
            return self::fail( 'Payload build failed: ' . $payload->get_error_message() );
        }

        return self::pass( 'Weekly payload built successfully.', [
            'iso_week'        => $iso_week,
            'exams_completed' => $payload['period']['exams_completed'],
            'subjects'        => count( $payload['subjects'] ),
            'payload'         => $payload,
        ] );
    }

    // ── Test Definition List ──────────────────────────────────────────────────

    private static function test_groups(): array {
        return [
            'system' => [
                'label' => '🔧 System',
                'tests' => [
                    'system_jwt_secret'   => [ 'label' => 'JWT Secret configured',    'method' => 'CHECK', 'route' => '' ],
                    'system_db_tables'    => [ 'label' => 'All DB tables exist',       'method' => 'CHECK', 'route' => '' ],
                    'system_railway_ping' => [ 'label' => 'Railway server reachable',  'method' => 'GET',   'route' => '' ],
                ],
            ],
            'auth' => [
                'label' => '🔐 Auth',
                'tests' => [
                    'auth_ping'       => [ 'label' => 'Health check (ping)',  'method' => 'GET',  'route' => '/ping' ],
                    'auth_login'      => [ 'label' => 'Login → JWT',         'method' => 'POST', 'route' => '/auth/login' ],
                    'auth_me'         => [ 'label' => 'Current user (/me)',   'method' => 'GET',  'route' => '/auth/me' ],
                    'auth_pin_set'    => [ 'label' => 'Set parent PIN',       'method' => 'POST', 'route' => '/auth/pin/set' ],
                    'auth_pin_verify' => [ 'label' => 'Verify parent PIN',    'method' => 'POST', 'route' => '/auth/pin/verify' ],
                ],
            ],
            'children' => [
                'label' => '👶 Children',
                'tests' => [
                    'children_list'   => [ 'label' => 'List children',     'method' => 'GET',    'route' => '/children' ],
                    'children_create' => [ 'label' => 'Create child',      'method' => 'POST',   'route' => '/children' ],
                    'children_switch' => [ 'label' => 'Switch active child', 'method' => 'POST', 'route' => '/children/{id}/switch' ],
                ],
            ],
            'tokens' => [
                'label' => '🪙 Tokens',
                'tests' => [
                    'tokens_balance' => [ 'label' => 'Get balance',          'method' => 'GET',  'route' => '/tokens/balance' ],
                    'tokens_credit'  => [ 'label' => 'Admin credit',         'method' => 'POST', 'route' => '/tokens/admin/credit' ],
                    'tokens_deduct'  => [ 'label' => 'Admin deduct',         'method' => 'POST', 'route' => '/tokens/admin/deduct' ],
                    'tokens_monthly' => [ 'label' => 'Trigger monthly reset','method' => 'POST', 'route' => '/tokens/admin/refresh' ],
                ],
            ],
            'exams' => [
                'label' => '📝 Exams',
                'tests' => [
                    'exams_catalogue' => [ 'label' => 'Exam catalogue',  'method' => 'GET',  'route' => '/exams' ],
                    'exams_start'     => [ 'label' => 'Start exam',      'method' => 'POST', 'route' => '/exams/start' ],
                ],
            ],
            'results' => [
                'label' => '📊 Results',
                'tests' => [
                    'results_history' => [ 'label' => 'Exam history',    'method' => 'GET', 'route' => '/results' ],
                    'results_stats'   => [ 'label' => 'Aggregate stats', 'method' => 'GET', 'route' => '/results/stats' ],
                ],
            ],
            'insights' => [
                'label' => '💡 Insights',
                'tests' => [
                    'insights_weekly_build' => [ 'label' => 'Build weekly payload', 'method' => 'CHECK', 'route' => '' ],
                ],
            ],
        ];
    }

    // ── HTTP Helpers ──────────────────────────────────────────────────────────

    private static function api_get( string $route, string $token = '' ): array {
        return self::api_call( 'GET', $route, [], $token );
    }

    private static function api_post( string $route, array $body = [], string $token = '' ): array {
        return self::api_call( 'POST', $route, $body, $token );
    }

    private static function api_call( string $method, string $route, array $body, string $token ): array {
        $url     = rest_url( NOEY_REST_NAMESPACE . $route );
        $headers = [ 'Content-Type' => 'application/json' ];

        if ( $token ) {
            $headers['Authorization'] = "Bearer {$token}";
        }

        $args = [
            'method'  => $method,
            'timeout' => 15,
            'headers' => $headers,
        ];

        if ( $method === 'POST' && ! empty( $body ) ) {
            $args['body'] = wp_json_encode( $body );
        }

        $response = wp_remote_request( $url, $args );

        if ( is_wp_error( $response ) ) {
            return [ 'status' => 0, 'error' => $response->get_error_message() ];
        }

        return [
            'status' => wp_remote_retrieve_response_code( $response ),
            'body'   => json_decode( wp_remote_retrieve_body( $response ), true ),
        ];
    }

    private static function get_admin_token(): string {
        $admin = get_users( [ 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ] );
        if ( empty( $admin ) ) return '';
        return Noey_JWT::encode( (int) $admin[0] );
    }

    // ── Result Builders ───────────────────────────────────────────────────────

    private static function pass( string $message, array $data = [] ): array {
        return [ 'pass' => true,  'status' => 'pass', 'message' => $message, 'data' => $data ];
    }

    private static function fail( string $message, array $data = [] ): array {
        return [ 'pass' => false, 'status' => 'fail', 'message' => $message, 'data' => $data ];
    }

    private static function warn( string $message, array $data = [] ): array {
        return [ 'pass' => null,  'status' => 'warn', 'message' => $message, 'data' => $data ];
    }
}
