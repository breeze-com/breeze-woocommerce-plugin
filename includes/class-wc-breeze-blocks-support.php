<?php
/**
 * Breeze Blocks Support
 *
 * @package Breeze_Payment_Gateway
 */

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Breeze Blocks Payment Method
 *
 * @extends AbstractPaymentMethodType
 */
final class WC_Breeze_Blocks_Support extends AbstractPaymentMethodType {

    /**
     * Name of the payment method.
     *
     * @var string
     */
    protected $name = 'breeze_payment_gateway';

    /**
     * Gateway instance.
     *
     * @var WC_Breeze_Payment_Gateway
     */
    private $gateway;

    /**
     * Constructor
     */
    public function __construct() {
        add_action( 'woocommerce_rest_checkout_process_payment_with_context', array( $this, 'add_payment_request_data' ), 10, 2 );
    }

    /**
     * Initializes the payment method type.
     */
    public function initialize() {
        $this->settings = get_option( 'woocommerce_breeze_payment_gateway_settings', array() );
        $gateways       = WC()->payment_gateways->payment_gateways();
        $this->gateway  = $gateways[ $this->name ];
    }

    /**
     * Returns if this payment method should be active.
     *
     * @return boolean
     */
    public function is_active() {
        return ! empty( $this->gateway ) && $this->gateway->is_available();
    }

    /**
     * Returns an array of scripts/handles to be registered for this payment method.
     *
     * @return array
     */
    public function get_payment_method_script_handles() {
        
        $script_path       = '/assets/js/blocks/breeze-blocks.js';
        $script_asset_path = BREEZE_PAYMENT_GATEWAY_PLUGIN_DIR . 'assets/js/blocks/breeze-blocks.asset.php';
        $script_asset      = file_exists( $script_asset_path )
            ? require $script_asset_path
            : array(
                'dependencies' => array(),
                'version'      => BREEZE_PAYMENT_GATEWAY_VERSION,
            );
        
        $script_url = BREEZE_PAYMENT_GATEWAY_PLUGIN_URL . $script_path;

        wp_register_script(
            'wc-breeze-blocks',
            $script_url,
            $script_asset['dependencies'],
            $script_asset['version'],
            true
        );

        return array( 'wc-breeze-blocks' );
    }

    /**
     * Returns an array of key=>value pairs of data made available to the payment methods script.
     *
     * @return array
     */
    public function get_payment_method_data() {
        return array(
            'title'       => $this->get_setting( 'title' ),
            'description' => $this->get_setting( 'description' ),
            'supports'    => $this->get_supported_features(),
            'testMode'    => $this->gateway->testmode,
            'icon'        => $this->gateway->icon,
        );
    }

    /**
     * Get list of supported features.
     *
     * @return array
     */
    public function get_supported_features() {
        $gateway_supports = array();
        
        if ( ! empty( $this->gateway->supports ) ) {
            foreach ( $this->gateway->supports as $support ) {
                $gateway_supports[] = $support;
            }
        }
        
        return $gateway_supports;
    }

    /**
     * Add payment request data.
     *
     * @param \PaymentContext $context Payment context.
     * @param \PaymentResult  $result  Payment result.
     */
    public function add_payment_request_data( $context, &$result ) {
        // Add any additional data needed for payment processing
        if ( $this->name === $context->payment_method ) {
            // You can add custom data here if needed
        }
    }
}

// Enqueue styles
wp_register_style(
    'wc-breeze-blocks',
    BREEZE_PAYMENT_GATEWAY_PLUGIN_URL . 'assets/css/breeze-blocks.css',
    array(),
    BREEZE_PAYMENT_GATEWAY_VERSION
);
wp_enqueue_style( 'wc-breeze-blocks' );
