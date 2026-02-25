<?php
/**
 * Breeze Class
 *
 * @package Breeze_Payment_Gateway
 */

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Breeze
 *
 * Provides a Breeze for WooCommerce.
 *
 * @class       WC_Breeze_Payment_Gateway
 * @extends     WC_Payment_Gateway
 * @version     1.0.0
 * @package     WooCommerce/Classes/Payment
 */
class WC_Breeze_Payment_Gateway extends WC_Payment_Gateway {

    /**
     * Breeze API Base URL
     *
     * Override via constant:  define( 'BREEZE_API_BASE_URL', 'https://api.qa.breeze.cash' );
     * Override via filter:    add_filter( 'breeze_api_base_url', fn() => 'https://api.qa.breeze.cash' );
     *
     * @var string
     */
    private $api_base_url;

    /**
     * Logger instance
     *
     * @var WC_Logger|null
     */
    private $log = null;

    /**
     * Constructor for the gateway.
     */
    public function __construct() {
        
        // API base URL — constant wins, then filter, then default
        $default_url      = 'https://api.breeze.cash';
        $this->api_base_url = defined( 'BREEZE_API_BASE_URL' )
            ? BREEZE_API_BASE_URL
            : apply_filters( 'breeze_api_base_url', $default_url );

        // Gateway ID
        $this->id = 'breeze_payment_gateway';
        
        // Icon for checkout
        $this->icon = apply_filters( 'woocommerce_breeze_gateway_icon', BREEZE_PAYMENT_GATEWAY_PLUGIN_URL . 'assets/images/breeze-icon.png' );
        
        // Payment page redirect (no fields on checkout)
        $this->has_fields = false;
        
        // Method title and description for admin
        $this->method_title = __( 'Breeze', 'breeze-payment-gateway' );
        $this->method_description = sprintf(
            /* translators: %s: URL to Breeze sales page */
            __( 'Accept payments through Breeze payment gateway. Customers will be redirected to Breeze to complete payment. Don\'t have a Breeze merchant account yet? <a href="%s" target="_blank">Contact our sales team</a> to get started.', 'breeze-payment-gateway' ),
            'https://breeze.com/sales'
        );
        
        // Supports
        $this->supports = array(
            'products',
            'refunds',
            'blocks',
        );

        // Load the settings
        $this->init_form_fields();
        $this->init_settings();

        // Define user set variables
        $this->title              = $this->get_option( 'title' );
        $this->description        = $this->get_option( 'description' );
        $this->enabled            = $this->get_option( 'enabled' );
        $this->testmode           = 'yes' === $this->get_option( 'testmode' );
        $this->api_key            = $this->testmode ? $this->get_option( 'test_api_key' ) : $this->get_option( 'live_api_key' );
        $this->webhook_secret     = $this->get_option( 'webhook_secret', '' );
        $this->debug              = 'yes' === $this->get_option( 'debug', 'no' );
        $this->payment_methods    = $this->get_option( 'payment_methods', array() );
        
        // Logging
        $this->log = $this->debug ? wc_get_logger() : null;

        // Actions
        add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
        add_action( 'woocommerce_api_' . $this->id, array( $this, 'webhook_handler' ) );
        
        // Return URL handler
        add_action( 'woocommerce_api_breeze_return', array( $this, 'handle_return' ) );
    }

