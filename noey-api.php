<?php
/**
 * Plugin Name:  NoeyAPI
 * Plugin URI:   https://noeyai.com
 * Description:  Unified REST API for the Noey AI learning platform. React / Next.js interface only — no WP front-end output.
 * Version:      1.0.0
 * Author:       Noey AI
 * Requires PHP: 8.0
 * Requires at least: 6.3
 *
 * @package NoeyAPI
 */

defined( 'ABSPATH' ) || exit;

// ── Constants ────────────────────────────────────────────────────────────────
define( 'NOEY_VERSION',            '1.0.0' );
define( 'NOEY_DB_VERSION',         '1.0' );
define( 'NOEY_PLUGIN_FILE',        __FILE__ );
define( 'NOEY_PLUGIN_DIR',         plugin_dir_path( __FILE__ ) );
define( 'NOEY_PLUGIN_URL',         plugin_dir_url( __FILE__ ) );
define( 'NOEY_REST_NAMESPACE',     'noey/v1' );

// Children
define( 'NOEY_MAX_CHILDREN',       3 );

// Tokens
define( 'NOEY_FREE_TOKEN_GRANT',   3 );   // on registration
define( 'NOEY_FREE_TOKEN_MONTHLY', 3 );   // free-tier monthly reset

// PIN
define( 'NOEY_PIN_MAX_ATTEMPTS',   5 );
define( 'NOEY_PIN_LOCKOUT',        900 ); // seconds (15 min)

// JWT
define( 'NOEY_JWT_EXPIRY',         604800 ); // 7 days

// Debug
define( 'NOEY_DEBUG_MAX_LOGS',     2000 );

// ── Autoloader ───────────────────────────────────────────────────────────────
spl_autoload_register( static function ( string $class ): void {
    $map = [
        // Core
        'Noey_Activator'        => 'includes/class-noey-activator.php',
        'Noey_Core'             => 'includes/class-noey-core.php',
        'Noey_WooCommerce'      => 'includes/class-noey-woocommerce.php',
        // Debug
        'Noey_Debug'            => 'includes/debug/class-noey-debug.php',
        // Auth
        'Noey_JWT'              => 'includes/auth/class-noey-jwt.php',
        // API layer
        'Noey_API_Base'         => 'includes/api/class-noey-api-base.php',
        'Noey_Auth_API'         => 'includes/api/class-noey-auth-api.php',
        'Noey_Children_API'     => 'includes/api/class-noey-children-api.php',
        'Noey_Tokens_API'       => 'includes/api/class-noey-tokens-api.php',
        'Noey_Exams_API'        => 'includes/api/class-noey-exams-api.php',
        'Noey_Results_API'      => 'includes/api/class-noey-results-api.php',
        'Noey_Insights_API'     => 'includes/api/class-noey-insights-api.php',
        // Services
        'Noey_Auth_Service'     => 'includes/services/class-noey-auth-service.php',
        'Noey_Children_Service' => 'includes/services/class-noey-children-service.php',
        'Noey_Token_Service'    => 'includes/services/class-noey-token-service.php',
        'Noey_Exam_Service'     => 'includes/services/class-noey-exam-service.php',
        'Noey_Results_Service'  => 'includes/services/class-noey-results-service.php',
        'Noey_Insight_Service'  => 'includes/services/class-noey-insight-service.php',
        // Cron
        'Noey_Cron'             => 'includes/cron/class-noey-cron.php',
        // Admin
        'Noey_Admin'            => 'includes/admin/class-noey-admin.php',
        'Noey_Admin_Settings'   => 'includes/admin/class-noey-admin-settings.php',
        'Noey_Admin_Debug'      => 'includes/admin/class-noey-admin-debug.php',
        'Noey_Admin_Testing'    => 'includes/admin/class-noey-admin-testing.php',
        'Noey_Admin_Pool'       => 'includes/admin/class-noey-admin-pool.php',
        'Noey_Admin_Members'    => 'includes/admin/class-noey-admin-members.php',
        'Noey_Admin_Tokens'     => 'includes/admin/class-noey-admin-tokens.php',
    ];

    if ( isset( $map[ $class ] ) ) {
        require_once NOEY_PLUGIN_DIR . $map[ $class ];
    }
} );

// ── Activation / Deactivation ─────────────────────────────────────────────────
register_activation_hook( __FILE__, [ 'Noey_Activator', 'activate' ] );
register_deactivation_hook( __FILE__, [ 'Noey_Activator', 'deactivate' ] );

// ── Boot ──────────────────────────────────────────────────────────────────────
add_action( 'plugins_loaded', [ 'Noey_Core', 'boot' ], 10 );
