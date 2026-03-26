<?php
/**
 * Noey_Children_API — Child profile management endpoints.
 *
 * Routes:
 *   GET    /noey/v1/children                  JWT parent  List all children
 *   POST   /noey/v1/children                  JWT parent  Create a child
 *   GET    /noey/v1/children/{child_id}        JWT parent  Get single child
 *   PATCH  /noey/v1/children/{child_id}        JWT parent  Update child
 *   DELETE /noey/v1/children/{child_id}        JWT parent  Remove child
 *   POST   /noey/v1/children/{child_id}/switch JWT parent  Switch active profile
 *   POST   /noey/v1/children/deselect          JWT parent  Return to parent context
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
                'callback'            => [ $this, 'list_children' ],
                'permission_callback' => '__return_true',
            ],
            [
                'methods'             => 'POST',
                'callback'            => [ $this, 'create_child' ],
                'permission_callback' => '__return_true',
                'args'                => [
                    'display_name' => [ 'required' => true,  'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ],
                    'username'     => [ 'required' => true,  'type' => 'string', 'sanitize_callback' => 'sanitize_user' ],
                    'password'     => [ 'required' => true,  'type' => 'string' ],
                    'standard'     => [ 'required' => false, 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ],
                    'term'         => [ 'required' => false, 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ],
                    'age'          => [ 'required' => false, 'type' => 'integer' ],
                    'avatar_index' => [ 'required' => false, 'type' => 'integer', 'default' => 1 ],
                ],
            ],
        ] );

        register_rest_route( $ns, '/children/deselect', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'deselect_child' ],
            'permission_callback' => '__return_true',
        ] );

        register_rest_route( $ns, '/children/(?P<child_id>\d+)', [
            [
                'methods'             => 'GET',
                'callback'            => [ $this, 'get_child' ],
                'permission_callback' => '__return_true',
            ],
            [
                'methods'             => 'PATCH',
                'callback'            => [ $this, 'update_child' ],
                'permission_callback' => '__return_true',
            ],
            [
                'methods'             => 'DELETE',
                'callback'            => [ $this, 'remove_child' ],
                'permission_callback' => '__return_true',
                'args'                => [
                    'confirm' => [ 'required' => true, 'type' => 'boolean' ],
                ],
            ],
        ] );

        register_rest_route( $ns, '/children/(?P<child_id>\d+)/switch', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'switch_child' ],
            'permission_callback' => '__return_true',
        ] );
    }

    // ── Handlers ──────────────────────────────────────────────────────────────

    public function list_children( WP_REST_Request $request ): WP_REST_Response|WP_Error {
        $parent_id = $this->require_parent( $request );
        if ( is_wp_error( $parent_id ) ) return $parent_id;

        $children       = Noey_Children_Service::list_children( $parent_id );
        $active_child   = (int) get_user_meta( $parent_id, 'noey_active_child_id', true ) ?: null;

        return $this->success( [
            'children'        => $children,
            'active_child_id' => $active_child,
            'can_add_more'    => count( $children ) < NOEY_MAX_CHILDREN,
        ] );
    }

    public function create_child( WP_REST_Request $request ): WP_REST_Response|WP_Error {
        $parent_id = $this->require_parent( $request );
        if ( is_wp_error( $parent_id ) ) return $parent_id;

        $result = Noey_Children_Service::create_child( $parent_id, $request->get_params() );
        return is_wp_error( $result ) ? $result : $this->created( $result );
    }

    public function get_child( WP_REST_Request $request ): WP_REST_Response|WP_Error {
        $parent_id = $this->require_parent( $request );
        if ( is_wp_error( $parent_id ) ) return $parent_id;

        $child = Noey_Children_Service::get_child_for_parent( $parent_id, (int) $request['child_id'] );
        return is_wp_error( $child ) ? $child : $this->success( $child );
    }

    public function update_child( WP_REST_Request $request ): WP_REST_Response|WP_Error {
        $parent_id = $this->require_parent( $request );
        if ( is_wp_error( $parent_id ) ) return $parent_id;

        $result = Noey_Children_Service::update_child(
            $parent_id,
            (int) $request['child_id'],
            $request->get_json_params() ?: $request->get_body_params()
        );
        return is_wp_error( $result ) ? $result : $this->success( $result );
    }

    public function remove_child( WP_REST_Request $request ): WP_REST_Response|WP_Error {
        $parent_id = $this->require_parent( $request );
        if ( is_wp_error( $parent_id ) ) return $parent_id;

        if ( ! $request->get_param( 'confirm' ) ) {
            return new WP_Error(
                'noey_confirmation_required',
                'Pass confirm: true in the request body to permanently delete this student profile.',
                [ 'status' => 422 ]
            );
        }

        $result = Noey_Children_Service::remove_child( $parent_id, (int) $request['child_id'] );
        return is_wp_error( $result ) ? $result : $this->success( [ 'removed' => true ] );
    }

    public function switch_child( WP_REST_Request $request ): WP_REST_Response|WP_Error {
        $parent_id = $this->require_parent( $request );
        if ( is_wp_error( $parent_id ) ) return $parent_id;

        $result = Noey_Children_Service::switch_to_child( $parent_id, (int) $request['child_id'] );
        return is_wp_error( $result ) ? $result : $this->success( $result );
    }

    public function deselect_child( WP_REST_Request $request ): WP_REST_Response|WP_Error {
        $parent_id = $this->require_parent( $request );
        if ( is_wp_error( $parent_id ) ) return $parent_id;

        return $this->success( Noey_Children_Service::switch_to_parent( $parent_id ) );
    }
}