    /**
     * Initialize Gateway Settings Form Fields
     */
    public function init_form_fields() {
        $this->form_fields = array(
            'enabled' => array(
                'title'       => __( 'Enable/Disable', 'breeze-payment-gateway' ),
                'label'       => __( 'Enable Breeze', 'breeze-payment-gateway' ),
                'type'        => 'checkbox',
                'description' => '',
                'default'     => 'no',
            ),
            'title' => array(
                'title'       => __( 'Title', 'breeze-payment-gateway' ),
                'type'        => 'text',
                'description' => __( 'This controls the title which the user sees during checkout.', 'breeze-payment-gateway' ),
                'default'     => __( 'Breeze', 'breeze-payment-gateway' ),
                'desc_tip'    => true,
            ),
            'description' => array(
                'title'       => __( 'Description', 'breeze-payment-gateway' ),
                'type'        => 'textarea',
                'description' => __( 'Payment method description that the customer will see on your checkout.', 'breeze-payment-gateway' ),
                'default'     => __( 'Pay securely using Breeze payment gateway.', 'breeze-payment-gateway' ),
                'desc_tip'    => true,
            ),
            'testmode' => array(
                'title'       => __( 'Test Mode', 'breeze-payment-gateway' ),
                'label'       => __( 'Enable Test Mode', 'breeze-payment-gateway' ),
                'type'        => 'checkbox',
                'description' => __( 'Place the payment gateway in test mode using test API credentials.', 'breeze-payment-gateway' ),
                'default'     => 'yes',
                'desc_tip'    => true,
            ),
            'test_api_key' => array(
                'title'       => __( 'Test API Key', 'breeze-payment-gateway' ),
                'type'        => 'password',
                'description' => __( 'Get your API key from your Breeze dashboard.', 'breeze-payment-gateway' ),
                'default'     => '',
                'desc_tip'    => true,
                'placeholder' => 'sk_test_...',
            ),
            'live_api_key' => array(
                'title'       => __( 'Live API Key', 'breeze-payment-gateway' ),
                'type'        => 'password',
                'description' => __( 'Get your API key from your Breeze dashboard.', 'breeze-payment-gateway' ),
                'default'     => '',
                'desc_tip'    => true,
                'placeholder' => 'sk_live_...',
            ),
            'webhook_secret' => array(
                'title'       => __( 'Webhook Secret', 'breeze-payment-gateway' ),
                'type'        => 'password',
                'description' => __( 'Enter your webhook secret from Breeze dashboard for webhook signature verification. This ensures webhook requests are authentic.', 'breeze-payment-gateway' ),
                'default'     => '',
                'desc_tip'    => true,
                'placeholder' => 'whook_sk_...',
            ),
            'debug' => array(
                'title'       => __( 'Debug Log', 'breeze-payment-gateway' ),
                'label'       => __( 'Enable Logging', 'breeze-payment-gateway' ),
                'type'        => 'checkbox',
                'description' => sprintf( 
                    __( 'Log Breeze events inside %s', 'breeze-payment-gateway' ),
                    '<code>' . WC_Log_Levels::DEBUG . '</code>'
                ),
                'default'     => 'no',
                'desc_tip'    => false,
            ),
            'payment_methods' => array(
                'title'       => __( 'Preferred Payment Methods', 'breeze-payment-gateway' ),
                'type'        => 'multiselect',
                'description' => __( 'Select which payment methods to show on the Breeze checkout page. If none selected, all methods will be available.', 'breeze-payment-gateway' ),
                'default'     => array(),
                'desc_tip'    => true,
                'options'     => array(
                    'apple_pay'  => __( 'Apple Pay', 'breeze-payment-gateway' ),
                    'google_pay' => __( 'Google Pay', 'breeze-payment-gateway' ),
                    'card'       => __( 'Card (Manual Card Payment)', 'breeze-payment-gateway' ),
                    'crypto'     => __( 'Crypto', 'breeze-payment-gateway' ),
                ),
                'class'       => 'wc-enhanced-select',
            ),
        );
    }

    /**
     * Payment form on checkout page
     */
    public function payment_fields() {
        
        // Description
        if ( $this->description ) {
            echo wpautop( wp_kses_post( $this->description ) );
        }

        // Test mode notice
        if ( $this->testmode ) {
            echo '<p>' . esc_html__( 'TEST MODE ENABLED. No real payments will be processed.', 'breeze-payment-gateway' ) . '</p>';
        }
    }

