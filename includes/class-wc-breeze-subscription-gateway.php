<?php
/**
 * Breeze Subscription Gateway Class
 *
 * @package Breeze_Payment_Gateway
 */

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WC_Breeze_Subscription_Gateway
 *
 * A dedicated WooCommerce payment gateway for Breeze subscription products.
 * Extends WC_Breeze_Payment_Gateway and always routes to the subscription
 * checkout flow. Crypto payment method fields are omitted because
 * subscriptions are fiat-only.
 *
 * @class       WC_Breeze_Subscription_Gateway
 * @extends     WC_Breeze_Payment_Gateway
 * @version     1.0.0
 * @package     WooCommerce/Classes/Payment
 */
class WC_Breeze_Subscription_Gateway extends WC_Breeze_Payment_Gateway {

	/**
	 * Constructor for the subscription gateway.
	 */
	public function __construct() {

		// Call parent constructor to inherit all base setup.
		parent::__construct();

		// Override gateway identity.
		$this->id                 = 'breeze_subscription_gateway';
		$this->method_title       = __( 'Breeze (Subscriptions)', 'breeze-payment-gateway' );
		$this->method_description = __( 'Accept subscription payments through Breeze. Use this gateway for products with a Breeze Price ID (subscription products). Card, Apple Pay, and Google Pay are supported; crypto is not available for subscriptions. Customers will be redirected to Breeze to complete their subscription sign-up.', 'breeze-payment-gateway' );

		// Re-run settings init so options are loaded under the correct gateway ID.
		$this->init_form_fields();
		$this->init_settings();

		// Re-derive the runtime credentials (api_key, webhook_secret, enabled,
		// testmode, debug, ...) from THIS gateway's settings. parent::__construct()
		// already derived them under the base gateway id 'breeze_payment_gateway';
		// without this the subscription gateway would authenticate with the base
		// gateway's key (empty/wrong when only the subscription gateway is set up).
		$this->load_runtime_settings();

		// Re-bind settings save action to the correct gateway ID.
		add_action(
			'woocommerce_update_options_payment_gateways_' . $this->id,
			array( $this, 'process_admin_options' )
		);
	}

	/**
	 * Initialize form fields, inheriting the parent set but removing
	 * crypto and preferred-payment-method fields (subscriptions are fiat-only).
	 */
	public function init_form_fields() {

		parent::init_form_fields();

		// Remove crypto-specific and preferred payment method fields.
		$remove = array(
			'payment_methods',
			'crypto_network',
			'crypto_token',
			'flexible_amount_section',
			'flexible_amount_max',
			'flexible_amount_percentage',
			'flexible_amount_fixed',
		);

		foreach ( $remove as $key ) {
			unset( $this->form_fields[ $key ] );
		}
	}

	/**
	 * Process payment — always routes to the Breeze subscription checkout flow.
	 *
	 * Unlike the parent gateway, there is no fallback to the payment page flow.
	 * If the order does not contain a subscription product, an error is shown.
	 *
	 * @param int $order_id WooCommerce order ID.
	 * @return array WooCommerce process_payment result array.
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

		return $this->create_subscription_checkout( $order );
	}
}
