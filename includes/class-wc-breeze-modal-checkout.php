<?php
/**
 * Breeze Modal Checkout Integration
 *
 * Enables the optional "modal" checkout display mode that embeds the Breeze
 * payment page in a lightbox on the checkout page instead of redirecting.
 * Supports both the legacy shortcode checkout (via an admin-ajax flow) and
 * the WooCommerce Checkout Blocks (via a client-side fetch intercept that
 * captures the redirect URL returned by process_payment()).
 *
 * Failure recovery reuses the existing _breeze_return_token + handle_return()
 * flow on the gateway — no new public endpoints introduce a griefing vector.
 *
 * @package Breeze_Payment_Gateway
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WC_Breeze_Modal_Checkout {

    const GATEWAY_ID  = 'breeze_payment_gateway';
    const NONCE_KEY   = 'breeze_modal_nonce';
    const NONCE_ACTION = 'breeze_modal_payment';

    /** @var self|null */
    private static $instance = null;

    public static function instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_scripts' ) );

        add_action( 'wp_ajax_breeze_create_modal_payment',        array( $this, 'ajax_create_payment' ) );
        add_action( 'wp_ajax_nopriv_breeze_create_modal_payment', array( $this, 'ajax_create_payment' ) );

        add_action( 'wp_ajax_breeze_cancel_modal_payment',        array( $this, 'ajax_cancel_payment' ) );
        add_action( 'wp_ajax_nopriv_breeze_cancel_modal_payment', array( $this, 'ajax_cancel_payment' ) );

        // Tag the response from process_payment() so the Blocks JS knows this
        // redirect should be opened in a modal instead of followed by the browser.
        add_filter( 'woocommerce_payment_successful_result', array( $this, 'tag_blocks_redirect' ), 10, 2 );
    }

    /**
     * Returns the Breeze gateway origin used for postMessage targeting and
     * URL-host validation. Filterable so test/staging environments can point
     * at a different host.
     *
     * @return string e.g. "https://pay.breeze.cash"
     */
    public static function get_breeze_origin() {
        return apply_filters( 'breeze_modal_origin', 'https://pay.breeze.cash' );
    }

    /** @return WC_Breeze_Payment_Gateway|null */
    private function get_gateway() {
        if ( ! function_exists( 'WC' ) || ! WC()->payment_gateways() ) {
            return null;
        }
        $gateways = WC()->payment_gateways()->payment_gateways();
        if ( ! isset( $gateways[ self::GATEWAY_ID ] ) ) {
            return null;
        }
        $gateway = $gateways[ self::GATEWAY_ID ];
        return ( $gateway instanceof WC_Breeze_Payment_Gateway ) ? $gateway : null;
    }

    /**
     * Detect whether the active checkout page is using WC Blocks.
     */
    private function is_blocks_checkout() {
        if ( ! function_exists( 'has_block' ) || ! function_exists( 'wc_get_page_id' ) ) {
            return false;
        }
        $checkout_page_id = wc_get_page_id( 'checkout' );
        if ( ! $checkout_page_id || $checkout_page_id < 1 ) {
            return false;
        }
        return has_block( 'woocommerce/checkout', $checkout_page_id );
    }

    public function enqueue_scripts() {
        if ( ! function_exists( 'is_checkout' ) || ! is_checkout() ) {
            return;
        }

        $gateway = $this->get_gateway();
        if ( ! $gateway || 'modal' !== $gateway->checkout_display || ! $gateway->is_available() ) {
            return;
        }

        $is_blocks = $this->is_blocks_checkout();
        $handle    = $is_blocks ? 'breeze-modal-blocks' : 'breeze-modal-legacy';
        $rel_path  = $is_blocks ? 'assets/js/modal/breeze-modal-blocks.js' : 'assets/js/modal/breeze-modal-legacy.js';
        $abs_path  = BREEZE_PAYMENT_GATEWAY_PLUGIN_DIR . $rel_path;
        $version   = file_exists( $abs_path ) ? (string) filemtime( $abs_path ) : BREEZE_PAYMENT_GATEWAY_VERSION;

        $deps = $is_blocks
            ? array( 'wc-blocks-registry', 'wc-settings', 'wp-element', 'wp-html-entities' )
            : array( 'jquery', 'wc-checkout' );

        wp_enqueue_style(
            'breeze-modal',
            BREEZE_PAYMENT_GATEWAY_PLUGIN_URL . 'assets/css/breeze-modal.css',
            array(),
            file_exists( BREEZE_PAYMENT_GATEWAY_PLUGIN_DIR . 'assets/css/breeze-modal.css' )
                ? (string) filemtime( BREEZE_PAYMENT_GATEWAY_PLUGIN_DIR . 'assets/css/breeze-modal.css' )
                : BREEZE_PAYMENT_GATEWAY_VERSION
        );

        wp_enqueue_script(
            $handle,
            BREEZE_PAYMENT_GATEWAY_PLUGIN_URL . $rel_path,
            $deps,
            $version,
            true
        );

        $site_url    = get_site_url();
        $parsed      = wp_parse_url( $site_url );
        $site_domain = isset( $parsed['host'] ) ? $parsed['host'] : '';

        $breeze_origin = self::get_breeze_origin();
        $parsed_origin = wp_parse_url( $breeze_origin );
        $breeze_host   = isset( $parsed_origin['host'] ) ? $parsed_origin['host'] : '';

        wp_localize_script( $handle, 'breezeModalData', array(
            'ajaxUrl'      => admin_url( 'admin-ajax.php' ),
            'nonce'        => wp_create_nonce( self::NONCE_KEY ),
            'storeName'    => get_bloginfo( 'name' ),
            'currency'     => function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : '',
            'checkoutUrl'  => function_exists( 'wc_get_checkout_url' ) ? wc_get_checkout_url() : '',
            'siteDomain'   => $site_domain,
            'breezeOrigin' => $breeze_origin,
            'breezeHost'   => $breeze_host,
            'debug'        => (bool) ( defined( 'WP_DEBUG' ) && WP_DEBUG ),
            'gatewayData'  => array(
                'title'       => $gateway->get_title(),
                'description' => $gateway->get_description(),
                'enabled'     => 'yes' === $gateway->enabled,
            ),
        ) );
    }

    /**
     * Tag the Blocks Store API checkout response so the modal JS can
     * recognise this redirect as one it should open in a lightbox.
     */
    public function tag_blocks_redirect( $result, $order_id ) {
        if ( ! is_array( $result ) || ! isset( $result['redirect'] ) ) {
            return $result;
        }

        $order = wc_get_order( $order_id );
        if ( ! $order || $order->get_payment_method() !== self::GATEWAY_ID ) {
            return $result;
        }

        $gateway = $this->get_gateway();
        if ( ! $gateway || 'modal' !== $gateway->checkout_display ) {
            return $result;
        }

        $result['breeze_modal']    = true;
        $result['breeze_fail_url'] = $this->build_fail_return_url( $order );

        // Mark this order as the session's pending modal order so the cancel
        // AJAX endpoint can verify ownership for guest checkouts. The Store API
        // does not run our legacy ajax_create_payment(), so we set it here.
        if ( function_exists( 'WC' ) && WC()->session ) {
            WC()->session->set( 'breeze_modal_pending_order', (int) $order->get_id() );
        }

        return $result;
    }

    /**
     * Reconstruct the same failReturnUrl that was sent to Breeze when the
     * payment page was created. Used by the JS modal to navigate to the
     * token-protected handle_return() endpoint if the user closes the modal.
     */
    private function build_fail_return_url( $order ) {
        $token = $order->get_meta( '_breeze_return_token' );
        if ( ! $token ) {
            return '';
        }
        return add_query_arg(
            array(
                'wc-api'   => 'breeze_return',
                'order_id' => $order->get_id(),
                'status'   => 'failed',
                'token'    => $token,
            ),
            home_url( '/' )
        );
    }

    /**
     * Legacy shortcode AJAX handler: create the order + the Breeze payment page
     * and return the payment URL for the modal to load.
     */
    public function ajax_create_payment() {
        if ( ! check_ajax_referer( self::NONCE_KEY, 'nonce', false ) ) {
            wp_send_json_error(
                array( 'message' => __( 'Security check failed. Please refresh and try again.', 'breeze-payment-gateway' ) ),
                403
            );
        }

        // Merge the serialised checkout form into $_POST so WC_Checkout::create_order()
        // sees the customer's submission. wp_parse_str() handles slashing safely.
        $raw_form = isset( $_POST['form'] ) ? wp_unslash( $_POST['form'] ) : '';
        if ( is_string( $raw_form ) && '' !== $raw_form ) {
            $form_data = array();
            wp_parse_str( $raw_form, $form_data );
            foreach ( $form_data as $key => $value ) {
                if ( ! isset( $_POST[ $key ] ) ) {
                    $_POST[ $key ] = $value;
                }
            }
        }

        // Verify the WC checkout nonce AFTER the form merge so the nonce field
        // from the form is in $_POST.
        $wc_nonce = isset( $_POST['woocommerce-process-checkout-nonce'] )
            ? sanitize_text_field( wp_unslash( $_POST['woocommerce-process-checkout-nonce'] ) )
            : '';
        if ( ! wp_verify_nonce( $wc_nonce, 'woocommerce-process_checkout' ) ) {
            wp_send_json_error( array(
                'message' => __( 'We were unable to process your order, please try again.', 'breeze-payment-gateway' ),
            ), 403 );
        }

        if ( ! WC()->cart || WC()->cart->is_empty() ) {
            wp_send_json_error( array(
                'message' => __( 'Your cart is empty.', 'breeze-payment-gateway' ),
            ) );
        }

        $gateway = $this->get_gateway();
        if ( ! $gateway ) {
            wp_send_json_error( array(
                'message' => __( 'Breeze gateway is not available.', 'breeze-payment-gateway' ),
            ) );
        }

        $checkout    = WC()->checkout();
        $posted_data = $checkout->get_posted_data();

        try {
            $order_id = $checkout->create_order( $posted_data );
        } catch ( Exception $e ) {
            wp_send_json_error( array( 'message' => $e->getMessage() ) );
        }

        if ( is_wp_error( $order_id ) ) {
            wp_send_json_error( array( 'message' => $order_id->get_error_message() ) );
        }
        if ( ! $order_id ) {
            wp_send_json_error( array(
                'message' => __( 'Order could not be created. Please try again.', 'breeze-payment-gateway' ),
            ) );
        }

        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            wp_send_json_error( array(
                'message' => __( 'Order not found after creation.', 'breeze-payment-gateway' ),
            ) );
        }

        do_action( 'woocommerce_checkout_order_created', $order );

        $result = $gateway->create_payment_for_order( $order );

        if ( is_wp_error( $result ) ) {
            // Cancel the newly-created order — we never sent the customer to Breeze.
            $order->update_status( 'cancelled', __( 'Breeze payment page creation failed.', 'breeze-payment-gateway' ) );
            wp_send_json_error( array( 'message' => $result->get_error_message() ) );
        }

        // Remember the order on the session so the cancel AJAX can verify ownership.
        if ( WC()->session ) {
            WC()->session->set( 'breeze_modal_pending_order', (int) $order_id );
        }

        wp_send_json_success( array(
            'paymentUrl' => $result['url'],
            'orderId'    => (int) $order_id,
            'failUrl'    => isset( $result['fail_return_url'] ) ? $result['fail_return_url'] : '',
        ) );
    }

    /**
     * AJAX handler invoked when the customer closes the modal before completing
     * payment. Verifies the nonce and that the order belongs to the current
     * session, then cancels the order and clears Blocks checkout state.
     */
    public function ajax_cancel_payment() {
        if ( ! check_ajax_referer( self::NONCE_KEY, 'nonce', false ) ) {
            wp_send_json_error( array( 'message' => 'Security check failed.' ), 403 );
        }

        $order_id = isset( $_POST['order_id'] ) ? absint( $_POST['order_id'] ) : 0;
        if ( ! $order_id ) {
            wp_send_json_error( array( 'message' => 'Missing order ID.' ), 400 );
        }

        $order = wc_get_order( $order_id );
        if ( ! $order || $order->get_payment_method() !== self::GATEWAY_ID ) {
            wp_send_json_error( array( 'message' => 'Order not found.' ), 404 );
        }

        // Ownership check: order must belong to the current session, or to
        // the current logged-in customer.
        $session_order_id = WC()->session ? (int) WC()->session->get( 'breeze_modal_pending_order' ) : 0;
        $customer_id      = get_current_user_id();
        $owns_via_session = ( $session_order_id === (int) $order_id );
        $owns_via_user    = ( $customer_id > 0 && (int) $order->get_user_id() === $customer_id );

        if ( ! $owns_via_session && ! $owns_via_user ) {
            wp_send_json_error( array( 'message' => 'Not authorised.' ), 403 );
        }

        // Only cancel orders that are still awaiting payment.
        if ( $order->has_status( array( 'pending', 'draft', 'checkout-draft', 'on-hold' ) ) ) {
            $order->update_status( 'cancelled', __( 'Breeze modal closed before payment completed.', 'breeze-payment-gateway' ) );
        }

        // Clear Blocks session keys so the next checkout starts fresh.
        if ( WC()->session ) {
            WC()->session->set( 'breeze_modal_pending_order', null );
            WC()->session->set( 'store_api_draft_order', null );
            WC()->session->set( 'order_awaiting_payment', null );
            WC()->session->set( 'chosen_payment_method', null );
        }

        wp_send_json_success( array( 'cancelled' => true ) );
    }
}
