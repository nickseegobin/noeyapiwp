<?php
/**
 * Noey_Auth_API — Authentication endpoints.
 *
 * Routes:
 *   POST  /noey/v1/auth/register       Open       Register a new parent account → JWT
 *   POST  /noey/v1/auth/login          Open       Login → JWT
 *   GET   /noey/v1/auth/me             JWT        Current user profile
 *   PATCH /noey/v1/auth/profile        JWT parent Update display_name and/or avatar_index
 *   POST  /noey/v1/auth/pin/set        JWT parent Set / update PIN
 *   POST  /noey/v1/auth/pin/verify     JWT        Verify parent PIN
 *   GET   /noey/v1/auth/pin/status     JWT parent PIN status
 *   GET   /noey/v1/ping                Open       Health check
 *
 * @package NoeyAPI
 */

defined( 'ABSPATH' ) || exit;

class Noey_Auth_API extends Noey_API_Base {

    public function register_routes(): void {
        $ns = $this->namespace;

        register_rest_route( $ns, '/auth/register', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'register' ],
            'permission_callback' => '__return_true',
            'args'                => [
                'display_name' => [ 'required' => true,  'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ],
                'username'     => [ 'required' => true,  'type' => 'string', 'sanitize_callback' => 'sanitize_user' ],
                'email'        => [ 'required' => true,  'type' => 'string', 'sanitize_callback' => 'sanitize_email' ],
                'password'     => [ 'required' => true,  'type' => 'string' ],
            ],
        ] );

        register_rest_route( $ns, '/auth/login', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'login' ],
            'permission_callback' => '__return_true',
            'args'                => [
                'username' => [ 'required' => true,  'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ],
                'password' => [ 'required' => true,  'type' => 'string' ],
            ],
        ] );

        register_rest_route( $ns, '/auth/me', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'me' ],
            'permission_callback' => '__return_true',
        ] );

        register_rest_route( $ns, '/auth/profile', [
            'methods'             => 'PATCH',
            'callback'            => [ $this, 'update_profile' ],
            'permission_callback' => '__return_true',
            'args'                => [
                'display_name' => [ 'required' => false, 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ],
                'avatar_index' => [ 'required' => false, 'type' => 'integer' ],
            ],
        ] );

        register_rest_route( $ns, '/auth/pin/set', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'set_pin' ],
            'permission_callback' => '__return_true',
            'args'                => [
                'pin' => [ 'required' => true, 'type' => 'string' ],
            ],
        ] );

        register_rest_route( $ns, '/auth/pin/verify', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'verify_pin' ],
            'permission_callback' => '__return_true',
            'args'                => [
                'pin' => [ 'required' => true, 'type' => 'string' ],
            ],
        ] );

        register_rest_route( $ns, '/auth/pin/status', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'pin_status' ],
            'permission_callback' => '__return_true',
        ] );

        register_rest_route( $ns, '/ping', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'ping' ],
            'permission_callback' => '__return_true',
        ] );
    }

    // ── Handlers ──────────────────────────────────────────────────────────────

    public function register( WP_REST_Request $request ): WP_REST_Response|WP_Error {
        $result = Noey_Auth_Service::register( [
            'display_name' => $request->get_param( 'display_name' ),
            'username'     => $request->get_param( 'username' ),
            'email'        => $request->get_param( 'email' ),
            'password'     => $request->get_param( 'password' ),
        ] );
        return is_wp_error( $result ) ? $result : $this->created( $result );
    }

    public function login( WP_REST_Request $request ): WP_REST_Response|WP_Error {
        $result = Noey_Auth_Service::login(
            $request->get_param( 'username' ),
            $request->get_param( 'password' )
        );
        return is_wp_error( $result ) ? $result : $this->success( $result );
    }

    public function me( WP_REST_Request $request ): WP_REST_Response|WP_Error {
        $user_id = $this->authenticate( $request );
        if ( is_wp_error( $user_id ) ) return $user_id;

        $profile = Noey_Auth_Service::get_profile( $user_id );
        return is_wp_error( $profile ) ? $profile : $this->success( $profile );
    }

    public function update_profile( WP_REST_Request $request ): WP_REST_Response|WP_Error {
        $parent_id = $this->require_parent( $request );
        if ( is_wp_error( $parent_id ) ) return $parent_id;

        $result = Noey_Auth_Service::update_profile( $parent_id, [
            'display_name' => $request->get_param( 'display_name' ),
            'avatar_index' => $request->get_param( 'avatar_index' ),
        ] );
        return is_wp_error( $result ) ? $result : $this->success( $result );
    }

    public function set_pin( WP_REST_Request $request ): WP_REST_Response|WP_Error {
        $parent_id = $this->require_parent( $request );
        if ( is_wp_error( $parent_id ) ) return $parent_id;

        $result = Noey_Auth_Service::set_pin( $parent_id, $request->get_param( 'pin' ) );
        return is_wp_error( $result ) ? $result : $this->success( [ 'pin_set' => true ] );
    }

    public function verify_pin( WP_REST_Request $request ): WP_REST_Response|WP_Error {
        $parent_id = $this->require_parent( $request );
        if ( is_wp_error( $parent_id ) ) return $parent_id;

        $result = Noey_Auth_Service::verify_pin( $parent_id, $request->get_param( 'pin' ) );
        return is_wp_error( $result ) ? $result : $this->success( [ 'verified' => true ] );
    }

    public function pin_status( WP_REST_Request $request ): WP_REST_Response|WP_Error {
        $parent_id = $this->require_parent( $request );
        if ( is_wp_error( $parent_id ) ) return $parent_id;

        return $this->success( Noey_Auth_Service::get_pin_status( $parent_id ) );
    }

    public function ping(): WP_REST_Response {
        return $this->success( [
            'status'  => 'ok',
            'version' => NOEY_VERSION,
            'time'    => current_time( 'mysql', true ),
        ] );
    }
}
