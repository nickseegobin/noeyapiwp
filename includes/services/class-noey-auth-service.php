<?php
/**
 * Noey_Auth_Service — Authentication business logic.
 *
 * v2.1 changes:
 *  - register()     — parent: email as user_login, first_name/last_name/phone stored,
 *                     display_name = first_name, WP nickname = first_name
 *  - login()        — tries email lookup first, falls back to user_login
 *  - get_profile()  — each child in response includes noey_nickname from user meta
 *
 * @package NoeyAPI
 */

defined( 'ABSPATH' ) || exit;

class Noey_Auth_Service {

    // ── Register ──────────────────────────────────────────────────────────────

    /**
     * Register a new parent account and return a JWT.
     *
     * v2.1: Parents log in with email. No separate username field.
     * display_name and WP nickname are set to first_name.
     *
     * @param  array $data { first_name, last_name, email, phone, password, avatar_index }
     * @return array|WP_Error
     */
    public static function register( array $data ): array|WP_Error {
        $first_name   = sanitize_text_field( $data['first_name']  ?? '' );
        $last_name    = sanitize_text_field( $data['last_name']   ?? '' );
        $email        = sanitize_email(      $data['email']       ?? '' );
        $phone        = sanitize_text_field( $data['phone']       ?? '' );
        $password     = $data['password'] ?? '';
        $avatar_index = max( 1, min( 10, (int) ( $data['avatar_index'] ?? 1 ) ) );

        // Validate required fields
        if ( ! $first_name || ! $last_name || ! $email || ! $password ) {
            return new WP_Error(
                'noey_missing_fields',
                'first_name, last_name, email and password are all required.',
                [ 'status' => 422 ]
            );
        }

        if ( ! is_email( $email ) ) {
            return new WP_Error( 'noey_invalid_email', 'Please provide a valid email address.', [ 'status' => 422 ] );
        }

        if ( email_exists( $email ) ) {
            return new WP_Error( 'noey_email_taken', 'An account with that email already exists.', [ 'status' => 409 ] );
        }

        // Create WP user — email is the login identifier for parents
        $user_id = wp_create_user( $email, $password, $email );
        if ( is_wp_error( $user_id ) ) {
            Noey_Debug::log( 'auth.register', 'wp_create_user failed', [
                'email' => $email,
                'error' => $user_id->get_error_message(),
            ], null, 'error' );
            return new WP_Error( 'noey_registration_failed', 'Account creation failed. Please try again.', [ 'status' => 500 ] );
        }

        // Set role and all name fields
        $user = new WP_User( $user_id );
        $user->set_role( 'noey_parent' );

        wp_update_user( [
            'ID'           => $user_id,
            'first_name'   => $first_name,
            'last_name'    => $last_name,
            'display_name' => $first_name,   // Display = first name only
            'nickname'     => $first_name,   // WP nickname = first name
        ] );

        // Store additional meta
        update_user_meta( $user_id, 'noey_phone',        $phone );
        update_user_meta( $user_id, 'noey_avatar_index', $avatar_index );

        // Grant welcome tokens
        Noey_Token_Service::grant_on_registration( $user_id );

        $token = Noey_JWT::encode( $user_id );

        Noey_Debug::log( 'auth.register', 'Parent account registered', [
            'user_id'    => $user_id,
            'email'      => $email,
            'first_name' => $first_name,
        ], $user_id, 'info' );

        return [
            'token'           => $token,
            'expires_in'      => NOEY_JWT_EXPIRY,
            'user_id'         => $user_id,
            'display_name'    => $first_name,
            'email'           => $email,
            'role'            => 'parent',
            'active_child_id' => null,
        ];
    }

    // ── Login ─────────────────────────────────────────────────────────────────