    /**
     * Process the payment and return the result
     *
     * @param int $order_id Order ID.
     * @return array
     */
    public function process_payment( $order_id ) {
        
        $order = wc_get_order( $order_id );

        // Log payment attempt
        if ( $this->debug ) {
            $this->log->debug( 
                sprintf( 'Processing payment for order #%s', $order_id ),
                array( 'source' => $this->id )
            );
        }

        try {
            
            // Step 1: Create or get customer in Breeze
            $customer_id = $this->get_or_create_breeze_customer( $order );
            
            if ( ! $customer_id ) {
                throw new Exception( __( 'Failed to create customer in Breeze.', 'breeze-payment-gateway' ) );
            }

            // Step 2: Create products array for order items
            $products = $this->create_breeze_products_array( $order );
            
            if ( empty( $products ) ) {
                throw new Exception( __( 'Failed to create products array.', 'breeze-payment-gateway' ) );
            }

            // Step 3: Create payment page
            $payment_page = $this->create_breeze_payment_page( $order, $customer_id, $products );
            
            if ( ! $payment_page || empty( $payment_page['url'] ) ) {
                throw new Exception( __( 'Failed to create payment page in Breeze.', 'breeze-payment-gateway' ) );
            }

            // Store Breeze data in order meta
            $order->update_meta_data( '_breeze_customer_id', $customer_id );
            $order->update_meta_data( '_breeze_payment_page_id', $payment_page['id'] );
            $order->save();

            // Mark as pending
            $order->update_status( 'pending', __( 'Awaiting Breeze payment.', 'breeze-payment-gateway' ) );

            // Log success
            if ( $this->debug ) {
                $this->log->info(
                    sprintf( 'Payment page created for order #%s. URL: %s', $order_id, $payment_page['url'] ),
                    array( 'source' => $this->id )
                );
            }

            // Return success and redirect to Breeze payment page
            return array(
                'result'   => 'success',
                'redirect' => $payment_page['url'],
            );

        } catch ( Exception $e ) {
            
            wc_add_notice( __( 'Payment error: ', 'breeze-payment-gateway' ) . $e->getMessage(), 'error' );
            
            // Log exception
            if ( $this->debug ) {
                $this->log->error(
                    sprintf( 'Payment exception for order #%s: %s', $order_id, $e->getMessage() ),
                    array( 'source' => $this->id )
                );
            }

            return array(
                'result'   => 'failure',
                'messages' => array( $e->getMessage() ),
            );
        }
    }

    /**
     * Get or create customer in Breeze
     *
     * @param WC_Order $order Order object.
     * @return string|false Customer ID or false on failure.
     */
    private function get_or_create_breeze_customer( $order ) {
        
        // Check if customer already has Breeze ID from prior store Order
        $user_id = $order->get_user_id();
        
        if ( $user_id ) {
            $breeze_customer_id = get_user_meta( $user_id, '_breeze_customer_id', true );
            
            if ( $breeze_customer_id ) {
                return $breeze_customer_id;
            }
        }

        // Check if customer already has Breeze ID from another Merchant or Channel
        $response = $this->breeze_api_request( 'GET', '/v1/customers?email=' . rawurlencode( $order->get_billing_email() ) );

        if ( $response && isset( $response['data']['id'] ) ) {
            $customer_id = $response['data']['id'];
            
            // Save to user meta if logged in
            if ( $user_id ) {
                update_user_meta( $user_id, '_breeze_customer_id', $customer_id );
            }
            
            return $customer_id;
        }
        
        // Create new customer
        $customer_data = array(
            'referenceId' => $user_id ? 'user-' . $user_id : 'guest-' . $order->get_id(),
            'email'       => $order->get_billing_email(),
            'signupAt'    => time() * 1000, // Convert to milliseconds
        );

        $response = $this->breeze_api_request( 'POST', '/v1/customers', $customer_data );

        if ( $response && isset( $response['data']['id'] ) ) {
            $customer_id = $response['data']['id'];
            
            // Save to user meta if logged in
            if ( $user_id ) {
                update_user_meta( $user_id, '_breeze_customer_id', $customer_id );
            }
            
            return $customer_id;
        }

        return false;
    }

