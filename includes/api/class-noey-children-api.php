<?php
/**
 * Noey_Children_API — Child account endpoints.
 *
 * v2.1 changes:
 *  - /children (POST) args updated — first_name, last_name, nickname replace
 *    display_name and username. nickname becomes user_login.
 *  - /children/:id (PATCH) args updated — first_name, last_name accepted.
 *
 * Routes:
 *   GET    /noey/v1/children                 JWT parent  List all children
 *   POST   /noey/v1/children                 JWT parent  Create a child account
 *   GET    /noey/v1/children/:id             JWT         Get a single child profile
 *   PATCH  /noey/v1/children/:id             JWT parent  Update a child profile
 *   DELETE /noey/v1/children/:id             JWT parent  Remove a child profile
 *   POST   /noey/v1/children/:id/switch      JWT parent  Set active child
 *   POST   /noey/v1/children/switch-parent   JWT parent  Return to parent context
 *
 * @package NoeyAPI
 */

defined( 'ABSPATH' ) || exit;

class Noey_Children_API extends Noey_API_Base {

    public function register_routes(): void {
        $ns = $this->namespace;

        register_rest_route( $ns, '/children', [
            [
                'methods'             => 'GET',
                'callback'            => [ $this, 'index' ],
                'permission_callback' => '__return_true',
            ],
            [
                'methods'             => 'POST',
                'callback'            => [ $this, 'create' ],
                'permission_callback' => '__return_true',
                'args'                => [
                    'first_name'   => [ 'required' => true,  'type' => 'string',  'sanitize_callback' => 'sanitize_text_field' ],
                    'last_name'    => [ 'required' => true,  'type' => 'string',  'sanitize_callback' => 'sanitize_text_field' ],
                    'nickname'     => [ 'required' => true,  'type' => 'string',  'sanitize_callback' => 'sanitize_text_field' ],
                    'password'     => [ 'required' => true,  'type' => 'string' ],
                    'standard'     => [ 'required' => true,  'type' => 'string',  'sanitize_callback' => 'sanitize_text_field' ],
                    'term'         => [ 'required' => false, 'type' => 'string',  'sanitize_callback' => 'sanitize_text_field' ],
                    'age'          => [ 'required' => false, 'type' => 'integer' ],
                    'avatar_index' => [ 'required' => false, 'type' => 'integer' ],
                ],
            ],
        ] );

        register_rest_route( $ns, '/children/(?P<id>\d+)', [
            [
                'methods'             => 'GET',
                'callback'            => [ $this, 'show' ],
                'permission_callback' => '__return_true',
            ],
            [
                'methods'             => 'PATCH',
                'callback'            => [ $this, 'update' ],
                'permission_callback' => '__return_true',
                'args'                => [
                    'first_name'   => [ 'required' => false, 'type' => 'string',  'sanitize_callback' => 'sanitize_text_field' ],
                    'last_name'    => [ 'required' => false, 'type' => 'string',  'sanitize_callback' => 'sanitize_text_field' ],
                    'standard'     => [ 'required' => false, 'type' => 'string',  'sanitize_callback' => 'sanitize_text_field' ],
                    'term'         => [ 'required' => false, 'type' => 'string',  'sanitize_callback' => 'sanitize_text_field' ],
                    'age'          => [ 'required' => false, 'type' => 'integer' ],
                    'avatar_index' => [ 'required' => false, 'type' => 'integer' ],
                ],
            ],
            [
                'methods'             => 'DELETE',
                'callback'            => [ $this, 'destroy' ],
                'permission_callback' => '__return_true',
            ],
        ] );

        register_rest_route( $ns, '/children/(?P<id>\d+)/switch', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'switch_to' ],
            'permission_callback' => '__return_true',
        ] );

        register_rest_route( $ns, '/children/switch-parent', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'switch_to_parent' ],
            'permission_callback' => '__return_true',
        ] );

        // Add this alongside the existing switch-parent route
        //Updated route to match the new v2.1 endpoint structure
        // Was missiong
        register_rest_route( $ns, '/children/deselect', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'switch_to_parent' ],
            'permission_callback' => '__return_true',
        ] );

        
    }

    

    // ── Handlers ──────────────────────────────────────────────────────────────

    public function index( WP_REST_Request $request ): WP_REST_Response|WP_Error {
        $parent_id = $this->require_parent( $request );
        if ( is_wp_error( $parent_id ) ) return $parent_id;

        return $this->success( [
            'children' => Noey_Children_Service::list_children( $parent_id ),
        ] );
    }

    public function create( WP_REST_Request $request ): WP_REST_Response|WP_Error {
        $parent_id = $this->require_parent( $request );
        if ( is_wp_error( $parent_id ) ) return $parent_id;

        $result = Noey_Children_Service::create_child( $parent_id, [
            'first_name'   => $request->get_param( 'first_name' ),
            'last_name'    => $request->get_param( 'last_name' ),
            'nickname'     => $request->get_param( 'nickname' ),
            'password'     => $request->get_param( 'password' ),
            'standard'     => $request->get_param( 'standard' ),
            'term'         => $request->get_param( 'term' ),
            'age'          => $request->get_param( 'age' ),
            'avatar_index' => $request->get_param( 'avatar_index' ),
        ] );

        return is_wp_error( $result ) ? $result : $this->created( $result );
    }

    public function show( WP_REST_Request $request ): WP_REST_Response|WP_Error {
        $child_id = $this->resolve_child_id( $request );
        if ( is_wp_error( $child_id ) ) return $child_id;

        $result = Noey_Children_Service::get_child( $child_id );
        return is_wp_error( $result ) ? $result : $this->success( $result );
    }

    public function update( WP_REST_Request $request ): WP_REST_Response|WP_Error {
        $parent_id = $this->require_parent( $request );
        if ( is_wp_error( $parent_id ) ) return $parent_id;

        $result = Noey_Children_Service::update_child( $parent_id, (int) $request['id'], [
            'first_name'   => $request->get_param( 'first_name' ),
            'last_name'    => $request->get_param( 'last_name' ),
            'standard'     => $request->get_param( 'standard' ),
            'term'         => $request->get_param( 'term' ),
            'age'          => $request->get_param( 'age' ),
            'avatar_index' => $request->get_param( 'avatar_index' ),
        ] );

        return is_wp_error( $result ) ? $result : $this->success( $result );
    }

    public function destroy( WP_REST_Request $request ): WP_REST_Response|WP_Error {
        $parent_id = $this->require_parent( $request );
        if ( is_wp_error( $parent_id ) ) return $parent_id;

        $result = Noey_Children_Service::remove_child( $parent_id, (int) $request['id'] );
        return is_wp_error( $result ) ? $result : $this->success( [ 'removed' => true ] );
    }

    public function switch_to( WP_REST_Request $request ): WP_REST_Response|WP_Error {
        $parent_id = $this->require_parent( $request );
        if ( is_wp_error( $parent_id ) ) return $parent_id;

        $result = Noey_Children_Service::switch_to_child( $parent_id, (int) $request['id'] );
        return is_wp_error( $result ) ? $result : $this->success( $result );
    }

    public function switch_to_parent( WP_REST_Request $request ): WP_REST_Response|WP_Error {
        $parent_id = $this->require_parent( $request );
        if ( is_wp_error( $parent_id ) ) return $parent_id;

        return $this->success( Noey_Children_Service::switch_to_parent( $parent_id ) );
    }

    
}