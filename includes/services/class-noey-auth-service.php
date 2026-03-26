<?php
/**
 * Noey_Auth_Service — Authentication business logic.
 *
 * Handles:
 *  - Username/password login → JWT issuance
 *  - JWT refresh
 *  - PIN set, verify, lockout
 *  - Current user profile resolution
 *
 * @package NoeyAPI
 */

defined( 'ABSPATH' ) || exit;

class Noey_Auth_Service {

    // ── Register ──────────────────────────────────────────────────────────────

    /**
     * Register a new parent account and return a JWT.
     *
     * @param  array $data { display_name, username, email, password }
     * @return array|WP_Error  Same shape as login() on success.
     */
    public static function register( array $data ): array|WP_Error {
        $username     = sanitize_user( $data['username'] ?? '' );
        $display_name = sanitize_text_field( $data['display_name'] ?? '' );
        $email        = sanitize_email( $data['email'] ?? '' );
        $password     = $data['password'] ?? '';

        if ( ! $username || ! $display_name || ! $email || ! $password ) {
            return new WP_Error( 'noey_missing_fields', 'display_name, username, email and password are all required.', [ 'status' => 422 ] );
        }
        if ( ! is_email( $email ) ) {
            return new WP_Error( 'noey_invalid_email', 'Please provide a valid email address.', [ 'status' => 422 ] );
        }
        if ( username_exists( $username ) ) {
            return new WP_Error( 'noey_username_taken', 'That username is already in use.', [ 'status' => 409 ] );
        }
        if ( email_exists( $email ) ) {
            return new WP_Error( 'noey_email_taken', 'An account with that email already exists.', [ 'status' => 409 ] );
        }

        $user_id = wp_create_user( $username, $password, $email );
        if ( is_wp_error( $user_id ) ) {
            return new WP_Error( 'noey_registration_failed', 'Account creation failed. Please try again.', [ 'status' => 500 ] );
        }

        $user = new WP_User( $user_id );
        $user->set_role( 'noey_parent' );
        wp_update_user( [ 'ID' => $user_id, 'display_name' => $display_name ] );

        // Grant welcome tokens
        Noey_Token_Service::grant_on_registration( $user_id );

        $token = Noey_JWT::encode( $user_id );

        Noey_Debug::log( 'auth.register', 'Parent account registered', [
            'user_id'  => $user_id,
            'username' => $username,
            'email'    => $email,
        ], $user_id, 'info' );

        return [
            'token'           => $token,
            'expires_in'      => NOEY_JWT_EXPIRY,
            'user_id'         => $user_id,
            'display_name'    => $display_name,
            'email'           => $email,
            'role'            => 'parent',
            'active_child_id' => null,
        ];
    }

    // ── Login ─────────────────────────────────────────────────────────────────

    /**
     * Authenticate with username + password and return a JWT.
     *
     * @param  string $username
     * @param  string $password
     * @return array|WP_Error  { token, user_id, display_name, email, role, active_child_id }
     */
    public static function login( string $username, string $password ): array|WP_Error {
        Noey_Debug::log( 'auth.login', 'Login attempt', [
            'username' => $username,
            'ip'       => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
        ], null, 'info' );

        $user = wp_authenticate( $username, $password );

        if ( is_wp_error( $user ) ) {
            Noey_Debug::log( 'auth.login', 'Login failed — bad credentials', [
                'username' => $username,
                'code'     => $user->get_error_code(),
            ], null, 'warning' );
            return new WP_Error( 'noey_invalid_credentials', 'Invalid username or password.', [ 'status' => 401 ] );
        }

        // Only noey_parent and noey_child may use the API
        $roles    = (array) $user->roles;
        $allowed  = [ 'noey_parent', 'noey_child', 'administrator' ];
        if ( empty( array_intersect( $roles, $allowed ) ) ) {
            Noey_Debug::log( 'auth.login', 'Login denied — role not allowed', [
                'user_id' => $user->ID,
                'roles'   => $roles,
            ], $user->ID, 'warning' );
            return new WP_Error( 'noey_forbidden', 'Your account type cannot access this platform.', [ 'status' => 403 ] );
        }

        $token          = Noey_JWT::encode( $user->ID );
        $role           = in_array( 'noey_parent', $roles, true ) ? 'parent' : ( in_array( 'noey_child', $roles, true ) ? 'child' : 'admin' );
        $active_child   = $role === 'parent' ? (int) get_user_meta( $user->ID, 'noey_active_child_id', true ) ?: null : null;

        Noey_Debug::log( 'auth.login', 'Login successful', [
            'user_id'         => $user->ID,
            'role'            => $role,
            'active_child_id' => $active_child,
        ], $user->ID, 'info' );

        return [
            'token'           => $token,
            'expires_in'      => NOEY_JWT_EXPIRY,
            'user_id'         => $user->ID,
            'display_name'    => $user->display_name,
            'email'           => $user->user_email,
            'role'            => $role,
            'active_child_id' => $active_child,
        ];
    }

