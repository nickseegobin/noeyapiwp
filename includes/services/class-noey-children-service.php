<?php
/**
 * Noey_Children_Service — Parent ↔ Child account management.
 *
 * Supports up to NOEY_MAX_CHILDREN (3) children per parent.
 * Relationships stored in wp_noey_children table.
 * Active child tracked in noey_active_child_id user meta on the parent.
 *
 * @package NoeyAPI
 */

defined( 'ABSPATH' ) || exit;

class Noey_Children_Service {

    // ── Create ────────────────────────────────────────────────────────────────

    /**
     * Create a new child WP user and link it to the parent.
     *
     * @param int   $parent_id
     * @param array $data {
     *   @type string $display_name  Required.
     *   @type string $username      Required. Must be unique.
     *   @type string $password      Required.
     *   @type string $standard      e.g. 'std_4'.
     *   @type string $term          e.g. 'term_1'.
     *   @type int    $age           Optional.
     *   @type int    $avatar_index  1–5 (default 1).
     * }
     * @return array|WP_Error  Child profile on success.
     */
    public static function create_child( int $parent_id, array $data ): array|WP_Error {
        Noey_Debug::log( 'children.create', 'Creating child account', [
            'parent_id'    => $parent_id,
            'display_name' => $data['display_name'] ?? '',
        ], $parent_id, 'info' );

        // Enforce max children
        $count = self::child_count( $parent_id );
        if ( $count >= NOEY_MAX_CHILDREN ) {
            Noey_Debug::log( 'children.create', 'Max children limit reached', [
                'parent_id' => $parent_id,
                'count'     => $count,
            ], $parent_id, 'warning' );
            return new WP_Error( 'noey_max_children', 'Maximum of ' . NOEY_MAX_CHILDREN . ' student profiles allowed per account.', [ 'status' => 422 ] );
        }

        // Validate required fields
        $username     = sanitize_user( $data['username'] ?? '' );
        $display_name = sanitize_text_field( $data['display_name'] ?? '' );
        $password     = $data['password'] ?? '';

        if ( ! $username || ! $display_name || ! $password ) {
            return new WP_Error( 'noey_missing_fields', 'username, display_name, and password are required.', [ 'status' => 422 ] );
        }

        if ( username_exists( $username ) ) {
            return new WP_Error( 'noey_username_taken', 'That username is already in use.', [ 'status' => 409 ] );
        }

        // Create WP user
        $child_id = wp_create_user( $username, $password, $username . '@noey.local' );
        if ( is_wp_error( $child_id ) ) {
            Noey_Debug::log( 'children.create', 'wp_create_user failed', [
                'parent_id' => $parent_id,
                'error'     => $child_id->get_error_message(),
            ], $parent_id, 'error' );
            return new WP_Error( 'noey_create_failed', 'Failed to create student account.', [ 'status' => 500 ] );
        }

        // Set role and display name
        $child_user = new WP_User( $child_id );
        $child_user->set_role( 'noey_child' );

        wp_update_user( [
            'ID'           => $child_id,
            'display_name' => $display_name,
        ] );

        // Store parent link on child user meta
        update_user_meta( $child_id, 'noey_parent_id', $parent_id );

        // Insert relationship row
        global $wpdb;
        $wpdb->insert(
            $wpdb->prefix . 'noey_children',
            [
                'parent_id'    => $parent_id,
                'child_id'     => $child_id,
                'display_name' => $display_name,
                'standard'     => sanitize_text_field( $data['standard'] ?? '' ),
                'term'         => sanitize_text_field( $data['term'] ?? '' ),
                'age'          => isset( $data['age'] ) ? (int) $data['age'] : null,
                'avatar_index' => max( 1, min( 5, (int) ( $data['avatar_index'] ?? 1 ) ) ),
                'created_at'   => current_time( 'mysql', true ),
            ],
            [ '%d', '%d', '%s', '%s', '%s', '%d', '%d', '%s' ]
        );

        // Auto-set as active child if this is the first one
        if ( $count === 0 ) {
            update_user_meta( $parent_id, 'noey_active_child_id', $child_id );
        }

        Noey_Debug::log( 'children.create', 'Child account created', [
            'parent_id' => $parent_id,
            'child_id'  => $child_id,
            'username'  => $username,
        ], $parent_id, 'info' );

        return self::get_child( $child_id );
    }

    // ── Read ──────────────────────────────────────────────────────────────────

