<?php
/**
 * Noey_API_Base — Abstract base for all NoeyAPI REST controllers.
 *
 * Provides:
 *  - JWT-based authentication helpers
 *  - Parent / child role enforcement
 *  - Standardised success / error response builders
 *  - Active-child resolution
 *
 * @package NoeyAPI
 */

defined( 'ABSPATH' ) || exit;

abstract class Noey_API_Base extends WP_REST_Controller {

    protected $namespace = NOEY_REST_NAMESPACE;

    // ── Authentication ────────────────────────────────────────────────────────

    /**
     * Validate the request JWT and return the authenticated WP user ID.
     *
     * @return int|WP_Error  User ID on success.
     */
    protected function authenticate( WP_REST_Request $request ): int|WP_Error {
        $user_id = Noey_JWT::from_request();

        if ( is_wp_error( $user_id ) ) {
            Noey_Debug::log( 'api.auth', 'JWT auth failed', [
                'code'    => $user_id->get_error_code(),
                'message' => $user_id->get_error_message(),
                'route'   => $request->get_route(),
            ], null, 'warning' );
            return $user_id;
        }

        Noey_Debug::log( 'api.auth', 'JWT auth OK', [
            'user_id' => $user_id,
            'route'   => $request->get_route(),
        ], $user_id, 'debug' );

        return $user_id;
    }

    /**
     * Authenticate and require the user to be a noey_parent.
     *
     * @return int|WP_Error  Parent user ID on success.
     */
    protected function require_parent( WP_REST_Request $request ): int|WP_Error {
        $user_id = $this->authenticate( $request );
        if ( is_wp_error( $user_id ) ) {
            return $user_id;
        }

        if ( ! $this->is_parent( $user_id ) ) {
            Noey_Debug::log( 'api.auth', 'Parent role required but user is not a parent', [
                'user_id' => $user_id,
                'roles'   => $this->get_user_roles( $user_id ),
            ], $user_id, 'warning' );
            return new WP_Error( 'noey_forbidden', 'Parent account required.', [ 'status' => 403 ] );
        }

        return $user_id;
    }

    /**
     * Authenticate and resolve the active child for this request.
     *
     * Handles two cases:
     *  1. Request JWT belongs to a parent → resolve active child from meta.
     *  2. Request JWT belongs to a child → use that child directly.
     *
     * @param  WP_REST_Request $request
     * @return array{parent_id:int,child_id:int}|WP_Error
     */
    protected function require_child_context( WP_REST_Request $request ): array|WP_Error {
        $user_id = $this->authenticate( $request );
        if ( is_wp_error( $user_id ) ) {
            return $user_id;
        }

        if ( $this->is_parent( $user_id ) ) {
            $child_id = (int) get_user_meta( $user_id, 'noey_active_child_id', true );
            if ( ! $child_id ) {
                return new WP_Error( 'noey_no_active_child', 'No active student profile selected.', [ 'status' => 422 ] );
            }
            return [ 'parent_id' => $user_id, 'child_id' => $child_id ];
        }

        if ( $this->is_child( $user_id ) ) {
            $parent_id = (int) get_user_meta( $user_id, 'noey_parent_id', true );
            return [ 'parent_id' => $parent_id, 'child_id' => $user_id ];
        }

        return new WP_Error( 'noey_forbidden', 'Valid parent or student account required.', [ 'status' => 403 ] );
    }

    // ── Response Helpers ──────────────────────────────────────────────────────

    protected function success( mixed $data, int $status = 200 ): WP_REST_Response {
        return new WP_REST_Response( [
            'success' => true,
            'data'    => $data,
        ], $status );
    }

    protected function created( mixed $data ): WP_REST_Response {
        return $this->success( $data, 201 );
    }

    protected function error( string $code, string $message, int $status = 400, array $extra = [] ): WP_Error {
        return new WP_Error( $code, $message, array_merge( [ 'status' => $status ], $extra ) );
    }

    // ── Role Helpers ──────────────────────────────────────────────────────────

    protected function is_parent( int $user_id ): bool {
        $user = get_userdata( $user_id );
        return $user && in_array( 'noey_parent', (array) $user->roles, true );
    }

    protected function is_child( int $user_id ): bool {
        $user = get_userdata( $user_id );
        return $user && in_array( 'noey_child', (array) $user->roles, true );
    }

    protected function get_user_roles( int $user_id ): array {
        $user = get_userdata( $user_id );
        return $user ? (array) $user->roles : [];
    }

    // ── Child ID Resolution (with parent override) ────────────────────────────

    /**
     * Resolve the child_id for a results request.
     *
     * If the caller is a parent and supplies ?child_id=X, skip context-switching
     * and return X directly — after verifying the parent owns that child.
     * Otherwise falls back to require_child_context() (active-child pattern).
     *
     * @return int|WP_Error  Resolved child_id.
     */
    protected function resolve_child_id( WP_REST_Request $request ): int|WP_Error {
        $override = (int) $request->get_param( 'child_id' );

        if ( $override ) {
            $user_id = $this->authenticate( $request );
            if ( is_wp_error( $user_id ) ) return $user_id;

            if ( ! $this->is_parent( $user_id ) ) {
                return new WP_Error( 'noey_forbidden', 'child_id override is only available to parent accounts.', [ 'status' => 403 ] );
            }

            if ( ! Noey_Children_Service::owns_child( $user_id, $override ) ) {
                return new WP_Error( 'noey_forbidden', 'You do not have access to that student profile.', [ 'status' => 403 ] );
            }

            return $override;
        }

        $ctx = $this->require_child_context( $request );
        if ( is_wp_error( $ctx ) ) return $ctx;

        return $ctx['child_id'];
    }

    // ── Pagination Helper ─────────────────────────────────────────────────────

    protected function paginate( WP_REST_Request $request ): array {
        return [
            'page'     => max( 1, (int) $request->get_param( 'page' ) ),
            'per_page' => max( 1, min( 100, (int) ( $request->get_param( 'per_page' ) ?: 20 ) ) ),
        ];
    }
}
