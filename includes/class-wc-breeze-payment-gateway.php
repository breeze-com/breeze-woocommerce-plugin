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
     * @var string
     */
    private $api_base_url;

    /** @var WC_Logger|null */
    private $log = null;

    /** @var bool */
    public $testmode = false;

    /** @var string */
    protected $api_key = '';

    /** @var string */
    protected $webhook_secret = '';

    /** @var bool */
    protected $debug = false;

    /** @var array */
    protected $payment_methods = array();

    /**
     * Preferred crypto network (e.g. BINANCE, ETHEREUM).
     *
     * @var string
     */
    protected $crypto_network = '';

    /**
     * Preferred crypto token (e.g. USDT, USDC).
     *
     * @var string
     */
    protected $crypto_token = '';

    /** @var bool */
    public $send_product_description = false;

    /** @var bool Send WooCommerce-calculated tax to Breeze (merchant-calculated tax mode). */
    public $merchant_calculated_tax = false;

    /** @var string */
    public $flexible_amount_max = '';

    /** @var string */
    public $flexible_amount_percentage = '';

    /** @var string */
    public $flexible_amount_fixed = '';

    /** @var string 'redirect' or 'modal' */
    public $checkout_display = 'redirect';

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
            __( 'Accept payments through Breeze payment gateway. Customers will be redirected to Breeze to complete payment. Don\'t have a Breeze merchant account yet? <a href="%s" target="_blank">Contact our sales team</a> to get started.<br><strong>Note:</strong> Breeze currently supports USD only. The gateway will be hidden automatically for other store currencies. To add support for additional currencies, use the <code>breeze_supported_currencies</code> filter.', 'breeze-payment-gateway' ),
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

        // Derive the runtime properties from the loaded settings.
        $this->load_runtime_settings();

        // Actions
        add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
        add_action( 'woocommerce_api_' . $this->id, array( $this, 'webhook_handler' ) );
        
        // Return URL handler
        add_action( 'woocommerce_api_breeze_return', array( $this, 'handle_return' ) );

        // Constrain the icon to 24x24px on the checkout payment methods list
        add_filter( 'woocommerce_gateway_icon', array( $this, 'filter_gateway_icon_html' ), 10, 2 );
    }

    /**
     * Derive the gateway's runtime properties from the loaded settings.
     *
     * Kept separate from the constructor so subclasses (e.g. the subscription
     * gateway) can re-run it after switching `$this->id` and reloading settings
     * under their own option key — otherwise they would inherit the base
     * gateway's API key / webhook secret and authenticate with the wrong (or an
     * empty) credential when configured independently.
     */
    protected function load_runtime_settings() {
        $this->title              = $this->get_option( 'title' );
        $this->description        = $this->get_option( 'description' );
        $this->enabled            = $this->get_option( 'enabled' );
        $this->testmode           = 'yes' === $this->get_option( 'testmode' );
        $this->api_key            = $this->testmode ? $this->get_option( 'test_api_key' ) : $this->get_option( 'live_api_key' );
        $this->webhook_secret     = $this->get_option( 'webhook_secret', '' );
        $this->debug              = 'yes' === $this->get_option( 'debug', 'no' );
        $this->payment_methods        = $this->get_option( 'payment_methods', array() );
        $this->crypto_network         = $this->get_option( 'crypto_network', '' );
        $this->crypto_token           = $this->get_option( 'crypto_token', '' );
        $this->send_product_description = 'yes' === $this->get_option( 'send_product_description', 'no' );
        $this->merchant_calculated_tax  = 'yes' === $this->get_option( 'merchant_calculated_tax', 'no' );
        $this->flexible_amount_max        = $this->get_option( 'flexible_amount_max' );
        $this->flexible_amount_percentage = $this->get_option( 'flexible_amount_percentage' );
        $this->flexible_amount_fixed      = $this->get_option( 'flexible_amount_fixed' );
        $checkout_display                 = $this->get_option( 'checkout_display', 'redirect' );
        $this->checkout_display           = ( 'modal' === $checkout_display ) ? 'modal' : 'redirect';

        // Logging
        $this->log = $this->debug ? wc_get_logger() : null;
    }

    /**
     * Constrain the Breeze gateway icon to 24x24px on the checkout page.
     *
     * WooCommerce renders the gateway icon at its natural size by default, which
     * can vary significantly across themes. This filter ensures the icon is always
     * displayed at a consistent 24×24px regardless of the active theme.
     *
     * @param string $icon_html Existing icon HTML generated by WooCommerce.
     * @param string $gateway_id The gateway ID being rendered.
     * @return string Modified icon HTML, or original HTML if not our gateway.
     */
    public function filter_gateway_icon_html( $icon_html, $gateway_id ) {
        if ( $gateway_id !== $this->id ) {
            return $icon_html;
        }
        return '<img src="' . esc_url( $this->icon ) . '" alt="' . esc_attr( $this->method_title ) . '" style="max-width:24px;max-height:24px;" />';
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
            'checkout_display' => array(
                'title'       => __( 'Checkout Display', 'breeze-payment-gateway' ),
                'type'        => 'select',
                'description' => sprintf(
                    /* translators: %s: URL to Breeze embedding iframe guidelines */
                    __( '<strong>Note:</strong> When using Modal, Apple Pay requires additional domain certification. See <a href="%s" target="_blank" rel="noopener noreferrer">Enabling Apple Pay on an embedded iframe</a> for setup instructions.', 'breeze-payment-gateway' ),
                    'https://docs.breeze.com/docs/embedding-iframe-guidelines#enabling-apple-pay-on-an-embedded-iframe'
                ),
                'default'     => 'redirect',
                'desc_tip'    => false,
                'options'     => array(
                    'redirect' => __( 'Redirect to Breeze (Recommended)', 'breeze-payment-gateway' ),
                    'modal'    => __( 'Open in a modal', 'breeze-payment-gateway' ),
                ),
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
                    'apple_pay'      => __( 'Apple Pay', 'breeze-payment-gateway' ),
                    'google_pay'     => __( 'Google Pay', 'breeze-payment-gateway' ),
                    'card'           => __( 'Card (Manual Card Payment)', 'breeze-payment-gateway' ),
                    'crypto_wallet'  => __( 'Crypto Wallet', 'breeze-payment-gateway' ),
                    'crypto_deposit' => __( 'Crypto Deposit', 'breeze-payment-gateway' ),
                ),
                'class'       => 'wc-enhanced-select',
            ),
            'crypto_network' => array(
                'title'       => __( 'Preferred Crypto Network', 'breeze-payment-gateway' ),
                'type'        => 'select',
                'description' => __( 'Pre-select a crypto network on the Breeze checkout page. Only applies when Crypto Deposit is set as a preferred payment method. Leave blank to let the customer choose.', 'breeze-payment-gateway' ),
                'default'     => '',
                'desc_tip'    => true,
                'options'     => array(
                    ''          => __( '— None —', 'breeze-payment-gateway' ),
                    'BINANCE'   => __( 'Binance (BEP-20)', 'breeze-payment-gateway' ),
                    'ETHEREUM'  => __( 'Ethereum (ERC-20)', 'breeze-payment-gateway' ),
                    'SOLANA'    => __( 'Solana', 'breeze-payment-gateway' ),
                    'POLYGON'   => __( 'Polygon', 'breeze-payment-gateway' ),
                ),
            ),
            'crypto_token' => array(
                'title'       => __( 'Preferred Crypto Token', 'breeze-payment-gateway' ),
                'type'        => 'select',
                'description' => __( 'Pre-select a crypto token on the Breeze checkout page. Only applies when Crypto Deposit is set as a preferred payment method. Leave blank to let the customer choose.', 'breeze-payment-gateway' ),
                'default'     => '',
                'desc_tip'    => true,
                'options'     => array(
                    ''     => __( '— None —', 'breeze-payment-gateway' ),
                    'USDT' => __( 'USDT (Tether)', 'breeze-payment-gateway' ),
                    'USDC' => __( 'USDC (USD Coin)', 'breeze-payment-gateway' ),
                    'BTC'  => __( 'BTC (Bitcoin)', 'breeze-payment-gateway' ),
                    'ETH'  => __( 'ETH (Ethereum)', 'breeze-payment-gateway' ),
                    'SOL'  => __( 'SOL (Solana)', 'breeze-payment-gateway' ),
                ),
            ),
            'send_product_description' => array(
                'title'       => __( 'Product Description', 'breeze-payment-gateway' ),
                'label'       => __( 'Send product description to Breeze', 'breeze-payment-gateway' ),
                'type'        => 'checkbox',
                'description' => __( 'When enabled, product names from the order will be sent as a description on the Breeze payment page. Useful for merchants who want customers to see a summary of their items on the Breeze checkout.', 'breeze-payment-gateway' ),
                'default'     => 'no',
                'desc_tip'    => true,
            ),
            'tax_section' => array(
                'title'       => __( 'Tax', 'breeze-payment-gateway' ),
                'type'        => 'title',
                'description' => __( 'Control how tax is handled for Breeze payments.', 'breeze-payment-gateway' ),
            ),
            'merchant_calculated_tax' => array(
                'title'       => __( 'Merchant-Calculated Tax', 'breeze-payment-gateway' ),
                'label'       => __( 'Send WooCommerce-calculated tax to Breeze', 'breeze-payment-gateway' ),
                'type'        => 'checkbox',
                'description' => __( 'When enabled, the tax WooCommerce calculates for each order is sent to Breeze and shown on the payment page, and Breeze skips its own location-based tax calculation. <strong>Requires Breeze to first enable merchant-calculated tax and turn off Breeze\'s own tax calculation for your account</strong> — otherwise payments will be rejected. Leave disabled to let Breeze calculate and collect tax.', 'breeze-payment-gateway' ),
                'default'     => 'no',
                'desc_tip'    => true,
            ),
            'flexible_amount_section' => array(
                'title'       => __( 'Flexible Amount (Crypto)', 'breeze-payment-gateway' ),
                'type'        => 'title',
                'description' => __( 'Configure a flexible deduction amount for crypto deposit payments. At least one of Percentage or Fixed Amount must be set to enable this feature.', 'breeze-payment-gateway' ),
            ),
            'flexible_amount_max' => array(
                'title'             => __( 'Max Amount', 'breeze-payment-gateway' ),
                'type'              => 'number',
                'description'       => __( 'Maximum flexible deduction amount in minor units (e.g. 100 = $1.00). Must be greater than 0; leave blank to apply no cap.', 'breeze-payment-gateway' ),
                'default'           => '',
                'desc_tip'          => true,
                'custom_attributes' => array( 'min' => '1', 'step' => '1' ),
            ),
            'flexible_amount_percentage' => array(
                'title'             => __( 'Percentage', 'breeze-payment-gateway' ),
                'type'              => 'number',
                'description'       => __( 'Flexible deduction as a percentage of the base amount. Must be greater than 0 and no more than 100. Set this or Fixed Amount (or both) to enable flexible amount on crypto deposits.', 'breeze-payment-gateway' ),
                'default'           => '',
                'desc_tip'          => true,
                'custom_attributes' => array( 'min' => '0.01', 'max' => '100', 'step' => '0.01' ),
            ),
            'flexible_amount_fixed' => array(
                'title'             => __( 'Fixed Amount', 'breeze-payment-gateway' ),
                'type'              => 'number',
                'description'       => __( 'Fixed flexible deduction amount in minor units (e.g. 100 = $1.00). Must be greater than 0. Set this or Percentage (or both) to enable flexible amount on crypto deposits.', 'breeze-payment-gateway' ),
                'default'           => '',
                'desc_tip'          => true,
                'custom_attributes' => array( 'min' => '1', 'step' => '1' ),
            ),
        );
    }

    /**
     * Validate Max Amount: blank, or positive integer (minor units, > 0).
     * Server rejects zero and negatives; spec says "minor units" so decimals are rejected too.
     */
    public function validate_flexible_amount_max_field( $key, $value ) {
        return $this->validate_positive_integer_minor_units( $key, $value, __( 'Max Amount', 'breeze-payment-gateway' ) );
    }

    /**
     * Validate Fixed Amount: blank, or positive integer (minor units, > 0).
     */
    public function validate_flexible_amount_fixed_field( $key, $value ) {
        return $this->validate_positive_integer_minor_units( $key, $value, __( 'Fixed Amount', 'breeze-payment-gateway' ) );
    }

    /**
     * Validate Percentage: blank, or number in (0, 100]. Server enforces > 0 and <= 100.
     */
    public function validate_flexible_amount_percentage_field( $key, $value ) {
        $value = is_string( $value ) ? trim( $value ) : $value;
        if ( '' === $value || null === $value ) {
            return '';
        }
        if ( ! is_numeric( $value ) ) {
            WC_Admin_Settings::add_error( __( 'Percentage must be a number greater than 0 and no more than 100.', 'breeze-payment-gateway' ) );
            return '';
        }
        $num = (float) $value;
        if ( $num <= 0 || $num > 100 ) {
            WC_Admin_Settings::add_error( __( 'Percentage must be greater than 0 and no more than 100.', 'breeze-payment-gateway' ) );
            return '';
        }
        return (string) $num;
    }

    /**
     * Shared validator for minor-unit integer fields (Max / Fixed Amount): blank or > 0.
     */
    private function validate_positive_integer_minor_units( $key, $value, $label ) {
        $value = is_string( $value ) ? trim( $value ) : $value;
        if ( '' === $value || null === $value ) {
            return '';
        }
        if ( ! is_numeric( $value ) || (string) (int) $value !== (string) $value ) {
            /* translators: %s: field label */
            WC_Admin_Settings::add_error( sprintf( __( '%s must be a positive whole number (minor units).', 'breeze-payment-gateway' ), $label ) );
            return '';
        }
        $num = (int) $value;
        if ( $num <= 0 ) {
            /* translators: %s: field label */
            WC_Admin_Settings::add_error( sprintf( __( '%s must be greater than 0.', 'breeze-payment-gateway' ), $label ) );
            return '';
        }
        return (string) $num;
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

        if ( ! $order ) {
            wc_add_notice( __( 'Payment error: invalid order.', 'breeze-payment-gateway' ), 'error' );
            return array(
                'result'   => 'failure',
                'messages' => array( __( 'Invalid order.', 'breeze-payment-gateway' ) ),
            );
        }

        // Route to subscription checkout when the order contains a Breeze
        // subscription product. Unsupported carts (mixed, multiple/qty>1,
        // misconfigured) are surfaced to the customer here.
        $subscription = $this->get_subscription_context( $order );
        if ( $subscription['is_subscription'] ) {
            if ( $subscription['error'] ) {
                wc_add_notice( $subscription['error'], 'error' );
                return array(
                    'result'   => 'failure',
                    'messages' => array( $subscription['error'] ),
                );
            }
            return $this->create_subscription_checkout( $order );
        }

        $result = $this->create_payment_for_order( $order );

        if ( is_wp_error( $result ) ) {
            wc_add_notice( __( 'Payment error: ', 'breeze-payment-gateway' ) . $result->get_error_message(), 'error' );
            return array(
                'result'   => 'failure',
                'messages' => array( $result->get_error_message() ),
            );
        }

        return array(
            'result'   => 'success',
            'redirect' => $result['url'],
        );
    }

    /**
     * Create a Breeze payment page for an existing WC order.
     *
     * Public API consumed by both process_payment() and the modal integration.
     * Builds the line items, calls the Breeze API to create a payment page,
     * persists the payment page ID and return token, and moves the order to
     * `pending` status.
     *
     * @param WC_Order $order Order object.
     * @return array|WP_Error On success: { url, id, fail_return_url }.
     */
    public function create_payment_for_order( $order ) {

        if ( ! ( $order instanceof WC_Order ) ) {
            return new WP_Error( 'invalid_order', __( 'Invalid order.', 'breeze-payment-gateway' ) );
        }

        if ( $this->debug ) {
            $this->log->debug(
                sprintf( 'Processing payment for order #%s', $order->get_id() ),
                array( 'source' => $this->id )
            );
        }

        try {
            $line_items = $this->build_line_items( $order );

            if ( empty( $line_items ) ) {
                return new WP_Error( 'no_line_items', __( 'Failed to build line items.', 'breeze-payment-gateway' ) );
            }

            $payment_page = $this->create_breeze_payment_page( $order, $line_items );

            if ( ! $payment_page || empty( $payment_page['url'] ) ) {
                return new WP_Error( 'breeze_page_create_failed', __( 'Failed to create payment page in Breeze.', 'breeze-payment-gateway' ) );
            }

            // Keep existing single-ID meta for refunds / backwards compat
            $order->update_meta_data( '_breeze_payment_page_id', $payment_page['id'] );

            // Also append to the cumulative list (for webhook validation across retries)
            $all_page_ids = $order->get_meta( '_breeze_payment_page_ids' );
            if ( ! is_array( $all_page_ids ) ) {
                $all_page_ids = array();
            }
            if ( ! in_array( $payment_page['id'], $all_page_ids, true ) ) {
                $all_page_ids[] = $payment_page['id'];
            }
            $order->update_meta_data( '_breeze_payment_page_ids', $all_page_ids );
            $order->save();

            $order->update_status( 'pending', __( 'Awaiting Breeze payment.', 'breeze-payment-gateway' ) );

            if ( $this->debug ) {
                $this->log->info(
                    sprintf( 'Payment page created for order #%s. URL: %s', $order->get_id(), $payment_page['url'] ),
                    array( 'source' => $this->id )
                );
            }

            return array(
                'url'             => $payment_page['url'],
                'id'              => isset( $payment_page['id'] ) ? $payment_page['id'] : '',
                'fail_return_url' => isset( $payment_page['fail_return_url'] ) ? $payment_page['fail_return_url'] : '',
            );

        } catch ( Exception $e ) {

            if ( $this->debug ) {
                $this->log->error(
                    sprintf( 'Payment exception for order #%s: %s', $order->get_id(), $e->getMessage() ),
                    array( 'source' => $this->id )
                );
            }

            return new WP_Error( 'breeze_payment_exception', $e->getMessage() );
        }
    }

    /**
     * Build line items array for Breeze payment page using the
     * "Inline Products (Your Product IDs)" approach (`clientProductId`).
     *
     * Each WooCommerce product is mapped to a Breeze line item carrying the
     * merchant's own product ID as `clientProductId`. This is preferred over
     * anonymous inline products because Breeze can correlate purchases to known
     * SKUs in the merchant's catalogue.
     *
     * Line item shape (clientProductId variant):
     *   clientProductId  string   — merchant's product ID (required)
     *   displayName      string   — human-readable name shown on the payment page (required)
     *   amount           int      — per-unit price in minor currency units / cents (required)
     *   currency         string   — ISO 4217 currency code (required)
     *   quantity         int      — number of units (required, ≥ 1)
     *   description      string   — optional short description
     *   image            string   — optional single image URL
     *
     * Rounding strategy (qty > 1 with coupon-adjusted line totals):
     *   floor(line_total_cents / qty) × (qty-1)  +  (line_total_cents - floor×(qty-1))
     *   ensures the sum always equals the exact WooCommerce line total.
     *
     * @param WC_Order $order Order object.
     * @return array Line items array.
     */
    private function build_line_items( $order ) {

        // Breeze caps lineItems at 20 entries. Reserve 1 slot for shipping.
        $max_product_entries = 19;
        $line_items          = array();

        foreach ( $order->get_items() as $item ) {

            $product = $item->get_product();

            if ( ! $product ) {
                continue;
            }

            // Skip items with no valid product ID (e.g. unattached variations).
            if ( ! $product->get_id() ) {
                continue;
            }

            $qty = $item->get_quantity();

            if ( $qty <= 0 ) {
                continue;
            }

            $line_total_cents = (int) round( (float) $item->get_total() * 100 );

            // Skip zero-amount items (free / 100%-discounted) — spec requires amount ≥ 1.
            if ( $line_total_cents <= 0 ) {
                continue;
            }

            $base_unit_cents = (int) floor( $line_total_cents / $qty );
            $remainder_cents = $line_total_cents - ( $base_unit_cents * $qty );

            $client_product_id = (string) $product->get_id();
            $display_name      = mb_substr( $item->get_name(), 0, 100 );
            // Only include product description when the admin setting is enabled.
            $description       = $this->send_product_description && $product->get_short_description()
                ? mb_substr( wp_strip_all_tags( $product->get_short_description() ), 0, 280 )
                : '';
            $image_url         = wp_get_attachment_url( $product->get_image_id() );
            $currency          = $order->get_currency();

            // A remainder forces a two-entry split; otherwise one entry suffices.
            $entries_needed = ( $remainder_cents !== 0 && $qty > 1 ) ? 2 : 1;

            if ( count( $line_items ) + $entries_needed > $max_product_entries ) {
                throw new Exception(
                    __( 'Order has too many line items for Breeze (max 19 product entries plus shipping). Please contact support.', 'breeze-payment-gateway' )
                );
            }

            if ( $remainder_cents === 0 ) {
                // Clean division — emit a single line item for the full quantity.
                $entry = array(
                    'clientProductId' => $client_product_id,
                    'displayName'     => $display_name,
                    'amount'          => $base_unit_cents,
                    'currency'        => $currency,
                    'quantity'        => $qty,
                );
                if ( $description ) {
                    $entry['description'] = $description;
                }
                if ( $image_url ) {
                    $entry['image'] = $image_url;
                }
                $line_items[] = $entry;
            } else {
                // Remainder — split to preserve the exact line total.
                // (qty - 1) units at the base price …
                if ( $qty > 1 ) {
                    $bulk = array(
                        'clientProductId' => $client_product_id,
                        'displayName'     => $display_name,
                        'amount'          => $base_unit_cents,
                        'currency'        => $currency,
                        'quantity'        => $qty - 1,
                    );
                    if ( $description ) {
                        $bulk['description'] = $description;
                    }
                    if ( $image_url ) {
                        $bulk['image'] = $image_url;
                    }
                    $line_items[] = $bulk;
                }

                // … and 1 unit at base + remainder to absorb the rounding delta.
                $tail = array(
                    'clientProductId' => $client_product_id,
                    'displayName'     => $display_name,
                    'amount'          => $base_unit_cents + $remainder_cents,
                    'currency'        => $currency,
                    'quantity'        => 1,
                );
                if ( $description ) {
                    $tail['description'] = $description;
                }
                if ( $image_url ) {
                    $tail['image'] = $image_url;
                }
                $line_items[] = $tail;
            }
        }

        // Shipping as a virtual line item (clientProductId = 'shipping').
        if ( $order->get_shipping_total() > 0 ) {
            $shipping_item = array(
                'clientProductId' => 'shipping',
                'displayName'     => __( 'Shipping', 'breeze-payment-gateway' ),
                'amount'          => (int) round( (float) $order->get_shipping_total() * 100 ),
                'currency'        => $order->get_currency(),
                'quantity'        => 1,
            );
            $shipping_method = $order->get_shipping_method();
            if ( $shipping_method ) {
                $shipping_item['description'] = mb_substr( $shipping_method, 0, 280 );
            }
            $line_items[] = $shipping_item;
        }

        // NOTE: Do NOT send WooCommerce tax as a line item.
        // Breeze is the Merchant of Record and calculates + collects tax itself.
        // Sending WooCommerce tax would result in double-taxation for the customer.

        // NOTE: Discounts are already reflected in the per-unit price above
        // ($item->get_total() returns the post-coupon line total).
        // No separate discount line items are required.

        return $line_items;
    }

    /**
     * Whether preferred crypto network/token params should be appended to the
     * checkout URL. These only affect the crypto deposit flow, so they are only
     * meaningful when Crypto Deposit is a selected preferred method. The legacy
     * 'crypto' value (which the checkout expands to wallet + deposit) is also
     * accepted for backwards compatibility with settings saved before the split.
     *
     * Pure helper (no dependencies) to keep the gating logic unit testable.
     *
     * @param array $payment_methods Configured preferred payment methods.
     * @return bool
     */
    public static function should_append_crypto_params( $payment_methods ) {
        if ( ! is_array( $payment_methods ) ) {
            return false;
        }
        return in_array( 'crypto_deposit', $payment_methods, true )
            || in_array( 'crypto', $payment_methods, true );
    }

    /**
     * Build the taxDetails payload for merchant-calculated tax.
     *
     * Pure helper (no WordPress/WooCommerce dependencies) so it can be unit
     * tested directly against the real production logic.
     *
     * When enabled, the plugin sends WooCommerce's computed tax as a display
     * line and Breeze skips its own location-based calculation. Line items
     * stay pre-tax; Breeze derives the charged/settled amount as
     * Σ(lineItems) + taxDetails.amount. A `0` tax is valid (zero-rated) and is
     * still sent so the merchant value remains authoritative.
     *
     * @param bool  $enabled   Whether merchant-calculated tax is enabled.
     * @param float $order_tax WooCommerce order total tax in major units.
     * @return array|null taxDetails array, or null when disabled.
     */
    public static function build_tax_details( $enabled, $order_tax ) {
        if ( ! $enabled ) {
            return null;
        }

        return array(
            'amount' => (int) round( (float) $order_tax * 100 ),
            'mode'   => 'merchant_handled',
        );
    }

    /**
     * Create payment page in Breeze
     *
     * @param WC_Order $order      Order object.
     * @param array    $line_items Line items built by build_line_items().
     * @return array|false Payment page data or false on failure.
     */
    private function create_breeze_payment_page( $order, $line_items ) {

        // Generate a one-time return token to prevent unauthenticated order status manipulation.
        // The token is included in both return URLs and verified in handle_return().
        $return_token = wp_generate_password( 32, false );

        // Keep existing single-token meta for backwards compat with orders that
        // predate the cumulative list.
        $order->update_meta_data( '_breeze_return_token', $return_token );

        // Also append to the cumulative list — covers checkout retries where the
        // customer pays on (and returns via) an earlier payment page's URL, which
        // carries that page's token, not the latest one.
        $all_tokens = $order->get_meta( '_breeze_return_tokens' );
        if ( ! is_array( $all_tokens ) ) {
            $all_tokens = array();
        }
        if ( ! in_array( $return_token, $all_tokens, true ) ) {
            $all_tokens[] = $return_token;
        }
        $order->update_meta_data( '_breeze_return_tokens', $all_tokens );
        $order->save();

        // Look up existing customer by email; if found pass their Breeze ID,
        // otherwise supply the full object for inline creation.
        $user_id        = $order->get_user_id();
        $lookup         = $this->breeze_api_request( 'GET', '/v1/customers?email=' . rawurlencode( $order->get_billing_email() ) );
        if ( $lookup && isset( $lookup['data']['id'] ) ) {
            $customer = array( 'id' => $lookup['data']['id'] );
        } else {
            $customer = array(
                'referenceId' => $user_id ? 'user-' . $user_id : 'guest-' . $order->get_id(),
                'email'       => $order->get_billing_email(),
                'firstName'   => $order->get_billing_first_name(),
                'lastName'    => $order->get_billing_last_name(),
                'signupAt'    => time() * 1000,
            );
        }

        $success_return_url = add_query_arg(
            array(
                'wc-api'   => 'breeze_return',
                'order_id' => $order->get_id(),
                'status'   => 'success',
                'token'    => $return_token,
            ),
            home_url( '/' )
        );

        $fail_return_url = add_query_arg(
            array(
                'wc-api'   => 'breeze_return',
                'order_id' => $order->get_id(),
                'status'   => 'failed',
                'token'    => $return_token,
            ),
            home_url( '/' )
        );

        $payment_data = array(
            'lineItems'         => $line_items,
            'billingEmail'      => $order->get_billing_email(),
            // Append a unique suffix so retries (same order, new payment attempt)
            // don't collide with the previous payment page already in Breeze.
            // The webhook handler uses absint() to extract the order ID, which
            // stops at the first non-numeric character — so 'order-42-x7k2m9'
            // is correctly resolved back to order 42.
            'clientReferenceId' => 'order-' . $order->get_id() . '-' . wp_generate_password( 6, false, false ),
            'successReturnUrl'  => $success_return_url,
            'failReturnUrl'     => $fail_return_url,
            'customer'          => $customer,
        );

        // Append merchant-calculated tax when enabled. Breeze skips its own
        // location-based calc and derives amount = Σ(lineItems) + taxDetails.amount.
        // Requires Breeze to have enabled merchant-calculated tax for this
        // merchant, otherwise the API rejects the request.
        $tax_details = self::build_tax_details( $this->merchant_calculated_tax, $order->get_total_tax() );
        if ( null !== $tax_details ) {
            $payment_data['taxDetails'] = $tax_details;

            if ( $this->debug ) {
                $this->log->debug(
                    sprintf(
                        'Merchant-calculated tax sent: %d minor units (mode = merchant_handled)',
                        $tax_details['amount']
                    ),
                    array( 'source' => $this->id )
                );
            }
        }

        // Conditionally append settings.flexibleAmount when at least one sub-field is configured.
        // Per spec, flexibleAmount lives under `settings` and is only honored for crypto deposit payments.
        $has_percentage = '' !== $this->flexible_amount_percentage && null !== $this->flexible_amount_percentage;
        $has_fixed      = '' !== $this->flexible_amount_fixed && null !== $this->flexible_amount_fixed;
        if ( $has_percentage || $has_fixed ) {
            $flexible = array();
            if ( '' !== $this->flexible_amount_max && null !== $this->flexible_amount_max ) {
                $flexible['maxAmount'] = (int) $this->flexible_amount_max;
            }
            if ( $has_percentage ) {
                $flexible['percentage'] = (float) $this->flexible_amount_percentage;
            }
            if ( $has_fixed ) {
                $flexible['fixedAmount'] = (int) $this->flexible_amount_fixed;
            }
            if ( ! isset( $payment_data['settings'] ) ) {
                $payment_data['settings'] = array();
            }
            $payment_data['settings']['flexibleAmount'] = $flexible;
        }

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

            // Add preferred crypto network and token as query parameters, but only
            // when Crypto is actually a selected preferred payment method. Sending
            // network/token on a non-crypto checkout (e.g. Apple Pay only) has no
            // meaning and would contradict the settings' documented behavior.
            $crypto_preferred = self::should_append_crypto_params( $this->payment_methods );

            if ( $crypto_preferred && ! empty( $this->crypto_network ) ) {
                $payment_data['url'] = add_query_arg(
                    'network',
                    strtoupper( sanitize_text_field( $this->crypto_network ) ),
                    $payment_data['url']
                );

                if ( $this->debug ) {
                    $this->log->debug(
                        sprintf( 'Adding crypto network to URL: %s', $this->crypto_network ),
                        array( 'source' => $this->id )
                    );
                }
            }

            if ( $crypto_preferred && ! empty( $this->crypto_token ) ) {
                $payment_data['url'] = add_query_arg(
                    'token',
                    strtoupper( sanitize_text_field( $this->crypto_token ) ),
                    $payment_data['url']
                );

                if ( $this->debug ) {
                    $this->log->debug(
                        sprintf( 'Adding crypto token to URL: %s', $this->crypto_token ),
                        array( 'source' => $this->id )
                    );
                }
            }

            // Surface the fail return URL so callers (modal integration) can
            // route the customer there if they close the modal mid-payment,
            // reusing the existing token-protected handle_return() flow.
            $payment_data['fail_return_url'] = $fail_return_url;

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

        // Log request (redact sensitive fields)
        if ( $this->debug ) {
            $safe_data = $data;
            unset( $safe_data['billingEmail'] );
            if ( isset( $safe_data['customer'] ) ) {
                $safe_data['customer'] = '[redacted]';
            }
            $this->log->debug(
                sprintf( 'Breeze API Request: %s %s', $method, $url ),
                array( 'source' => $this->id, 'data' => $safe_data )
            );
        }

        $response = wp_remote_request( $url, $args );

        // Check for errors
        if ( is_wp_error( $response ) ) {
            // Always log transport failures (not just in debug) so a broken
            // checkout is diagnosable from the WooCommerce logs.
            $logger = $this->log ? $this->log : wc_get_logger();
            $logger->error(
                sprintf( 'Breeze API request failed: %s %s - %s', $method, $url, $response->get_error_message() ),
                array( 'source' => $this->id )
            );
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
            // Always log API error responses (not just in debug) so misconfigurations
            // — e.g. a merchant not enabled for taxDetails (TAX_DETAILS_NOT_ENABLED) or a
            // tax/Breeze-tax conflict — are diagnosable without first turning on debug.
            $logger = $this->log ? $this->log : wc_get_logger();
            $logger->error(
                sprintf( 'Breeze API Error Response: %s %s - Status: %d - %s', $method, $url, $response_code, $response_body ),
                array( 'source' => $this->id )
            );
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

        // Verify the return token to prevent unauthenticated order completion.
        // Accept the submitted token if it's a member of the cumulative list (covers
        // checkout retries where the customer returned via an earlier payment page),
        // or matches the legacy single-token meta (orders that predate the list).
        $all_tokens   = $order->get_meta( '_breeze_return_tokens' );
        $single_token = $order->get_meta( '_breeze_return_token' );

        $valid_tokens = is_array( $all_tokens ) && ! empty( $all_tokens )
            ? $all_tokens
            : ( $single_token ? array( $single_token ) : array() );

        $token_matches = false;
        if ( ! empty( $token ) ) {
            foreach ( $valid_tokens as $valid_token ) {
                // hash_equals() for timing-safe comparison.
                if ( hash_equals( $valid_token, $token ) ) {
                    $token_matches = true;
                    break;
                }
            }
        }

        if ( ! $token_matches ) {
            if ( $this->debug ) {
                $this->log->warning(
                    sprintf( 'Return URL token verification failed for order #%s', $order_id ),
                    array( 'source' => $this->id )
                );
            }
            wp_safe_redirect( wc_get_cart_url() );
            exit;
        }

        // Consume all tokens — return URLs are one-time use, and once any one
        // has been redeemed the order is in a settled state, so further returns
        // (e.g. from a stale URL belonging to a different payment page) need not
        // be honored.
        $order->delete_meta_data( '_breeze_return_tokens' );
        $order->delete_meta_data( '_breeze_return_token' );
        $order->save();

        if ( 'success' === $status ) {
            
            // Do NOT call payment_complete() here — the webhook is the authoritative
            // confirmation of payment. Set to on-hold until the webhook fires.
            // This prevents marking orders as paid if the customer is redirected back
            // but the payment hasn't actually settled on Breeze's side.
            if ( ! $order->is_paid() ) {
                $order->update_status( 'on-hold', __( 'Customer returned from Breeze — awaiting webhook confirmation.', 'breeze-payment-gateway' ) );
            }

            // Remove cart
            if ( WC()->cart ) {
                WC()->cart->empty_cart();
            }

            // Log
            if ( $this->debug ) {
                $this->log->info(
                    sprintf( 'Customer returned from Breeze for order #%s — awaiting webhook', $order_id ),
                    array( 'source' => $this->id )
                );
            }

            // Redirect to thank you page
            wp_safe_redirect( $this->get_return_url( $order ) );
            exit;

        } else {
            
            // Payment failed
            $order->update_status( 'failed', __( 'Payment failed or cancelled by customer.', 'breeze-payment-gateway' ) );

            // Remove cart. WC()->cart can be null on a wc-api request (the cart
            // is loaded on wp_loaded, which does not reliably run for this
            // context), so guard it exactly like the success path above —
            // otherwise the failed-payment redirect fatals.
            if ( WC()->cart ) {
                WC()->cart->empty_cart();
            }

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
     * Process refund via Breeze API.
     *
     * Breeze refund endpoint: POST /v1/payment_pages/{pageId}/refund
     * Body: { "amount": <cents>, "reason": "..." }
     *
     * Supports both full and partial refunds. WooCommerce handles multiple
     * partial refunds by calling this method once per refund request.
     *
     * @param int    $order_id Order ID.
     * @param float  $amount   Refund amount (in major currency units, e.g. 9.99).
     * @param string $reason   Refund reason.
     * @return boolean|WP_Error True on success, WP_Error on failure.
     */
    public function process_refund( $order_id, $amount = null, $reason = '' ) {

        $order = wc_get_order( $order_id );

        if ( ! $order ) {
            return new WP_Error( 'invalid_order', __( 'Invalid order.', 'breeze-payment-gateway' ) );
        }

        // Prefer the transaction ID set by payment_complete() in the webhook handler —
        // that's the page the customer actually paid on. If multiple payment pages
        // were created (checkout retries), _breeze_payment_page_id holds the *latest*
        // created page, which may not be the paid one. Fall back to it only for
        // orders that predate the webhook setting a transaction ID.
        $page_id = $order->get_transaction_id();

        if ( empty( $page_id ) ) {
            $page_id = $order->get_meta( '_breeze_payment_page_id' );
        }

        if ( empty( $page_id ) ) {
            return new WP_Error(
                'missing_page_id',
                __( 'Cannot refund — no Breeze payment page ID found on this order.', 'breeze-payment-gateway' )
            );
        }

        if ( ! $amount || $amount <= 0 ) {
            return new WP_Error(
                'invalid_amount',
                __( 'Refund amount must be greater than zero.', 'breeze-payment-gateway' )
            );
        }

        $refund_data = array(
            'amount' => (int) round( $amount * 100 ), // Convert to cents
        );

        if ( ! empty( $reason ) ) {
            $refund_data['reason'] = sanitize_text_field( $reason );
        }

        // Log refund attempt
        if ( $this->debug ) {
            $this->log->info(
                sprintf( 'Processing refund for order #%s: %s %s (page: %s)',
                    $order_id,
                    $amount,
                    $order->get_currency(),
                    $page_id
                ),
                array( 'source' => $this->id )
            );
        }

        $result = $this->breeze_api_request(
            'POST',
            '/v1/payment_pages/' . $page_id . '/refund',
            $refund_data
        );

        if ( ! $result ) {
            // Common failure: crypto payments cannot be refunded via API
            // (no card to reverse — requires wallet address or manual process)
            return new WP_Error(
                'refund_failed',
                __( 'Breeze refund request failed. If the customer paid with crypto, refunds must be processed manually from the Breeze dashboard (requires customer wallet address). For card payments, check the debug log for details.', 'breeze-payment-gateway' )
            );
        }

        // Extract refund ID if available
        $refund_id = '';
        if ( isset( $result['data']['id'] ) ) {
            $refund_id = $result['data']['id'];
        } elseif ( isset( $result['id'] ) ) {
            $refund_id = $result['id'];
        }

        $order->add_order_note(
            sprintf(
                __( 'Refund of %s %s processed via Breeze. Refund ID: %s. Reason: %s', 'breeze-payment-gateway' ),
                $amount,
                $order->get_currency(),
                $refund_id ? $refund_id : 'N/A',
                $reason ? $reason : 'N/A'
            )
        );

        if ( $this->debug ) {
            $this->log->info(
                sprintf( 'Refund successful for order #%s. Refund ID: %s', $order_id, $refund_id ),
                array( 'source' => $this->id )
            );
        }

        return true;
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

        // Log verified webhook (redact PII — only log event type and order reference)
        if ( $this->debug ) {
            $this->log->debug(
                sprintf( 'Breeze webhook verified: type=%s, ref=%s',
                    isset( $webhook_data['type'] ) ? $webhook_data['type'] : 'unknown',
                    isset( $webhook_data['data']['clientReferenceId'] ) ? $webhook_data['data']['clientReferenceId'] : 'none'
                ),
                array( 'source' => $this->id )
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

                case 'SUBSCRIPTION_STATUS_UPDATED':
                    $this->handle_subscription_status_updated_webhook( $data );
                    break;

                case 'INVOICE_STATUS_UPDATED':
                    $this->handle_invoice_status_updated_webhook( $data );
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
            if ( $this->log ) {
                $this->log->error(
                    'Webhook rejected: no webhook secret configured. Set the Webhook Secret in WooCommerce > Settings > Payments > Breeze.',
                    array( 'source' => $this->id )
                );
            }
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
     * Extract and validate order from webhook data.
     *
     * @param array $data Webhook data.
     * @return WC_Order|false Order object or false on failure.
     */
    private function get_order_from_webhook( $data ) {

        if ( ! isset( $data['clientReferenceId'] ) ) {
            if ( $this->debug ) {
                $this->log->warning( 'Webhook missing clientReferenceId', array( 'source' => $this->id ) );
            }
            return false;
        }

        // Extract order ID — use absint() to sanitize
        $raw_id  = str_replace( 'order-', '', $data['clientReferenceId'] );
        $order_id = absint( $raw_id );

        if ( ! $order_id ) {
            if ( $this->debug ) {
                $this->log->warning(
                    sprintf( 'Webhook has invalid order reference: %s', $data['clientReferenceId'] ),
                    array( 'source' => $this->id )
                );
            }
            return false;
        }

        $order = wc_get_order( $order_id );

        if ( ! $order ) {
            if ( $this->debug ) {
                $this->log->warning(
                    sprintf( 'Webhook references non-existent order #%d', $order_id ),
                    array( 'source' => $this->id )
                );
            }
            return false;
        }

        // Verify the payment page ID matches what we stored on this order.
        // This prevents a valid webhook for order A from being used to mark order B as paid.
        $stored_page_id  = $order->get_meta( '_breeze_payment_page_id' );
        $all_page_ids    = $order->get_meta( '_breeze_payment_page_ids' );
        $webhook_page_id = isset( $data['pageId'] ) ? $data['pageId'] : '';

        if ( $stored_page_id ) {
            if ( ! $webhook_page_id ) {
                // We have a stored pageId but the webhook omits it — reject.
                // Accepting pageId-less webhooks would silently bypass cross-order protection.
                if ( $this->debug ) {
                    $this->log->warning(
                        sprintf( 'Webhook missing pageId for order #%d (stored: %s)', $order_id, $stored_page_id ),
                        array( 'source' => $this->id )
                    );
                }
                return false;
            }

            // Accept if webhook pageId is in the cumulative list (covers retries),
            // OR matches the single stored ID (legacy / first-attempt orders).
            $valid_ids = is_array( $all_page_ids ) && ! empty( $all_page_ids ) ? $all_page_ids : array( $stored_page_id );
            if ( ! in_array( $webhook_page_id, $valid_ids, true ) ) {
                if ( $this->debug ) {
                    $this->log->error(
                        sprintf(
                            'Webhook page ID mismatch for order #%d: valid=%s, webhook=%s',
                            $order_id, implode( ', ', $valid_ids ), $webhook_page_id
                        ),
                        array( 'source' => $this->id )
                    );
                }
                return false;
            }
        }

        return $order;
    }

    /**
     * Handle payment success webhook
     *
     * @param array $data Webhook data.
     */
    private function handle_payment_success_webhook( $data ) {

        $order = $this->get_order_from_webhook( $data );

        if ( ! $order ) {
            return;
        }

        if ( ! $order->is_paid() ) {
            $transaction_id = isset( $data['pageId'] ) ? $data['pageId'] : '';
            $order->payment_complete( $transaction_id );
            $order->add_order_note(
                sprintf(
                    __( 'Payment confirmed via Breeze webhook. Transaction ID: %s', 'breeze-payment-gateway' ),
                    $transaction_id ? $transaction_id : 'N/A'
                )
            );
            // payment_complete() triggers wc_reduce_stock_levels() automatically in WC 3.0+

            // Expire any sibling payment pages created for this order. After a
            // checkout retry, earlier pages remain "open" in Breeze and could
            // still accept a charge — which would double-bill the customer.
            // /v1/payment_pages/{id}/expire prevents that. Best-effort: a
            // failure here doesn't unwind the (already successful) primary
            // payment, but we log it so the merchant has a trail.
            $this->expire_sibling_payment_pages( $order, $transaction_id );

            return;
        }

        // Order is already paid. Two cases land here:
        //   1. Idempotent webhook retry (same pageId Breeze already confirmed) — ignore.
        //   2. A *different* payment page for this same order was paid — i.e. the
        //      customer was charged twice (paid on multiple retry pages). The plugin
        //      cannot deduplicate at Breeze's side (each page is an independent
        //      payment intent with its own clientReferenceId), so surface this loudly
        //      via an order note + error log so the merchant can issue a manual refund.
        $incoming_page_id = isset( $data['pageId'] ) ? $data['pageId'] : '';
        $recorded_page_id = $order->get_transaction_id();

        if ( ! $incoming_page_id || $incoming_page_id === $recorded_page_id ) {
            return;
        }

        // Dedupe duplicate-payment notes by pageId so webhook retries don't
        // spam the order timeline with one note per delivery attempt.
        $noted = $order->get_meta( '_breeze_duplicate_pages_noted' );
        if ( ! is_array( $noted ) ) {
            $noted = array();
        }
        if ( in_array( $incoming_page_id, $noted, true ) ) {
            return;
        }

        $order->add_order_note(
            sprintf(
                /* translators: 1: duplicate payment page ID, 2: original paid page ID */
                __( 'Duplicate payment received via Breeze on page %1$s. This order was already paid on %2$s, so the customer has likely been charged twice. Issue a manual refund for the duplicate via the Breeze dashboard.', 'breeze-payment-gateway' ),
                $incoming_page_id,
                $recorded_page_id ? $recorded_page_id : 'N/A'
            )
        );

        $noted[] = $incoming_page_id;
        $order->update_meta_data( '_breeze_duplicate_pages_noted', $noted );
        $order->save();

        if ( $this->debug ) {
            $this->log->error(
                sprintf(
                    'DUPLICATE payment webhook for order #%d: incoming pageId=%s, recorded transaction=%s',
                    $order->get_id(), $incoming_page_id, $recorded_page_id ? $recorded_page_id : 'N/A'
                ),
                array( 'source' => $this->id )
            );
        }
    }

    /**
     * Expire every payment page on the order except the one that just succeeded.
     *
     * Uses Breeze's POST /v1/payment_pages/{id}/expire — "Marks an open payment
     * page as expired so it can no longer accept payments." Called immediately
     * after payment_complete() to slam the door on any sibling pages from earlier
     * retry attempts, preventing a second charge if the customer (or anyone with
     * a stale URL) tries to complete payment on one of them.
     *
     * Best-effort: a 4xx from /expire (e.g. page already expired/succeeded) is
     * a no-op for our purposes; transport-level failures are logged but do not
     * propagate, since the primary payment is already settled in WC.
     *
     * @param WC_Order $order        Order whose sibling pages should be expired.
     * @param string   $keep_page_id Page ID to leave untouched (the one that was paid).
     */
    private function expire_sibling_payment_pages( $order, $keep_page_id ) {
        $all_page_ids = $order->get_meta( '_breeze_payment_page_ids' );
        if ( ! is_array( $all_page_ids ) || empty( $all_page_ids ) ) {
            return;
        }

        foreach ( $all_page_ids as $page_id ) {
            if ( ! $page_id || $page_id === $keep_page_id ) {
                continue;
            }

            $result = $this->breeze_api_request(
                'POST',
                '/v1/payment_pages/' . rawurlencode( $page_id ) . '/expire'
            );

            if ( false === $result && $this->debug ) {
                // breeze_api_request() returns false on non-2xx / transport error;
                // a 2xx with empty body returns null and is treated as success here.
                $this->log->warning(
                    sprintf(
                        'Failed to expire sibling payment page %s for order #%d (the page may still be reachable for payment — monitor for duplicate-charge notes)',
                        $page_id, $order->get_id()
                    ),
                    array( 'source' => $this->id )
                );
            }
        }
    }

    /**
     * Handle payment failed webhook
     *
     * @param array $data Webhook data.
     */
    private function handle_payment_failed_webhook( $data ) {

        $order = $this->get_order_from_webhook( $data );

        if ( ! $order ) {
            return;
        }

        // Do NOT override a paid order with a failed status.
        // A delayed failure webhook could arrive after a success webhook.
        if ( $order->is_paid() ) {
            if ( $this->debug ) {
                $this->log->warning(
                    sprintf( 'Ignoring failure webhook for already-paid order #%d', $order->get_id() ),
                    array( 'source' => $this->id )
                );
            }
            return;
        }

        $order->update_status( 'failed', __( 'Payment failed via Breeze webhook notification.', 'breeze-payment-gateway' ) );
    }

    /**
     * Supported currencies. Breeze currently supports USD, EUR, SGD, and CAD.
     * Override via filter: add_filter( 'breeze_supported_currencies', fn($c) => array_merge($c, ['GBP']) );
     *
     * @return array
     */
    private function get_supported_currencies() {
        return apply_filters( 'breeze_supported_currencies', array( 'USD', 'EUR', 'SGD', 'CAD' ) );
    }

    /**
     * Only show the gateway if the store currency is supported by Breeze.
     *
     * @return boolean
     */
    public function is_available() {
        if ( ! parent::is_available() ) {
            return false;
        }

        $currency = get_woocommerce_currency();
        if ( ! in_array( $currency, $this->get_supported_currencies(), true ) ) {
            return false;
        }

        return true;
    }

    /**
     * Check if gateway supports blocks
     *
     * @return boolean
     */
    public function is_blocks_supported() {
        return true;
    }

    /**
     * Create a Breeze subscription checkout session for an order containing
     * a subscription product (identified by the `_breeze_price_id` meta).
     *
     * Calls POST /v1/subscriptions and redirects the customer to the returned
     * `checkoutUrl`. The Breeze subscription ID is persisted on the order as
     * `_breeze_subscription_id` for later webhook correlation.
     *
     * @param WC_Order $order WooCommerce order containing the subscription product.
     * @return array WooCommerce process_payment result array.
     */
    protected function create_subscription_checkout( $order ) {

        // Resolve and validate the subscription line (single price/product,
        // quantity 1, both Breeze IDs present).
        $subscription = $this->get_subscription_context( $order );

        if ( ! $subscription['is_subscription'] || $subscription['error'] ) {
            $message = $subscription['error']
                ? $subscription['error']
                : __( 'No Breeze subscription product found in this order.', 'breeze-payment-gateway' );
            wc_add_notice( $message, 'error' );
            return array(
                'result'   => 'failure',
                'messages' => array( $message ),
            );
        }

        // POST /v1/subscriptions requires an existing Breeze customer, so
        // resolve (creating if needed) the customer before creating the sub.
        $customer_id = $this->resolve_breeze_customer_id( $order );
        if ( ! $customer_id ) {
            wc_add_notice( __( 'Payment error: could not set up your Breeze customer profile. Please try again.', 'breeze-payment-gateway' ), 'error' );
            return array(
                'result'   => 'failure',
                'messages' => array( __( 'Could not create Breeze customer.', 'breeze-payment-gateway' ) ),
            );
        }

        // Generate a one-time return token (same pattern as payment page flow).
        $return_token = wp_generate_password( 32, false );
        $order->update_meta_data( '_breeze_return_token', $return_token );
        $all_tokens = $order->get_meta( '_breeze_return_tokens' );
        if ( ! is_array( $all_tokens ) ) {
            $all_tokens = array();
        }
        if ( ! in_array( $return_token, $all_tokens, true ) ) {
            $all_tokens[] = $return_token;
        }
        $order->update_meta_data( '_breeze_return_tokens', $all_tokens );
        $order->save();

        $success_return_url = add_query_arg(
            array(
                'wc-api'   => 'breeze_return',
                'order_id' => $order->get_id(),
                'status'   => 'success',
                'token'    => $return_token,
            ),
            home_url( '/' )
        );

        $fail_return_url = add_query_arg(
            array(
                'wc-api'   => 'breeze_return',
                'order_id' => $order->get_id(),
                'status'   => 'failed',
                'token'    => $return_token,
            ),
            home_url( '/' )
        );

        // Both productId and priceId are required by POST /v1/subscriptions,
        // and the customer must be referenced by id (not email). Crypto is
        // intentionally excluded — subscriptions are fiat-only.
        $subscription_data = array(
            'clientReferenceId'       => 'order-' . $order->get_id(),
            'productId'               => $subscription['product_id'],
            'priceId'                 => $subscription['price_id'],
            'customer'                => array( 'id' => $customer_id ),
            'preferredPaymentMethods' => array( 'card', 'apple_pay', 'google_pay' ),
            'successReturnUrl'        => $success_return_url,
            'failReturnUrl'           => $fail_return_url,
        );

        $result = $this->breeze_api_request( 'POST', '/v1/subscriptions', $subscription_data );

        // The API returns { id, url }; `url` is the first invoice's hosted
        // payment page to redirect the customer to.
        if ( ! $result || empty( $result['url'] ) ) {
            wc_add_notice( __( 'Payment error: failed to create Breeze subscription checkout.', 'breeze-payment-gateway' ), 'error' );
            return array(
                'result'   => 'failure',
                'messages' => array( __( 'Failed to create Breeze subscription checkout.', 'breeze-payment-gateway' ) ),
            );
        }

        // Persist the Breeze subscription ID for webhook correlation, and flag
        // this as the original subscription order (renewals carry the same
        // subscription id, so this distinguishes the first order from renewals).
        if ( ! empty( $result['id'] ) ) {
            $order->update_meta_data( '_breeze_subscription_id', $result['id'] );
        }
        $order->update_meta_data( '_breeze_is_subscription_order', 'yes' );
        $order->save();

        $order->update_status( 'pending', __( 'Awaiting Breeze subscription payment.', 'breeze-payment-gateway' ) );

        return array(
            'result'   => 'success',
            'redirect' => $result['url'],
        );
    }

    /**
     * Inspect an order's line items for a Breeze subscription product.
     *
     * A product is a subscription only when it carries BOTH `_breeze_price_id`
     * and `_breeze_product_id`. The Breeze subscriptions API models exactly one
     * price per subscription (no quantity multiplier, no multiple products), so
     * this enforces a single subscription line at quantity 1 and blocks mixed
     * carts.
     *
     * @param WC_Order $order Order to inspect.
     * @return array {
     *     @type bool   $is_subscription Whether the order contains a subscription product.
     *     @type string $price_id        Breeze Price ID (only set when the cart is valid).
     *     @type string $product_id      Breeze Product ID (only set when the cart is valid).
     *     @type string $error           User-facing error when the cart is unsupported ('' when OK).
     * }
     */
    private function get_subscription_context( $order ) {
        $sub_lines       = array();
        $has_other_items = false;
        $total_sub_qty   = 0;

        foreach ( $order->get_items() as $item ) {
            $product_ref = $item->get_product_id();
            $price_id    = get_post_meta( $product_ref, '_breeze_price_id', true );

            if ( $price_id ) {
                $sub_lines[]    = array(
                    'price_id'   => $price_id,
                    'product_id' => get_post_meta( $product_ref, '_breeze_product_id', true ),
                );
                $total_sub_qty += (int) $item->get_quantity();
            } else {
                $has_other_items = true;
            }
        }

        $context = array(
            'is_subscription' => ! empty( $sub_lines ),
            'price_id'        => '',
            'product_id'      => '',
            'error'           => '',
        );

        if ( empty( $sub_lines ) ) {
            return $context;
        }

        // Subscriptions must be purchased on their own.
        if ( $has_other_items ) {
            $context['error'] = __( 'Subscription items must be purchased separately from one-time products.', 'breeze-payment-gateway' );
            return $context;
        }

        // The API supports exactly one price per subscription — no multiple
        // subscription products and no quantity multiplier.
        if ( count( $sub_lines ) > 1 || $total_sub_qty > 1 ) {
            $context['error'] = __( 'Only one subscription item (quantity 1) can be purchased per order. Please adjust your cart.', 'breeze-payment-gateway' );
            return $context;
        }

        // Both IDs are required by the subscriptions API.
        if ( empty( $sub_lines[0]['product_id'] ) ) {
            $context['error'] = __( 'This subscription product is misconfigured (missing Breeze Product ID). Please contact the store.', 'breeze-payment-gateway' );
            return $context;
        }

        $context['price_id']   = $sub_lines[0]['price_id'];
        $context['product_id'] = $sub_lines[0]['product_id'];
        return $context;
    }

    /**
     * Resolve (creating if necessary) the Breeze customer ID for an order.
     *
     * POST /v1/subscriptions requires an existing Breeze customer id. Breeze's
     * POST /v1/customers is an upsert keyed by `referenceId`, so we send a
     * stable referenceId (the WC user id for logged-in shoppers, else a hash of
     * the billing email for guests) and repeated checkouts resolve to the same
     * Breeze customer.
     *
     * @param WC_Order $order Order to resolve the customer for.
     * @return string|false Breeze customer id, or false on failure.
     */
    private function resolve_breeze_customer_id( $order ) {
        $email   = $order->get_billing_email();
        $user_id = $order->get_customer_id();

        if ( $user_id ) {
            $reference_id = 'wc-user-' . $user_id;
        } elseif ( $email ) {
            $reference_id = 'wc-guest-' . md5( strtolower( $email ) );
        } else {
            // Nothing stable to key a customer on.
            return false;
        }

        $customer_data = array(
            'referenceId' => $reference_id,
            'signupAt'    => (int) ( time() * 1000 ), // Breeze timestamps are unix ms.
        );

        if ( $email ) {
            $customer_data['email'] = $email;
        }
        $first_name = $order->get_billing_first_name();
        if ( $first_name ) {
            $customer_data['firstName'] = $first_name;
        }
        $last_name = $order->get_billing_last_name();
        if ( $last_name ) {
            $customer_data['lastName'] = $last_name;
        }

        $result = $this->breeze_api_request( 'POST', '/v1/customers', $customer_data );

        if ( ! $result || empty( $result['id'] ) ) {
            return false;
        }

        return $result['id'];
    }

    /**
     * Handle SUBSCRIPTION_STATUS_UPDATED webhook event.
     *
     * Routes on `data.status` to update the associated WooCommerce order.
     *
     * @param array $data Webhook data payload.
     */
    private function handle_subscription_status_updated_webhook( $data ) {

        $order = $this->get_order_from_subscription_webhook( $data );
        if ( ! $order ) {
            return;
        }

        $status        = isset( $data['status'] ) ? $data['status'] : '';
        $sub_id        = isset( $data['id'] ) ? $data['id'] : '';

        switch ( $status ) {

            case 'ACTIVE':
                $order->add_order_note(
                    sprintf(
                        __( 'Breeze subscription active (ID: %s)', 'breeze-payment-gateway' ),
                        $sub_id ? $sub_id : 'N/A'
                    )
                );
                if ( $sub_id ) {
                    $order->update_meta_data( '_breeze_subscription_id', $sub_id );
                    $order->save();
                }
                break;

            case 'TRIALING':
            case 'DISCOUNTED_TRIALING':
                $order->add_order_note( __( 'Subscription trial period started', 'breeze-payment-gateway' ) );
                break;

            case 'GRACE_PERIOD':
                $order->add_order_note( __( 'Breeze subscription entered grace period', 'breeze-payment-gateway' ) );
                $order->update_status( 'on-hold', __( 'Breeze subscription in grace period.', 'breeze-payment-gateway' ) );
                break;

            case 'SUSPENDED':
                $order->add_order_note( __( 'Breeze subscription suspended', 'breeze-payment-gateway' ) );
                $order->update_status( 'on-hold', __( 'Breeze subscription suspended.', 'breeze-payment-gateway' ) );
                break;

            case 'CANCELED':
            case 'INCOMPLETE_EXPIRED':
                $order->add_order_note(
                    sprintf(
                        __( 'Breeze subscription %s', 'breeze-payment-gateway' ),
                        strtolower( $status )
                    )
                );
                $order->update_status( 'cancelled', __( 'Breeze subscription cancelled.', 'breeze-payment-gateway' ) );
                break;

            case 'INCOMPLETE':
            case 'SCHEDULED':
                $order->add_order_note(
                    sprintf(
                        __( 'Breeze subscription status: %s', 'breeze-payment-gateway' ),
                        $status
                    )
                );
                break;

            default:
                if ( $this->debug ) {
                    $this->log->warning(
                        sprintf( 'Unknown subscription status: %s', $status ),
                        array( 'source' => $this->id )
                    );
                }
        }
    }

    /**
     * Handle INVOICE_STATUS_UPDATED webhook event.
     *
     * On PAID: creates a new WooCommerce renewal order linked to the original
     * subscription order. On other statuses: adds informational order notes.
     *
     * @param array $data Webhook data payload.
     */
    private function handle_invoice_status_updated_webhook( $data ) {

        $status          = isset( $data['status'] ) ? $data['status'] : '';
        $subscription_id = isset( $data['subscriptionId'] ) ? $data['subscriptionId'] : '';
        $invoice_id      = isset( $data['id'] ) ? $data['id'] : 'N/A';

        switch ( $status ) {

            case 'PAID':
                if ( ! $subscription_id ) {
                    return;
                }

                // Idempotency: invoice webhooks are delivered at-least-once. If
                // this invoice has already been recorded on an order (the
                // original for the first invoice, or a renewal for later ones),
                // a redelivery must not create a duplicate paid order.
                if ( 'N/A' !== $invoice_id && $this->order_exists_with_invoice_id( $invoice_id ) ) {
                    return;
                }

                $original_order = $this->get_order_by_subscription_id( $subscription_id );
                if ( ! $original_order ) {
                    return;
                }

                // Breeze marks the first invoice of a subscription with no
                // `previousInvoiceId`; renewals reference the prior invoice.
                // This authoritative signal (rather than the order's paid state)
                // decides whether to complete the customer's own order or spin
                // up a renewal.
                $is_renewal = ! empty( $data['previousInvoiceId'] );

                if ( ! $is_renewal ) {
                    // First invoice → complete the customer's OWN order instead
                    // of creating a separate renewal. Without this the original
                    // order is stranded on-hold (no paid email / fulfillment).
                    if ( ! $original_order->is_paid() ) {
                        $original_order->payment_complete( 'N/A' !== $invoice_id ? $invoice_id : '' );
                    }
                    if ( 'N/A' !== $invoice_id ) {
                        $original_order->update_meta_data( '_breeze_invoice_id', $invoice_id );
                    }
                    $original_order->add_order_note(
                        sprintf(
                            __( 'Breeze subscription first payment received — Invoice %s.', 'breeze-payment-gateway' ),
                            $invoice_id
                        )
                    );
                    $original_order->save();
                    return;
                }

                // Subsequent invoice → create a renewal order mirroring the
                // original, priced from the invoice and captured as paid.
                $this->create_renewal_order( $original_order, $subscription_id, $invoice_id, $data );
                break;

            case 'EXPIRED':
            case 'CANCELED':
                if ( $subscription_id ) {
                    $order = $this->get_order_by_subscription_id( $subscription_id );
                    if ( $order ) {
                        $order->add_order_note(
                            sprintf(
                                __( 'Breeze subscription invoice %s: %s', 'breeze-payment-gateway' ),
                                $invoice_id,
                                $status
                            )
                        );
                    }
                }
                break;

            case 'PENDING':
            case 'GRACE_PERIOD':
                if ( $subscription_id ) {
                    $order = $this->get_order_by_subscription_id( $subscription_id );
                    if ( $order ) {
                        $order->add_order_note(
                            sprintf(
                                __( 'Breeze subscription invoice %s status: %s', 'breeze-payment-gateway' ),
                                $invoice_id,
                                $status
                            )
                        );
                    }
                }
                break;
        }
    }

    /**
     * Find the ORIGINAL WooCommerce order for a Breeze subscription.
     *
     * Every renewal order also stores `_breeze_subscription_id`, so a naive
     * newest-first query would return a renewal once one exists. Ordering by
     * date ASC returns the oldest match — the order created at checkout.
     *
     * @param string $subscription_id Breeze subscription ID.
     * @return WC_Order|false Order object or false if not found.
     */
    private function get_order_by_subscription_id( $subscription_id ) {
        $orders = wc_get_orders( array(
            'meta_key'   => '_breeze_subscription_id',
            'meta_value' => $subscription_id,
            'orderby'    => 'date',
            'order'      => 'ASC',
            'limit'      => 1,
        ) );
        return ! empty( $orders ) ? $orders[0] : false;
    }

    /**
     * Whether any order already records the given Breeze invoice ID.
     *
     * Used to make INVOICE_STATUS_UPDATED:PAID idempotent against at-least-once
     * webhook redelivery — both the original order (first invoice) and renewal
     * orders store `_breeze_invoice_id`.
     *
     * @param string $invoice_id Breeze invoice ID.
     * @return bool True if an order with this invoice ID exists.
     */
    private function order_exists_with_invoice_id( $invoice_id ) {
        $orders = wc_get_orders( array(
            'meta_key'   => '_breeze_invoice_id',
            'meta_value' => $invoice_id,
            'limit'      => 1,
            'return'     => 'ids',
        ) );
        return ! empty( $orders );
    }

    /**
     * Create a paid renewal order mirroring the original subscription order.
     *
     * The renewal total is sourced from the invoice's `amount` (minor units)
     * so coupons/proration applied on the Breeze side are honored rather than
     * recomputed from the current catalog price. Payment is captured via
     * payment_complete() so the renewal gets a transaction ID + paid date and
     * triggers the normal paid-order flow (emails, fulfillment).
     *
     * @param WC_Order $original_order  The original subscription order.
     * @param string   $subscription_id Breeze subscription ID.
     * @param string   $invoice_id      Breeze invoice ID ('N/A' if absent).
     * @param array    $data            Invoice webhook data payload.
     */
    private function create_renewal_order( $original_order, $subscription_id, $invoice_id, $data ) {
        $renewal_order = wc_create_order( array(
            'customer_id'          => $original_order->get_customer_id(),
            'payment_method'       => $original_order->get_payment_method(),
            'payment_method_title' => $original_order->get_payment_method_title(),
        ) );

        if ( is_wp_error( $renewal_order ) || ! $renewal_order ) {
            if ( $this->debug ) {
                $this->log->error(
                    sprintf( 'Failed to create renewal order for subscription %s (invoice %s)', $subscription_id, $invoice_id ),
                    array( 'source' => $this->id )
                );
            }
            return;
        }

        foreach ( $original_order->get_items() as $item ) {
            $product = $item->get_product();
            if ( $product ) {
                $renewal_order->add_product( $product, $item->get_quantity() );
            }
        }

        $renewal_order->set_address( $original_order->get_address( 'billing' ), 'billing' );
        $renewal_order->set_address( $original_order->get_address( 'shipping' ), 'shipping' );
        $renewal_order->set_currency( $original_order->get_currency() );
        $renewal_order->calculate_totals();

        // Prefer the amount Breeze actually charged over the recomputed catalog
        // total. Amount is in minor units; the gateway is USD-only (2 decimals).
        if ( isset( $data['amount'] ) && is_numeric( $data['amount'] ) ) {
            $renewal_order->set_total( round( ( (int) $data['amount'] ) / 100, 2 ) );
        }

        $renewal_order->update_meta_data( '_breeze_subscription_id', $subscription_id );
        $renewal_order->update_meta_data( '_breeze_is_renewal', 'yes' );
        if ( 'N/A' !== $invoice_id ) {
            $renewal_order->update_meta_data( '_breeze_invoice_id', $invoice_id );
        }
        $renewal_order->add_order_note(
            sprintf(
                __( 'Breeze subscription renewal — Invoice %s.', 'breeze-payment-gateway' ),
                $invoice_id
            )
        );

        $renewal_order->payment_complete( 'N/A' !== $invoice_id ? $invoice_id : '' );
        $renewal_order->save();
    }

    /**
     * Resolve a WooCommerce order from a subscription webhook payload.
     *
     * Tries `clientReferenceId` first (same format as payment webhooks), then
     * falls back to a meta query on `_breeze_subscription_id`.
     *
     * @param array $data Webhook data payload.
     * @return WC_Order|false Order object or false if not resolved.
     */
    private function get_order_from_subscription_webhook( $data ) {

        $subscription_id = isset( $data['id'] ) ? $data['id'] : '';

        // Prefer clientReferenceId (format: "order-{id}").
        if ( isset( $data['clientReferenceId'] ) ) {
            $raw_id   = str_replace( 'order-', '', $data['clientReferenceId'] );
            $order_id = absint( $raw_id );
            if ( $order_id ) {
                $order = wc_get_order( $order_id );
                // Only accept the resolved order if it actually owns this
                // subscription — parity with the payment webhook's pageId
                // cross-check. Guards against a signed payload whose
                // clientReferenceId resolves to an unrelated / reused order.
                if ( $order && ( ! $subscription_id || $order->get_meta( '_breeze_subscription_id' ) === $subscription_id ) ) {
                    return $order;
                }
            }
        }

        // Fall back to _breeze_subscription_id meta query.
        if ( $subscription_id ) {
            return $this->get_order_by_subscription_id( $subscription_id );
        }

        return false;
    }
}
