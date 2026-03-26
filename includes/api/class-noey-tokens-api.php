<?php
/**
 * Noey_Tokens_API — Token wallet endpoints.
 *
 * Routes:
 *   GET  /noey/v1/tokens/balance          JWT  Current balance
 *   GET  /noey/v1/tokens/ledger           JWT  Transaction history
 *   POST /noey/v1/tokens/admin/credit     Admin Credit tokens to any parent
 *   POST /noey/v1/tokens/admin/deduct     Admin Deduct tokens from any parent
 *   POST /noey/v1/tokens/admin/refresh    Admin Manually trigger monthly refresh
 *
 * @package NoeyAPI
 */

defined( 'ABSPATH' ) || exit;

class Noey_Tokens_API extends Noey_API_Base {

    public function register_routes(): void {
        $ns = $this->namespace;

        register_rest_route( $ns, '/tokens/balance', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'balance' ],
            'permission_callback' => '__return_true',
        ] );

        register_rest_route( $ns, '/tokens/ledger', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'ledger' ],
            'permission_callback' => '__return_true',
            'args'                => [
                'limit'  => [ 'default' => 50,  'type' => 'integer', 'minimum' => 1, 'maximum' => 100 ],
                'offset' => [ 'default' => 0,   'type' => 'integer', 'minimum' => 0 ],
            ],
        ] );

        register_rest_route( $ns, '/tokens/admin/credit', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'admin_credit' ],
            'permission_callback' => [ $this, 'admin_permission' ],
            'args'                => [
                'user_id' => [ 'required' => true, 'type' => 'integer' ],
                'amount'  => [ 'required' => true, 'type' => 'integer', 'minimum' => 1 ],
                'note'    => [ 'required' => false, 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ],
            ],
        ] );

        register_rest_route( $ns, '/tokens/admin/deduct', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'admin_deduct' ],
            'permission_callback' => [ $this, 'admin_permission' ],
            'args'                => [
                'user_id' => [ 'required' => true,  'type' => 'integer' ],
                'amount'  => [ 'required' => true,  'type' => 'integer', 'minimum' => 1 ],
                'note'    => [ 'required' => false, 'type' => 'string',  'sanitize_callback' => 'sanitize_text_field' ],
            ],
        ] );

        register_rest_route( $ns, '/tokens/admin/refresh', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'admin_refresh' ],
            'permission_callback' => [ $this, 'admin_permission' ],
        ] );
    }

    // ── Handlers ──────────────────────────────────────────────────────────────

    public function balance( WP_REST_Request $request ): WP_REST_Response|WP_Error {
        $user_id = $this->authenticate( $request );
        if ( is_wp_error( $user_id ) ) return $user_id;

        $parent_id = Noey_Token_Service::resolve_to_parent( $user_id );

        return $this->success( [
            'balance'        => Noey_Token_Service::get_balance( $parent_id ),
            'tokens_lifetime' => (int) get_user_meta( $parent_id, 'noey_tokens_lifetime', true ),
        ] );
    }

    public function ledger( WP_REST_Request $request ): WP_REST_Response|WP_Error {
        $user_id = $this->authenticate( $request );
        if ( is_wp_error( $user_id ) ) return $user_id;

        $rows = Noey_Token_Service::get_ledger(
            $user_id,
            (int) $request->get_param( 'limit' ),
            (int) $request->get_param( 'offset' )
        );

        return $this->success( [ 'ledger' => $rows ] );
    }

    public function admin_credit( WP_REST_Request $request ): WP_REST_Response|WP_Error {
        $result = Noey_Token_Service::credit(
            (int) $request->get_param( 'user_id' ),
            (int) $request->get_param( 'amount' ),
            'admin_credit',
            '',
            $request->get_param( 'note' ) ?: 'Admin credit'
        );
        return is_wp_error( $result ) ? $result : $this->success( $result );
    }

    public function admin_deduct( WP_REST_Request $request ): WP_REST_Response|WP_Error {
        $result = Noey_Token_Service::deduct(
            (int) $request->get_param( 'user_id' ),
            (int) $request->get_param( 'amount' ),
            '',
            $request->get_param( 'note' ) ?: 'Admin deduct'
        );
        return is_wp_error( $result ) ? $result : $this->success( $result );
    }

    public function admin_refresh(): WP_REST_Response {
        $count = Noey_Token_Service::run_monthly_refresh();
        return $this->success( [ 'accounts_refreshed' => $count ] );
    }

    // ── Permission ────────────────────────────────────────────────────────────

    public function admin_permission(): bool {
        $user_id = Noey_JWT::from_request();
        if ( is_wp_error( $user_id ) ) return false;
        return user_can( $user_id, 'manage_options' );
    }
}
