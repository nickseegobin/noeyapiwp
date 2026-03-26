<?php
/**
 * Noey_JWT — Lightweight HS256 JSON Web Token handler.
 *
 * No external library required. Uses NOEY_JWT_SECRET constant (define in wp-config.php),
 * falling back to JWT_AUTH_SECRET_KEY if present.
 *
 * @package NoeyAPI
 */

defined( 'ABSPATH' ) || exit;

class Noey_JWT {

    // ── Encode ────────────────────────────────────────────────────────────────

    /**
     * Issue a signed JWT for the given WP user ID.
     *
     * @param  int    $user_id  WP user ID (always the parent account holder).
     * @return string           Signed JWT string.
     */
    public static function encode( int $user_id ): string {
        $now     = time();
        $header  = self::b64u( wp_json_encode( [ 'typ' => 'JWT', 'alg' => 'HS256' ] ) );
        $payload = self::b64u( wp_json_encode( [
            'iss'     => get_site_url(),
            'iat'     => $now,
            'exp'     => $now + NOEY_JWT_EXPIRY,
            'user_id' => $user_id,
        ] ) );
        $sig     = self::b64u( self::hmac( "{$header}.{$payload}" ) );

        Noey_Debug::log( 'jwt.encode', 'Token issued', [
            'user_id' => $user_id,
            'exp'     => $now + NOEY_JWT_EXPIRY,
        ], $user_id, 'debug' );

        return "{$header}.{$payload}.{$sig}";
    }

    // ── Decode ────────────────────────────────────────────────────────────────

    /**
     * Validate and decode a JWT string.
     *
     * @param  string           $token  Raw JWT string.
     * @return array|WP_Error           ['user_id' => int] on success, WP_Error on failure.
     */
    public static function decode( string $token ): array|WP_Error {
        $parts = explode( '.', $token );

        if ( count( $parts ) !== 3 ) {
            Noey_Debug::log( 'jwt.decode', 'Malformed token — wrong segment count', [], null, 'warning' );
            return new WP_Error( 'jwt_invalid', 'Malformed token.', [ 'status' => 401 ] );
        }

        [ $header_b64, $payload_b64, $sig_b64 ] = $parts;

        // Verify signature
        $expected = self::b64u( self::hmac( "{$header_b64}.{$payload_b64}" ) );
        if ( ! hash_equals( $expected, $sig_b64 ) ) {
            Noey_Debug::log( 'jwt.decode', 'Invalid token signature', [], null, 'warning' );
            return new WP_Error( 'jwt_invalid', 'Invalid token signature.', [ 'status' => 401 ] );
        }

        // Decode payload
        $payload = json_decode(
            base64_decode( strtr( $payload_b64, '-_', '+/' ) ),
            true
        );

        if ( ! is_array( $payload ) || empty( $payload['user_id'] ) || ! isset( $payload['exp'] ) ) {
            Noey_Debug::log( 'jwt.decode', 'Invalid payload structure', [], null, 'warning' );
            return new WP_Error( 'jwt_invalid', 'Invalid token payload.', [ 'status' => 401 ] );
        }

        // Check expiry
        if ( $payload['exp'] < time() ) {
            Noey_Debug::log( 'jwt.decode', 'Token expired', [
                'user_id' => $payload['user_id'],
                'exp'     => $payload['exp'],
            ], (int) $payload['user_id'], 'info' );
            return new WP_Error( 'jwt_expired', 'Token has expired. Please log in again.', [ 'status' => 401 ] );
        }

        // Verify user still exists
        $user = get_user_by( 'id', (int) $payload['user_id'] );
        if ( ! $user ) {
            Noey_Debug::log( 'jwt.decode', 'Token user not found in WP', [
                'user_id' => $payload['user_id'],
            ], null, 'warning' );
            return new WP_Error( 'jwt_invalid', 'Token user no longer exists.', [ 'status' => 401 ] );
        }

        return [ 'user_id' => (int) $payload['user_id'] ];
    }

    // ── From Request ─────────────────────────────────────────────────────────

    /**
     * Extract and validate the JWT from the current HTTP request's Authorization header.
     *
     * @return int|WP_Error  WP user ID on success, WP_Error on failure.
     */
    public static function from_request(): int|WP_Error {
        $auth = $_SERVER['HTTP_AUTHORIZATION'] ?? '';

        // Some server configs strip the header — try alternate location
        if ( ! $auth && ! empty( $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ) ) {
            $auth = $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
        }
        if ( ! $auth && function_exists( 'apache_request_headers' ) ) {
            $headers = apache_request_headers();
            $auth    = $headers['Authorization'] ?? $headers['authorization'] ?? '';
        }

        if ( ! $auth || stripos( $auth, 'Bearer ' ) !== 0 ) {
            return new WP_Error( 'jwt_missing', 'Authorization: Bearer token required.', [ 'status' => 401 ] );
        }

        $token  = trim( substr( $auth, 7 ) );
        $result = self::decode( $token );

        if ( is_wp_error( $result ) ) {
            return $result;
        }

        return $result['user_id'];
    }

    // ── Private Helpers ───────────────────────────────────────────────────────

    private static function hmac( string $data ): string {
        return hash_hmac( 'sha256', $data, self::secret(), true );
    }

    private static function secret(): string {
        if ( defined( 'NOEY_JWT_SECRET' ) && NOEY_JWT_SECRET ) {
            return NOEY_JWT_SECRET;
        }
        if ( defined( 'JWT_AUTH_SECRET_KEY' ) && JWT_AUTH_SECRET_KEY ) {
            return JWT_AUTH_SECRET_KEY;
        }
        // Last resort — derive from WP salts (deterministic but not ideal)
        return hash( 'sha256', AUTH_KEY . DB_PASSWORD );
    }

    private static function b64u( string $data ): string {
        return rtrim( strtr( base64_encode( $data ), '+/', '-_' ), '=' );
    }
}
