<?php
/**
 * Noey_Leaderboard_API — Leaderboard REST endpoints.
 *
 * v2.0: Board key is standard + term + subject only. Difficulty removed.
 *
 * Routes:
 *   GET  /noey/v1/leaderboard/me                              JWT  Personal board summary
 *   GET  /noey/v1/leaderboard/:standard/:term/:subject        JWT  Top 10 for a subject board
 *
 * @package NoeyAPI
 */

defined( 'ABSPATH' ) || exit;

class Noey_Leaderboard_API extends Noey_API_Base {

    public function register_routes(): void {
        $ns = $this->namespace;

        // /leaderboard/me — registered first so 'me' is never captured as :standard
        register_rest_route( $ns, '/leaderboard/me', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'my_boards' ],
            'permission_callback' => '__return_true',
        ] );

        // /leaderboard/:standard/:term/:subject — no difficulty segment
        register_rest_route( $ns, '/leaderboard/(?P<standard>[a-z0-9_]+)/(?P<term>[a-z0-9_]+)/(?P<subject>[a-z0-9_]+)', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'board' ],
            'permission_callback' => '__return_true',
            'args'                => [
                'standard' => [
                    'required'          => true,
                    'type'              => 'string',
                    'sanitize_callback' => 'sanitize_text_field',
                ],
                'term'     => [
                    'required'          => true,
                    'type'              => 'string',
                    'sanitize_callback' => 'sanitize_text_field',
                    'description'       => 'Use "none" for std_5 boards.',
                ],
                'subject'  => [
                    'required'          => true,
                    'type'              => 'string',
                    'sanitize_callback' => 'sanitize_text_field',
                ],
            ],
        ] );
    }

    // ── Handlers ──────────────────────────────────────────────────────────────

    /**
     * GET /noey/v1/leaderboard/:standard/:term/:subject
     *
     * Returns today's top 10 for the subject board.
     * Points are the accumulated daily total across all difficulties.
     */
    public function board( WP_REST_Request $request ): WP_REST_Response|WP_Error {
        $ctx = $this->require_child_context( $request );
        if ( is_wp_error( $ctx ) ) return $ctx;

        $result = Noey_Leaderboard_Service::get_board(
            $request->get_param( 'standard' ),
            $request->get_param( 'term' ),
            $request->get_param( 'subject' ),
            $ctx['child_id']
        );

        return is_wp_error( $result ) ? $result : $this->success( $result );
    }

    /**
     * GET /noey/v1/leaderboard/me
     *
     * Returns all subject boards the current child appears on today.
     * Scoped to their enrolled standard + term.
     */
    public function my_boards( WP_REST_Request $request ): WP_REST_Response|WP_Error {
        $ctx = $this->require_child_context( $request );
        if ( is_wp_error( $ctx ) ) return $ctx;

        $result = Noey_Leaderboard_Service::get_my_boards( $ctx['child_id'] );

        return is_wp_error( $result ) ? $result : $this->success( $result );
    }
}