    /**
     * Authenticate and return a JWT.
     *
     * v2.1: Tries email lookup first, falls back to user_login.
     * Parents send email as their identifier. Children are not
     * expected to log in directly.
     *
     * @param  string $username  Email address or user_login
     * @param  string $password
     * @return array|WP_Error
     */
    public static function login( string $username, string $password ): array|WP_Error {
        Noey_Debug::log( 'auth.login', 'Login attempt', [
            'identifier' => $username,
            'ip'         => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
        ], null, 'info' );

        // Try email first (parent login path), fall back to login field
        $user = null;
        if ( is_email( $username ) ) {
            $user = get_user_by( 'email', $username );
        }
        if ( ! $user ) {
            $user = get_user_by( 'login', $username );
        }

        // Authenticate via wp_authenticate to go through all WP hooks
        if ( $user ) {
            $authenticated = wp_authenticate( $user->user_login, $password );
        } else {
            $authenticated = wp_authenticate( $username, $password );
        }

        if ( is_wp_error( $authenticated ) ) {
            Noey_Debug::log( 'auth.login', 'Login failed — bad credentials', [
                'identifier' => $username,
                'code'       => $authenticated->get_error_code(),
            ], null, 'warning' );
            return new WP_Error( 'noey_invalid_credentials', 'Invalid email or password.', [ 'status' => 401 ] );
        }

        $roles   = (array) $authenticated->roles;
        $allowed = [ 'noey_parent', 'noey_child', 'administrator' ];

        if ( empty( array_intersect( $roles, $allowed ) ) ) {
            Noey_Debug::log( 'auth.login', 'Login denied — role not allowed', [
                'user_id' => $authenticated->ID,
                'roles'   => $roles,
            ], $authenticated->ID, 'warning' );
            return new WP_Error( 'noey_forbidden', 'Your account type cannot access this platform.', [ 'status' => 403 ] );
        }

        $token        = Noey_JWT::encode( $authenticated->ID );
        $role         = in_array( 'noey_parent', $roles, true ) ? 'parent'
                      : ( in_array( 'noey_child', $roles, true ) ? 'child' : 'admin' );
        $active_child = $role === 'parent'
                      ? (int) get_user_meta( $authenticated->ID, 'noey_active_child_id', true ) ?: null
                      : null;

        Noey_Debug::log( 'auth.login', 'Login successful', [
            'user_id'         => $authenticated->ID,
            'role'            => $role,
            'active_child_id' => $active_child,
        ], $authenticated->ID, 'info' );

        return [
            'token'           => $token,
            'expires_in'      => NOEY_JWT_EXPIRY,
            'user_id'         => $authenticated->ID,
            'display_name'    => $authenticated->display_name,
            'email'           => $authenticated->user_email,
            'role'            => $role,
            'active_child_id' => $active_child,
        ];
    }

    // ── Profile (me) ─────────────────────────────────────────────────────────

    /**
     * Return the authenticated user's profile, token balance, and children.
     *
     * v2.1: Each child in the children array now includes noey_nickname
     * from user meta so React can display the Caribbean leaderboard name.
     */
    public static function get_profile( int $user_id ): array|WP_Error {
        $user = get_userdata( $user_id );
        if ( ! $user ) {
            return new WP_Error( 'noey_not_found', 'User not found.', [ 'status' => 404 ] );
        }

        $roles        = (array) $user->roles;
        $is_parent    = in_array( 'noey_parent', $roles, true );
        $active_child = $is_parent
                      ? (int) get_user_meta( $user_id, 'noey_active_child_id', true ) ?: null
                      : null;
        $balance      = $is_parent ? Noey_Token_Service::get_balance( $user_id ) : null;

        $profile = [
            'user_id'         => $user_id,
            'display_name'    => $user->display_name,
            'first_name'      => get_user_meta( $user_id, 'first_name', true ),
            'last_name'       => get_user_meta( $user_id, 'last_name', true ),
            'email'           => $user->user_email,
            'role'            => $is_parent ? 'parent' : 'child',
            'active_child_id' => $active_child,
            'token_balance'   => $balance,
            'avatar_index'    => (int) get_user_meta( $user_id, 'noey_avatar_index', true ) ?: 1,
        ];

        if ( $is_parent ) {
            $children        = Noey_Children_Service::list_children( $user_id );
            $profile['children'] = $children; // nickname included via format_child_row()
        }

        Noey_Debug::log( 'auth.profile', 'Profile fetched', [
            'user_id'    => $user_id,
            'has_active' => (bool) $active_child,
        ], $user_id, 'debug' );

        return $profile;
    }