    /**
     * Create line items for Breeze payment page
     *
     * @param WC_Order $order Order object.
     * @return array Line items array.
     */
    /**
     * Create products array for Breeze payment page
     *
     * @param WC_Order $order Order object.
     * @return array Products array.
     */
    private function create_breeze_products_array( $order ) {
        
        $products = array();

        // Get order items
        foreach ( $order->get_items() as $item_id => $item ) {
            
            $product = $item->get_product();
            
            if ( ! $product ) {
                continue;
            }

            // Add product to array (no API call needed)
            $product_item = array(
                'name'        => $item->get_name(),
                'description' => $product->get_short_description() ? $product->get_short_description() : $item->get_name(),
                'currency'    => $order->get_currency(),
                'amount'      => (int) round( $item->get_product()->get_price() * 100 ), // Amount in cents
                'quantity'    => $item->get_quantity(),
            );

            // Add image if available
            $image_url = wp_get_attachment_url( $product->get_image_id() );
            if ( $image_url ) {
                $product_item['images'] = array( $image_url );
            }

            // Add optional ID from merchant's system
            if ( $product->get_id() ) {
                $product_item['id'] = (string) $product->get_id();
            }

            $products[] = $product_item;
        }

        // Add shipping as a product if present
        if ( $order->get_shipping_total() > 0 ) {
            $products[] = array(
                'name'        => __( 'Shipping', 'breeze-payment-gateway' ),
                'description' => $order->get_shipping_method(),
                'currency'    => $order->get_currency(),
                'amount'      => (int) round( $order->get_shipping_total() * 100 ), // Amount in cents
                'quantity'    => 1,
            );
        }

        // Add tax as a product if present
        if ( $order->get_total_tax() > 0 ) {
            $products[] = array(
                'name'        => __( 'Tax', 'breeze-payment-gateway' ),
                'description' => __( 'Tax', 'breeze-payment-gateway' ),
                'currency'    => $order->get_currency(),
                'amount'      => (int) round( $order->get_total_tax() * 100 ), // Amount in cents
                'quantity'    => 1,
            );
        }

        // Distribute discounts proportionally across product items
        // Breeze API does not accept negative amounts, so we reduce each product's
        // unit price proportionally rather than adding a negative discount line item.
        // This works for both fixed and percentage discounts.
        $discount_total = $order->get_discount_total();
        if ( $discount_total > 0 ) {
            // Calculate subtotal of product items only (not shipping/tax)
            $product_subtotal = 0;
            foreach ( $products as $p ) {
                if ( ! in_array( $p['name'], array( __( 'Shipping', 'breeze-payment-gateway' ), __( 'Tax', 'breeze-payment-gateway' ) ), true ) ) {
                    $product_subtotal += $p['amount'] * $p['quantity'];
                }
            }

            // Distribute discount proportionally across products
            if ( $product_subtotal > 0 ) {
                $discount_cents    = (int) round( $discount_total * 100 );
                $allocated         = 0;
                $last_product_idx  = -1;

                foreach ( $products as $idx => &$p ) {
                    if ( in_array( $p['name'], array( __( 'Shipping', 'breeze-payment-gateway' ), __( 'Tax', 'breeze-payment-gateway' ) ), true ) ) {
                        continue;
                    }
                    $last_product_idx = $idx;

                    $line_total     = $p['amount'] * $p['quantity'];
                    $line_discount  = (int) round( ( $line_total / $product_subtotal ) * $discount_cents );
                    $p['amount']    = (int) round( ( $line_total - $line_discount ) / $p['quantity'] );
                    $allocated     += $line_discount;
                }
                unset( $p );

                // Apply any rounding remainder to the last product item
                $remainder = $discount_cents - $allocated;
                if ( $remainder !== 0 && $last_product_idx >= 0 ) {
                    $products[ $last_product_idx ]['amount'] -= $remainder;
                }
            }
        }

        return $products;
    }

    /**
     * Create payment page in Breeze
     *
     * @param WC_Order $order Order object.
     * @param string $customer_id Breeze customer ID.
     * @param array $products Products array.
     * @return array|false Payment page data or false on failure.
     */
    private function create_breeze_payment_page( $order, $customer_id, $products ) {

        // Generate a one-time return token to prevent unauthenticated order status manipulation.
        // The token is included in both return URLs and verified in handle_return().
        $return_token = wp_generate_password( 32, false );
        $order->update_meta_data( '_breeze_return_token', $return_token );
        $order->save();

        $payment_data = array(
            'products'          => $products,
            'billingEmail'      => $order->get_billing_email(),
            'clientReferenceId' => 'order-' . $order->get_id(),
            'successReturnUrl'  => add_query_arg(
                array(
                    'wc-api'   => 'breeze_return',
                    'order_id' => $order->get_id(),
                    'status'   => 'success',
                    'token'    => $return_token,
                ),
                home_url( '/' )
            ),
            'failReturnUrl'     => add_query_arg(
                array(
                    'wc-api'   => 'breeze_return',
                    'order_id' => $order->get_id(),
                    'status'   => 'failed',
                    'token'    => $return_token,
                ),
                home_url( '/' )
            ),
            'customer'          => array(
                'id' => $customer_id,
            ),
        );

        $response = $this->breeze_api_request( 'POST', '/v1/payment_pages', $payment_data );

        if ( $response && isset( $response['data'] ) ) {
            $payment_data = $response['data'];
            
            // Add preferred payment methods as query parameter if configured
            if ( ! empty( $this->payment_methods ) && is_array( $this->payment_methods ) ) {
                $payment_methods_string = implode( ',', $this->payment_methods );
                $payment_data['url'] = add_query_arg( 
                    'preferred_payment_methods', 
                    $payment_methods_string, 
                    $payment_data['url'] 
                );
                
                // Log payment methods being used
                if ( $this->debug ) {
                    $this->log->debug(
                        sprintf( 'Adding payment methods to URL: %s', $payment_methods_string ),
                        array( 'source' => $this->id )
                    );
                }
            }
            
            return $payment_data;
        }

        return false;
    }

