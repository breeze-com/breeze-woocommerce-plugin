<?php
/**
 * Plugin Name: Breeze
 * Plugin URI: https://breeze.com
 * Description: Accept payments through Breeze payment gateway for WooCommerce
 * Version: 1.0.2
 * Author: Breeze
 * Author URI: https://breeze.com
 * Text Domain: breeze-payment-gateway
 * Domain Path: /languages
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * Requires Plugins: woocommerce
 * WC requires at least: 6.0
 * WC tested up to: 8.5
 *
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 */

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Define plugin constants
define( 'BREEZE_PAYMENT_GATEWAY_VERSION', '1.0.2' );
define( 'BREEZE_PAYMENT_GATEWAY_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'BREEZE_PAYMENT_GATEWAY_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'BREEZE_PAYMENT_GATEWAY_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

/**
 * Check if WooCommerce is active (supports multisite network activation)
 */
$active_plugins = apply_filters( 'active_plugins', get_option( 'active_plugins' ) );
if ( is_multisite() ) {
    $network_plugins = get_site_option( 'active_sitewide_plugins', array() );
    if ( isset( $network_plugins['woocommerce/woocommerce.php'] ) ) {
        $active_plugins[] = 'woocommerce/woocommerce.php';
    }
}
if ( ! in_array( 'woocommerce/woocommerce.php', $active_plugins ) ) {
    return;
}

/**
 * Add the gateway to WooCommerce
 */
add_filter( 'woocommerce_payment_gateways', 'breeze_add_payment_gateway' );
function breeze_add_payment_gateway( $gateways ) {
    $gateways[] = 'WC_Breeze_Payment_Gateway';
    return $gateways;
}

/**
 * Initialize the gateway class
 */
add_action( 'plugins_loaded', 'breeze_payment_gateway_init', 11 );
function breeze_payment_gateway_init() {
    
    // Load plugin textdomain
    load_plugin_textdomain( 'breeze-payment-gateway', false, dirname( BREEZE_PAYMENT_GATEWAY_PLUGIN_BASENAME ) . '/languages' );
    
    // Include the gateway class
    if ( class_exists( 'WC_Payment_Gateway' ) ) {
        require_once BREEZE_PAYMENT_GATEWAY_PLUGIN_DIR . 'includes/class-wc-breeze-payment-gateway.php';
    }
}

/**
 * Register blocks integration
 */
add_action( 'woocommerce_blocks_loaded', 'breeze_payment_gateway_blocks_support' );
function breeze_payment_gateway_blocks_support() {
    
    // Check if the required class exists
    if ( ! class_exists( 'Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType' ) ) {
        return;
    }

    // Include the blocks integration class
    require_once BREEZE_PAYMENT_GATEWAY_PLUGIN_DIR . 'includes/class-wc-breeze-blocks-support.php';

    // Register the payment method type
    add_action(
        'woocommerce_blocks_payment_method_type_registration',
        function( Automattic\WooCommerce\Blocks\Payments\PaymentMethodRegistry $payment_method_registry ) {
            $payment_method_registry->register( new WC_Breeze_Blocks_Support() );
        }
    );
}

/**
 * Add settings link to plugin page
 */
add_filter( 'plugin_action_links_' . BREEZE_PAYMENT_GATEWAY_PLUGIN_BASENAME, 'breeze_payment_gateway_action_links' );
function breeze_payment_gateway_action_links( $links ) {
    $plugin_links = array(
        '<a href="' . admin_url( 'admin.php?page=wc-settings&tab=checkout&section=breeze_payment_gateway' ) . '">' . __( 'Settings', 'breeze-payment-gateway' ) . '</a>',
    );
    return array_merge( $plugin_links, $links );
}

/**
 * Declare HPOS compatibility and Cart Checkout Blocks compatibility
 */
add_action( 'before_woocommerce_init', function() {
    if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', __FILE__, true );
    }
} );
