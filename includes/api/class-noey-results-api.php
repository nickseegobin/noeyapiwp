<?php
/**
 * Noey_Results_API — Exam results and history endpoints.
 *
 * Routes:
 *   GET /noey/v1/results                    JWT  Paginated session history
 *   GET /noey/v1/results/stats              JWT  Aggregate stats + topic breakdown
 *   GET /noey/v1/results/{session_id}       JWT  Full session detail with answers
 *
 * Parent override: pass ?child_id={id} to /results or /results/stats to read any owned
 * child's data without changing the active-child context (useful for analytics overview).
 * The parent must own the child or a 403 is returned.
 *
 * @package NoeyAPI
 */

defined( 'ABSPATH' ) || exit;

class Noey_Results_API extends Noey_API_Base {

    public function register_routes(): void {
        $ns = $this->namespace;

        register_rest_route( $ns, '/results', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'history' ],
            'permission_callback' => '__return_true',
            'args'                => [
                'page'     => [ 'default' => 1,  'type' => 'integer', 'minimum' => 1 ],
                'per_page' => [ 'default' => 20, 'type' => 'integer', 'minimum' => 1, 'maximum' => 100 ],
                'child_id' => [ 'type' => 'integer', 'minimum' => 1 ],
            ],
        ] );

        register_rest_route( $ns, '/results/stats', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'stats' ],
            'permission_callback' => '__return_true',
            'args'                => [
                'child_id' => [ 'type' => 'integer', 'minimum' => 1 ],
            ],
        ] );

        register_rest_route( $ns, '/results/(?P<session_id>\d+)', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'detail' ],
            'permission_callback' => '__return_true',
        ] );
    }

    // ── Handlers ──────────────────────────────────────────────────────────────

    public function history( WP_REST_Request $request ): WP_REST_Response|WP_Error {
        $child_id = $this->resolve_child_id( $request );
        if ( is_wp_error( $child_id ) ) return $child_id;

        $paging = $this->paginate( $request );
        $result = Noey_Results_Service::get_sessions( $child_id, $paging['page'], $paging['per_page'] );

        return $this->success( $result );
    }

    public function stats( WP_REST_Request $request ): WP_REST_Response|WP_Error {
        $child_id = $this->resolve_child_id( $request );
        if ( is_wp_error( $child_id ) ) return $child_id;

        return $this->success( Noey_Results_Service::get_stats( $child_id ) );
    }

    public function detail( WP_REST_Request $request ): WP_REST_Response|WP_Error {
        $ctx = $this->require_child_context( $request );
        if ( is_wp_error( $ctx ) ) return $ctx;

        $result = Noey_Results_Service::get_session_detail( (int) $request['session_id'], $ctx['child_id'] );
        return is_wp_error( $result ) ? $result : $this->success( $result );
    }
}