    // ── Profile Update ────────────────────────────────────────────────────────

    /**
     * Update a parent's profile fields.
     *
     * @param  int   $parent_id
     * @param  array $data  Keys: first_name, last_name, display_name, avatar_index
     * @return array|WP_Error
     */
    public static function update_profile( int $parent_id, array $data ): array|WP_Error {
        $first_name   = isset( $data['first_name'] )   ? trim( $data['first_name'] )   : null;
        $last_name    = isset( $data['last_name'] )    ? trim( $data['last_name'] )    : null;
        $display_name = isset( $data['display_name'] ) ? trim( $data['display_name'] ) : null;
        $avatar_index = isset( $data['avatar_index'] ) ? (int) $data['avatar_index']   : null;

        if ( $first_name === null && $last_name === null && $display_name === null && $avatar_index === null ) {
            return new WP_Error(
                'noey_missing_fields',
                'Provide at least one field to update.',
                [ 'status' => 422 ]
            );
        }

        $update = [ 'ID' => $parent_id ];

        if ( $first_name !== null ) {
            if ( strlen( $first_name ) < 2 ) {
                return new WP_Error( 'noey_invalid_first_name', 'First name must be at least 2 characters.', [ 'status' => 422 ] );
            }
            $update['first_name']   = $first_name;
            $update['display_name'] = $first_name; // keep display_name in sync with first_name
            $update['nickname']     = $first_name;
        }

        if ( $last_name !== null ) {
            $update['last_name'] = $last_name;
        }

        // Allow explicit display_name override if provided separately
        if ( $display_name !== null && ! isset( $update['display_name'] ) ) {
            $update['display_name'] = $display_name;
        }

        wp_update_user( $update );

        if ( $avatar_index !== null ) {
            update_user_meta( $parent_id, 'noey_avatar_index', max( 1, min( 10, $avatar_index ) ) );
        }

        clean_user_cache( $parent_id );

        $user         = get_userdata( $parent_id );
        $avatar_saved = (int) get_user_meta( $parent_id, 'noey_avatar_index', true ) ?: 1;

        Noey_Debug::log( 'auth.update_profile', 'Profile updated', [
            'user_id'    => $parent_id,
            'fields'     => array_keys( $data ),
        ], $parent_id, 'info' );

        return [
            'user_id'      => $parent_id,
            'display_name' => $user->display_name,
            'first_name'   => get_user_meta( $parent_id, 'first_name', true ),
            'last_name'    => get_user_meta( $parent_id, 'last_name', true ),
            'avatar_index' => $avatar_saved,
        ];
    }

    // ── PIN ───────────────────────────────────────────────────────────────────

    /**
     * Set or update the parent's PIN.
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
     */
    public static function verify_pin( int $parent_id, string $pin ): true|WP_Error {
        Noey_Debug::log( 'auth.pin', 'PIN verification attempt', [ 'parent_id' => $parent_id ], $parent_id, 'debug' );

        $locked_until = (int) get_user_meta( $parent_id, 'noey_pin_locked_until', true );
        if ( $locked_until && time() < $locked_until ) {
            $remaining = $locked_until - time();
            Noey_Debug::log( 'auth.pin', 'PIN locked', [
                'parent_id'         => $parent_id,
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

            return new WP_Error(
                'noey_pin_invalid',
                'Incorrect PIN. ' . ( NOEY_PIN_MAX_ATTEMPTS - $attempts ) . ' attempt(s) remaining.',
                [ 'status' => 401 ]
            );
        }

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
            'pin_set'           => ! empty( $hash ),
            'is_locked'         => $is_locked,
            'locked_until'      => $is_locked ? $locked_until : null,
            'seconds_remaining' => $is_locked ? ( $locked_until - time() ) : 0,
        ];
    }
}