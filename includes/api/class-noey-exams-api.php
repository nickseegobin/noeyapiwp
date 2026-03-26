<?php
/**
 * Noey_Exams_API — Exam delivery endpoints.
 *
 * Routes:
 *   GET    /noey/v1/exams                          JWT  Exam catalogue
 *   GET    /noey/v1/exams/active                   JWT  Active session for current child (or null)
 *   POST   /noey/v1/exams/start                    JWT  Start exam (deduct token + serve package)
 *   GET    /noey/v1/exams/{session_id}/checkpoint  JWT  Get saved checkpoint
 *   POST   /noey/v1/exams/{session_id}/checkpoint  JWT  Save mid-exam checkpoint
 *   POST   /noey/v1/exams/{session_id}/submit      JWT  Submit exam answers
 *   DELETE /noey/v1/exams/{session_id}             JWT  Cancel an active exam session
 *
 * @package NoeyAPI
 */

defined( 'ABSPATH' ) || exit;

class Noey_Exams_API extends Noey_API_Base {

    public function register_routes(): void {
        $ns = $this->namespace;

        register_rest_route( $ns, '/exams', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'catalogue' ],
            'permission_callback' => '__return_true',
            'args'                => [
                'standard'   => [ 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ],
                'term'       => [ 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ],
                'subject'    => [ 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ],
                'difficulty' => [ 'type' => 'string', 'enum' => [ 'easy', 'medium', 'hard' ] ],
            ],
        ] );

        register_rest_route( $ns, '/exams/active', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'active_session' ],
            'permission_callback' => '__return_true',
        ] );

        register_rest_route( $ns, '/exams/start', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'start' ],
            'permission_callback' => '__return_true',
            'args'                => [
                'standard'   => [ 'required' => true,  'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ],
                'term'       => [ 'required' => true,  'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ],
                'subject'    => [ 'required' => true,  'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ],
                'difficulty' => [ 'required' => false, 'type' => 'string', 'default' => 'medium', 'enum' => [ 'easy', 'medium', 'hard' ] ],
            ],
        ] );

        register_rest_route( $ns, '/exams/(?P<session_id>\d+)/checkpoint', [
            [
                'methods'             => 'GET',
                'callback'            => [ $this, 'get_checkpoint' ],
                'permission_callback' => '__return_true',
            ],
            [
                'methods'             => 'POST',
                'callback'            => [ $this, 'save_checkpoint' ],
                'permission_callback' => '__return_true',
                'args'                => [
                    'state' => [ 'required' => true ],
                ],
            ],
        ] );

        register_rest_route( $ns, '/exams/(?P<session_id>\d+)/submit', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'submit' ],
            'permission_callback' => '__return_true',
            'args'                => [
                'answers' => [ 'required' => true, 'type' => 'array' ],
            ],
        ] );

        register_rest_route( $ns, '/exams/(?P<session_id>\d+)', [
            'methods'             => 'DELETE',
            'callback'            => [ $this, 'cancel' ],
            'permission_callback' => '__return_true',
        ] );
    }

    // ── Handlers ──────────────────────────────────────────────────────────────

    public function active_session( WP_REST_Request $request ): WP_REST_Response|WP_Error {
        $ctx = $this->require_child_context( $request );
        if ( is_wp_error( $ctx ) ) return $ctx;

        $session = Noey_Exam_Service::get_active_session( $ctx['child_id'] );
        return $this->success( [ 'session' => $session ] );
    }

    public function catalogue( WP_REST_Request $request ): WP_REST_Response|WP_Error {
        $user_id = $this->authenticate( $request );
        if ( is_wp_error( $user_id ) ) return $user_id;

        $filters = array_filter( [
            'standard'   => $request->get_param( 'standard' ),
            'term'       => $request->get_param( 'term' ),
            'subject'    => $request->get_param( 'subject' ),
            'difficulty' => $request->get_param( 'difficulty' ),
        ] );

        return $this->success( [
            'catalogue' => Noey_Exam_Service::get_catalogue( $filters ),
        ] );
    }

    public function start( WP_REST_Request $request ): WP_REST_Response|WP_Error {
        $ctx = $this->require_child_context( $request );
        if ( is_wp_error( $ctx ) ) return $ctx;

        $result = Noey_Exam_Service::start(
            $ctx['parent_id'],
            $ctx['child_id'],
            $request->get_param( 'standard' ),
            $request->get_param( 'term' ),
            $request->get_param( 'subject' ),
            $request->get_param( 'difficulty' )
        );

        return is_wp_error( $result ) ? $result : $this->success( $result );
    }

    public function get_checkpoint( WP_REST_Request $request ): WP_REST_Response|WP_Error {
        $ctx = $this->require_child_context( $request );
        if ( is_wp_error( $ctx ) ) return $ctx;

        $raw = get_user_meta( $ctx['child_id'], 'noey_checkpoint', true );
        if ( ! $raw ) {
            return $this->success( [ 'checkpoint' => null ] );
        }

        $checkpoint = json_decode( $raw, true );

        // Only return if it belongs to this session
        if ( (int) ( $checkpoint['session_id'] ?? 0 ) !== (int) $request['session_id'] ) {
            return $this->success( [ 'checkpoint' => null ] );
        }

        return $this->success( [ 'checkpoint' => $checkpoint ] );
    }

    public function save_checkpoint( WP_REST_Request $request ): WP_REST_Response|WP_Error {
        $ctx = $this->require_child_context( $request );
        if ( is_wp_error( $ctx ) ) return $ctx;

        $result = Noey_Exam_Service::checkpoint(
            (int) $request['session_id'],
            $ctx['child_id'],
            $request->get_param( 'state' )
        );

        return is_wp_error( $result ) ? $result : $this->success( [ 'saved' => true ] );
    }

    public function submit( WP_REST_Request $request ): WP_REST_Response|WP_Error {
        $ctx = $this->require_child_context( $request );
        if ( is_wp_error( $ctx ) ) return $ctx;

        $result = Noey_Exam_Service::submit(
            (int) $request['session_id'],
            $ctx['child_id'],
            $request->get_param( 'answers' )
        );

        return is_wp_error( $result ) ? $result : $this->success( $result );
    }

    public function cancel( WP_REST_Request $request ): WP_REST_Response|WP_Error {
        $ctx = $this->require_child_context( $request );
        if ( is_wp_error( $ctx ) ) return $ctx;

        $result = Noey_Exam_Service::cancel( (int) $request['session_id'], $ctx['child_id'] );
        return is_wp_error( $result ) ? $result : $this->success( $result );
    }
}