    // ── Profile (me) ─────────────────────────────────────────────────────────

    /**
     * Return the authenticated user's profile, token balance, and active child info.
     */
    public static function get_profile( int $user_id ): array|WP_Error {
        $user = get_userdata( $user_id );
        if ( ! $user ) {
            return new WP_Error( 'noey_not_found', 'User not found.', [ 'status' => 404 ] );
        }

        $roles          = (array) $user->roles;
        $is_parent      = in_array( 'noey_parent', $roles, true );
        $active_child   = $is_parent ? (int) get_user_meta( $user_id, 'noey_active_child_id', true ) ?: null : null;
        $balance        = $is_parent ? Noey_Token_Service::get_balance( $user_id ) : null;

        $profile = [
            'user_id'         => $user_id,
            'display_name'    => $user->display_name,
            'email'           => $user->user_email,
            'role'            => $is_parent ? 'parent' : 'child',
            'active_child_id' => $active_child,
            'token_balance'   => $balance,
        ];

        if ( $is_parent ) {
            $children = Noey_Children_Service::list_children( $user_id );
            $profile['children'] = $children;
        }

        Noey_Debug::log( 'auth.profile', 'Profile fetched', [
            'user_id'    => $user_id,
            'has_active' => (bool) $active_child,
        ], $user_id, 'debug' );

        return $profile;
    }

    // ── Profile Update ────────────────────────────────────────────────────────

    /**
     * Update a parent's display_name and/or avatar_index.
     *
     * All fields are optional — at least one must be provided.
     *
     * @param  int   $parent_id
     * @param  array $data  Keys: display_name (string|null), avatar_index (int|null)
     * @return array|WP_Error  { user_id, display_name, avatar_index }
     */
    public static function update_profile( int $parent_id, array $data ): array|WP_Error {
        $display_name = isset( $data['display_name'] ) ? trim( $data['display_name'] ) : null;
        $avatar_index = isset( $data['avatar_index'] ) ? (int) $data['avatar_index'] : null;

        // At least one field required
        if ( $display_name === null && $avatar_index === null ) {
            return new WP_Error( 'noey_missing_fields', 'Provide at least one field to update (display_name or avatar_index).', [ 'status' => 422 ] );
        }

        if ( $display_name !== null ) {
            if ( strlen( $display_name ) < 2 ) {
                return new WP_Error( 'noey_invalid_display_name', 'Display name must be at least 2 characters.', [ 'status' => 422 ] );
            }
            wp_update_user( [ 'ID' => $parent_id, 'display_name' => $display_name ] );
        }

        if ( $avatar_index !== null ) {
            $avatar_index = max( 1, min( 10, $avatar_index ) );
            update_user_meta( $parent_id, 'noey_avatar_index', $avatar_index );
        }

        clean_user_cache( $parent_id );

        // Read back current state for the response
        $user         = get_userdata( $parent_id );
        $avatar_saved = (int) get_user_meta( $parent_id, 'noey_avatar_index', true ) ?: 1;

        Noey_Debug::log( 'auth.update_profile', 'Profile updated', [
            'user_id'      => $parent_id,
            'display_name' => $display_name,
            'avatar_index' => $avatar_index,
        ], $parent_id, 'info' );

        return [
            'user_id'      => $parent_id,
            'display_name' => $user->display_name,
            'avatar_index' => $avatar_saved,
        ];
    }

    // ── PIN ───────────────────────────────────────────────────────────────────