    /**
     * Make API request to Breeze
     *
     * @param string $method HTTP method.
     * @param string $endpoint API endpoint.
     * @param array $data Request data.
     * @return array|false Response data or false on failure.
     */
    private function breeze_api_request( $method, $endpoint, $data = array() ) {
        
        $url = $this->api_base_url . $endpoint;

        $args = array(
            'method'      => $method,
            'timeout'     => 45,
            'httpversion' => '1.1',
            'headers'     => array(
                'Content-Type'  => 'application/json',
                'Authorization' => 'Basic ' . base64_encode( $this->api_key . ':' ),
            ),
        );

        if ( ! empty( $data ) && in_array( $method, array( 'POST', 'PUT', 'PATCH' ), true ) ) {
            $args['body'] = wp_json_encode( $data );
        }

        // Log request
        if ( $this->debug ) {
            $this->log->debug(
                sprintf( 'Breeze API Request: %s %s', $method, $url ),
                array( 'source' => $this->id, 'data' => $data )
            );
        }

        $response = wp_remote_request( $url, $args );

        // Check for errors
        if ( is_wp_error( $response ) ) {
            if ( $this->debug ) {
                $this->log->error(
                    sprintf( 'Breeze API Error: %s', $response->get_error_message() ),
                    array( 'source' => $this->id )
                );
            }
            return false;
        }

        // Parse response
        $response_code = wp_remote_retrieve_response_code( $response );
        $response_body = wp_remote_retrieve_body( $response );
        $result = json_decode( $response_body, true );

        // Log response
        if ( $this->debug ) {
            $this->log->debug(
                sprintf( 'Breeze API Response: %s %s - Status: %d', $method, $url, $response_code ),
                array( 'source' => $this->id, 'response' => $result )
            );
        }

        // Handle response
        if ( $response_code >= 200 && $response_code < 300 ) {
            return $result;
        } else {
            if ( $this->debug ) {
                $this->log->error(
                    sprintf( 'Breeze API Error Response: %d - %s', $response_code, $response_body ),
                    array( 'source' => $this->id )
                );
            }
            return false;
        }
    }

