<?php
/**
 * Noey_Core — Plugin bootstrap.
 *
 * Boots all subsystems: CORS, REST API routes, cron hooks, admin.
 *
 * @package NoeyAPI
 */

defined( 'ABSPATH' ) || exit;

class Noey_Core {

    public static function boot(): void {
        // Safety net DB upgrade
        Noey_Activator::maybe_upgrade();

        // CORS preflight must be handled before WP sends anything
        self::handle_cors_preflight();

        // Register REST routes
        add_action( 'rest_api_init', [ __CLASS__, 'register_routes' ] );

        // Inject CORS headers into REST responses
        add_filter( 'rest_pre_serve_request', [ __CLASS__, 'send_cors_headers' ], 10, 4 );

        // Prevent the third-party jwt-authentication-for-wp-rest-api plugin from
        // intercepting noey/* routes — we handle JWT auth ourselves.
        // That plugin uses rest_pre_dispatch at priority 10 to return stored errors.
        // We run at priority 11 to intercept and clear those errors for our namespace.
        add_filter( 'rest_pre_dispatch', [ __CLASS__, 'bypass_third_party_jwt' ], 11, 3 );

        // WooCommerce integration
        Noey_WooCommerce::boot();

        // Cron hooks
        Noey_Cron::register_hooks();

        // Admin
        if ( is_admin() ) {
            Noey_Admin::boot();
        }

        Noey_Debug::log( 'core.boot', 'NoeyAPI booted', [
            'version'    => NOEY_VERSION,
            'debug_mode' => Noey_Debug::is_enabled(),
        ], null, 'debug' );
    }

    // ── REST Routes ───────────────────────────────────────────────────────────

    public static function register_routes(): void {
        ( new Noey_Auth_API() )->register_routes();
        ( new Noey_Children_API() )->register_routes();
        ( new Noey_Tokens_API() )->register_routes();
        ( new Noey_Exams_API() )->register_routes();
        ( new Noey_Results_API() )->register_routes();
        ( new Noey_Insights_API() )->register_routes();
        ( new Noey_Leaderboard_API() )->register_routes();

        Noey_Debug::log( 'core.routes', 'All REST routes registered', [], null, 'debug' );
    }

    // ── CORS ──────────────────────────────────────────────────────────────────

    /**
     * Handle OPTIONS preflight before WordPress processes the request.
     */
    public static function handle_cors_preflight(): void {
        if ( ( $_SERVER['REQUEST_METHOD'] ?? '' ) !== 'OPTIONS' ) {
            return;
        }

        $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
        if ( ! $origin || ! self::is_allowed_origin( $origin ) ) {
            return;
        }

        header( 'Access-Control-Allow-Origin: ' . esc_url_raw( $origin ) );
        header( 'Access-Control-Allow-Methods: GET, POST, PATCH, DELETE, OPTIONS' );
        header( 'Access-Control-Allow-Headers: Authorization, Content-Type, X-Requested-With' );
        header( 'Access-Control-Allow-Credentials: true' );
        header( 'Access-Control-Max-Age: 86400' );
        status_header( 204 );
        exit;
    }

    /**
     * Inject CORS + no-cache headers into every REST response for allowed origins.
     *
     * Cache-Control: no-store prevents WordPress caching plugins, CDNs, and browsers
     * from serving stale responses after writes (e.g. GET /auth/me returning an old
     * active_child_id after POST /children/{id}/switch has already updated it).
     */
    public static function send_cors_headers( bool $served, WP_HTTP_Response $result, WP_REST_Request $request, WP_REST_Server $server ): bool {
        $origin = $_SERVER['HTTP_ORIGIN'] ?? '';

        if ( $origin && self::is_allowed_origin( $origin ) ) {
            header( 'Access-Control-Allow-Origin: ' . esc_url_raw( $origin ) );
            header( 'Access-Control-Allow-Credentials: true' );
            header( 'Access-Control-Allow-Methods: GET, POST, PATCH, DELETE, OPTIONS' );
            header( 'Access-Control-Allow-Headers: Authorization, Content-Type, X-Requested-With' );
            header( 'Vary: Origin' );
        }

        // All noey/v1 responses must never be cached — responses are always
        // user-specific and state changes (switch child, token deduction) must
        // be reflected immediately on the next request.
        if ( strpos( $request->get_route(), '/noey/v1' ) === 0 ) {
            header( 'Cache-Control: no-store, no-cache, must-revalidate' );
            header( 'Pragma: no-cache' );
        }

        return $served;
    }

    // ── Third-party JWT bypass ────────────────────────────────────────────────

    /**
     * For all noey/v1 routes, clear any WP_Error injected into rest_pre_dispatch
     * by the jwt-authentication-for-wp-rest-api plugin (priority 10).
     *
     * That plugin stores a jwt_error on determine_current_user and returns it
     * from rest_pre_dispatch, short-circuiting the entire request before our
     * permission_callback ever runs. We intercept at priority 11, check the route,
     * and return null so WordPress continues dispatching normally.
     *
     * @param  mixed            $result   Value from previous rest_pre_dispatch filters.
     * @param  WP_REST_Server   $server   REST server instance.
     * @param  WP_REST_Request  $request  Current request.
     * @return mixed  null for noey routes (clears JWT error), original $result otherwise.
     */
    public static function bypass_third_party_jwt( $result, $server, $request ) {
        if ( ! is_wp_error( $result ) ) {
            return $result; // Nothing to clear
        }

        $route = $request->get_route();

        if ( strpos( $route, '/noey/v1' ) === 0 ) {
            Noey_Debug::log( 'core.jwt_bypass', 'Third-party JWT error cleared for noey route', [
                'route'      => $route,
                'error_code' => $result->get_error_code(),
            ], null, 'debug' );

            return null; // Let NoeyAPI's own permission_callback handle auth
        }

        return $result;
    }

    // ── Origin Validation ─────────────────────────────────────────────────────

    /**
     * Check whether the given origin is in the allow-list.
     *
     * Configured in Admin › NoeyAPI › Settings › Allowed Origins (comma-separated).
     */
    public static function is_allowed_origin( string $origin ): bool {
        $raw      = get_option( 'noey_allowed_origins', '' );
        $allowed  = array_filter( array_map( 'trim', explode( ',', $raw ) ) );

        // Always allow same site
        $allowed[] = get_site_url();

        foreach ( $allowed as $allowed_origin ) {
            if ( rtrim( $allowed_origin, '/' ) === rtrim( $origin, '/' ) ) {
                return true;
            }
        }

        return false;
    }
}
