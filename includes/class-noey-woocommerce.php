<?php
/**
 * Noey_WooCommerce — WooCommerce integration.
 *
 * Connects WooCommerce product purchases to the NoeyAPI token wallet.
 *
 * How it works:
 *  1. Each WooCommerce product can carry a `_noey_token_amount` meta field.
 *     The field is exposed in the "General" tab of the Product Data panel.
 *  2. When an order reaches "completed" status, each line item is inspected.
 *     If a product has `_noey_token_amount > 0`, that amount × quantity is
 *     credited to the purchasing parent's wallet.
 *  3. Order meta `_noey_tokens_granted` is set after crediting so the hook
 *     never fires twice for the same order (e.g. manual status toggle).
 *
 * @package NoeyAPI
 */

defined( 'ABSPATH' ) || exit;

class Noey_WooCommerce {

    public static function boot(): void {
        if ( ! self::is_woocommerce_active() ) {
            return;
        }

        // Product data field — show in WooCommerce "General" tab
        add_action( 'woocommerce_product_options_general_product_data', [ __CLASS__, 'render_token_field' ] );
        add_action( 'woocommerce_process_product_meta',                 [ __CLASS__, 'save_token_field' ] );

        // Credit tokens when order is completed
        add_action( 'woocommerce_order_status_completed', [ __CLASS__, 'handle_order_completed' ], 10, 1 );

        // Also handle instant-payment gateways that skip "processing" and go straight to completed
        add_action( 'woocommerce_payment_complete', [ __CLASS__, 'handle_order_completed' ], 10, 1 );

        Noey_Debug::log( 'woocommerce', 'WooCommerce integration booted', [], null, 'debug' );
    }

    // ── Product field ─────────────────────────────────────────────────────────

    /**
     * Render the "Noey Tokens" number field in the WooCommerce General product tab.
     */
    public static function render_token_field(): void {
        echo '<div class="options_group">';

        woocommerce_wp_text_input( [
            'id'                => '_noey_token_amount',
            'label'             => __( 'Noey Tokens granted', 'noey-api' ),
            'description'       => __( 'Number of tokens credited to the buyer\'s Noey account when this product is purchased. Leave 0 or blank for no tokens.', 'noey-api' ),
            'desc_tip'          => true,
            'type'              => 'number',
            'custom_attributes' => [ 'min' => '0', 'step' => '1' ],
            'value'             => get_post_meta( get_the_ID(), '_noey_token_amount', true ) ?: '',
        ] );

        echo '</div>';
    }

    /**
     * Save the `_noey_token_amount` field when the product is saved.
     */
    public static function save_token_field( int $post_id ): void {
        $amount = (int) ( $_POST['_noey_token_amount'] ?? 0 );
        if ( $amount > 0 ) {
            update_post_meta( $post_id, '_noey_token_amount', $amount );
        } else {
            delete_post_meta( $post_id, '_noey_token_amount' );
        }
    }

    // ── Order handling ────────────────────────────────────────────────────────

    /**
     * Credit tokens for every Noey token product in a completed order.
     *
     * @param int $order_id  WooCommerce order ID.
     */
    public static function handle_order_completed( int $order_id ): void {
        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            return;
        }

        // Idempotency guard — never credit the same order twice
        if ( $order->get_meta( '_noey_tokens_granted' ) ) {
            Noey_Debug::log( 'woocommerce.order', 'Order already granted — skipping', [
                'order_id' => $order_id,
            ], null, 'debug' );
            return;
        }

        // Resolve to a WordPress user
        $wp_user_id = $order->get_customer_id();
        if ( ! $wp_user_id ) {
            Noey_Debug::log( 'woocommerce.order', 'Order has no WP user — cannot credit tokens', [
                'order_id' => $order_id,
            ], null, 'warning' );
            return;
        }

        // Ensure this user is a noey_parent (or an admin) — only parents hold wallets
        $user  = get_userdata( $wp_user_id );
        $roles = $user ? (array) $user->roles : [];

        if ( ! array_intersect( $roles, [ 'noey_parent', 'administrator' ] ) ) {
            Noey_Debug::log( 'woocommerce.order', 'Buyer is not a noey_parent — skipping token credit', [
                'order_id'   => $order_id,
                'wp_user_id' => $wp_user_id,
                'roles'      => $roles,
            ], $wp_user_id, 'warning' );
            return;
        }

        $total_credited = 0;
        $credited_items = [];

        foreach ( $order->get_items() as $item ) {
            /** @var WC_Order_Item_Product $item */
            $product_id   = $item->get_product_id();
            $token_amount = (int) get_post_meta( $product_id, '_noey_token_amount', true );

            if ( $token_amount <= 0 ) {
                continue;
            }

            $qty     = max( 1, (int) $item->get_quantity() );
            $to_credit = $token_amount * $qty;

            $result = Noey_Token_Service::credit(
                $wp_user_id,
                $to_credit,
                'purchase',
                (string) $order_id,
                'WooCommerce order #' . $order_id . ' — ' . $item->get_name()
            );

            if ( ! is_wp_error( $result ) ) {
                $total_credited += $to_credit;
                $credited_items[] = [
                    'product'    => $item->get_name(),
                    'token_each' => $token_amount,
                    'qty'        => $qty,
                    'total'      => $to_credit,
                ];
            }
        }

        if ( $total_credited > 0 ) {
            // Mark order so it never credits again
            $order->update_meta_data( '_noey_tokens_granted', $total_credited );
            $order->add_order_note(
                sprintf( 'Noey: %d token(s) credited to user #%d.', $total_credited, $wp_user_id )
            );
            $order->save();

            Noey_Debug::log( 'woocommerce.order', 'Tokens credited from WooCommerce order', [
                'order_id'       => $order_id,
                'wp_user_id'     => $wp_user_id,
                'total_credited' => $total_credited,
                'items'          => $credited_items,
            ], $wp_user_id, 'info' );
        }
    }

    // ── Utility ───────────────────────────────────────────────────────────────

    private static function is_woocommerce_active(): bool {
        return class_exists( 'WooCommerce' );
    }
}