    /**
     * Handle return from Breeze payment page
     */
    public function handle_return() {

        $order_id = isset( $_GET['order_id'] ) ? absint( $_GET['order_id'] ) : 0;
        $status   = isset( $_GET['status'] ) ? sanitize_text_field( wp_unslash( $_GET['status'] ) ) : '';
        $token    = isset( $_GET['token'] ) ? sanitize_text_field( wp_unslash( $_GET['token'] ) ) : '';

        if ( ! $order_id ) {
            wp_safe_redirect( wc_get_cart_url() );
            exit;
        }

        $order = wc_get_order( $order_id );

        if ( ! $order ) {
            wp_safe_redirect( wc_get_cart_url() );
            exit;
        }

        // Verify the one-time return token to prevent unauthenticated order completion.
        $expected_token = $order->get_meta( '_breeze_return_token' );
        if ( empty( $expected_token ) || ! hash_equals( $expected_token, $token ) ) {
            if ( $this->debug ) {
                $this->log->warning(
                    sprintf( 'Return URL token verification failed for order #%s', $order_id ),
                    array( 'source' => $this->id )
                );
            }
            wp_safe_redirect( wc_get_cart_url() );
            exit;
        }

        // Consume the token — one-time use only.
        $order->delete_meta_data( '_breeze_return_token' );
        $order->save();

        if ( 'success' === $status ) {
            
            // Mark as processing/completed
            $order->payment_complete();
            $order->add_order_note( __( 'Payment completed via Breeze.', 'breeze-payment-gateway' ) );
            
            // Reduce stock levels
            wc_reduce_stock_levels( $order_id );

            // Remove cart
            WC()->cart->empty_cart();

            // Log success
            if ( $this->debug ) {
                $this->log->info(
                    sprintf( 'Payment completed for order #%s via return URL', $order_id ),
                    array( 'source' => $this->id )
                );
            }

            // Redirect to thank you page
            wp_safe_redirect( $this->get_return_url( $order ) );
            exit;

        } else {
            
            // Payment failed
            $order->update_status( 'failed', __( 'Payment failed or cancelled by customer.', 'breeze-payment-gateway' ) );

            // Remove cart
            WC()->cart->empty_cart();

            // Log failure
            if ( $this->debug ) {
                $this->log->warning(
                    sprintf( 'Payment failed for order #%s via return URL', $order_id ),
                    array( 'source' => $this->id )
                );
            }

            wc_add_notice( __( 'Payment was not completed.', 'breeze-payment-gateway' ), 'error' );
            
            // Redirect to checkout
            wp_safe_redirect( wc_get_checkout_url() );
            exit;
        }
    }

    /**
     * Process refund
     *
     * @param int $order_id Order ID.
     * @param float $amount Refund amount.
     * @param string $reason Refund reason.
     * @return boolean|WP_Error
     */
    public function process_refund( $order_id, $amount = null, $reason = '' ) {
        
        $order = wc_get_order( $order_id );

        if ( ! $order ) {
            return new WP_Error( 'invalid_order', __( 'Invalid order.', 'breeze-payment-gateway' ) );
        }

        // Note: Breeze API refund implementation would go here
        // For now, we'll return an error indicating manual refund is needed
        
        return new WP_Error( 
            'refund_not_supported', 
            __( 'Refunds must be processed manually through your Breeze dashboard.', 'breeze-payment-gateway' ) 
        );
    }

    /**
     * Webhook handler
     */
    public function webhook_handler() {
        
        // Get the raw POST data
        $payload = file_get_contents( 'php://input' );
        
        // Parse webhook data
        $webhook_data = json_decode( $payload, true );

        // Validate webhook structure
        if ( ! $webhook_data || ! isset( $webhook_data['signature'] ) || ! isset( $webhook_data['data'] ) ) {
            if ( $this->debug ) {
                $this->log->error(
                    'Invalid webhook structure received',
                    array( 'source' => $this->id, 'payload' => $payload )
                );
            }
            wp_send_json_error( array( 'message' => 'Invalid webhook structure' ), 400 );
            return;
        }

        // Verify webhook signature
        if ( ! $this->verify_webhook_signature( $webhook_data ) ) {
            if ( $this->debug ) {
                $this->log->error(
                    'Webhook signature verification failed',
                    array( 'source' => $this->id )
                );
            }
            wp_send_json_error( array( 'message' => 'Invalid webhook signature' ), 400 );
            return;
        }

        // Log verified webhook
        if ( $this->debug ) {
            $this->log->debug(
                'Breeze webhook received and verified',
                array( 'source' => $this->id, 'data' => $webhook_data )
            );
        }

        // Handle webhook events
        if ( isset( $webhook_data['type'] ) && isset( $webhook_data['data'] ) ) {
            
            $event_type = $webhook_data['type'];
            $data = $webhook_data['data'];
            
            switch ( $event_type ) {
                
                case 'payment.succeeded':
                case 'PAYMENT_SUCCEEDED':
                    $this->handle_payment_success_webhook( $data );
                    break;
                
                case 'payment.failed':
                case 'PAYMENT_EXPIRED':
                    $this->handle_payment_failed_webhook( $data );
                    break;
                
                default:
                    // Log unknown event
                    if ( $this->debug ) {
                        $this->log->warning(
                            sprintf( 'Unknown webhook event: %s', $event_type ),
                            array( 'source' => $this->id )
                        );
                    }
            }
        }

        wp_send_json_success( array( 'message' => 'Webhook processed' ), 200 );
    }