    /**
     * Set or update the parent's PIN.
     *
     * @param  int    $parent_id  Parent WP user ID.
     * @param  string $pin        4-digit string.
     * @return true|WP_Error
     */
    public static function set_pin( int $parent_id, string $pin ): true|WP_Error {
        if ( ! preg_match( '/^\d{4}$/', $pin ) ) {
            return new WP_Error( 'noey_invalid_pin', 'PIN must be exactly 4 digits.', [ 'status' => 422 ] );
        }

        $hash = password_hash( $pin, PASSWORD_DEFAULT );
        update_user_meta( $parent_id, 'noey_pin_hash', $hash );
        delete_user_meta( $parent_id, 'noey_pin_attempts' );
        delete_user_meta( $parent_id, 'noey_pin_locked_until' );

        Noey_Debug::log( 'auth.pin', 'PIN set/updated', [ 'parent_id' => $parent_id ], $parent_id, 'info' );

        return true;
    }

    /**
     * Verify the parent's PIN with rate limiting.
     *
     * @param  int    $parent_id
     * @param  string $pin
     * @return true|WP_Error
     */
    public static function verify_pin( int $parent_id, string $pin ): true|WP_Error {
        Noey_Debug::log( 'auth.pin', 'PIN verification attempt', [ 'parent_id' => $parent_id ], $parent_id, 'debug' );

        // Check lockout
        $locked_until = (int) get_user_meta( $parent_id, 'noey_pin_locked_until', true );
        if ( $locked_until && time() < $locked_until ) {
            $remaining = $locked_until - time();
            Noey_Debug::log( 'auth.pin', 'PIN locked', [
                'parent_id'        => $parent_id,
                'seconds_remaining' => $remaining,
            ], $parent_id, 'warning' );
            return new WP_Error( 'noey_pin_locked', "Too many attempts. Try again in {$remaining} seconds.", [ 'status' => 429 ] );
        }

        $hash = get_user_meta( $parent_id, 'noey_pin_hash', true );
        if ( ! $hash ) {
            return new WP_Error( 'noey_pin_not_set', 'No PIN has been configured.', [ 'status' => 422 ] );
        }

        if ( ! password_verify( $pin, $hash ) ) {
            $attempts = (int) get_user_meta( $parent_id, 'noey_pin_attempts', true ) + 1;
            update_user_meta( $parent_id, 'noey_pin_attempts', $attempts );

            if ( $attempts >= NOEY_PIN_MAX_ATTEMPTS ) {
                update_user_meta( $parent_id, 'noey_pin_locked_until', time() + NOEY_PIN_LOCKOUT );
                delete_user_meta( $parent_id, 'noey_pin_attempts' );
                Noey_Debug::log( 'auth.pin', 'PIN locked after max attempts', [
                    'parent_id' => $parent_id,
                    'attempts'  => $attempts,
                ], $parent_id, 'warning' );
                return new WP_Error( 'noey_pin_locked', 'Too many failed attempts. Account locked for 15 minutes.', [ 'status' => 429 ] );
            }

            Noey_Debug::log( 'auth.pin', 'PIN incorrect', [
                'parent_id' => $parent_id,
                'attempts'  => $attempts,
                'remaining' => NOEY_PIN_MAX_ATTEMPTS - $attempts,
            ], $parent_id, 'warning' );

            return new WP_Error( 'noey_pin_invalid', 'Incorrect PIN. ' . ( NOEY_PIN_MAX_ATTEMPTS - $attempts ) . ' attempt(s) remaining.', [ 'status' => 401 ] );
        }

        // Correct — clear attempt counter
        delete_user_meta( $parent_id, 'noey_pin_attempts' );
        delete_user_meta( $parent_id, 'noey_pin_locked_until' );

        Noey_Debug::log( 'auth.pin', 'PIN verified successfully', [ 'parent_id' => $parent_id ], $parent_id, 'info' );

        return true;
    }

    /**
     * Return PIN status for a parent.
     */
    public static function get_pin_status( int $parent_id ): array {
        $hash         = get_user_meta( $parent_id, 'noey_pin_hash', true );
        $locked_until = (int) get_user_meta( $parent_id, 'noey_pin_locked_until', true );
        $is_locked    = $locked_until && time() < $locked_until;

        return [
            'pin_set'          => ! empty( $hash ),
            'is_locked'        => $is_locked,
            'locked_until'     => $is_locked ? $locked_until : null,
            'seconds_remaining' => $is_locked ? ( $locked_until - time() ) : 0,
        ];
    }
}