    /**
     * List all children for a parent.
     *
     * @return array[]
     */
    public static function list_children( int $parent_id ): array {
        global $wpdb;

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}noey_children WHERE parent_id = %d ORDER BY child_row_id ASC",
                $parent_id
            ),
            ARRAY_A
        ) ?: [];

        Noey_Debug::log( 'children.list', 'Listed children', [
            'parent_id' => $parent_id,
            'count'     => count( $rows ),
        ], $parent_id, 'debug' );

        return array_map( [ __CLASS__, 'format_child_row' ], $rows );
    }

    /**
     * Get a single child profile by child WP user ID.
     */
    public static function get_child( int $child_id ): array|WP_Error {
        global $wpdb;

        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}noey_children WHERE child_id = %d",
                $child_id
            ),
            ARRAY_A
        );

        if ( ! $row ) {
            return new WP_Error( 'noey_not_found', 'Student profile not found.', [ 'status' => 404 ] );
        }

        return self::format_child_row( $row );
    }

    /**
     * Get child by parent_id + child_id (ownership check).
     */
    public static function get_child_for_parent( int $parent_id, int $child_id ): array|WP_Error {
        global $wpdb;

        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}noey_children WHERE parent_id = %d AND child_id = %d",
                $parent_id,
                $child_id
            ),
            ARRAY_A
        );

        if ( ! $row ) {
            return new WP_Error( 'noey_not_found', 'Student profile not found or does not belong to this account.', [ 'status' => 404 ] );
        }

        return self::format_child_row( $row );
    }

    // ── Update ────────────────────────────────────────────────────────────────

    /**
     * Update a child's profile fields.
     *
     * @param int   $parent_id
     * @param int   $child_id
     * @param array $data  Keys: display_name, standard, term, age, avatar_index
     */
    public static function update_child( int $parent_id, int $child_id, array $data ): array|WP_Error {
        Noey_Debug::log( 'children.update', 'Updating child profile', [
            'parent_id' => $parent_id,
            'child_id'  => $child_id,
            'fields'    => array_keys( $data ),
        ], $parent_id, 'info' );

        // Ownership check
        $existing = self::get_child_for_parent( $parent_id, $child_id );
        if ( is_wp_error( $existing ) ) {
            return $existing;
        }

        global $wpdb;
        $update = [];
        $format = [];

        if ( isset( $data['display_name'] ) ) {
            $update['display_name'] = sanitize_text_field( $data['display_name'] );
            $format[]               = '%s';
            wp_update_user( [ 'ID' => $child_id, 'display_name' => $update['display_name'] ] );
        }
        if ( isset( $data['standard'] ) ) {
            $update['standard'] = sanitize_text_field( $data['standard'] );
            $format[]           = '%s';
        }
        if ( isset( $data['term'] ) ) {
            $update['term'] = sanitize_text_field( $data['term'] );
            $format[]       = '%s';
        }
        if ( isset( $data['age'] ) ) {
            $update['age'] = (int) $data['age'];
            $format[]      = '%d';
        }
        if ( isset( $data['avatar_index'] ) ) {
            $update['avatar_index'] = max( 1, min( 5, (int) $data['avatar_index'] ) );
            $format[]               = '%d';
        }

        if ( ! empty( $update ) ) {
            $wpdb->update(
                $wpdb->prefix . 'noey_children',
                $update,
                [ 'child_id' => $child_id ],
                $format,
                [ '%d' ]
            );
        }

        return self::get_child( $child_id );
    }

    // ── Delete ────────────────────────────────────────────────────────────────

    /**
     * Remove a child profile (and optionally the WP user).
     */
    public static function remove_child( int $parent_id, int $child_id ): true|WP_Error {
        $existing = self::get_child_for_parent( $parent_id, $child_id );
        if ( is_wp_error( $existing ) ) {
            return $existing;
        }

        global $wpdb;

        // Remove relationship
        $wpdb->delete( $wpdb->prefix . 'noey_children', [ 'child_id' => $child_id ], [ '%d' ] );

        // Delete WP user
        require_once ABSPATH . 'wp-admin/includes/user.php';
        wp_delete_user( $child_id );

        // Clear active child if it was this one
        $active = (int) get_user_meta( $parent_id, 'noey_active_child_id', true );
        if ( $active === $child_id ) {
            delete_user_meta( $parent_id, 'noey_active_child_id' );
        }

        Noey_Debug::log( 'children.remove', 'Child profile removed', [
            'parent_id' => $parent_id,
            'child_id'  => $child_id,
        ], $parent_id, 'info' );

        return true;
    }

    // ── Profile Switching ─────────────────────────────────────────────────────

    /**
     * Switch the parent's active child to the given child_id.
     *
     * @return array  { active_child_id, child_profile }
     */
    public static function switch_to_child( int $parent_id, int $child_id ): array|WP_Error {
        $child = self::get_child_for_parent( $parent_id, $child_id );
        if ( is_wp_error( $child ) ) {
            return $child;
        }

        update_user_meta( $parent_id, 'noey_active_child_id', $child_id );

        // Flush the WP object cache for this user so any persistent cache
        // (Redis, Memcached) immediately reflects the new active child.
        clean_user_cache( $parent_id );

        Noey_Debug::log( 'children.switch', 'Switched active child', [
            'parent_id' => $parent_id,
            'child_id'  => $child_id,
        ], $parent_id, 'info' );

        return [
            'active_child_id' => $child_id,
            'child'           => $child,
        ];
    }

    /**
     * Clear the active child (switch back to parent context).
     */
    public static function switch_to_parent( int $parent_id ): array {
        delete_user_meta( $parent_id, 'noey_active_child_id' );

        Noey_Debug::log( 'children.switch', 'Switched to parent context', [
            'parent_id' => $parent_id,
        ], $parent_id, 'info' );

        return [ 'active_child_id' => null ];
    }

    // ── Utilities ─────────────────────────────────────────────────────────────

    public static function child_count( int $parent_id ): int {
        global $wpdb;
        return (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->prefix}noey_children WHERE parent_id = %d",
                $parent_id
            )
        );
    }

    /**
     * Verify a child belongs to a parent (lightweight check).
     */
    public static function owns_child( int $parent_id, int $child_id ): bool {
        global $wpdb;
        return (bool) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT 1 FROM {$wpdb->prefix}noey_children WHERE parent_id = %d AND child_id = %d",
                $parent_id,
                $child_id
            )
        );
    }

    // ── Formatter ─────────────────────────────────────────────────────────────

    private static function format_child_row( array $row ): array {
        return [
            'child_id'     => (int) $row['child_id'],
            'display_name' => $row['display_name'],
            'standard'     => $row['standard'],
            'term'         => $row['term'],
            'age'          => $row['age'] !== null ? (int) $row['age'] : null,
            'avatar_index' => (int) $row['avatar_index'],
            'created_at'   => $row['created_at'],
        ];
    }
}