    /**
     * Verify webhook signature
     *
     * @param array $webhook_data Webhook payload data.
     * @return boolean True if signature is valid, false otherwise.
     */
    private function verify_webhook_signature( $webhook_data ) {
        
        // Webhook secret is required. Without it, signature verification is impossible
        // and any caller could spoof payment events — reject all webhooks.
        if ( empty( $this->webhook_secret ) ) {
            $this->log->error(
                'Webhook rejected: no webhook secret configured. Set the Webhook Secret in WooCommerce > Settings > Payments > Breeze.',
                array( 'source' => $this->id )
            );
            return false;
        }

        // Extract signature and data
        $provided_signature = isset( $webhook_data['signature'] ) ? $webhook_data['signature'] : '';
        $data = isset( $webhook_data['data'] ) ? $webhook_data['data'] : array();

        if ( empty( $provided_signature ) || empty( $data ) ) {
            return false;
        }

        // Step 1: Sort the data keys recursively
        $this->ksort_recursive( $data );

        // Step 2: JSON encode with no spaces (matching Breeze format)
        $sorted_json = wp_json_encode( $data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );

        if ( false === $sorted_json ) {
            if ( $this->debug ) {
                $this->log->error(
                    'Failed to JSON encode webhook data',
                    array( 'source' => $this->id )
                );
            }
            return false;
        }

        // Step 3: Generate HMAC SHA256 signature
        $expected_signature = base64_encode( hash_hmac( 'sha256', $sorted_json, $this->webhook_secret, true ) );

        // Step 4: Securely compare signatures (timing-safe comparison)
        $signature_valid = hash_equals( $expected_signature, $provided_signature );

        // Log verification result
        if ( $this->debug ) {
            $this->log->debug(
                sprintf( 'Webhook signature verification: %s', $signature_valid ? 'PASSED' : 'FAILED' ),
                array(
                    'source' => $this->id,
                    'provided_signature' => $provided_signature,
                    'expected_signature' => $expected_signature,
                )
            );
        }

        return $signature_valid;
    }

    /**
     * Recursively sort array keys
     *
     * @param array $array Array to sort.
     */
    private function ksort_recursive( &$array ) {
        if ( ! is_array( $array ) ) {
            return;
        }

        ksort( $array );

        foreach ( $array as &$value ) {
            if ( is_array( $value ) ) {
                $this->ksort_recursive( $value );
            }
        }
    }

    /**
     * Handle payment success webhook
     *
     * @param array $data Webhook data.
     */
    private function handle_payment_success_webhook( $data ) {
        
        if ( ! isset( $data['clientReferenceId'] ) ) {
            return;
        }

        // Extract order ID from client reference
        $order_id = str_replace( 'order-', '', $data['clientReferenceId'] );
        $order = wc_get_order( $order_id );

        if ( ! $order ) {
            return;
        }

        if ( ! $order->is_paid() ) {
            $order->payment_complete( isset( $data['pageId'] ) ? $data['pageId'] : '' );
            $order->add_order_note(
                sprintf(
                    __( 'Payment confirmed via Breeze webhook. Transaction ID: %s', 'breeze-payment-gateway' ),
                    isset( $data['pageId'] ) ? $data['pageId'] : 'N/A'
                )
            );
        }
    }

    /**
     * Handle payment failed webhook
     *
     * @param array $data Webhook data.
     */
    private function handle_payment_failed_webhook( $data ) {
        
        if ( ! isset( $data['clientReferenceId'] ) ) {
            return;
        }

        // Extract order ID from client reference
        $order_id = str_replace( 'order-', '', $data['clientReferenceId'] );
        $order = wc_get_order( $order_id );

        if ( ! $order ) {
            return;
        }

        $order->update_status( 'failed', __( 'Payment failed via Breeze webhook notification.', 'breeze-payment-gateway' ) );
    }

    /**
     * Check if gateway supports blocks
     *
     * @return boolean
     */
    public function is_blocks_supported() {
        return true;
    }
}
